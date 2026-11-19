<?php
/**
 * POST /api/auth/password/reset - a new password, with a link that was texted.
 *
 * The token is the credential here, so this address is the one place in the API
 * where a stranger can plausibly stand: it is unauthenticated by design, because a
 * person who has forgotten their password has nothing to prove with. What bounds it
 * is the token itself - 64 random hex characters, spent on use, dead after fifteen
 * minutes, five wrong guesses per value - and the fact that a successful call
 * revokes every session the account holds, so a link that was abused cannot be
 * abused a second time and cannot leave a phone signed in behind it.
 *
 * It does not sign anybody in. Changing a password because somebody else's link
 * landed on your handset is not a sign that you should now have a session.
 */

require_once __DIR__ . '/../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$token = authApiParam($req, 'token');
if ($token === '') {
    $token = authApiParam($req, 'reset_token');
}

$password = authApiParam($req, 'password');
$confirm  = authApiParam($req, 'password_confirmation');
if ($confirm === '') {
    $confirm = authApiParam($req, 'confirm_password');
}
if ($confirm !== '' && $confirm !== $password) {
    authApiFail('Duibar ekui password likhun.', 400, array('code' => 'mismatch'));
}

$res = authPasswordResetConsume($db, $token, $password);
if (!$res['ok']) {
    authApiFail($res['error'], $res['retryable'] ? 400 : 410,
        array('code' => $res['retryable'] ? 'bad_password' : 'link_dead',
              'retryable' => $res['retryable'] ? 1 : 0));
}

authApiReply(array(
    'success' => true,
    'message' => 'Password change hoise. Ekhon notun password diye login korun.',
), 200);
