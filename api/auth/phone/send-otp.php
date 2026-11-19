<?php
/**
 * POST /api/auth/phone/send-otp - a code to a number that already has an account.
 *
 * The number is looked up before anything is sent, because a code that goes to a
 * handset with no account behind it is an SMS bill and a dead end: the person
 * receiving it has nothing to sign in to. The answer is the same either way, so a
 * caller cannot walk a list of numbers and keep the ones that get a code.
 */

require_once __DIR__ . '/../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$raw   = authApiParam($req, 'phone');
$phone = otpNormalizePhone($raw);
if ($phone === '') {
    authApiFail('Ekta thik mobile number din (01XXXXXXXXX).', 400, array('code' => 'bad_phone'));
}

// A number that names nobody, or names two people, gets the sentence a person with
// an account would also be given: this address cannot register, and saying so in
// detail would turn it into a directory of who holds an account.
$generic = 'Jodi ei number e kono account thake, code pathano hoise. 5 minute er moddhe din.';

$rows = authFindUsersByIdentifier($db, $phone);
if (count($rows) !== 1) {
    authAudit('otp_send', false, null, otpMaskPhone($phone),
        count($rows) === 0 ? 'login request for a number with no account'
                           : 'login request for a number held by ' . count($rows) . ' accounts');
    authApiReply(array('success' => true, 'message' => $generic, 'retry_in' => OTP_RESEND_SECONDS));
}

$account = $rows[0];
if ((string)$account['status'] !== 'active') {
    authAudit('otp_send', false, (int)$account['id'], otpMaskPhone($phone), 'inactive account asked for a code');
    authApiReply(array('success' => true, 'message' => $generic, 'retry_in' => OTP_RESEND_SECONDS));
}

$ownerId = otpLoginSenderOwner($db, $account);
$res = otpSendCode($db, $ownerId, $phone, 'login', array(
    'user_id' => (int)$account['id'],
    'app'     => 'Smart POS',
));

if (!$res['ok']) {
    // A deliberate trade. The gateway's refusal is shown as it is, because a
    // shopkeeper whose SMS provider is not configured would otherwise be told a
    // code was sent and would wait for an SMS that never comes - and this product
    // has no other way to reach that person. The cost is that a 502 here means the
    // number does hold an account, which is what the login page already says in
    // plain words; the rate limit above (per number and per address) is what makes
    // walking a list of numbers expensive rather than free.
    authApiFail($res['error'], $res['retry_in'] > 0 ? 429 : 502,
        array('code' => $res['retry_in'] > 0 ? 'throttled' : 'send_failed', 'retry_in' => $res['retry_in']));
}

authApiReply(array('success' => true, 'message' => $generic, 'phone' => otpMaskPhone($phone),
                   'expires_in' => OTP_TTL_SECONDS, 'retry_in' => OTP_RESEND_SECONDS), 200);
