<?php
/**
 * Mobile API - Sign in with Google
 *
 * THE SHAPE THIS MATCHES
 * ----------------------
 * The answer is byte-for-byte the one mobile_api/login.php gives: the same
 * token, the same permission map, the same user object. The app already knows
 * how to store that and how to build its menu from it, so Google becomes a
 * second way onto the same session rather than a second kind of sign-in - which
 * is the whole reason the mobile credential is a PHP session id (config/
 * mobile_auth.php): isLoggedIn(), currentRole(), planAllows() and
 * hasPermission() apply to a Google-signed phone with no extra code.
 *
 * WHY THE APP SENDS A TOKEN AND NOT A PROFILE
 * -------------------------------------------
 * The phone shows the user's name and email itself. Nothing in this file
 * believes any of it. What arrives is the signed ID token the Google SDK handed
 * the device, verified here against Google's own keys (config/auth_google.php)
 * before a single column is read, so a client that invents an email cannot open
 * a door in somebody else's shop.
 *
 * No nonce is checked and that is deliberate: the web nonce ties a credential to
 * one browser session, and a phone has no session before it is signed in. The
 * replay defence is the same one the native login form has - the credential is
 * only ever sent over TLS to a server the user configured on the device.
 */

require_once '../config/mobile_auth.php';
require_once '../config/auth_core.php';
require_once '../config/auth_google.php';
mobileCors();

/**
 * Both the failure and the success answer the way login.php does: HTTP 200 with
 * success false or true, because the app's login screen reads data.message and
 * keys nothing off a status code. 401 is reserved for "your session died" (see
 * mobileAuth()), and this endpoint has no session to die yet.
 */
function mobileGoogleReply($payload)
{
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function mobileGoogleFail($message)
{
    mobileGoogleReply(['success' => false, 'message' => $message]);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$idToken = trim((string)($data['idToken'] ?? ''));
if ($idToken === '') {
    mobileGoogleFail('No Google credential was sent.');
}

$cfg = googleSignInConfig();
if (!$cfg['enabled']) {
    // Named as a configuration state rather than an error, so the shopkeeper
    // filing a screenshot tells us which of the two it is.
    mobileGoogleFail('Google sign-in is not enabled on this server.');
}

$verified = googleSignInVerifyIdToken($idToken, $cfg['client_id'], false);
if (!$verified['ok']) {
    // The reason is for the log only. The visitor gets one plain sentence, the
    // same as the web endpoint: which check tripped is a free oracle for anyone
    // probing this URL with guessed tokens.
    error_log('mobile google_login refused: ' . $verified['error'] . ' from ' . authClientIp());
    authAudit('google_login', false, null, '', $verified['error']);
    mobileGoogleFail('Google could not confirm that sign-in. Please try again.');
}

$claims = $verified['claims'];
$found  = googleSignInResolve($claims);

if ($found['action'] === 'none') {
    // No auto-provisioning, on a phone exactly as on the web page: auth/register.php
    // is closed by default because a button must not be able to mint a shop owner.
    // The app shows the sentence, and the account gets created by whoever already
    // has access, in Admin -> Users.
    authAudit('google_login', false, null, (string)($claims['email'] ?? ''), $found['reason'] !== '' ? $found['reason'] : 'No account for this Google identity');
    mobileGoogleReply([
        'success' => false,
        'code'    => 'needs_account',
        'message' => $found['reason'] !== ''
            ? $found['reason']
            : 'No account here uses this Google address yet.',
    ]);
}

$user = $found['user'];

// A concurrent disable between the resolve and the session write is caught by
// the next mobileAuth() call, which re-reads status on every request; the
// refresh below is what the app shows on the login screen.
$session = mobileIssueSession($user);
$effectiveOwnerId = (int)($user['owner_id'] ?: $user['id']);

authAudit(
    'google_login',
    true,
    $user['id'],
    (string)$user['email'],
    $found['action'] === 'link' ? 'Signed in with Google and linked the identity' : 'Signed in with Google'
);

mobileGoogleReply([
    'success'     => true,
    'token'       => $session['token'],
    'is_admin'    => $session['is_admin'],
    'plan'        => $session['plan'],
    'permissions' => $session['permissions'],
    'user' => [
        'id'       => (int)$user['id'],
        'email'    => $user['email'],
        'name'     => $user['name'],
        'role'     => $user['role'],
        'store_id' => $user['store_id'] ?? null,
        'owner_id' => $effectiveOwnerId,
    ],
]);
