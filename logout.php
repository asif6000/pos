<?php
/**
 * POS System — Logout
 * Destroys session and redirects to the correct login portal.
 *   - Super-admin (login_type = 'admin') → /admin/login.php
 *   - Everyone else                      → /auth/login.php
 */

require_once 'config/db.php';
startSecureSession();

// Remember which portal this user logged in from BEFORE we destroy the session
$loginType = $_SESSION['login_type'] ?? 'user';

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Redirect to the matching login portal
if ($loginType === 'admin') {
    header('Location: admin/login.php');
} else {
    header('Location: auth/login.php');
}
exit;
