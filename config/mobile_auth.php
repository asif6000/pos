<?php
/**
 * Mobile API sign-in.
 *
 * THE HOLE THIS CLOSES
 * --------------------
 * Until now every file under mobile_api/ read owner_id and user_id out of the
 * request body and believed them. login.php handed out a string that was never
 * checked afterwards, so the "token" was decoration: any client could POST
 * {"owner_id": 123} to products.php and read another shop's catalogue, or to
 * sell.php and write sales, customers and stock movements into it - with
 * Access-Control-Allow-Origin: * on top. The app worked because it is the only
 * caller anyone has bothered to point at the API, not because it was private.
 *
 * So: the app signs in once, gets back a token, and sends it as
 * "Authorization: Bearer <token>" on every later call. The token IS a PHP
 * session id, which means the mobile API resolves the same $_SESSION the web
 * pages do and therefore reuses isLoggedIn(), currentRole(), planAllows() and
 * hasPermission() unchanged instead of growing a second, divergent copy of the
 * permission rules. owner_id and user_id are read from that session and the
 * body's versions are ignored - a client can no longer name the shop it talks
 * about.
 *
 * WHY A SEPARATE SESSION DIRECTORY
 * --------------------------------
 * Mobile sign-ins have to survive a phone being idle for days; web sessions
 * expire in ~24 minutes. PHP's garbage collector only cleans the save path of
 * the process that happens to trigger it, so sharing admin/sessions would let
 * an ordinary web request delete idle mobile session files and log cashiers out
 * mid-shift. Mobile sessions therefore live in their own directory with their
 * own lifetime, and carry an explicit expires_at as well so collection timing
 * is never what decides whether a phone is still signed in.
 */

require_once __DIR__ . '/db.php';
// The token register: every mobile sign-in writes a row here so an admin can name
// the devices an account holds and switch one off. config/auth_sessions.php
// explains why a row is not the credential itself, only its fingerprint and its
// expiry; the PHP session file remains what actually authenticates the call.
require_once __DIR__ . '/auth_sessions.php';

/** Mobile sessions stay valid this long, refreshed on each call. */
define('MOBILE_SESSION_TTL', 2592000); // 30 days

/**
 * The permission keys the staff app is allowed to be told about. This is the
 * list the web sidebars and pages gate on, collected so the app can decide what
 * to render without duplicating the rules.
 *
 * Every key here has to exist on the Roles page, because a key nothing can be
 * granted is reported to the app as false forever - 'expense' used to sit in this
 * list while the feature is really called 'cashbook', so the app was told the
 * account could never open it.
 */
function mobilePermissionKeys()
{
    return [
        'dashboard', 'pos', 'sales', 'sales_delete', 'returns', 'reports',
        'products', 'categories', 'variables', 'stock', 'suppliers', 'transfers',
        'stores', 'customers', 'marketing', 'vouchers', 'cashbook', 'staff',
        'users', 'roles', 'settings', 'barcode_settings', 'google_contacts', 'ecommerce',
    ];
}

/**
 * Start (or resume) a session in the mobile-only save path.
 *
 * use_cookies is off on purpose: the credential arrives in a header, and a
 * response that also sets a cookie would suggest to the next reader that a
 * cookie is what authenticates these calls.
 *
 * @param string|null $id Existing session id to resume, or null for a fresh one.
 */
function mobileSessionStart($id = null)
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $dir = __DIR__ . '/../sessions_mobile';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // The directory is inside the document root, so it is reachable by URL
    // unless something says otherwise. A session id plus a readable file in
    // there is a signed-in account.
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }

    session_save_path($dir);
    @ini_set('session.use_cookies', 0);
    @ini_set('session.use_strict_mode', 1);
    @ini_set('session.gc_maxlifetime', (string)MOBILE_SESSION_TTL);

    if ($id !== null) {
        session_id($id);
    }
    session_start();
}

/**
 * The bearer token from the request, or '' when there is none.
 */
function mobileBearerToken()
{
    $header = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower(trim($name)) === 'authorization') { $header = trim($value); break; }
        }
    }
    if ($header === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    }
    if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    if (stripos($header, 'bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

/**
 * JSON error + HTTP status, then stop. The app keys its "go back to the login
 * screen" behaviour off the 401 status, so the two must travel together.
 */
function mobileDeny($message, $status = 401)
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message, 'auth' => false]);
    exit;
}

/**
 * Turn a verified user row into a live session and return what the app needs to
 * build its menu.
 *
 * Called by login.php after it has checked the password. The id is regenerated
 * so whatever id the request arrived with cannot be the one that gets issued.
 *
 * @return array{token:string,permissions:array<string,bool>,plan:string|null,is_admin:bool}
 */
function mobileIssueSession(array $user)
{
    mobileSessionStart();
    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['store_id']      = $user['store_id'] ?? null;
    $_SESSION['owner_id']      = $user['owner_id'] ?? null;
    $_SESSION['last_activity'] = time();
    $_SESSION['mobile_expires_at'] = time() + MOBILE_SESSION_TTL;

    // Read while the session is open: the permission and plan helpers resolve
    // against it, and after the close they would start it all over again.
    $permissions = mobilePermissionMap();
    $plan        = currentPlanSlug();
    $isAdmin     = in_array((string)$user['role'], adminSlugs(), true);
    $token       = session_id();

    session_write_close();

    // Registered after the session is written and before it is handed back, so the
    // row and the file describe the same token. It cannot fail the sign-in: a
    // token with no row is exactly what every token was before this table existed
    // - signed in, and not revocable until it is issued again.
    try {
        authSessionIssue(getDB(), (int)$user['id'], $token, 'mobile', MOBILE_SESSION_TTL);
    } catch (Throwable $e) {
        error_log('mobileIssueSession register: ' . $e->getMessage());
    }

    return ['token' => $token, 'permissions' => $permissions, 'plan' => $plan, 'is_admin' => $isAdmin];
}

