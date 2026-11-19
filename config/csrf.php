<?php
/**
 * Qoder System - CSRF tokens
 *
 * Every other form in this app posts bare, which is why a purchase - a form that
 * moves stock and cash - carries a token instead. One session-wide token per
 * login: cheap to issue, and it still proves the POST came from a page this
 * browser loaded.
 *
 * Existing pages are untouched on purpose; adding a field to a form whose
 * handler does not verify it changes nothing but the markup, so the retrofit is
 * a separate pass rather than a side effect of this module.
 */

/** The token for this session, created on first use. */
function csrfToken()
{
    startSecureSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside a <form>. */
function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Same value for a fetch() header. */
function csrfMeta()
{
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * True when the request carries the session's token.
 *
 * Reads the posted field first and the X-CSRF-Token header second, because the
 * product search on the purchase form is a fetch rather than a form post.
 */
function csrfCheck($method = 'POST')
{
    if (strtoupper((string)$method) !== 'POST') {
        return true;
    }
    $sent = '';
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $sent = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $sent = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return $sent !== '' && hash_equals(csrfToken(), $sent);
}

/**
 * Guard a POST handler: flash, then send the user back where the form came from.
 *
 * The redirect target is taken from a whitelist of this module's own pages, so a
 * crafted `back` cannot turn a rejection into an open redirect.
 */
function csrfRequire($back = 'dashboard.php', $allowed = [])
{
    if (csrfCheck()) {
        return;
    }
    setFlash('danger', 'Your session expired before the form was sent. Please try again.');
    if (isset($_POST['back']) && in_array((string)$_POST['back'], $allowed, true)) {
        $back = (string)$_POST['back'];
    }
    redirect($back);
}
