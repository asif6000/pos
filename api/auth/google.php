<?php
/**
 * POST /api/auth/google - the credential from Google, checked, and a sign-in.
 *
 * WHAT HAPPENS HERE
 * -----------------
 * Google Identity Services runs in the page or on the device, the visitor picks an
 * account, and the library hands the client a JWT. This endpoint takes that JWT and
 * does the one thing the client cannot: decide whether it is real. config/
 * auth_google.php checks the signature against Google's own keys, the issuer, the
 * audience, the expiry and the nonce minted when the login page was drawn. Only
 * after all five pass is a single line of the token treated as fact.
 *
 * TWO ANSWERS, ONE CHECK
 * ----------------------
 * mode=session (the default, and what the login page's button posts) writes a
 * browser session and replies with the redirect to walk to. mode=token is what an
 * app posts, and replies with the bearer token - the body every other sign-in on
 * this API gives. Both halves run the same verification, so there is one definition
 * of "this Google identity was confirmed" and it cannot be argued with by choosing a
 * different door.
 *
 * WHY THE ORIGIN TEST IS ONLY FOR THE BROWSER
 * -------------------------------------------
 * The same-origin check exists because the browser half authenticates with a
 * cookie: a page on another domain that posts a captured credential here with a
 * visitor's session cookie riding along gets to learn things about their account
 * from the reply. The token half sends no cookie and holds no session before it is
 * signed in - there is nothing to ride - so requiring an Origin header would only
 * refuse the clients that send the fewest headers, which is every native app. The
 * credential is what is checked, in both halves.
 *
 * WHAT MAKES A REPLAY WORTHLESS
 * -----------------------------
 * In browser mode the nonce is spent by the verification step whether or not the
 * sign-in then succeeds, so a captured credential cannot be posted twice: the second
 * attempt has no session behind it. A phone credential carries no nonce - the web
 * nonce ties a token to one browser session, and a device has none yet - and its
 * replay defence is the one the native password form has: the credential only ever
 * travels over TLS to the server the user configured on the device.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth_core.php';
require_once __DIR__ . '/../../config/auth_google.php';
// authApiSignIn(), so a Google sign-in and a code sign-in hand the client the same
// object. Two shapes for one session is how an app ends up with two kinds of storage
// and a menu that is right in one flow and wrong in the other.
require_once __DIR__ . '/../../config/auth_api.php';

$db = getDB();

$body = authApiRequest();
$mode = strtolower(authApiParam($body, 'mode', 'session'));

// Decided before anything else, because it also decides whether a browser session
// is opened at all: a native client that gets a Set-Cookie back has no way to be
// sure the next call will not be authenticated by it, and this endpoint's whole
// answer is which credential the response trusts.
$tokenMode = $mode === 'token';

if (!$tokenMode) {
    startSecureSession();
}

/**
 * One answer for both halves.
 *
 * The page reads ok / error / redirect / nonce; the app reads success / message /
 * token / permissions. A payload is written once, in the page's shape, and
 * translated here - which keeps the six refusal sites in this file from having to
 * remember two contracts each.
 */
function googleReply(array $payload, $status = 200, $forApp = false)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    // A sign-in response is never cacheable: a shared browser must not be able to
    // reload a page that has already moved a session along.
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $ok = !empty($payload['ok']) || (isset($payload['success']) && $payload['success'] === true);

    if ($forApp) {
        $out = array('success' => $ok);
        if (isset($payload['code'])) {
            $out['code'] = $payload['code'];
        }
        $out['message'] = isset($payload['message']) ? $payload['message']
            : (isset($payload['error']) ? $payload['error'] : '');
        foreach (array('token', 'is_admin', 'plan', 'permissions', 'user') as $key) {
            if (isset($payload[$key])) {
                $out[$key] = $payload[$key];
            }
        }
        echo json_encode($out);
        exit;
    }

    // Google signs the nonce into the token and verification spends it, so a
    // visitor who stays on the page needs a fresh value for the next attempt or
    // the button works once per page load and then refuses. When nothing was
    // spent, this returns the same value the page already holds and the script
    // leaves the button alone.
    if (!$ok) {
        $payload['nonce'] = googleSignInNonce();
    }

    echo json_encode($payload);
    exit;
}

/**
 * Did this request come from this site?
 *
 * The credential itself proves who the visitor is, so a cross-site POST cannot
 * sign anybody in as anybody else - but a page on another domain that posts here
 * with a visitor's session cookie still gets to learn things about their account
 * from the reply. Refusing it costs one comparison.
 */
