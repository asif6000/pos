<?php
/**
 * Run the invoice path against this database and print what actually fails.
 *
 * "Invoice ta banano jay ni: database error." says nothing, because by the time
 * it reaches the screen the reason has been reduced to a short line - and the
 * shop cannot act on a short line it cannot verify. This walks exactly the same
 * sequence as admin/api/invoice-send.php against the real database and prints the
 * exception class and message in full, so the answer comes from the server rather
 * than from a guess about which file is stale.
 *
 * Read-only. It builds the receipt markup and the WhatsApp job shape but writes
 * nothing and sends nothing.
 *
 *   php tools/diagnose-invoice.php            the newest sale
 *   php tools/diagnose-invoice.php 1042       a specific invoice
 */

// Command line only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This is a command line tool.');
}

$root = dirname(__DIR__);

// getDB() calls die() when the database is unreachable, and die() cannot be
// caught. Buffered so that message can be replaced with something that says what
// to do, rather than leaving a raw PDO error as the last thing on the screen.
ob_start();
register_shutdown_function(function () {
    if (!empty($GLOBALS['diag_done'])) {
        return;
    }
    $said = ob_get_contents();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo $said;
    echo "\n!! The script was stopped before it finished.\n";
    echo "   The message above is why. A database that will not connect from the\n";
    echo "   command line but works in the browser usually means the CLI is outside\n";
    echo "   the account, or the host in config/db.php differs for the shell.\n";
});

require_once $root . '/config/db.php';
require_once $root . '/config/marketing.php';
require_once $root . '/config/wa_agent.php';
// sms_gateway.php for marketingSendSms(). wa_agent.php does not pull it in, so
// without this line the function check below reports it missing and the SMS
// section cannot run - which is a false finding about the server.
require_once $root . '/config/sms_gateway.php';
require_once $root . '/config/invoice_token.php';
require_once $root . '/config/invoice_image.php';

function line($s = '') { echo $s . "\n"; }
function ok($s)      { echo "  ok    $s\n"; }
function bad($s)     { echo "  FAIL  $s\n"; }

/** One step, with the real error if it throws. */
function step($label, callable $fn) {
    try {
        $r = $fn();
        ok($label);
        return ['ok' => true, 'value' => $r];
    } catch (Throwable $e) {
        echo "  FAIL  $label\n";
        // The class matters as much as the message: an Error is a missing
        // function or a type mistake, which is never the database's fault, and
        // reporting it as one sends the shop looking in the wrong place.
        echo '        ' . get_class($e) . "\n";
        echo '        ' . $e->getMessage() . "\n";
        $f = $e->getFile();
        $l = $e->getLine();
        if ($f) {
            echo '        at ' . basename($f) . ':' . $l . "\n";
        }
        return ['ok' => false, 'error' => $e];
    }
}

line('=== invoice diagnosis ===');
line('php ' . PHP_VERSION);
line('');

// â”€â”€ 1. the files the endpoint requires â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('-- files --');
foreach ([
    'config/db.php', 'config/marketing.php', 'config/wa_agent.php',
    'config/invoice_token.php', 'config/invoice_image.php',
    'admin/api/invoice-send.php', 'admin/api/invoice-sms.php',
] as $f) {
    $full = $root . '/' . $f;
    if (!is_file($full)) {
        bad("MISSING  $f");
    } else {
        ok(sprintf('%-34s %d bytes, modified %s',
            $f, filesize($full), date('Y-m-d H:i', filemtime($full))));
    }
}
line('');
line('A file that is much older than the others is the usual cause of a');
line('"code-er kache function nai" failure: it is still on disk but is an old copy.');
line('');

// â”€â”€ 2. the functions the endpoint calls â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('-- functions --');
foreach ([
    'invoiceBuildHtml', 'invoiceEnsureToken', 'invoicePublicUrl', 'invoiceSmsText',
    'marketingNormalizePhone', 'waAgentEnqueue', 'waAgentModeEnabled',
    'marketingAgentStatus', 'getMarketingSettings', 'marketingSendSms',
] as $fn) {
    if (function_exists($fn)) { ok($fn . '()'); } else { bad("MISSING  $fn()"); }
}
line('');

// â”€â”€ 3. the database â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('-- database --');
$dbRes = step('connect', function () { return getDB(); });
if (!$dbRes['ok']) {
    line('');
    line('The database is not reachable from this shell, so nothing below can run.');
    line('On cPanel that usually means the CLI is outside the account, or the');
    line('credentials in config/db.php differ from what the web host uses.');
    $GLOBALS['diag_done'] = true;
    $GLOBALS['diag_done'] = true;
    exit(1);
}
$db = $dbRes['value'];

