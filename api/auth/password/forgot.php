<?php
/**
 * POST /api/auth/password/forgot - ask for a reset link for an account.
 *
 * The link travels by SMS to the number that was verified on the account, because
 * this installation has no mail transport: an email that never leaves the server
 * would look like a working feature from the outside and fail from the inside. An
 * account with no verified number cannot reset itself and is told to see the
 * admin, which is the honest answer rather than a dead end.
 *
 * The reply does not confirm that an account exists. It says what happens if one
 * does.
 */

require_once __DIR__ . '/../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$identifier = authApiParam($req, 'identifier');
if ($identifier === '') {
    $identifier = authApiParam($req, 'email');
}
if ($identifier === '') {
    $identifier = authApiParam($req, 'phone');
}
if ($identifier === '') {
    authApiFail('Email ba mobile number din.', 400, array('code' => 'incomplete'));
}

$res = authPasswordForgot($db, $identifier, array('app' => 'Smart POS'));
if (!$res['ok']) {
    // A service-level refusal (no gateway, table missing, a budget spent) is shown
    // as it is; see the trade documented in authPasswordForgot().
    authApiFail($res['error'], $res['retry_in'] > 0 ? 429 : 502,
        array('code' => $res['retry_in'] > 0 ? 'throttled' : 'send_failed', 'retry_in' => $res['retry_in']));
}

authApiReply(array(
    'success'    => true,
    'message'    => 'Jodi ei identifier e kono account thake, tar mobile number e reset link '
                  . 'pathano hoise. Link ta 15 minute cholbe.',
    'expires_in' => PASSWORD_RESET_TTL_SECONDS,
), 200);