/**
 * What a mobile endpoint is allowed to act on: who is signed in, and which shop.
 *
 * Ends the request with 401 when there is no live sign-in, and with 403 when
 * $permission is named and this account's role or plan does not carry it - so an
 * endpoint that serves reports cannot be reached by a cashier even by calling the
 * URL directly.
 *
 * @param string|null   $permission required hasPermission() key
 * @param callable|null $whileOpen  runs just before the session closes; whatever it
 *                                  returns comes back under the 'data' key. Any
 *                                  hasPermission() call a caller makes itself has to
 *                                  go through this window: once the session is closed,
 *                                  isLoggedIn() reaches startSecureSession(), which
 *                                  points PHP at the WEB session directory, finds no
 *                                  file for this token there and starts a blank
 *                                  session - so every key answers false and a
 *                                  shopkeeper is told their account opens nothing.
 * @return array{user_id:int,owner_id:int,store_id:int|null,role:string,is_admin:bool,data:mixed}
 */
function mobileAuth($permission = null, $whileOpen = null)
{
    $token = mobileBearerToken();
    // PHP session ids from the default handler are 26-32 chars of [a-z0-9], and
    // use_strict_mode rejects an id that has no file behind it. The shape check
    // is so a junk header costs one comparison rather than a file open.
    if ($token === '' || !preg_match('/^[a-z0-9]{20,64}$/i', $token)) {
        mobileDeny('Please sign in again.');
    }

    mobileSessionStart($token);

    // The register gets the first word. A row that says this token was revoked - by
    // its own sign-out, by a password reset, or by an admin signing an account out
    // everywhere - ends the call and throws the file away, so "logged out" means
    // logged out on the next request rather than whenever the thirty days run out.
    // A row that does not exist is not a refusal: every token issued before
    // auth_sessions existed has none, and treating that as death would sign every
    // phone in the country out on the day this file is uploaded. Those keep
    // authenticating against their session file until they sign in again and get a
    // row - see config/auth_sessions.php.
    $registered = authSessionState(getDB(), $token);
    if ($registered === 'revoked' || $registered === 'expired') {
        session_unset();
        session_destroy();
        mobileDeny($registered === 'revoked'
            ? 'This device was signed out. Please sign in again.'
            : 'Your session expired. Please sign in again.');
    }

    if (!isLoggedIn() || empty($_SESSION['mobile_expires_at']) || $_SESSION['mobile_expires_at'] < time()) {
        session_unset();
        session_destroy();
        mobileDeny('Your session expired. Please sign in again.');
    }

    $userId  = (int)$_SESSION['user_id'];
    $ownerId = (int)($_SESSION['owner_id'] ?: $userId);

    // The account could have been disabled or deleted since the phone signed in.
    // Reading the row also refreshes the expiry, so an in-use phone is never cut
    // off in the middle of a shift.
    try {
        $st = getDB()->prepare("SELECT status, role FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        session_write_close();
        mobileDeny('Sign-in could not be verified.', 500);
    }
    if (!$row || $row['status'] !== 'active') {
        session_unset();
        session_destroy();
        mobileDeny('This account is no longer active.');
    }

    $role = (string)$row['role'];
    $_SESSION['user_role']     = $role;
    $_SESSION['last_activity'] = time();
    $_SESSION['mobile_expires_at'] = time() + MOBILE_SESSION_TTL;

    $isAdmin = in_array($role, adminSlugs(), true);

    // Seen, and good for another thirty days from now, in the register as well as
    // in the session file. Same reasoning as the file's own refresh above: a phone
    // in active use must not be cut off mid-shift by a date that was written when
    // it last signed in.
    authSessionTouch(getDB(), $token, MOBILE_SESSION_TTL);

    // The permission check happens while the session is still open on purpose.
    // hasPermission() runs through isLoggedIn(), and once the session is closed
    // that call starts it again - which would take the file lock back and put
    // the app's parallel requests in a queue, exactly what closing it avoids.
    if ($permission !== null && !$isAdmin && !hasPermission($permission)) {
        session_write_close();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account cannot use that part of the app.', 'auth' => true]);
        exit;
    }

    // Done writing. Holding the session file open while the endpoint runs its
    // queries would serialise the app's parallel calls to a trickle.
    $data = is_callable($whileOpen) ? $whileOpen() : null;
    session_write_close();

    return [
        'user_id'  => $userId,
        'owner_id' => $ownerId,
        'store_id' => isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] ?: null : null,
        'role'     => $role,
        'is_admin' => $isAdmin,
        'data'     => $data,
    ];
}

/**
 * Every permission this account holds, for the app to build its menu from.
 * Called from login.php while the session it reads is still the live one.
 */
function mobilePermissionMap()
{
    $out = [];
    foreach (mobilePermissionKeys() as $key) {
        $out[$key] = hasPermission($key);
    }
    return $out;
}

/**
 * CORS for the mobile endpoints, plus the preflight answer.
 *
 * The header list now has to include Authorization, or the app's own requests
 * would be blocked in any browser-based build of the app.
 */
function mobileCors()
{
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
