<?php
/**
 * API - WhatsApp inbox
 *
 * Backs the Inbox page. The bridge can see WhatsApp traffic but knows nothing
 * about shops, so this file is the join between the two: it drains the bridge's
 * buffer into MySQL, then serves conversations back out of MySQL.
 *
 * That split matters for restarts. The bridge's buffer is capped and lives in
 * memory, so it is a delivery mechanism, not a record. Once a message is here it
 * survives the bridge being stopped, restarted or re-linked.
 *
 * POST action:
 *   pull       {} -> drain the bridge into the table
 *   threads    {} -> conversation list
 *   messages   { chat_id, after } -> one conversation
 *   send       { chat_id, body } -> send and record
 *   mark_read  { chat_id, ids }
 *   save_lead  { chat_id, name } -> turn an unknown number into a customer
 *   backfill   { chat_id } -> ask WhatsApp for a chat's recent history
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
require_once '../../config/whatsapp_inbox.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasPermission('marketing')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$db      = getDB();
$user    = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Chats are stored canonically, exactly as marketingNormalizePhone() does. */
function chatId($raw)
{
    return marketingNormalizePhone($raw);
}

/**
 * Escape a value for a vCard field.
 *
 * A comma, semicolon or newline inside a customer name silently corrupts the
 * whole card - the phone either truncates the name or refuses the contact. Names
 * like "Rahim, Babu" or "Shah & Sons" are ordinary enough in this trade.
 */
function vcardEscape($value)
{
    return str_replace(
        ['\\', "\r\n", "\n", "\r", ';', ','],
        ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
        (string)$value
    );
}

whatsappEnsureInboxTable($db);

/**
 * Refresh the list of numbers this shop can message.
 *
 * Separate from the message pull on purpose. Messages are the urgent half and
 * must not be held up by a catalog fetch that can be slow, and the catalog is
 * worth refreshing on a slower beat anyway - it changes when somebody new is
 * added to WhatsApp, which is rare compared to somebody sending a message.
 *
 * Never removes rows. A number that stops appearing from WhatsApp may simply be
 * one the shop has not spoken to in a while, and silently deleting a customer's
 * thread because a sync was incomplete would lose their history.
 *
 * Every number it stores is also offered to the customer list as a confirmed
 * WhatsApp number, which is the only thing that makes this more than a display
 * table: a number the account can reach needs no existence probe, so the
 * Recipients list fills itself from here.
 *
 * @return int how many numbers are now known
 */
function syncContacts($db, $ownerId)
{
    $res = marketingBridgeCall($ownerId, 'GET', '/api/catalog', [], 30);
    if (!$res['ok'] || empty($res['data']['chats'])) {
        return -1;   // unknown: the bridge could not be asked, which is not the
                     // same as "the shop has no contacts"
    }

    $up = $db->prepare("INSERT INTO whatsapp_contacts
        (owner_id, chat_id, name, notify, username, wa_last_at, wa_unread, source)
        VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?)
        ON DUPLICATE KEY UPDATE
            name       = COALESCE(NULLIF(VALUES(name), ''), name),
            notify     = COALESCE(NULLIF(VALUES(notify), ''), notify),
            username   = COALESCE(NULLIF(VALUES(username), ''), username),
            wa_last_at = GREATEST(COALESCE(wa_last_at, '1970-01-01'), VALUES(wa_last_at)),
            wa_unread  = VALUES(wa_unread),
            source     = VALUES(source),
            seen_at    = NOW()");

    $count = 0;
    $reachable = [];
    foreach ($res['data']['chats'] as $c) {
        $chat = chatId($c['chat_id'] ?? '');
        if ($chat === '') { continue; }
        $up->execute([
            $ownerId,
            $chat,
            trim((string)($c['name'] ?? '')) ?: null,
            trim((string)($c['notify'] ?? '')) ?: null,
            trim((string)($c['username'] ?? '')) ?: null,
            (int)($c['last_at'] ?? 0) ?: 0,
            (int)($c['unread'] ?? 0),
            ($c['source'] ?? '') === 'chat' ? 'chat' : 'known',
        ]);
        $count++;
        $reachable[$chat] = true;
    }

    // The same reconciliation the agent path does. Without it a shop on direct
    // mode would fill its address book and still have an empty Recipients list,
    // because the two modes differ only in how the catalog arrives, not in what
    // it means. See whatsappMarkCustomersOnWa().
    whatsappMarkCustomersOnWa($db, $ownerId, array_keys($reachable));

    return $count;
}

