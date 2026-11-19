<?php
/**
 * Mobile API - one-time link into the web admin.
 *
 * The app has four screens; the back office has twenty-odd, and all of them are
 * laid out for a phone as well. Any account the menu offers a page to gets that
 * page inside the app through a WebView, and this is the step that lets the WebView
 * in without typing the password twice - see config/mobile_sso.php for why a
 * spent-after-use code travels instead of the bearer token.
 *
 * This used to refuse everybody but the administrator. That was reasonable while
 * the app decided for itself which shortcuts to draw - it drew them for admins only
 * - but the menu now comes from the server, filtered by the account's own
 * permissions, so the rows a manager is offered are exactly the pages their role
 * carries. Answering "no" here afterwards only made the two screens disagree.
 *
 * Nothing about authorisation is weakened: the code opens a session for THIS
 * account, with this account's role, and admin/includes/header.php runs
 * hasPermission() on every page inside it - the same check that already decides the
 * account's own web login.
 */

require_once '../config/mobile_auth.php';
require_once '../config/mobile_sso.php';
mobileCors();

$auth = mobileAuth();

echo json_encode([
    'success'    => true,
    'code'       => mobileSsoIssue($auth),
    'expires_in' => MOBILE_SSO_TTL,
]);
