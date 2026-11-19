<?php
/**
 * POST /api/auth/phone/link/send-otp - a code to a number the signed-in account
 * wants added to its own profile.
 *
 * WHO MAY LINK WHOM
 * -----------------
 * Only the account holding a valid token, and only to itself. An admin who wants a
 * number set on somebody else's account has to get that person's handset, which is
 * the point: a phone number is a login credential that works without a password, so
 * the permission that matters is "can you receive SMS on it", not "are you allowed
 * to edit users". The web side of this same workflow is the Phone Number box on the
 * Settings page, which is why both use purpose 'link' and neither writes
 * users.phone_verified directly.
 */

require_once __DIR__ . '/../../../../config/auth_api.php';

authApiBoot();
authApiRequirePost();

$db  = getDB();
$req = authApiRequest();

$me  = mobileAuth(null);
$authUserId = (int)$me['user_id'];

$phone = otpNormalizePhone(authApiParam($req, 'phone'));
if ($phone === '') {
    authApiFail('Ekta thik mobile number din (01XXXXXXXXX).', 400, array('code' => 'bad_phone'));
}

$heldElsewhere = otpPhoneTaken($db, $phone, $authUserId);
if ($heldElsewhere !== null) {
    // Named as the account it belongs to, to the person who is already signed in:
    // they are not guessing at other people's accounts, and "your number is
    // already on Reza's profile" is the sentence that ends a duplicate in one
    // conversation rather than five support calls.
    authAudit('phone_link', false, $authUserId, otpMaskPhone($phone),
        'number already on account ' . (int)$heldElsewhere['id']);
    authApiFail('Ei number ta onno ekta account e ache (ID ' . (int)$heldElsewhere['id']
              . '). Agey sekhan theke shorate korun.', 409, array('code' => 'number_taken'));
}

// The account row again, for the shop's own gateway settings and current number.
$st = $db->prepare("SELECT id, name, email, role, status, store_id, owner_id, phone FROM users WHERE id = ? LIMIT 1");
$st->execute(array($authUserId));
$account = $st->fetch(PDO::FETCH_ASSOC);
if (!$account) {
    authApiFail('Account ta khuje pawa jay nai.', 403, array('code' => 'no_account'));
}

$ownerId = (int)($me['owner_id'] ?: $authUserId);
$res = otpSendCode($db, $ownerId, $phone, 'link', array(
    'user_id' => $authUserId,
    'app'     => 'Smart POS',
));

if (!$res['ok']) {
    authApiFail($res['error'], $res['retry_in'] > 0 ? 429 : 502,
        array('code' => $res['retry_in'] > 0 ? 'throttled' : 'send_failed', 'retry_in' => $res['retry_in']));
}

authApiReply(array(
    'success'    => true,
    'message'    => 'OTP pathano hoise. ' . otpMaskPhone($phone) . ' e code ta asbe.',
    'phone'      => otpMaskPhone($phone),
    'expires_in' => OTP_TTL_SECONDS,
    'retry_in'   => OTP_RESEND_SECONDS,
), 200);
