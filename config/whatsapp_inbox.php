<?php
/**
 * WhatsApp inbox + address book tables, and the reconciliation between the
 * address book and the customer list.
 *
 * Lived in the inbox page itself rather than a migration script in the project
 * root, which is how this project already does it for the staff module: the
 * page is behind a login, so nothing unauthenticated can run DDL. (A root-level
 * migrate_*.php is exactly the mistake migrate_staff_module.php was.)
 */
function whatsappEnsureInboxTable($db)
{
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        -- Digits only, international form (8801XXXXXXXXX). The @c.us suffix is a
        -- WhatsApp implementation detail and must never reach this column.
        chat_id VARCHAR(20) NOT NULL,
        direction ENUM('in','out') NOT NULL,
        body TEXT NULL,
        has_media TINYINT(1) NOT NULL DEFAULT 0,
        -- WhatsApp's own message id. NULL is allowed and repeated on purpose:
        -- a message we could not identify must not be silently dropped, and the
        -- unique key only dedupes rows that actually carry an id.
        wa_message_id VARCHAR(90) NULL,
        is_revoked TINYINT(1) NOT NULL DEFAULT 0,
        read_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_owner_msg (owner_id, wa_message_id),
        INDEX idx_thread (owner_id, chat_id, id),
        INDEX idx_unread (owner_id, direction, read_at)
    ) ENGINE=InnoDB");

    // The numbers this WhatsApp account can talk to, whether or not they have
    // ever written.
    //
    // whatsapp_messages alone cannot answer "who can I message?", because a row
    // only exists once somebody has sent something. A shop needs the whole
    // address book to start a conversation, not just the replies. This is
    // WhatsApp's own chat list merged with the numbers the bridge has a secure
    // session with, so a number shows up the moment it is reachable.
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS whatsapp_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        -- Digits only, international form. Same canonical form as chat_id above.
        chat_id VARCHAR(20) NOT NULL,
        -- The name saved on the phone, and the name the person set themselves.
        -- Either can be absent, and a bare number is a legitimate row: the
        -- bridge knows the number is reachable long before WhatsApp tells it a
        -- name for it.
        name VARCHAR(120) NULL,
        notify VARCHAR(120) NULL,
        username VARCHAR(60) NULL,
        -- Last activity WhatsApp knows about, used only for ordering. Zero/NULL
        -- for a number that has never had a conversation.
        wa_last_at DATETIME NULL,
        wa_unread INT NOT NULL DEFAULT 0,
        -- chat = WhatsApp listed it as a conversation, known = the bridge only
        -- has a session with it. Lets the UI say there are no messages yet
        -- instead of showing an empty row as if something were missing.
        source VARCHAR(10) NOT NULL DEFAULT 'known',
        seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_owner_contact (owner_id, chat_id)
    ) ENGINE=InnoDB");
}

