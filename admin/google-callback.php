<?php
/**
 * Google OAuth 2.0 Callback Handler
 * POS System - Bangladesh
 */

require_once '../config/db.php';
require_once '../config/google_contacts.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'] ?? $currentUser['id'];

// Check for error from Google
if (isset($_GET['error'])) {
    $oauthError = $_GET['error'];
    $oauthErrorDesc = $_GET['error_description'] ?? '';

    // access_denied with no description is Google's generic "consent screen
    // refused you" response. It is almost always the app sitting in Testing
    // status with an account that was never added as a test user, so point at
    // that instead of showing the raw code, which tells the shop owner nothing.
    if ($oauthError === 'access_denied' && $oauthErrorDesc === '') {
        setFlash('danger',
            'Google refused the authorization (access_denied). This means the Google account you signed in with is not allowed to use this app yet. '
            . 'Open Google Cloud Console -> APIs &amp; Services -> OAuth consent screen -> Test users, and add that exact Gmail address, then try again.'
        );
    } else {
        setFlash('danger', 'Google Authorization failed: ' . htmlspecialchars($oauthError)
            . ($oauthErrorDesc !== '' ? ' - ' . htmlspecialchars($oauthErrorDesc) : ''));
    }

    redirect('google-contacts.php');
}

// Check for code
if (!isset($_GET['code'])) {
    setFlash('danger', 'Invalid response from Google: No authorization code received.');
    redirect('google-contacts.php');
}

$code = $_GET['code'];

// Must be byte-identical to the redirect_uri Google was handed at the start of
// the flow, or the token exchange comes back redirect_uri_mismatch. Both sides
// now call the same builder so they cannot drift apart.
$redirectUri = googleOAuthRedirectUri();

$res = exchangeGoogleAuthCode($code, $redirectUri, $ownerId);

if ($res['success']) {
    setFlash('success', 'Google Contacts connected successfully! New customers will now sync to your Google Account.');
} else {
    setFlash('danger', 'Failed to connect Google Account: ' . $res['message']);
}

redirect('google-contacts.php');
