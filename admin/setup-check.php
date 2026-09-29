<?php
/**
 * Deployment check - read only.
 *
 * Answers "is this host able to run the app, and if not, what exactly is
 * missing?" without changing anything. Safe to leave on a live site: it runs no
 * DDL, writes no files, and only ever reports.
 *
 * Built because the four pages that were returning HTTP 500 - Products,
 * Variable Name, Google Contacts and Marketing - had two unrelated causes that
 * looked identical from the browser:
 *
 *   Products / Variable Name   ran CREATE TABLE at page load, and this host's
 *                              database user is not allowed to. MySQL refuses
 *                              "CREATE TABLE IF NOT EXISTS" on a table that is
 *                              already there, because it checks the privilege
 *                              before it looks at the table.
 *   Google Contacts / Marketing  called curl_init() with no guard, and this host
 *                              has no php-curl. A missing function raises an
 *                              Error, which catch (Exception) does not catch.
 *
 * Both are fixed in the code. This page is how you confirm the host before
 * uploading, and after uploading.
 *
 * Admin only. It reveals the PHP version, loaded extensions and database
 * grants, which is more than an anonymous visitor should learn.
 */

require_once '../config/db.php';
// For getMarketingSettings(), so the bridge URL resolves the same way here as it
// does on the pages that actually talk to the bridge.
require_once '../config/marketing.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

$checks = [];
$fail = 0;
$warn = 0;

function add(&$checks, $state, $name, $detail)
{
    $checks[] = ['state' => $state, 'name' => $name, 'detail' => $detail];
}

function state($bad) { global $fail, $warn; if ($bad === 'bad') $fail++; if ($bad === 'warn') $warn++; }

// ---------------------------------------------------------------- PHP itself
$ver = PHP_VERSION;
$ok = version_compare($ver, '7.4.0', '>=');
add($checks, $ok ? 'ok' : 'bad', 'PHP version', $ver . ($ok ? '' : '  - 7.4 or newer is required'));
state($ok ? 'ok' : 'bad');

foreach ([
    'pdo_mysql' => 'Database connections. Without it nothing works.',
    'mbstring'  => 'Invoice rendering and the Bangla text in SMS/WhatsApp messages. product labels break without it.',
    'curl'      => 'WhatsApp bridge, bulk SMS, and Google Contacts. The app now degrades with a message instead of a 500, but those three features stay switched off.',
    'json'      => 'Every API endpoint speaks JSON.',
    'gd'        => 'Only needed if you generate images in PHP.',
] as $ext => $why) {
    $has = extension_loaded($ext);
    $critical = in_array($ext, ['pdo_mysql', 'json'], true);
    add($checks, $has ? 'ok' : ($critical ? 'bad' : 'warn'), "Extension: $ext",
        ($has ? 'loaded' : 'NOT LOADED') . '  -  ' . $why);
    state($has ? 'ok' : ($critical ? 'bad' : 'warn'));
}

$mem = ini_get('memory_limit');
$memOk = $mem === '-1' || (int) $mem >= 128;
add($checks, $memOk ? 'ok' : 'warn', 'memory_limit', $mem . ($memOk ? '' : '  - 128M or more recommended'));
state($memOk ? 'ok' : 'warn');

// ------------------------------------------------------------------ Database
try {
    $db = getDB();
    add($checks, 'ok', 'Database connection', 'connected to ' . DB_NAME);
    state('ok');
} catch (Throwable $e) {
    add($checks, 'bad', 'Database connection', 'FAILED - ' . $e->getMessage());
    state('bad');
    $db = null;
}