switch ($action) {

    /**
     * Drain the bridge into the table.
     *
     * Called on a timer by the Inbox page. Safe to call as often as you like:
     * the unique key on (owner_id, wa_message_id) makes a repeat delivery a
     * no-op, so a page that reloads mid-poll neither duplicates nor skips. That
     * is also why `since` is only an optimisation - a page that starts at 0
     * just replays the buffer and the duplicates are discarded.
     */
    case 'pull': {
        $status = marketingGetBridgeStatus($ownerId);
        if (empty($status['status'])) {
            echo json_encode(['ok' => false, 'error' => $status['error'] ?: 'Bridge is not reachable.']);
            break;
        }

        // Agent mode: there is no bridge to drain.
        //
        // The agent posts what arrives straight into this table over the agent
        // endpoint, so by the time the page asks, the rows are already here and
        // the only thing left to do is let it redraw. Reporting "nothing new"
        // rather than an error is what keeps the inbox working: the alternative
        // is a page that shows a connection, and then an error under it, every
        // five seconds for as long as the tab is open.
        //
        // What is given up here is revocation reporting - "deleted on the phone"
        // needs a live socket to ask, and in this direction the agent would have
        // to volunteer it. Messages are still stored and still shown; a message
        // deleted on the phone simply stays visible, which is the lesser of the
        // two wrongs.
        if (($status['mode'] ?? '') === 'agent') {
            echo json_encode([
                'ok'      => true,
                'seq'     => 0,
                'new'     => 0,
                'revoked' => 0,
                'gap'     => false,
            ]);
            break;
        }

        $since = (int)($_POST['since'] ?? 0);
        $res = marketingBridgeCall($ownerId, 'GET', '/api/messages?since=' . $since . '&limit=300', [], 25);

        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => $res['error'] ?: 'Could not read the bridge buffer.']);
            break;
        }

        $items = $res['data']['items'] ?? [];
        $ins = $db->prepare("INSERT IGNORE INTO whatsapp_messages
            (owner_id, chat_id, direction, body, has_media, wa_message_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))");
        $stored = 0;
        foreach ($items as $m) {
            $chat = chatId($m['chat_id'] ?? '');
            if ($chat === '') { continue; }
            $ins->execute([
                $ownerId,
                $chat,
                ($m['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                (string)($m['body'] ?? ''),
                !empty($m['has_media']) ? 1 : 0,
                $m['wa_message_id'] ?? null,
                (int)($m['timestamp'] ?? time()),
            ]);
            // INSERT IGNORE reports 0 rows when the id was already there, which
            // is the dedupe working rather than a failure.
            $stored += $ins->rowCount();
        }

        // Messages deleted on the phone. Marked rather than dropped so a thread
        // does not quietly change shape while somebody is reading it.
        $revoked = 0;
        $rv = marketingBridgeCall($ownerId, 'GET', '/api/revoked', [], 20);
        if ($rv['ok'] && !empty($rv['data']['items'])) {
            $up = $db->prepare("UPDATE whatsapp_messages SET is_revoked = 1
                               WHERE owner_id = ? AND wa_message_id = ?");
            foreach ($rv['data']['items'] as $r) {
                if (empty($r['wa_message_id'])) { continue; }
                $up->execute([$ownerId, $r['wa_message_id']]);
                $revoked += $up->rowCount();
            }
        }

        // A gap means the buffer rolled over while nobody was collecting. The
        // lost messages are not recoverable from here - only from WhatsApp's own
        // history - so say so rather than quietly reporting a short thread.
        echo json_encode([
            'ok' => true,
            'seq' => (int)($res['data']['seq'] ?? 0),
            'new' => $stored,
            'revoked' => $revoked,
            'received' => count($items),
            'gap' => !empty($res['data']['gap']),
            'connected' => !empty($res['data']['connected']),
            'contacts' => syncContacts($db, $ownerId),
        ]);
        break;
    }

    /**
     * The chat list: every number this shop can message, plus anything that has
     * written even if the address book has not caught up.
     *
     * Two sources, unioned rather than joined on one:
     *   whatsapp_contacts  the address book, including numbers with no messages
     *   whatsapp_messages  a chat_id missing from the address book, which means
     *                       somebody new wrote before the catalog knew of them.
     *                       Dropping those would lose a brand new lead, which is
     *                       the one row a shopkeeper most wants to see.
     *
     * Customer matching is done in PHP rather than SQL because customers.phone
     * holds whatever the shopkeeper typed - 01617763939, +8801617763939,
     * 8801617763939 - while a chat is always canonical. Comparing them in SQL
     * means a chain of REPLACE() calls that has to be kept correct forever;
     * marketingNormalizePhone() already does the job and is used everywhere
     * else, so the two lists cannot drift apart.
     */
    case 'threads': {
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));

        $summary = "SELECT m.chat_id,
                           MAX(m.created_at) AS last_at,
                           MAX(m.id)         AS last_id,
                           SUM(m.direction = 'in' AND m.read_at IS NULL) AS unread
                    FROM whatsapp_messages m
                    WHERE m.owner_id = ?
                    GROUP BY m.chat_id";

        $sql = "SELECT c.chat_id, c.name AS wa_name, c.source, c.wa_last_at,
                       m.last_at, m.last_id, COALESCE(m.unread, 0) AS unread
                FROM whatsapp_contacts c
                LEFT JOIN ($summary) m ON m.chat_id = c.chat_id
                WHERE c.owner_id = ?
                UNION
                SELECT m.chat_id, NULL AS wa_name, 'chat' AS source, NULL AS wa_last_at,
                       m.last_at, m.last_id, COALESCE(m.unread, 0) AS unread
                FROM ($summary) m
                WHERE m.chat_id NOT IN (
                    SELECT chat_id FROM whatsapp_contacts WHERE owner_id = ?
                )";

        $st = $db->prepare($sql);
        // Four bindings, not three: the summary subquery is embedded once per
        // half of the union and each carries its own owner_id, so a third
        // binding leaves the statement with an unfilled placeholder and PDO
        // throws instead of quietly returning nothing.
        $st->execute([$ownerId, $ownerId, $ownerId, $ownerId]);
        $rows = $st->fetchAll();

        if (!$rows) {
            echo json_encode(['ok' => true, 'threads' => [], 'count' => 0, 'leads' => 0,
                              'customers' => 0, 'chatted' => 0, 'silent' => 0]);
            break;
        }

        // The owners' customers, keyed by canonical number. One query for the
        // whole list rather than one per row.
        //
        // has_whatsapp is carried through as a genuine three-state: true, false,
        // or null for never probed. It is not collapsed to a boolean, because
        // "we have not checked" and "this number is not on WhatsApp" are
        // completely different facts. Telling a shopkeeper a customer is
        // unreachable when the number was merely never tested would make them
        // give up on somebody who is perfectly reachable.
        $customers = [];
        $cs = $db->prepare("SELECT id, name, phone, has_whatsapp
                            FROM customers WHERE owner_id = ?");
        $cs->execute([$ownerId]);
        foreach ($cs->fetchAll() as $c) {
            $key = marketingNormalizePhone($c['phone'] ?? '');
            if ($key === '') { continue; }
            // First one wins: a shop that has the same person saved twice should
            // still show one thread, and the dup-check module is where that gets
            // flagged rather than here.
            if (!isset($customers[$key])) {
                $customers[$key] = [
                    'id'           => (int)$c['id'],
                    'name'         => $c['name'],
                    'has_whatsapp' => $c['has_whatsapp'] === null ? null : ((int)$c['has_whatsapp'] === 1),
                ];
            }
        }

        // The newest row of each thread, for the list preview. One grouped query
        // instead of a lookup per conversation.
        $last = [];
        $ls = $db->prepare("SELECT chat_id, direction, body, is_revoked FROM whatsapp_messages
                            WHERE owner_id = ? AND id IN (
                                SELECT MAX(id) FROM whatsapp_messages
                                WHERE owner_id = ? GROUP BY chat_id
                            )");
        $ls->execute([$ownerId, $ownerId]);
        foreach ($ls->fetchAll() as $r) {
            $last[$r['chat_id']] = $r;
        }

        $out = [];
        foreach ($rows as $r) {
            $chat   = $r['chat_id'];
            $l      = $last[$chat] ?? null;
            $cust   = $customers[$chat] ?? null;
            $body   = $l ? (string)$l['body'] : '';
            $waName = trim((string)($r['wa_name'] ?? ''));

            // Best name first: the shop's own customer record, then the name saved
            // on the phone, then the formatted number. A shopkeeper recognises a
            // person by their customer record far more reliably than by whatever
            // the contact happens to be called on WhatsApp.
            $label = $cust['name'] ?? ($waName ?: marketingFormatPhone($chat));

            $out[] = [
                'chat_id'       => $chat,
                'label'         => $label,
                'phone_display' => marketingFormatPhone($chat),
                'wa_name'       => $waName,
                'last_at'       => $r['last_at'] ?: $r['wa_last_at'],
                'last_id'       => (int)($r['last_id'] ?? 0),
                'unread'        => (int)$r['unread'],
                'has_messages'  => $l !== null,
                'preview'       => !$l
                                     ? 'Kono message nai - prothom bar likhte paren'
                                     : (!empty($l['is_revoked'])
                                         ? '[message deleted]'
                                         : (($l['direction'] ?? '') === 'out' ? 'You: ' : '') . $body),
                // A number with no matching customer. This is how someone who
                // has never bought gets noticed, so it is worth showing plainly.
                'is_lead'       => $cust === null,
                'customer_id'   => $cust['id'] ?? null,
                'customer_name' => $cust['name'] ?? null,
                'has_whatsapp'  => $cust['has_whatsapp'] ?? null,
                'source'        => $r['source'],
            ];
        }

        // Customers the shop has saved that WhatsApp has never mentioned.
        //
        // The union above is built from what WhatsApp knows, so a customer who
        // has never been messaged and is not in the phone's address book is
        // simply absent - which is the opposite of what this screen is for. A
        // shopkeeper opening Chats wants to start a conversation with a customer,
        // and needing them to message first defeats that.
        //
        // Added in PHP rather than SQL because customers.phone holds whatever was
        // typed, while a chat is always canonical, and the two can only be
        // compared after normalisation.
        $listed = [];
        foreach ($out as $t) { $listed[$t['chat_id']] = true; }

        foreach ($customers as $chat => $cust) {
            if (isset($listed[$chat])) { continue; }
            $out[] = [
                'chat_id'       => $chat,
                'label'         => $cust['name'] ?: marketingFormatPhone($chat),
                'phone_display' => marketingFormatPhone($chat),
                'wa_name'       => '',
                'last_at'       => null,
                'last_id'       => 0,
                'unread'        => 0,
                'has_messages'  => false,
                'preview'       => 'Customer list theke - prothom message pathao',
                'is_lead'       => false,
                'customer_id'   => $cust['id'],
                'customer_name' => $cust['name'],
                'has_whatsapp'  => $cust['has_whatsapp'],
                'source'        => 'customer',
            ];
        }

        // Which numbers the list shows.
        //
        // Default is deliberately narrow: the customers this shop has saved AND
        // that are actually on WhatsApp. The bridge also knows every number it
        // holds a secure session with - for a real account that is hundreds of
        // numbers the shopkeeper has no relationship with - and listing those
        // buries the handful of people the list is actually for.
        //
        // One thing is never filtered out: a number that has actually exchanged
        // messages. Somebody who wrote and got an answer must not vanish from
        // the chat list, because then the shopkeeper cannot reply to them and
        // the conversation becomes unreachable. That is a real conversation, not
        // address-book noise.
        $scope = ($_GET['scope'] ?? 'wa') === 'all' ? 'all' : 'wa';
        if ($scope === 'wa') {
            $out = array_values(array_filter($out, function ($t) {
                return $t['has_messages'] || $t['has_whatsapp'] === true;
            }));
        }

        // Newest activity first, then everything with no activity at all, then
        // alphabetically. Ordering only by activity would leave the never-messaged
        // numbers in an arbitrary order that reshuffles on every poll.
        usort($out, function ($a, $b) {
            $at = $a['last_at'] ? strtotime($a['last_at']) : 0;
            $bt = $b['last_at'] ? strtotime($b['last_at']) : 0;
            if ($at !== $bt) { return $bt <=> $at; }
            return strcmp($a['label'], $b['label']);
        });

        // Totals are counted over the WHOLE list, before any of it is cut for the
        // page. Counting the page instead is what made a shop with 297 numbers on
        // WhatsApp read "200" in the header: the list was trimmed to 200 and then
        // the tally described the trim, so the shortfall was invisible - it looked
        // like that was all there was. This count is the one number on the screen
        // the shopkeeper uses to judge whether the sync worked.
        $leads = 0;
        $chatted = 0;
        foreach ($out as $t) {
            if ($t['is_lead']) { $leads++; }
            if ($t['has_messages']) { $chatted++; }
        }
        $totalAll = count($out);

        // Paged rather than trimmed. The old behaviour kept the newest $limit and
        // discarded the rest for good, so a shop with more contacts than the cap
        // could never reach its own customers at all. The address book can now be
        // walked a page at a time.
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $page   = ($offset > 0) ? array_slice($out, $offset, $limit) : $out;
        if (count($page) > $limit) {
            $page = array_slice($page, 0, $limit);
        }
        $hasMore = ($offset + count($page)) < $totalAll;

        echo json_encode([
            'ok'        => true,
            'threads'   => $page,
            'count'     => count($page),
            'offset'    => $offset,
            'limit'     => $limit,
            'total'     => $totalAll,
            'has_more'  => $hasMore,
            'leads'     => $leads,
            'customers' => $totalAll - $leads,
            'chatted'   => $chatted,
            'silent'    => $totalAll - $chatted,
            'scope'     => $scope,
        ]);
        break;
    }

    /** One conversation, oldest first, optionally only what is new. */
    case 'messages': {
        $chat = chatId($_GET['chat_id'] ?? '');
        if ($chat === '') { fail('chat_id is required'); }
        $after = max(0, (int)($_GET['after'] ?? 0));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 80)));

        $st = $db->prepare("SELECT id, direction, body, has_media, is_revoked, created_at, read_at
                            FROM whatsapp_messages
                            WHERE owner_id = ? AND chat_id = ? AND id > ?
                            ORDER BY id ASC LIMIT $limit");
        $st->execute([$ownerId, $chat, $after]);
        $rows = $st->fetchAll();

        echo json_encode(['ok' => true, 'chat_id' => $chat, 'messages' => $rows, 'count' => count($rows)]);
        break;
    }

    case 'send': {
        $chat = chatId($_POST['chat_id'] ?? '');
        $body = trim((string)($_POST['body'] ?? ''));
        if ($chat === '') { fail('chat_id is required'); }
        if ($body === '') { fail('Message khaali rakha jay na.'); }
        if (mb_strlen($body) > 4096) { fail('Message onek lamba (max 4096 characters).'); }

        $res = marketingSendWhatsApp($ownerId, $chat, $body);
        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => $res['error'] ?: 'Send failed.']);
            break;
        }

        // Recorded here as well as by the bridge's own capture of our own send.
        // The unique key means whichever arrives first wins and the other is
        // ignored - but recording it here means the message shows in the thread
        // at once instead of waiting for the next poll.
        $ins = $db->prepare("INSERT IGNORE INTO whatsapp_messages
            (owner_id, chat_id, direction, body, has_media, wa_message_id, read_at, created_at)
            VALUES (?, ?, 'out', ?, 0, ?, NOW(), FROM_UNIXTIME(?))");
        $ins->execute([
            $ownerId, $chat, $body,
            // marketingSendWhatsApp() flattens the bridge's reply to message_id.
            $res['message_id'] ?: null,
            time(),
        ]);

        echo json_encode(['ok' => true, 'chat_id' => $chat]);
        break;
    }

    /**
     * Mark a chat read.
     *
     * Two separate things happen here. The local unread flag is cleared so the
     * list stops showing a count, and WhatsApp is told, so the customer's phone
     * stops showing unread ticks. Only inbound messages are ever unread -
     * outgoing rows were written with read_at already set.
     *
     * The ids for the read receipts are collected here rather than asked of the
     * page. The page only knows row ids, WhatsApp only accepts its own message
     * ids, and the table already holds the mapping - so the server is the only
     * place that can do this translation.
     */
    case 'mark_read': {
        $chat = chatId($_POST['chat_id'] ?? '');
        if ($chat === '') { fail('chat_id is required'); }

        $find = $db->prepare("SELECT wa_message_id FROM whatsapp_messages
                              WHERE owner_id = ? AND chat_id = ? AND direction = 'in'
                                AND read_at IS NULL AND wa_message_id IS NOT NULL
                              LIMIT 50");
        $find->execute([$ownerId, $chat]);
        $ids = array_values(array_filter(array_column($find->fetchAll(), 'wa_message_id')));

        $db->prepare("UPDATE whatsapp_messages
                      SET read_at = NOW()
                      WHERE owner_id = ? AND chat_id = ? AND direction = 'in' AND read_at IS NULL")
           ->execute([$ownerId, $chat]);

        if ($ids) {
            // Best effort. A failed receipt is cosmetic - the message is already
            // marked read here - so it must not fail the whole call.
            marketingBridgeCall($ownerId, 'POST', '/api/read', [
                'to'  => $chat,
                'ids' => $ids,
            ], 15);
        }

        echo json_encode(['ok' => true, 'receipts' => count($ids)]);
        break;
    }

    /** Save an unknown number as a customer, then the thread belongs to them. */
    case 'save_lead': {
        $chat = chatId($_POST['chat_id'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        if ($chat === '') { fail('chat_id is required'); }
        if ($name === '') { fail('Customer name is required'); }

        $local = marketingFormatPhone($chat);
        // Store it the way the rest of the shop stores phone numbers.
        $digits = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? $local));
        if (strlen($digits) === 13 && strpos($digits, '880') === 0) {
            $digits = '0' . substr($digits, 3);
        }

        $ins = $db->prepare("INSERT INTO customers (name, phone, owner_id) VALUES (?, ?, ?)");
        $ins->execute([$name, $digits, $ownerId]);
        $id = (int)$db->lastInsertId();

        // The number is known to be on WhatsApp - it just wrote to us - so
        // record that rather than leaving it as an unchecked NULL.
        $db->prepare("UPDATE customers SET has_whatsapp = 1, whatsapp_checked_at = NOW() WHERE id = ?")
           ->execute([$id]);

        echo json_encode(['ok' => true, 'customer_id' => $id, 'name' => $name, 'phone' => $digits]);
        break;
    }

    /**
     * Ask WhatsApp for a chat's recent history.
     *
     * The bridge buffer only sees traffic arriving while it is watching, so
     * without this a conversation that happened yesterday opens empty. The
     * messages arrive asynchronously on WhatsApp's side, so the bridge waits
     * for them rather than returning immediately.
     */
    case 'backfill': {
        $chat = chatId($_POST['chat_id'] ?? '');
        if ($chat === '') { fail('chat_id is required'); }

        $res = marketingBridgeCall($ownerId, 'GET', '/api/history?to=' . $chat . '&limit=60', [], 40);
        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => $res['error'] ?: 'History fetch failed.']);
            break;
        }

        $items = $res['data']['items'] ?? [];
        $ins = $db->prepare("INSERT IGNORE INTO whatsapp_messages
            (owner_id, chat_id, direction, body, has_media, wa_message_id, read_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), FROM_UNIXTIME(?))");
        $stored = 0;
        foreach ($items as $m) {
            $c = chatId($m['chat_id'] ?? '');
            if ($c === '') { continue; }
            $ins->execute([
                $ownerId, $c,
                ($m['direction'] ?? 'in') === 'out' ? 'out' : 'in',
                (string)($m['body'] ?? ''),
                !empty($m['has_media']) ? 1 : 0,
                $m['wa_message_id'] ?? null,
                (int)($m['timestamp'] ?? time()),
            ]);
            $stored += $ins->rowCount();
        }

        echo json_encode(['ok' => true, 'stored' => $stored, 'fetched' => count($items)]);
        break;
    }

    /**
     * QR codes that add a number to the shop's own phone contacts.
     *
     * WhatsApp has no public API for writing contacts, so this cannot add
     * anything by itself and does not pretend to. The only routes that actually
     * work both go through the phone's own scanner: a vCard QR for one number at
     * a time, or a .vcf file for the whole list at once. This action builds the
     * vCard text and asks the bridge to render it.
     *
     * The number goes out in international form (+880...) because that is what
     * the phone's contact book expects, and a local 01... is ambiguous enough
     * that some importers drop the leading zero and store a wrong number.
     */
    case 'contact_qr': {
        $status = marketingGetBridgeStatus($ownerId);
        if (empty($status['connected'])) {
            echo json_encode([
                'ok' => false,
                'error' => 'QR banate hole WhatsApp bridge connected thakte hobe. '
                         . 'Marketing page e QR scan kore link kore nin.',
            ]);
            break;
        }

        $ids = $_POST['chat_ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            fail('chat_ids is required');
        }

        $cs = $db->prepare("SELECT id, name, phone FROM customers WHERE owner_id = ?");
        $cs->execute([$ownerId]);
        $names = [];
        foreach ($cs->fetchAll() as $c) {
            $key = marketingNormalizePhone($c['phone'] ?? '');
            if ($key !== '' && !isset($names[$key])) { $names[$key] = $c['name']; }
        }

        $items = [];
        $cards = [];
        foreach (array_slice($ids, 0, 60) as $raw) {
            $chat = chatId($raw);
            if ($chat === '') { continue; }
            $name = $names[$chat] ?? ('+' . $chat);

            // vCard 3.0 is what Android's and iOS's scanners both understand.
            // Newlines must be CRLF per the spec, and commas/semicolons in a
            // name have to be escaped or the card will not parse at all.
            $vcard = "BEGIN:VCARD\r\n"
                   . "VERSION:3.0\r\n"
                   . 'N:' . vcardEscape($name) . "\r\n"
                   . 'FN:' . vcardEscape($name) . "\r\n"
                   . "TEL;type=CELL;type=VOICE:+" . $chat . "\r\n"
                   . "END:VCARD";

            $items[] = ['key' => $chat, 'text' => $vcard];
            $cards[] = [
                'chat_id'   => $chat,
                'phone'     => marketingFormatPhone($chat),
                'name'      => $name,
                'vcard'     => $vcard,
                // Opens the chat in WhatsApp. A second way in, for when the
                // camera will not focus on a screen.
                'wa_link'   => 'https://wa.me/' . $chat,
            ];
        }

        if (!$items) {
            fail('Kono number paoa jay ni');
        }

        $res = marketingBridgeCall($ownerId, 'POST', '/api/qr', ['items' => $items], 40);
        if (!$res['ok']) {
            echo json_encode(['ok' => false, 'error' => $res['error'] ?: 'QR banano jay ni.']);
            break;
        }

        $codes = $res['data']['codes'] ?? [];
        foreach ($cards as &$c) {
            $c['qr'] = $codes[$c['chat_id']] ?? null;
        }
        unset($c);

        echo json_encode(['ok' => true, 'cards' => $cards, 'count' => count($cards)]);
        break;
    }

    /**
     * Every contact as a .vcf file, for importing the whole list in one go.
     *
     * This is the only practical route for a whole customer list. A QR holds
     * one contact, so a shop with fifty customers would be scanning fifty codes
     * by hand; the phone's own contact importer takes the lot in one step.
     */
    case 'contact_vcf': {
        $st = $db->prepare("SELECT name, phone FROM customers
                            WHERE owner_id = ? AND TRIM(COALESCE(phone, '')) <> ''
                            ORDER BY name");
        $st->execute([$ownerId]);

        $out = '';
        $count = 0;
        foreach ($st->fetchAll() as $c) {
            $chat = marketingNormalizePhone($c['phone']);
            if ($chat === '') { continue; }
            $name = trim((string)$c['name']);
            if ($name === '') { $name = '+' . $chat; }
            $out .= "BEGIN:VCARD\r\n"
                  . "VERSION:3.0\r\n"
                  . 'N:' . vcardEscape($name) . "\r\n"
                  . 'FN:' . vcardEscape($name) . "\r\n"
                  . "TEL;type=CELL;type=VOICE:+" . $chat . "\r\n"
                  . "END:VCARD\r\n";
            $count++;
        }

        if ($count === 0) {
            fail('Ektu number nai');
        }

        // Sent as a download rather than JSON, so the phone can save it directly.
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="shop-contacts.vcf"');
        header('Content-Length: ' . strlen($out));
        echo $out;
        exit;
    }

    default:
        fail('Unknown action: ' . htmlspecialchars($action), 400);
}
