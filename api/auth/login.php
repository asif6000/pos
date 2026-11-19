<?php
/**
 * POST /api/auth/login - phone number or email, with a password.
 *
 * Answers the same body as mobile_api/login.php so an app that already speaks the
 * older address reads one shape, and accepts a phone number as well as an email,
 * which the older address does not.
 */

require_once __DIR__ . '/../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

// username, email, phone and identifier are all accepted: the shipped apps send
// "username", the web forms send "email", and a client that has read the spec sends
// "identifier". All four mean the same one thing - the string the person typed.
$identifier = authApiParam($req, 'identifier');
if ($identifier === '') {
    $identifier = authApiParam($req, 'username');
}
if ($identifier === '') {
    $identifier = authApiParam($req, 'email');
}
if ($identifier === '') {
    $identifier = authApiParam($req, 'phone');
}
$password = authApiParam($req, 'password');

if ($identifier === '' || $password === '') {
    authApiFail('Please enter your email or phone number and your password.', 400,
        array('code' => 'incomplete'));
}

$gate = authApiLoginGate($db, $identifier);
if (!$gate['allowed']) {
    authApiFail('Ektu beshi chesta hoise. ' . max(1, (int)ceil($gate['retry_in'] / 60))
              . ' minute por abara korun.', 429,
        array('code' => 'throttled', 'retry_in' => $gate['retry_in']));
}

$found = authApiResolveAccount($db, $identifier, $password);
if (!$found['ok']) {
    authApiLoginFailure($db, $identifier);
    authAudit('login', false, null, $identifier, $found['reason']);
    authApiFail($found['error'], 401, array('code' => 'bad_credentials'));
}

authApiLoginSuccess($db, $identifier);
authApiReply(authApiSignIn($db, $found['user'], 'a password'), 200);