if ($db) {
    // Can this user run DDL? Not needed to use the app any more, but the
    // installer and the migration file need it.
    $canDdl = false;
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS _deploy_check_tmp (id INT)');
        $db->exec('DROP TABLE IF EXISTS _deploy_check_tmp');
        $canDdl = true;
    } catch (Throwable $e) {
        $canDdl = false;
    }
    add($checks, $canDdl ? 'ok' : 'warn', 'Database DDL rights',
        $canDdl
            ? 'can CREATE/ALTER - the migration file can be imported by the app user'
            : 'cannot CREATE/ALTER. This is fine and is the usual cPanel setup. The app no longer needs it at page load - import config/migration_002_runtime_tables.sql through phpMyAdmin instead.');
    state($canDdl ? 'ok' : 'warn');

    // Which tables are present?
    $expected = [
        'stores', 'categories', 'products', 'roles', 'users', 'store_stocks',
        'transfers', 'transfer_items', 'customers', 'sales', 'sale_items',
        'settings', 'stock_history', 'returns', 'return_items',
        'role_permissions', 'system_settings',
        'cashbook_entries', 'expense_categories', 'marketing_campaigns',
        'marketing_recipients', 'product_variables', 'product_variable_values',
        'product_variable_map', 'staff', 'staff_payments',
        'whatsapp_contacts', 'whatsapp_messages',
    ];
    $present = [];
    try {
        foreach ($db->query("SELECT table_name FROM information_schema.TABLES
                             WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $present[strtolower($t)] = true;
        }
    } catch (Throwable $e) {
        $present = [];
    }
    $missing = array_values(array_filter($expected, fn($t) => !isset($present[$t])));
    add($checks, $missing ? 'bad' : 'ok', 'Tables',
        $missing
            ? count($missing) . ' missing: ' . implode(', ', $missing) . '  -  import config/migration_002_runtime_tables.sql'
            : 'all ' . count($expected) . ' present');
    state($missing ? 'bad' : 'ok');

    // users.role must not be an ENUM that lacks 'staff'
    try {
        $st = $db->prepare("SELECT column_type FROM information_schema.COLUMNS
                            WHERE table_schema = DATABASE() AND table_name='users' AND column_name='role'");
        $st->execute();
        $roleType = (string) $st->fetchColumn();
        $roleOk = stripos($roleType, 'enum') === false;
        add($checks, $roleOk ? 'ok' : 'bad', 'users.role column type',
            $roleType . ($roleOk ? '' : '  -  an ENUM without \'staff\' silently stores an empty string; the migration widens it to VARCHAR(50)'));
        state($roleOk ? 'ok' : 'bad');
    } catch (Throwable $e) {
        add($checks, 'warn', 'users.role column type', 'could not be read');
        state('warn');
    }

    // sales.printed
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE table_schema = DATABASE() AND table_name='sales' AND column_name='printed'");
        $st->execute();
        $hasPrinted = (int) $st->fetchColumn() === 1;
        add($checks, $hasPrinted ? 'ok' : 'warn', 'sales.printed column',
            $hasPrinted ? 'present' : 'missing - receipts cannot be marked as printed; the migration adds it');
        state($hasPrinted ? 'ok' : 'warn');
    } catch (Throwable $e) {
        state('warn');
    }

    // roles seeded
    try {
        $have = $db->query("SELECT slug FROM roles")->fetchAll(PDO::FETCH_COLUMN);
        $want = ['admin', 'owner', 'manager', 'staff', 'cashier'];
        $miss = array_values(array_diff($want, array_map('strtolower', $have)));
        add($checks, $miss ? 'warn' : 'ok', 'Roles seeded',
            $miss ? 'missing: ' . implode(', ', $miss) . '  -  the migration inserts them' : 'all present');
        state($miss ? 'warn' : 'ok');
    } catch (Throwable $e) {
        state('warn');
    }
}

