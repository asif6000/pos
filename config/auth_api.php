<?php
/**
 * Authentication API: the parts every /api/auth/* endpoint shares.
 *
 * WHY THESE FILES EXIST SIDE BY SIDE WITH mobile_api/
 * --------------------------------------------------
 * mobile_api/login.php is what the shipped APKs call today, and its shape is
 * frozen: a JSON body with username/password, a response with token, permissions,
 * plan and user. This API is the same engine with the paths the product spec
 * names - password login, phone + OTP, Google, phone linking, forgotten password,
 * logout, who-am-I - so a client that wants a code instead of a password has a
 * documented address for it. Nothing here changes what mobile_api/ answers, and
 * both go through config/mobile_auth.php for what a token means, so there is one
 * definition of "signed in" and one place that decides it.
 *
 * WHAT NO ENDPOINT HERE DOES
 * --------------------------
 * It does not read owner_id or store_id out of a request body. The shop is decided
 * by the credential that was just proved - a row from users, a spent code, a
 * verified Google token - and a client that names a shop gets that name ignored.
 * That rule is the reason the API was safe to open up at all, and it is enforced
 * by mobileAuth(), not by each file remembering to.
 *
 * ERRORS ARE GENERIC, STATUS CODES ARE NOT
 * ----------------------------------------
 * "Invalid email or password" for every credential failure, whatever the reason,
 * because a form that says "no account with that number" and "wrong password"
 * separately is a list of who has an account. The HTTP status still distinguishes
 * a refusal (401) from a rate limit (429) from a broken request (400), because the
 * app's behaviour differs on those: one sends the user back to the login screen,
 * one shows a countdown, one is a bug in the app.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_core.php';
require_once __DIR__ . '/auth_otp.php';
require_once __DIR__ . '/auth_password.php';
require_once __DIR__ . '/auth_sessions.php';
require_once __DIR__ . '/mobile_auth.php';

/** Password guesses allowed per identifier before the account is left alone. */
define('AUTH_API_LOGIN_MAX', 5);
define('AUTH_API_LOGIN_WINDOW', 900);
define('AUTH_API_LOGIN_LOCK', 900);

/**
 * Headers, and the preflight answer. Every endpoint calls this first.
 */
function authApiBoot()
{
    mobileCors();
    if (!headers_sent()) {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
    }
}

/**
 * The request body, whichever way it arrived.
 *
 * A JSON body because that is what the app already posts; form fields because a
 * browser form or a curl one-liner posting to the same address should not need a
 * different call. A key that is in both is read from the body: a client that sent
 * JSON means JSON.
 *
 * @return array<string,mixed>
 */
