<?php
/**
 * API - the shop's remaining SMS credit at its gateway
 *
 * This number is not in our database: it is what the provider still lets this
 * shop send, so it has to be asked for over HTTP with the stored API key. That
 * is why it lives here instead of in marketing.php - a key that reached the
 * browser would be readable by anyone who opened the page, and the answer this
 * returns is only ever a number plus a message.
 *
 * The provider call blocks for as long as the gateway takes to answer, so the
 * result is held in the session for a minute and marketing.php asks for it after
 * the page has already painted. The chip's refresh button sends ?refresh=1 to
 * skip that cache.
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';   // getSmsSettings() layers on getMarketingSettings()
require_once '../../config/sms_gateway.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || !hasPermission('marketing')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$user    = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];
$cache   = 'sms_balance_' . (int)$ownerId;
$ttl     = 60;

$cached = $_SESSION[$cache] ?? null;
if (!isset($_GET['refresh']) && is_array($cached) && (time() - (int)$cached['at']) < $ttl) {
    $out = $cached['data'];
    $out['cached']     = true;
    $out['checked_at'] = date('H:i', (int)$cached['at']);
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$settings = getSmsSettings($ownerId);

if (($settings['marketing_sms_provider'] ?? 'manual') === 'manual') {
    $out = ['ok' => false, 'balance' => '', 'error' => 'Gateway set kora nei — SMS phone er app theke jay.'];
} else {
    $res = marketingSmsCheckBalance($ownerId);
    // $res['raw'] is left out on purpose: it is the provider's entire response
    // body and has no business being sent to a browser.
    $out = [
        'ok'      => !empty($res['ok']),
        'balance' => (string)($res['balance'] ?? ''),
        'error'   => (string)($res['error'] ?? ''),
    ];
}

$out['cached']     = false;
$out['checked_at'] = date('H:i');
$_SESSION[$cache]  = ['at' => time(), 'data' => $out];

echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE);
