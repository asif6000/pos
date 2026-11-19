<?php
/**
 * GET /api/auth/me - who this token belongs to, and what that account may open.
 *
 * WHY THE APP NEEDS THIS
 * ----------------------
 * The token is a session id, so the app already holds something that authenticates
 * it; what it does not hold is a current answer. A phone that signed in thirty days
 * ago has a menu built from permissions the admin may have changed, a package that
 * may have lapsed, or an account that may be switched off. This is the call that
 * notices: every field comes from the database on this request, and the shape is
 * the same one login hands back, so a client can refresh its whole picture with one
 * round trip rather than a screen for each piece.
 *
 * A 401 here means the app should go back to the sign-in screen - which is what
 * config/mobile_auth.php's mobileDeny() is for, and why the status and the body
 * travel together.
 */

require_once __DIR__ . '/../../config/auth_api.php';

authApiBoot();

$db = getDB();

$me = mobileAuth(null, function () {
    // Permissions and plan are read in this window on purpose. hasPermission()
    // runs through isLoggedIn(), and once the session is closed that call starts a
    // new one - in the web session directory, where this token has no file - and
    // every key answers false. The app would be told its own account opens
    // nothing, and the menu would empty out.
    return array(
        'permissions' => mobilePermissionMap(),
        'plan'        => currentPlanSlug(),
    );
});

$userId = (int)$me['user_id'];

$st = $db->prepare("SELECT id, name, email, role, status, store_id, owner_id, phone, last_login
                    FROM users WHERE id = ? LIMIT 1");
$st->execute(array($userId));
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    authApiFail('Account ta khuje pawa jay nai.', 401, array('code' => 'no_account'));
}

// The device list is the reason auth_sessions exists: "is the phone I lost still
// signed in?" is answerable here, and the answer is a row with an IP, a name and a
// last-seen date rather than a guess.
$sessions = array();
foreach (authSessionListForUser($db, $userId, 'mobile') as $row) {
    $live = !empty($row['revoked_at']) ? 'revoked'
          : (strtotime((string)$row['expires_at']) <= time() ? 'expired' : 'active');
    $sessions[] = array(
        'id'           => (int)$row['id'],
        'device'       => substr((string)$row['user_agent'], 0, 80),
        'ip'           => $row['ip'],
        'signed_in_at' => $row['created_at'],
        'last_seen_at' => $row['last_seen_at'],
        'expires_at'   => $row['expires_at'],
        'status'       => $live,
    );
}

authApiReply(array(
    'success'     => true,
    'is_admin'    => $me['is_admin'],
    'plan'        => $me['data']['plan'],
    'permissions' => $me['data']['permissions'],
    'user'        => array(
        'id'       => (int)$user['id'],
        'email'    => $user['email'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'phone'    => $user['phone'],
        'store_id' => $user['store_id'] === null ? null : (int)$user['store_id'],
        'owner_id' => (int)($user['owner_id'] ?: $user['id']),
        'status'   => $user['status'],
    ),
    'sessions'    => $sessions,
), 200);
