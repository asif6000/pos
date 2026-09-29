<?php
/**
 * One-off: put every stored customer number into the 8801... form.
 *
 * Numbers were saved exactly as typed, so the same person can be in the table
 * twice - 01712345678 from the POS customer form and 8801712345678 from the
 * Marketing page, which has always normalised. That matters because the WhatsApp
 * check, the campaign audience and the receipt link all key on the number: a
 * customer stored the short way is invisible to every one of them, and a search
 * misses them.
 *
 * Dry run by default. Read the report, then pass --apply to write. There is no
 * undo beyond a backup, which is why the dry run lists exactly what would change
 * rather than just counting it.
 *
 *   php tools/normalize-customer-phones.php
 *   php tools/normalize-customer-phones.php --apply
 */

// Command line only, and refused before anything is loaded.
//
// This script writes to every customer row in the shop. The .htaccess deny list
// does not cover the tools folder, so without this guard the file is a URL: any
// visitor who guessed /tools/normalize-customer-phones.php?1=--apply would
// rewrite the customer table. The check lives here rather than only in the server
// config because it travels with the script and costs nothing to keep.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This is a command line tool.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/marketing.php';

$apply = in_array('--apply', $argv, true);

$db = getDB();

$stmt = $db->query("SELECT id, owner_id, name, phone
                    FROM customers
                    WHERE phone IS NOT NULL AND TRIM(phone) <> ''
                    ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$changes = [];
$skipped = [];

foreach ($rows as $r) {
    $from = (string)$r['phone'];
    $to   = marketingNormalizePhone($from);

    if ($to === '') {
        $skipped[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'from' => $from, 'why' => 'no digits'];
        continue;
    }
    if ($to === $from) {
        continue;                       // already in the right shape
    }
    // Only a Bangladeshi mobile is rewritten. A foreign number, or anything of
    // an unexpected length, is left exactly as it is - guessing at an
    // international number is how a working contact gets corrupted.
    $isBd = (strlen($to) === 13 && strpos($to, '880') === 0);
    if (!$isBd) {
        $skipped[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'from' => $from, 'why' => 'not a BD number'];
        continue;
    }

    $changes[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'from' => $from, 'to' => $to];
}

echo "customers with a number: " . count($rows) . "\n";
echo "would change:           " . count($changes) . "\n";
echo "left alone:             " . count($skipped) . "\n\n";

if ($skipped) {
    echo "Left alone (check these by eye):\n";
    foreach ($skipped as $s) {
        echo "  #{$s['id']}  {$s['from']}  ({$s['why']})  {$s['name']}\n";
    }
    echo "\n";
}

if (!$changes) {
    echo "Nothing to do.\n";
    exit(0);
}

if (!$apply) {
    echo "First 30 of " . count($changes) . " that would change:\n";
    foreach (array_slice($changes, 0, 30) as $c) {
        echo "  #{$c['id']}  {$c['from']}  ->  {$c['to']}   {$c['name']}\n";
    }
    if (count($changes) > 30) {
        echo "  ... and " . (count($changes) - 30) . " more\n";
    }
    echo "\nDRY RUN - nothing written. Re-run with --apply to make these changes.\n";
    exit(0);
}

// A number that is already in the target form belongs to somebody else if this
// shop ends up with a duplicate, so the duplicate is reported rather than
// silently merged or silently kept. Deciding what to do about it is the shop's
// call, not this script's.
$up = $db->prepare("UPDATE customers SET phone = ?, has_whatsapp = NULL, whatsapp_checked_at = NULL
                    WHERE id = ? AND owner_id = ?");
$done = 0;
$failed = [];

foreach ($changes as $c) {
    $ownerId = null;
    foreach ($rows as $r) {
        if ((int)$r['id'] === $c['id']) {
            $ownerId = $r['owner_id'];
            break;
        }
    }
    try {
        $up->execute([$c['to'], $c['id'], $ownerId]);
        $done++;
    } catch (Throwable $e) {
        $failed[] = '#' . $c['id'] . ': ' . $e->getMessage();
    }
}

echo "updated: $done\n";

if ($failed) {
    echo "failed:  " . count($failed) . "\n";
    foreach (array_slice($failed, 0, 20) as $f) {
        echo "  $f\n";
    }
}

// Now report duplicates, which is the one thing that can have gone wrong and is
// worth knowing about before the shop sends anything.
echo "\nDuplicate numbers after the change:\n";
$dupes = $db->query("SELECT phone, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
                     FROM customers
                     WHERE phone IS NOT NULL AND TRIM(phone) <> ''
                     GROUP BY phone
                     HAVING n > 1
                     ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
if (!$dupes) {
    echo "  none\n";
} else {
    foreach ($dupes as $d) {
        echo "  {$d['phone']}  x{$d['n']}  ids: {$d['ids']}\n";
    }
    echo "\n  These are the same person recorded twice under different forms.\n";
    echo "  The WhatsApp badge on each was cleared, so run Marketing -> Check numbers\n";
    echo "  once the shop decides which row to keep.\n";
}

echo "\nDone. Run Marketing -> Check numbers to fill in the WhatsApp badges.\n";
