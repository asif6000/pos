<?php
/**
 * Mobile API - Register a push token.
 *
 * The staff app calls this after login (owner/manager/admin only) so the
 * backend can notify that phone when a sale is rung up elsewhere. The token is
 * stored against whoever is registering, so a shared device follows the last
 * person who logged in. See config/push.php.
 */
require_once '../config/mobile_auth.php';
require_once '../config/push.php';
mobileCors();

$db = getDB();

$auth  = mobileAuth();
$data  = json_decode(file_get_contents('php://input'), true) ?: [];

// Who is registering comes from the sign-in, not from the body, so a phone cannot
// be enrolled as somebody else and start receiving their sale alerts.
$owner_id = $auth['owner_id'];
$user_id  = $auth['user_id'];
$role     = $auth['role'];
$token    = trim((string)($data['token'] ?? ''));
$platform = (string)($data['platform'] ?? '');

if ($token === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Only Expo push tokens are accepted. Storing an arbitrary string here would
// let a caller fill the table with junk that later fails on every send.
if (!preg_match('/^Expo(nent)?PushToken\[/', $token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid push token']);
    exit;
}

// Only the roles that should be notified bother storing a token. A cashier's
// token is refused rather than stored-and-filtered, so the table stays small.
if (!in_array($role, pushNotifyRoles(), true)) {
    echo json_encode(['success' => true, 'ignored' => true, 'message' => 'Role not eligible for sale alerts']);
    exit;
}

try {
    pushRegisterToken($db, $owner_id, $user_id, $role, $token, $platform);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('register_push failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not register token']);
}