function authApiRequest()
{
    $raw = '';
    try {
        $raw = file_get_contents('php://input');
    } catch (Throwable $e) {
        $raw = '';
    }

    $json = array();
    if (trim((string)$raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    $out = array();
    foreach ($_POST as $k => $v) {
        $out[(string)$k] = $v;
    }
    foreach ($json as $k => $v) {
        $out[(string)$k] = $v;
    }
    // A GET has only the query string, and /me is documented as a GET.
    foreach ($_GET as $k => $v) {
        if (!array_key_exists((string)$k, $out)) {
            $out[(string)$k] = $v;
        }
    }
    return $out;
}

/**
 * One value from the request, as a string.
 */
function authApiParam(array $req, $key, $default = '')
{
    if (!array_key_exists($key, $req) || $req[$key] === null || is_array($req[$key])) {
        return (string)$default;
    }
    if (is_bool($req[$key])) {
        return $req[$key] ? '1' : '0';
    }
    return trim((string)$req[$key]);
}

/**
 * Answer and stop.
 *
 * `success` is kept alongside the status because the app's existing calls read it
 * - a client should not need two rules for "did this work".
 */
function authApiReply(array $payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode($payload);
    exit;
}

/**
 * The failure shape: ok=false, a message the client can show, nothing else.
 *
 * Never a field naming what was wrong with the credential (no `field`, no partial
 * echo of the identifier), and never a token, code or hash.
 */
function authApiFail($message, $status = 401, array $extra = array())
{
    $payload = array_merge(array('success' => false, 'message' => (string)$message), $extra);
    authApiReply($payload, $status);
}

/**
 * The same login-failure budget for every credential form on this API.
 *
 * Keyed on identifier AND address: one person hammering their own password cannot
 * be locked out by a stranger who happens to type the same email, and one address
 * cannot walk through a whole shop's account list. Both directions of that need
 * the same lockout length, so a failed attempt costs either a wait or a new
 * address, and the per-code attempt cap in auth_otp.php is the same idea for
 * codes.
 *
 * @return array{allowed:bool,retry_in:int}
 */
function authApiLoginGate($db, $identifier)
{
    $ip = authClientIp();
    $subject = strtolower(trim((string)$identifier)) . '|' . $ip;

    $byIp = otpThrottleLocked($db, 'password_login_ip', $ip);
    if ($byIp['locked']) {
        return array('allowed' => false, 'retry_in' => $byIp['retry_in']);
    }
    $bySubject = otpThrottleLocked($db, 'password_login', $subject);
    if ($bySubject['locked']) {
        return array('allowed' => false, 'retry_in' => $bySubject['retry_in']);
    }
    return array('allowed' => true, 'retry_in' => 0);
}

/**
 * Record a failed credential. Called for every refusal, including an unknown
 * account, so the budget burns at the same rate either way.
 */
function authApiLoginFailure($db, $identifier)
{
    $ip = authClientIp();
    $subject = strtolower(trim((string)$identifier)) . '|' . $ip;
    otpThrottleRecord($db, 'password_login', $subject, AUTH_API_LOGIN_MAX,
        AUTH_API_LOGIN_WINDOW, AUTH_API_LOGIN_LOCK);
    // The address bucket is wider, so one office NAT address behind which
    // everybody in a shop mistypes their password is not a lockout for the shop.
    otpThrottleRecord($db, 'password_login_ip', $ip, AUTH_API_LOGIN_MAX * 6,
        AUTH_API_LOGIN_WINDOW, AUTH_API_LOGIN_LOCK);
}

/**
 * A credential was proved: stop counting against this pair.
 */
function authApiLoginSuccess($db, $identifier)
{
    $ip = authClientIp();
    $subject = strtolower(trim((string)$identifier)) . '|' . $ip;
    otpThrottleClear($db, 'password_login', $subject);
}

/**
 * Who this identifier names, if it names exactly one active account.
 *
 * The refusal reasons are deliberately indistinguishable from a bad password at
 * the caller: "no account", "several accounts" and "inactive account" all answer
 * the generic sentence, and authAudit is where the truth is recorded for the
 * admin. A shopkeeper whose number sits on two rows is told to see the admin, in
 * the log rather than on the screen, because the screen is where a stranger is
 * standing.
 *
 * @return array{ok:bool,user:array|null,error:string,reason:string}
 */
function authApiResolveAccount($db, $identifier, $password = null)
{
    $out = array('ok' => false, 'user' => null, 'error' => 'Invalid email or password.', 'reason' => '');

    $id = trim((string)$identifier);
    if ($id === '') {
        $out['reason'] = 'empty identifier';
        return $out;
    }

    $rows = authFindUsersByIdentifier($db, $id);
    if (!$rows) {
        $out['reason'] = 'no account for ' . (strpos($id, '@') === false ? 'a number' : 'an email');
        return $out;
    }
    if (count($rows) > 1) {
        $out['reason'] = 'number held by ' . count($rows) . ' accounts';
        return $out;
    }

    $user = $rows[0];
    if ((string)$user['status'] !== 'active') {
        $out['reason'] = 'inactive account';
        $out['error'] = 'Your account is inactive. Please contact administrator.';
        return $out;
    }

    if ($password !== null) {
        if (!authPasswordVerifyAndUpgrade($db, $user, $password)) {
            $out['reason'] = 'wrong password';
            $out['error'] = 'Invalid email or password.';
            return $out;
        }
    }

    $out['ok'] = true;
    $out['user'] = $user;
    $out['error'] = '';
    $out['reason'] = '';
    return $out;
}

/**
 * Turn a proved account into what the app needs to build its menu, and hand out a
 * token for it.
 *
 * mobileIssueSession() writes the session and its register row; this only shapes
 * the answer, so the four endpoints that can sign somebody in - password, code,
 * Google, and a linked number - cannot drift apart on what a successful sign-in
 * looks like.
 */
function authApiSignIn($db, array $user, $method = 'password', $auditAction = 'login')
{
    $session = mobileIssueSession($user);
    $ownerId = (int)($user['owner_id'] ?: $user['id']);

    authAudit($auditAction, true, (int)$user['id'], (string)$user['email'], 'Signed in with ' . $method);

    return array(
        'success'     => true,
        'token'       => $session['token'],
        'is_admin'    => $session['is_admin'],
        'plan'        => $session['plan'],
        'permissions' => $session['permissions'],
        'user'        => array(
            'id'       => (int)$user['id'],
            'email'    => $user['email'],
            'name'     => $user['name'],
            'role'     => $user['role'],
            'phone'    => isset($user['phone']) ? $user['phone'] : '',
            'store_id' => isset($user['store_id']) ? ($user['store_id'] === null ? null : (int)$user['store_id']) : null,
            'owner_id' => $ownerId,
        ),
    );
}

/**
 * A POST-only address.
 */
function authApiRequirePost()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        authApiFail('This address expects a POST request.', 405, array('code' => 'method'));
    }
}