/**
 * Confirm, for free, which saved customers are on WhatsApp.
 *
 * ── The problem this solves ────────────────────────────────────────────────
 *
 * A number can only be confirmed by asking WhatsApp whether it is registered,
 * and until recently the only way this project did that was the shopkeeper
 * pressing "Check numbers": a browser-driven loop that asked the bridge one
 * small batch at a time. That has two costs, both of which the shopkeeper feels.
 *
 * The first is that the answer is only ever as fresh as the last time somebody
 * opened the Marketing page. A customer who installed WhatsApp yesterday is
 * still NULL this morning, so the Recipients list shows them as not reachable
 * and the shop sends its next campaign without them.
 *
 * The second is that asking costs something. An existence probe is a round trip
 * to WhatsApp per number, and doing it to a whole customer list is slow enough
 * to be a thing the shopkeeper puts off, which is how the list ends up mostly
 * NULL in the first place.
 *
 * ── The observation ────────────────────────────────────────────────────────
 *
 * The bridge already uploads the WhatsApp account's own address book every few
 * seconds, into whatsapp_contacts. A number in that table is reachable by
 * definition: WhatsApp handed it to us as a chat, or opened a session with it.
 * So the answer to "does this customer have WhatsApp" is very often sitting in
 * a table we already keep, and reading it is free and instant.
 *
 * That is this function. It is the reason the Recipients list can be honest
 * without a button.
 *
 * ── What it deliberately does not do ───────────────────────────────────────
 *
 * It never writes 0. Absence from the address book proves nothing: the number
 * may well be on WhatsApp and simply have never been messaged from this
 * account, and in Bangladesh a shop's address book is a tiny fraction of the
 * country. Treating "not in my chats" as "not on WhatsApp" would quietly write
 * off most of the customer list. Only a real probe against WhatsApp may set 0,
 * and that is the sync_whatsapp action.
 *
 * It also never touches a row that is already 1. There is no such thing as
 * losing WhatsApp in a way this can detect, and a later probe that fails must
 * not be able to take a confirmed number back down.
 *
 * ── Why the numbers are matched in PHP ─────────────────────────────────────
 *
 * customers.phone holds whatever the shop typed: 01712345678, +8801712345678,
 * 8801712345678 and so on, and marketingNormalizePhone() is what folds those
 * into one shape. There is no normalized column and no functional index on
 * one, so the comparison cannot be a plain indexed `WHERE phone IN (...)`.
 *
 * This scans one shop's own customers instead, and only those still needing an
 * answer. That is a few hundred rows on a single-owner install, run at most
 * once per address-book upload, and the write it produces is bounded by the
 * number of customers who genuinely flipped. Adding a generated column plus an
 * index would make it faster and would mean changing every write path that
 * touches customers.phone, which is a much larger blast radius than the speed
 * is worth here.
 *
 * @param PDO    $db
 * @param int    $ownerId  shop the contacts belong to
 * @param array  $phones   chat_id values from the bridge, digits only
 * @return array{marked:int, scanned:int}  how many customers flipped, and how
 *                                          many rows the scan had to read
 */
function whatsappMarkCustomersOnWa($db, $ownerId, array $phones)
{
    if (!$phones) {
        return ['marked' => 0, 'scanned' => 0];
    }

    // The batch the bridge sent, as a hash. Lookup is then O(1) per customer
    // instead of an in_array scan of up to 500 numbers times every customer,
    // which is the difference between this being cheap and being silly.
    $reachable = array_fill_keys($phones, true);
    if (!$reachable) {
        return ['marked' => 0, 'scanned' => 0];
    }

    // Only rows that could still change. A confirmed 1 is left alone, so a
    // customer cannot be knocked back to unknown by a later upload, and the
    // steady-state cost of this function is a nearly empty result set.
    //
    // Written as "IS NULL OR = 0" rather than the shorter "IS NOT 1" that
    // means the same thing here. MariaDB's IS NOT is a NULL test and only
    // accepts NULL / TRUE / FALSE, so "IS NOT 1" is a syntax error on the
    // server this runs on - while parsing perfectly on SQLite, which is the
    // kind of difference that only shows up in production.
    $stmt = $db->prepare("SELECT id, phone FROM customers
                          WHERE owner_id = ? AND (has_whatsapp IS NULL OR has_whatsapp = 0)
                            AND TRIM(COALESCE(phone, '')) <> ''");
    $stmt->execute([$ownerId]);
    $candidates = $stmt->fetchAll();
    $scanned = count($candidates);

    if (!$candidates) {
        return ['marked' => 0, 'scanned' => 0];
    }

    $marked = 0;
    $ids = [];
    foreach ($candidates as $c) {
        $phone = marketingNormalizePhone($c['phone']);
        if ($phone !== '' && isset($reachable[$phone])) {
            $ids[] = (int)$c['id'];
        }
    }

    // One statement for the whole batch. A number of individual updates here
    // would be a statement per matching customer inside a request the bridge is
    // already waiting on.
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $up = $db->prepare("UPDATE customers
                            SET has_whatsapp = 1, whatsapp_checked_at = NOW()
                            WHERE owner_id = ? AND (has_whatsapp IS NULL OR has_whatsapp = 0)
                              AND id IN ($marks)");
        $up->execute(array_merge([$ownerId], $ids));
        // rowCount is the honest number: two catalogs overlapping in one batch
        // can list the same customer twice, and counting the array would
        // report a flip that did not happen.
        $marked = $up->rowCount();
    }

    return ['marked' => $marked, 'scanned' => $scanned];
}
