<?php
/**
 * POST /api/auth/logout - switch this token off, keep the account.
 *
 * Two things happen and neither of them touches the profile: the register row is
 * marked revoked, and the PHP session behind the token is destroyed. Both are
 * needed, because they stop different attacks. The row is what makes an
 * admin-initiated "sign this phone out" stick even if a session file survives on
 * disk; destroying the file is what makes a token whose row nobody can write -
 * one issued before auth_sessions existed, or on a host that refused the migration -
 * end here rather than lingering for thirty days.
 *
 * A person signing themselves out is not a security event worth an argument: an
 * expired or already-dead token gets the same answer, because the app is about to
 * throw its stored token away either way and a 401 would look like the logout
 * failed.
 */

require_once __DIR__ . '/../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db    = getDB();
$token = mobileBearerToken();

if ($token === '' || !preg_match('/^[a-z0-9]{20,64}$/i', $token)) {
    authApiReply(array('success' => true, 'message' => 'Signed out.'));
}

// A token with no register row is still destroyed below, by opening its session
// and throwing the file away: that is how a legacy token - one issued before
// auth_sessions existed, or on a host that refused the migration - is ended.
$me = mobileAuth(null, function () {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    session_unset();
    // The file, not just the id: a destroyed session is the difference between "the
    // app forgot the token" and "this token holds nothing".
    @session_destroy();
    return $userId;
});

$userId = (int)$me['data'];
if ($userId > 0) {
    authSessionRevoke($db, $token);
    authAudit('logout', true, $userId, '', 'Signed out this device');
}

authApiReply(array('success' => true, 'message' => 'Signed out.', 'revoked' => true), 200);
