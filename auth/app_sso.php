<?php
/**
 * Sign the staff app's WebView in with a one-time code.
 *
 * The app's own credential is a bearer header, which a WebView cannot attach to a
 * navigation - so the app first asks mobile_api/sso_link.php for a code, opens
 * this page with it, and the page writes the session auth/login.php would have
 * written after a correct password. Every admin page's own isLoggedIn() and
 * hasPermission() gate then applies exactly as it does on a laptop, which is the
 * point: the WebView is not a second, softer way into the back office.
 *
 * The code is spent here whether or not it works (see mobileSsoRedeem), so a
 * failed attempt cannot be retried by whoever else saw the URL.
 */

require_once '../config/db.php';
require_once '../config/mobile_sso.php';

startSecureSession();

$payload = mobileSsoRedeem($_GET['code'] ?? '');
if ($payload === null) {
    redirect('login.php');
}

try {
    $stmt = getDB()->prepare("SELECT id, name, email, role, status, store_id, owner_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $user = false;
}

// The code was issued at most two minutes ago, but an account can be disabled or
// deleted inside that window, and a session the password form would now refuse is
// not one this page should hand out either.
//
// The role is deliberately not checked here any more. This used to be admin-only,
// which left a manager offered a page by the app's menu standing in front of a
// login screen. Authorisation is not weaker for it: the session carries this
// account's own role, and every admin page still runs its own hasPermission() gate
// through admin/includes/header.php before it renders.
if (!$user || $user['status'] !== 'active') {
    redirect('login.php');
}

session_regenerate_id(true);

$_SESSION['user_id']       = $user['id'];
$_SESSION['user_name']     = $user['name'];
$_SESSION['user_email']    = $user['email'];
$_SESSION['user_role']     = $user['role'];
$_SESSION['store_id']      = $user['store_id'];
$_SESSION['owner_id']      = $user['owner_id'];
$_SESSION['last_activity'] = time();

// Where to land. A page name is only ever taken as a filename inside admin/ - no
// slashes, no scheme - so this cannot be pointed at another host or another
// directory, and the page itself still checks its own permission.
$page = (string)($_GET['page'] ?? '');
if ($page !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,40}\.php$/', $page) && is_file(__DIR__ . '/../admin/' . $page)) {
    redirect('../admin/' . $page);
}

$landing = defaultLandingPage();
redirect('../admin/' . ($landing !== null ? $landing : 'dashboard.php'));
