<?php
/**
 * POST /api/auth/phone/link/verify-otp - spend a linking code and save the number.
 *
 * This is the only address that turns a number into a verified one for a signed-in
 * phone client, and it takes the number from the challenge that was just spent
 * rather than from the request. The difference matters in one specific case: the
 * person typed number A, then changed the box to number B and hit verify. A code for
 * A must not bless B, and if the number came from the form it would.
 */

require_once __DIR__ . '/../../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$me = mobileAuth(null);
$userId = (int)$me['user_id'];

$phone = otpNormalizePhone(authApiParam($req, 'phone'));
$code  = authApiParam($req, 'code');
if ($code === '') {
    $code = authApiParam($req, 'otp');
}

if ($phone === '') {
    authApiFail('Ekta thik mobile number din (01XXXXXXXXX).', 400, array('code' => 'bad_phone'));
}
if ($code === '') {
    authApiFail('OTP code ta din.', 400, array('code' => 'incomplete'));
}

$chk = otpConsume($db, 'link', $phone, $code);
if (!$chk['ok']) {
    authApiFail($chk['error'], 401, array(
        'code'      => $chk['retryable'] ? 'bad_code' : 'code_spent',
        'retryable' => $chk['retryable'] ? 1 : 0,
    ));
}

$proved = $chk['phone'];

// Checked again, here rather than trusting the send step: the window between the
// code and this request is long enough for an admin to have put the same number on
// another account, and saving it twice is the one outcome this whole flow exists to
// prevent. The code is already spent, so this person needs a new one either way -
// the refusal costs them a resend, not their number.
$heldElsewhere = otpPhoneTaken($db, $proved, $userId);
if ($heldElsewhere !== null) {
    authAudit('phone_link', false, $userId, otpMaskPhone($proved),
        'refused: number moved to account ' . (int)$heldElsewhere['id'] . ' while the code was live');
    authApiFail('Ei number ta ekhon onno ekta account e ache (ID ' . (int)$heldElsewhere['id']
              . '). Notun kore OTP din, agey ota shore felun.', 409, array('code' => 'number_taken'));
}

if (!otpMarkUserPhone($db, $userId, $proved)) {
    authAudit('phone_link', false, $userId, otpMaskPhone($proved), 'save refused');
    authApiFail('Number ta save kora jay nai. Abara chesta korun.', 500, array('code' => 'save_failed'));
}

authAudit('phone_link', true, $userId, otpMaskPhone($proved), 'mobile number verified and saved');

authApiReply(array(
    'success'         => true,
    'message'         => 'Number verify hoise! Ekhon apni ei number diye login korte parben.',
    'phone'           => $proved,
    'phone_verified'  => true,
), 200);
