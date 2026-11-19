<?php
/**
 * POST /api/auth/phone/verify-otp - the code, checked, and a token if it matches.
 *
 * A spent code is the proof: it arrived on the handset, so whoever typed it holds
 * the number. That is why a successful call here also marks the account's number
 * verified (config/auth_otp.php is the only place allowed to do that) rather than
 * signing in and leaving the number in the "typed by an admin" state forever.
 *
 * There is no session behind this request, so the state is the challenge row: the
 * client sends the same number it sent to send-otp, and the live challenge for that
 * number is what gets spent. A code cannot be spent against a different number,
 * because the hash covers the number as well as the code.
 */

require_once __DIR__ . '/../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$phone = otpNormalizePhone(authApiParam($req, 'phone'));
$code  = authApiParam($req, 'code');
if ($code === '') {
    $code = authApiParam($req, 'otp');
}
if ($code === '') {
    $code = authApiParam($req, 'verification_code');
}

if ($phone === '') {
    authApiFail('Ekta thik mobile number din (01XXXXXXXXX).', 400, array('code' => 'bad_phone'));
}
if (preg_replace('/[^0-9]/', '', $code) === '') {
    authApiFail('OTP code ta din.', 400, array('code' => 'incomplete'));
}

$chk = otpConsume($db, 'login', $phone, $code);
if (!$chk['ok']) {
    authApiFail($chk['error'], 401, array(
        'code'      => $chk['retryable'] ? 'bad_code' : 'code_spent',
        'retryable' => $chk['retryable'] ? 1 : 0,
    ));
}

// The number the code was for, not the number the request claimed: they are equal
// unless something has changed under us, and this is the value that goes into the
// account.
$proved = $chk['phone'];

$rows = authFindUsersByIdentifier($db, $proved);
if (count($rows) !== 1) {
    // Nothing here can be signed in, and the code is already spent, so the honest
    // answer is that this person's number is not usable and an admin has to look
    // at it. Recorded, because this is the one case the shop cannot fix itself.
    authAudit('login', false, $chk['user_id'], otpMaskPhone($proved),
        count($rows) === 0 ? 'code spent for a number with no account'
                           : 'code spent for a number held by ' . count($rows) . ' accounts');
    authApiFail('Ei number ta ekta account e verify kora jay nai. Admin theke check korun.', 403,
        array('code' => 'number_unusable'));
}

$account = $rows[0];
if ((string)$account['status'] !== 'active') {
    authAudit('login', false, (int)$account['id'], otpMaskPhone($proved), 'inactive account spent a code');
    authApiFail('Your account is inactive. Please contact administrator.', 403,
        array('code' => 'inactive'));
}

otpMarkUserPhone($db, (int)$account['id'], $proved);

$payload = authApiSignIn($db, $account, 'an OTP code');
authApiReply($payload, 200);
