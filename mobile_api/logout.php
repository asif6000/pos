<?php
/**
 * Mobile API - Sign out
 *
 * THE SHAPE THIS MATCHES
 * ----------------------
 * HTTP 200 with success true or false, like every other mobile_api/ file: the app's
 * screens read data.message and key nothing off a status code, and a logout that
 * answered 401 would look like it failed on a phone that is about to throw its
 * token away anyway. api/auth/logout.php is the same action for a client that does
 * read statuses; this is the one the shipped APKs can call without an update.
 *
 * WHAT IT DOES
 * ------------
 * Marks the register row revoked, then destroys the session file behind the token.
 * The account is untouched - no profile, no permissions, no history - so signing in
 * again afterwards works exactly as it always has.
 */

require_once '../config/mobile_auth.php';
require_once '../config/auth_sessions.php';
mobileCors();

$token = mobileBearerToken();

if ($token === '' || !preg_match('/^[a-z0-9]{20,64}$/i', $token)) {
    echo json_encode(['success' => true, 'message' => 'Signed out']);
    exit;
}

$userId = 0;
mobileSessionStart($token);
if (isLoggedIn()) {
    $userId = (int)$_SESSION['user_id'];
}
session_unset();
session_destroy();
session_write_close();

if ($userId > 0) {
    try {
        authSessionRevoke(getDB(), $token);
    } catch (Throwable $e) {
        // The file is gone, which ends the session anyway. A register row that
        // could not be marked - on a host where the table was never imported, most
        // likely - is a bookkeeping miss, not a reason to refuse somebody their
        // own sign-out.
        error_log('mobile_api/logout register: ' . $e->getMessage());
    }
    authAudit('logout', true, $userId, '', 'Signed out from the app');
}

echo json_encode(['success' => true, 'message' => 'Signed out']);