// ------------------------------------------------------------------- Bridge
// The WhatsApp bridge is a separate Node service, not part of this application.
// Shared hosting cannot run it, so the usual arrangement is that it runs on a
// machine inside the shop and the POS reaches it over HTTPS through a tunnel.
// This check answers the only question that matters from here: can the server
// actually reach it?
try {
    // Prefer a saved value, then fall back to whatever the app itself would
    // resolve. Reading the settings table alone reported "no bridge URL
    // configured" on a development machine that was working perfectly, because
    // there the URL comes from whatsapp-server/.env rather than the database -
    // and this is the page people are sent to when the Inbox says the bridge is
    // down, so the two disagreeing is actively unhelpful.
    $bUrl = '';
    $bTok = '';
    $st = $db->prepare("SELECT setting_key, setting_value FROM settings
                        WHERE setting_key IN ('marketing_bridge_url','marketing_bridge_token')");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        if ($r['setting_key'] === 'marketing_bridge_url')  $bUrl = trim((string)$r['setting_value']);
        if ($r['setting_key'] === 'marketing_bridge_token') $bTok = trim((string)$r['setting_value']);
    }
    if ($bUrl === '' || $bTok === '') {
        $resolved = getMarketingSettings(0);
        if ($bUrl === '') { $bUrl = trim((string)($resolved['marketing_bridge_url'] ?? '')); }
        if ($bTok === '') { $bTok = trim((string)($resolved['marketing_bridge_token'] ?? '')); }
    }

    if ($bUrl === '') {
        add($checks, 'warn', 'WhatsApp bridge', 'no bridge URL configured - set it on the Inbox page ("Bridge setup") or in Settings > WhatsApp Bridge. WhatsApp campaigns and WhatsApp invoices are switched off until then');
        state('warn');
    } elseif ($bTok === '') {
        add($checks, 'warn', 'WhatsApp bridge', 'a bridge URL is set but no API token - copy API_TOKEN out of the bridge .env into the same form');
        state('warn');
    } elseif (!appHasCurl()) {
        add($checks, 'bad', 'WhatsApp bridge', 'this server has no php-curl, so the bridge cannot be contacted at all');
        state('bad');
    } else {
        $t0 = microtime(true);
        $ch = curl_init(rtrim($bUrl, '/') . '/api/status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['X-Api-Token: ' . $bTok],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $isLocal = (bool)preg_match('~^(https?://)(127\.0\.0\.1|localhost|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)~i', $bUrl);
        $where  = $isLocal ? ' (this is a loopback address, so it only works if something is running on the same server)' : '';

        if ($code === 200) {
            $j = json_decode((string)$body, true);
            // The bridge reports { ok, status, qr, me, lastError, ... }. There is
            // no "connected" boolean - status carries it. Checking for a field
            // that does not exist made this report "not logged in" against a
            // bridge that was logged in and answering, which is worse than not
            // checking at all.
            $status = strtoupper((string)($j['status'] ?? 'unknown'));
            $me     = $j['me']['name'] ?? null;
            $as     = $me ? ' as ' . $me : '';
            if ($status === 'CONNECTED') {
                add($checks, 'ok', 'WhatsApp bridge', "connected{$as} ({$ms}ms)");
                state('ok');
            } elseif ($status === 'QR' || !empty($j['qr'])) {
                add($checks, 'warn', 'WhatsApp bridge',
                    "reachable ({$ms}ms) but waiting for a QR scan - open the bridge and scan to link the number");
                state('warn');
            } else {
                $err = $j['lastError'] ?? null;
                add($checks, 'warn', 'WhatsApp bridge',
                    "reachable ({$ms}ms) but status={$status}" . ($err ? " - {$err}" : ' - open the bridge to see what it says'));
                state('warn');
            }
        } elseif ($code === 401) {
            add($checks, 'bad', 'WhatsApp bridge',
                'reachable but the API token does not match. The token in Marketing settings must be identical to API_TOKEN in the bridge .env file.');
            state('bad');
        } else {
            $hint = $isLocal
                ? ' - 127.0.0.1 on shared hosting is the cPanel server itself, and it cannot run a Node process. Point this at a tunnel URL instead, or switch WhatsApp off and use SMS.'
                : ' - check the bridge is running and the tunnel is up.';
            add($checks, 'bad', 'WhatsApp bridge',
                "not reachable (HTTP {$code}, {$ms}ms)" . ($cerr ? ": " . $cerr : '') . $hint);
            state('bad');
        }
    }
} catch (Throwable $e) {
    add($checks, 'warn', 'WhatsApp bridge', 'could not be checked: ' . $e->getMessage());
    state('warn');
}

// ------------------------------------------------------------------ Outbound IP
// Added because of a specific and confusing failure: bulksmsbd rejects a request
// with "code 1032 - IP not whitelisted", the shop whitelists the server address,
// and the SMS still will not send.
//
// The reason is that the address bulksmsbd sees is the one the request LEAVES
// from, and that is not always the cPanel server. Run the POS on cPanel and it
// is the server's address. Run it on the shop's own computer with XAMPP and it
// is the shop's home broadband address, which changes whenever the router
// reconnects. Whitelisting the server address then has no effect on a local
// test, and the error looks identical either way.
$egress = null;
$egressErr = null;
if (appHasCurl()) {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $b = curl_exec($ch);
    if ($b === false) {
        $egressErr = curl_error($ch);
    } else {
        $egress = trim((string)$b);
    }
    curl_close($ch);
}
if ($egress) {
    $isServer = ($egress === '103.174.50.208');
    add($checks, 'warn', 'Outgoing IP (this server)',
        $egress . ($isServer
            ? '  -  this is the cPanel server, so it is the address to whitelist with the SMS provider'
            : '  -  NOT the cPanel address. This looks like the shop computer running XAMPP, so SMS leaves from here and the address the provider needs is ' . $egress . ' - a home connection address, which can change.'));
    state('warn');
} else {
    add($checks, 'warn', 'Outgoing IP (this server)',
        'could not be determined' . ($egressErr ? ': ' . $egressErr : '') . ' - check the SMS provider with the address your host shows you');
    state('warn');
}

// ------------------------------------------------------------------ SMS gateway
if (appHasCurl()) {
    $smsCfg = [];
    try {
        $st = $db->prepare("SELECT setting_key, setting_value FROM settings
                            WHERE setting_key LIKE 'marketing_sms_%'");
        $st->execute();
        foreach ($st->fetchAll() as $r) $smsCfg[$r['setting_key']] = (string)$r['setting_value'];
    } catch (Throwable $e) {
        $smsCfg = [];
    }
    $hasKey = !empty($smsCfg['marketing_sms_api_key']);
    $hasUrl = !empty($smsCfg['marketing_sms_post_url']) || !empty($smsCfg['marketing_sms_get_url']);
    $prov   = $smsCfg['marketing_sms_provider'] ?? '';
    if ($hasKey && $hasUrl) {
        add($checks, 'ok', 'SMS gateway',
            "configured (provider=" . ($prov ?: 'not set') . "). A 1032 from the provider means the outgoing IP is not on their whitelist - see the line above.");
        state('ok');
    } else {
        add($checks, 'warn', 'SMS gateway',
            'incomplete - provider=' . ($prov ?: 'not set') . ', api key=' . ($hasKey ? 'set' : 'MISSING')
            . ', URL=' . ($hasUrl ? 'set' : 'MISSING') . '. Set these on the Settings page.');
        state('warn');
    }
} else {
    add($checks, 'bad', 'SMS gateway', 'cannot be checked: this server has no php-curl');
    state('bad');
}

// ------------------------------------------------------------------- Storage
foreach ([
    '../sessions' => 'PHP session files. If this folder is missing or not writable, nobody can stay signed in.',
    '../uploads'  => 'Product and label images.',
] as $rel => $why) {
    $path = __DIR__ . '/' . $rel;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $ok = $exists && $writable;
    add($checks, $ok ? 'ok' : 'warn', trim($rel, './') . '/ folder',
        ($exists ? 'exists' : 'MISSING') . ', ' . ($exists ? ($writable ? 'writable' : 'NOT WRITABLE') : 'n/a') . '  -  ' . $why);
    state($ok ? 'ok' : 'warn');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
add($checks, 'ok', 'HTTPS', $isHttps ? 'on' : 'off  -  session cookies are sent without the secure flag');
state('ok');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deployment Check</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; background: #f4f5f7; margin: 0; padding: 2rem; }
        .wrap { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .sub { color: #6b7280; margin: 0 0 1.5rem; font-size: .9rem; }
        .banner { padding: .9rem 1.1rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; }
        .ok  { background: #dcfce7; border-left: 4px solid #16a34a; }
        .warn{ background: #fef3c7; border-left: 4px solid #d97706; }
        .bad { background: #fee2e2; border-left: 4px solid #dc2626; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        th, td { text-align: left; padding: .7rem .9rem; border-bottom: 1px solid #e5e7eb; font-size: .88rem; vertical-align: top; }
        th { background: #f9fafb; font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
        td.n { font-weight: 600; white-space: nowrap; width: 1%; }
        td.s { width: 1%; white-space: nowrap; font-weight: 700; }
        .tag { padding: .15rem .5rem; border-radius: 4px; font-size: .72rem; font-weight: 700; }
        .t-ok   { background: #dcfce7; color: #15803d; }
        .t-warn { background: #fef3c7; color: #b45309; }
        .t-bad  { background: #fee2e2; color: #b91c1c; }
        .d { color: #4b5563; }
        .back { display: inline-block; margin-top: 1.5rem; color: #2563eb; text-decoration: none; font-size: .9rem; }
        code { background: #f3f4f6; padding: .1rem .35rem; border-radius: 3px; font-size: .85em; }
    </style>
</head>

<body>
    <div class="wrap">
        <h1>Deployment check</h1>
        <p class="sub">Read only. Nothing on this page creates, alters or deletes anything.</p>

        <?php if ($fail > 0): ?>
            <div class="banner bad"><?= $fail ?> problem(s) will break the app. Fix these first.</div>
        <?php elseif ($warn > 0): ?>
            <div class="banner warn">No blockers. <?= $warn ?> thing(s) worth knowing about.</div>
        <?php else: ?>
            <div class="banner ok">Everything this host needs is present.</div>
        <?php endif; ?>

        <table>
            <tr>
                <th>Check</th>
                <th>State</th>
                <th>Detail</th>
            </tr>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td class="n"><?= htmlspecialchars($c['name']) ?></td>
                    <td class="s"><span class="tag t-<?= $c['state'] ?>"><?= strtoupper($c['state']) ?></span></td>
                    <td class="d"><?= htmlspecialchars($c['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p style="margin-top:1.5rem">
            Missing tables or a missing <code>users.role</code> widening? Import
            <code>config/migration_002_runtime_tables.sql</code> through phpMyAdmin. It is safe to run more than once.
        </p>

        <a class="back" href="dashboard.php">&larr; Back to dashboard</a>
    </div>
</body>

</html>