function googleSigninSameOrigin()
{
    $source = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $source = (string)$_SERVER['HTTP_ORIGIN'];
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
        $source = (string)$_SERVER['HTTP_REFERER'];
    }
    if ($source === '') {
        // No Origin and no Referer: a hand-made request, or a browser that hides
        // both. A real page from this site always sends one of them, so a missing
        // pair is not evidence of anything and gets refused rather than trusted.
        return false;
    }
    $host = parse_url($source, PHP_URL_HOST);
    if ($host === null || $host === false) {
        return false;
    }
    return strtolower($host) === strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    googleReply(array('ok' => false, 'error' => 'This endpoint expects a sign-in credential.'), 405, $tokenMode);
}

if (!$tokenMode && !googleSigninSameOrigin()) {
    authAudit('google_login', false, null, '', 'Rejected a request from another site');
    googleReply(array('ok' => false, 'error' => 'Please sign in from this site.'), 403, $tokenMode);
}

$cfg = googleSignInConfig();
if (!$cfg['enabled']) {
    // Deliberately the same wording the page would show for any other failure: a
    // disabled feature is not something to explain to a stranger at a form.
    authAudit('google_login', false, null, '', 'Google sign-in is not configured');
    googleReply(array('ok' => false, 'error' => 'Google sign-in is not available on this site.'), 400, $tokenMode);
}

$credential = authApiParam($body, 'credential');
if ($credential === '') {
    // The app's older address has always called this field idToken; a client that
    // has read this spec calls it credential. Either is the same signed JWT.
    $credential = authApiParam($body, 'id_token');
}
if ($credential === '') {
    $credential = authApiParam($body, 'idToken');
}
if ($credential === '') {
    googleReply(array('ok' => false, 'error' => 'No credential was received from Google.'), 400, $tokenMode);
}

$checked = googleSignInVerifyIdToken($credential, $cfg['client_id'], !$tokenMode);
if (!$checked['ok']) {
    authAudit('google_login', false, null, '', $checked['error']);
    googleReply(array('ok' => false, 'error' => $checked['error']), 401, $tokenMode);
}

$claims   = $checked['claims'];
$resolved = googleSignInResolve($claims);
$user     = isset($resolved['user']) ? $resolved['user'] : null;

// The address in a verified token is as good an identifier for the log as an email
// typed into a form, and better: it came from Google.
$identifier = isset($claims['email']) ? (string)$claims['email'] : ('sub:' . substr((string)$claims['sub'], 0, 24));

if ($user === null) {
    if (!$tokenMode) {
        // Only the browser half has somewhere to put this: the remembered claims are
        // what lets the register page offer to create the account the visitor just
        // tried to use, with the email already filled in.
        googleSignInRemember($claims);
    }
    authAudit('google_login', false, null, $identifier, 'No account for this Google identity');
    googleReply(array(
        'ok'            => false,
        'code'          => 'needs_account',
        'needs_account' => true,
        'email'         => $identifier,
        'error'         => $resolved['reason'] !== ''
            ? $resolved['reason']
            : 'There is no account here for ' . $identifier . '. Create one to continue.',
        'register_url'  => rtrim(appBasePath(), '/') . '/auth/register.php',
    ), 404, $tokenMode);
}

if ((string)$user['status'] !== 'active') {
    authAudit('google_login', false, (int)$user['id'], $identifier, 'Inactive account');
    googleReply(array(
        'ok'    => false,
        'code'  => 'inactive',
        'error' => 'Your account is inactive. Please contact administrator.',
    ), 403, $tokenMode);
}

if ($tokenMode) {
    // The device gets a token and nothing else: no cookie, no redirect, no session
    // id handed to a browser that was never involved in the sign-in.
    $session = authApiSignIn($db, $user, 'Google',
        $resolved['action'] === 'link' ? 'google_link' : 'google_login');
    $session['ok'] = true;
    googleReply($session, 200, $tokenMode);
}

if (isLoggedIn() && (int)$_SESSION['user_id'] !== (int)$user['id']) {
    // A different person is already signed in on this browser. Signing the second
    // one in over the top of the first is how a shared till ends up showing the
    // wrong shop to the wrong cashier.
    authAudit('google_login', false, $user['id'], $identifier, 'Refused over an existing sign-in');
    googleReply(array(
        'ok'    => false,
        'error' => 'Another account is already signed in on this browser. Sign out first.',
    ), 409, $tokenMode);
}

authWebSessionFromUser($user);

authAudit(
    $resolved['action'] === 'link' ? 'google_link' : 'google_login',
    true,
    $user['id'],
    $identifier,
    'Signed in with Google'
);

googleReply(array(
    'ok'       => true,
    'name'     => $user['name'],
    'redirect' => rtrim(appBasePath(), '/') . '/' . authWebLanding($user),
));
