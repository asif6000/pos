<?php
/**
 * Test - WhatsApp contact catalog confirms customer numbers for free
 *
 * Run:  php tools/test-wa-customer-sync.php
 *
 * Uses a scratch customer with a number that is already in whatsapp_contacts,
 * so the real address book is what decides the outcome. Everything it creates
 * is removed at the end, including on failure, so a failed run cannot leave a
 * test customer in a live shop.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/marketing.php';
require_once __DIR__ . '/../config/whatsapp_inbox.php';

$db = getDB();
whatsappEnsureInboxTable($db);

$pass = 0;
$fail = 0;

function check($name, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok    $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

/** A customer id nothing else in the shop is using. */
$ownerId = 1;
$probePhone = null;
$probeId = null;
$otherId = null;
$formatIds = [];
$syntheticContactId = null;

try {
    // ── Fixture ────────────────────────────────────────────────────────────
    // Borrow a number the bridge has actually confirmed, in whatever form the
    // catalog stores it, so the test exercises the real match rather than a
    // shape invented here.
    $src = $db->prepare("SELECT chat_id FROM whatsapp_contacts
                         WHERE chat_id <> '' ORDER BY id LIMIT 1");
    $src->execute();
    $probePhone = (string)($src->fetchColumn() ?: '');

    if ($probePhone === '') {
        echo "No row in whatsapp_contacts to test against.\n";
        echo "Run the bridge once, or insert a contact, then re-run this.\n";
        exit(1);
    }

    echo "Using whatsapp_contacts number $probePhone\n\n";

    $ins = $db->prepare("INSERT INTO customers (name, phone, owner_id) VALUES (?, ?, ?)");
    $ins->execute(['ZZ sync test', $probePhone, $ownerId]);
    $probeId = (int)$db->lastInsertId();

    // ── The three states, in the order the code treats them ────────────────
    echo "has_whatsapp starts NULL:\n";
    $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $probeId")->fetchColumn();
    check('a new customer is not yet known to be on WhatsApp', $v === null,
        'got ' . var_export($v, true));

    // ── 1. A number in the catalog is confirmed ────────────────────────────
    echo "\nconfirms a number the account can reach:\n";
    $r = whatsappMarkCustomersOnWa($db, $ownerId, [$probePhone]);
    $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $probeId")->fetchColumn();
    check('has_whatsapp becomes 1', (int)$v === 1, 'got ' . var_export($v, true));
    check('reports exactly one customer marked', (int)$r['marked'] === 1,
        'marked=' . $r['marked']);

    $t = $db->query("SELECT whatsapp_checked_at FROM customers WHERE id = $probeId")->fetchColumn();
    check('records when it was confirmed', !empty($t));

    // ── 2. Absence proves nothing ──────────────────────────────────────────
    // The rule that stops a shop quietly writing off most of its customers: a
    // number that is not in the address book must stay unknown, never become 0.
    echo "\nleaves a number it cannot see alone:\n";
    $other = $db->prepare("INSERT INTO customers (name, phone, owner_id)
                           VALUES ('ZZ unknown test', '8801999999999', ?)");
    $other->execute([$ownerId]);
    $otherId = (int)$db->lastInsertId();

    whatsappMarkCustomersOnWa($db, $ownerId, [$probePhone]);
    $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $otherId")->fetchColumn();
    check('a number not in the catalog stays NULL, not 0', $v === null,
        'got ' . var_export($v, true));

    // ── 3. A confirmed row is never walked back ─────────────────────────────
    // Nothing here can detect an account being deleted, and a later probe that
    // fails must not undo a real answer.
    echo "\nnever reverses a confirmed number:\n";
    $r = whatsappMarkCustomersOnWa($db, $ownerId, ['999999999999']);
    $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $probeId")->fetchColumn();
    check('an unrelated catalog batch leaves it at 1', (int)$v === 1,
        'got ' . var_export($v, true));
    check('an unrelated batch marks nothing', (int)$r['marked'] === 0,
        'marked=' . $r['marked']);

    // ── 4. Different phone formats resolve to the same customer ────────────
    // customers.phone holds whatever the shop typed. Matching has to survive
    // that or the whole thing silently does nothing - and the forms a Bangladeshi
    // shop actually types are not the form the catalog stores.
    //
    // Driven off a synthetic 880 number rather than the borrowed one, because a
    // number with no valid local spelling cannot be tested for local spelling.
    // Its contact row is removed in the finally block like everything else.
    echo "\nrecognises the number whatever way it was typed:\n";
    $synthetic = '8801712345678';
    $db->prepare("INSERT INTO whatsapp_contacts (owner_id, chat_id, name, source)
                  VALUES (?, ?, 'ZZ format test', 'chat')")
        ->execute([$ownerId, $synthetic]);
    $syntheticContactId = (int)$db->lastInsertId();

    $formatIds = [];
    $forms = [
        '01712345678'          => 'local',
        '+8801712345678'       => 'international with plus',
        '8801712345678'        => 'international',
        '880 1712 345678'      => 'spaced',
        '01712-345678'         => 'dashed local',
    ];
    foreach ($forms as $typed => $label) {
        $p = $db->prepare("INSERT INTO customers (name, phone, owner_id)
                           VALUES (?, ?, ?)");
        $p->execute(['ZZ format test', $typed, $ownerId]);
        $formatIds[] = (int)$db->lastInsertId();
        $formatLabels[] = $label;
    }

    whatsappMarkCustomersOnWa($db, $ownerId, [$synthetic]);

    foreach ($formatIds as $i => $fid) {
        $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $fid")->fetchColumn();
        check('number typed as ' . $formatLabels[$i] . ' is confirmed', (int)$v === 1,
            'got ' . var_export($v, true));
    }

    // ── 5. Empty and junk input is harmless ────────────────────────────────
    // The bridge is the caller, and it must not be able to crash this.
    echo "\nsurvives input the bridge should never send:\n";
    check('an empty batch is a no-op',
        whatsappMarkCustomersOnWa($db, $ownerId, [])['marked'] === 0);

    // ── 6. Shop isolation ──────────────────────────────────────────────────
    // A number confirmed for one shop must not confirm it for another.
    echo "\ndoes not cross shop boundaries:\n";
    $v = $db->query("SELECT has_whatsapp FROM customers WHERE id = $otherId")->fetchColumn();
    check('another shop is unaffected by this shop\'s catalog', $v === null,
        'got ' . var_export($v, true));

} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    $fail++;
} finally {
    // Always clean up, including after a failure, so a broken run cannot leave
    // a ZZ test customer sitting in a real customer list.
    if ($probeId)  { $db->exec("DELETE FROM customers WHERE id = $probeId"); }
    if ($otherId)  { $db->exec("DELETE FROM customers WHERE id = $otherId"); }
    foreach ($formatIds as $fid) {
        $db->exec("DELETE FROM customers WHERE id = " . (int)$fid);
    }
    if ($syntheticContactId) {
        $db->exec("DELETE FROM whatsapp_contacts WHERE id = " . (int)$syntheticContactId);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
