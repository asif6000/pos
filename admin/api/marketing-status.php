<?php
/**
 * API - Marketing / WhatsApp bridge status
 *
 * Returns the connection state and, when the bridge is waiting for a scan, the
 * login QR as a base64 PNG data URL. The page polls this every few seconds
 * while the QR is on screen.
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
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

// Encoded with a checked result, because json_encode() does not throw.
//
// The payload carries the agent's own log lines, which come back from the bridge
// verbatim, and a single invalid UTF-8 byte anywhere in them makes json_encode()
// return false with no warning. echo false then writes an empty body, and an
// empty body under HTTP/2 is a protocol error in the browser - which is what
// this endpoint was producing, repeatedly, as ERR_HTTP2_PROTOCOL_ERROR. The
// second attempt substitutes the bad bytes so a mangled log line cannot take
// the whole status down with it.
$payload = marketingGetBridgeStatus($ownerId);

$json = json_encode($payload);
if ($json === false) {
    error_log('marketing-status: json_encode failed: ' . json_last_error_msg());
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
}
if ($json === false) {
    http_response_code(500);
    $json = json_encode(['ok' => false, 'error' => 'Status encode kora jay ni.']);
}

echo $json;