// â”€â”€ 4. the columns the receipt reads â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('');
line('-- columns --');
$cols = [
    'sales'      => ['id', 'invoice_number', 'customer_id', 'user_id', 'subtotal',
                     'discount_percent', 'discount_amount', 'vat_percent', 'vat_amount',
                     'total', 'paid_amount', 'change_amount', 'payment_method',
                     'payment_status', 'owner_id', 'created_at', 'invoice_token'],
    'sale_items' => ['id', 'sale_id', 'product_name', 'quantity', 'unit_price', 'total_price'],
    'customers'  => ['id', 'name', 'phone', 'owner_id', 'has_whatsapp'],
    'users'      => ['id', 'name', 'owner_id'],
    'settings'   => ['setting_key', 'setting_value', 'owner_id'],
    'wa_jobs'    => ['id', 'owner_id', 'kind', 'to', 'payload', 'payload_html',
                     'status', 'result', 'request_key', 'claim_token'],
    'wa_agents'  => ['owner_id', 'status', 'qr', 'events', 'last_seen', 'command'],
];
foreach ($cols as $table => $want) {
    $have = [];
    try {
        foreach ($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $have[strtolower($c['Field'])] = true;
        }
    } catch (Throwable $e) {
        bad("table missing: $table");
        continue;
    }
    $missing = array_values(array_filter($want, fn($c) => !isset($have[strtolower($c)])));
    if ($missing) {
        bad("$table is missing: " . implode(', ', $missing));
    } else {
        ok($table);
    }
}

// â”€â”€ 5. a real sale, through the real function â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('');
line('-- a real sale --');
$wantId = (int)($argv[1] ?? 0);
if ($wantId > 0) {
    $st = $db->prepare("SELECT id, owner_id FROM sales WHERE id = ?");
    $st->execute([$wantId]);
    $sale = $st->fetch();
} else {
    $sale = $db->query("SELECT id, owner_id FROM sales ORDER BY id DESC LIMIT 1")->fetch();
}

if (!$sale) {
    bad('no sales in this database, so the receipt cannot be built. Make a sale first.');
    $GLOBALS['diag_done'] = true;
    exit(1);
}
line('  using sale #' . $sale['id']);

$built = step('invoiceBuildHtml()', function () use ($db, $sale) {
    return invoiceBuildHtml($db, (int)$sale['id'], (int)$sale['owner_id'], '8801712345678');
});
if (!$built['ok']) {
    line('');
    line('That is the failure. The message above is the whole of it.');
    $GLOBALS['diag_done'] = true;
    exit(1);
}
$b = $built['value'];
line('        receipt markup: ' . strlen($b['html']) . ' bytes, invoice ' . ($b['number'] ?? '?'));

// â”€â”€ 6. the queue shape the WhatsApp send depends on â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('');
line('-- whatsapp send --');
step('waAgentEnqueue() dry shape', function () use ($db) {
    $st = $db->prepare("SELECT COUNT(*) FROM wa_jobs");
    $st->execute();
    return (int)$st->fetchColumn();
});
if (($dbRes['ok'] ?? false)) {
    // The one column added on demand, which is where a database user without
    // ALTER rights stops.
    try {
        $db->query("SELECT payload_html FROM wa_jobs LIMIT 1");
        ok('wa_jobs.payload_html exists - the queue can carry an invoice');
    } catch (Throwable $e) {
        bad('wa_jobs.payload_html is MISSING - run the ALTER below as a user that has it');
        line('        ALTER TABLE wa_jobs ADD COLUMN payload_html MEDIUMTEXT NULL AFTER payload;');
    }
    try {
        $db->query("SELECT invoice_token FROM sales LIMIT 1");
        ok('sales.invoice_token exists - an SMS link can be built');
    } catch (Throwable $e) {
        bad('sales.invoice_token is MISSING - run the ALTER below as a user that has it');
        line('        ALTER TABLE sales ADD COLUMN invoice_token VARCHAR(40) NULL;');
        line('        ALTER TABLE sales ADD UNIQUE KEY uq_sales_invoice_token (invoice_token);');
    }
}

// â”€â”€ 7. what the SMS would look like â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
line('');
line('-- sms --');
$settings = getMarketingSettings((int)$sale['owner_id']);
$url = invoicePublicUrl(str_repeat('a1b2', 8));
$msg = invoiceSmsText(
    $settings['shop_name'] ?? 'Shop',
    'INV-TEST',
    1000,
    $url,
    '28 Sep 2026',
    ['label' => 'Follow & watch the live raffle draw', 'url' => $settings['coupon_raffle_url'] ?? '']
);
line('  segments: ' . $msg['segments'] . '   chars: ' . strlen($msg['text']));
line('  ---');
line('  ' . str_replace("\n", "\n  ", $msg['text']));
line('  ---');
line('');
line('  invoice_sms_promo setting: '
    . var_export($settings['invoice_sms_promo'] ?? '(not set - promo off)', true));
line('  coupon_raffle_url setting: '
    . var_export($settings['coupon_raffle_url'] ?? '(not set)', true));

line('');
line('=== done ===');
$GLOBALS['diag_done'] = true;

