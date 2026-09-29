<?php
/**
 * Google Contacts Integration Service
 * POS System - Bangladesh
 * 
 * Method: Google People API (OAuth 2.0)
 *
 * The Google Apps Script webhook that used to be offered here is gone. It was
 * the easier of the two to set up, but it pushed every customer through a
 * third-party script URL, and nothing in this codebase needs it. Sync stays off
 * until a shop connects a Google account, so removing it costs nothing.
 */

require_once __DIR__ . '/db.php';

/**
 * Get all Google Contacts integration settings for a specific store owner
 */
function getGoogleContactsSettings($ownerId)
{
    $db = getDB();
    $settings = [
        'google_contacts_enabled' => '0',
        'google_contacts_mode'    => 'oauth',
        'google_client_id'        => '',
        'google_client_secret'    => '',
        'google_access_token'     => '',
        'google_refresh_token'    => '',
        'google_token_expires_at' => 0,
        'google_last_sync_status' => '',
        'google_last_sync_time'   => ''
    ];

    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ? AND setting_key LIKE 'google_%'");
        $stmt->execute([$ownerId]);
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        error_log("Error reading Google Contacts settings: " . $e->getMessage());
    }

    return $settings;
}

/**
 * Save a single Google Contacts setting
 */
function saveGoogleContactsSetting($key, $value, $ownerId)
{
    $db = getDB();
    try {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id) 
                              VALUES (?, ?, ?) 
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, (string)$value, $ownerId]);
        return true;
    } catch (Exception $e) {
        error_log("Error saving Google Contacts setting [$key]: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if Google Contacts sync is ready/active for owner
 */
/**
 * Tear down a Google connection whose grant is no longer valid.
 *
 * Called when Google answers invalid_grant. Everything that would make
 * isGoogleContactsReady() and the Connect button disagree with each other
 * has to go: the dead refresh token, the stale access token, the old expiry
 * and the enabled flag. Leaves client_id and client_secret alone, because
 * those are still correct and re-authorising only needs the consent screen
 * again, not a full re-setup.
 */
function clearGoogleConnection($ownerId, $reason = '')
{
    saveGoogleContactsSetting('google_access_token', '', $ownerId);
    saveGoogleContactsSetting('google_refresh_token', '', $ownerId);
    saveGoogleContactsSetting('google_token_expires_at', '0', $ownerId);
    saveGoogleContactsSetting('google_contacts_enabled', '0', $ownerId);
    saveGoogleContactsSetting('google_last_sync_status', 'Disconnected (' . $reason . ')', $ownerId);
    saveGoogleContactsSetting('google_last_sync_time', date('Y-m-d H:i:s'), $ownerId);
}

/**
 * Build the OAuth redirect_uri exactly once, for both the page that starts the
 * flow and the callback that finishes it.
 *
 * Google compares this string character for character, so the two places that
 * need it were building it two different ways (one from REQUEST_URI, one from
 * dirname(REQUEST_URI)). Any disagreement surfaces as redirect_uri_mismatch
 * with nothing to indicate which side drifted, so the construction is shared
 * now.
 *
 * Both google-contacts.php and google-callback.php sit in the same directory,
 * so the folder holding the running script is also the folder the callback
 * lives in. That keeps this correct wherever the project is deployed, instead
 * of assuming a fixed docroot layout.
 *
 * Returns the full callback URL to paste into Authorized redirect URIs.
 */
function googleOAuthRedirectUri()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $scheme = $https ? 'https' : 'http';

    // HTTP_HOST is attacker-controlled, so it is filtered to characters that
    // cannot appear in a URL authority or smuggle in a path. It only ever
    // reaches a string that is shown to the shop owner and compared by Google,
    // never a fetch target, so a spoofed Host cannot redirect a request.
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $path = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($path === '') {
        // SCRIPT_NAME is absent on CLI and under some CGI setups. REQUEST_URI
        // is the fallback; strip the query first so it cannot leak in.
        $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    }

    $dir = rtrim(dirname($path), '/');

    return $scheme . '://' . $host . $dir . '/google-callback.php';
}

/**
 * Check all callers of getGoogleContactsSettings() so a disconnected shop is
 * never left believing it is still syncing.
 */
function isGoogleContactsReady($ownerId)
{
    $settings = getGoogleContactsSettings($ownerId);
    if (($settings['google_contacts_enabled'] ?? '0') !== '1') {
        return false;
    }

    // Only OAuth remains. Sync counts as ready when there is a usable token,
    // regardless of the stored mode string, so any leftover 'webhook' row from
    // an older install cannot strand a shop that has already connected.
    return !empty($settings['google_refresh_token']) || !empty($settings['google_access_token']);
}

/**
 * Main Sync Function: Called whenever a customer is created or updated
 * Safely wraps calls so failure NEVER interrupts POS billing or customer creation.
 *
 * @param array $customer Customer array (name, phone, email, address, optional id)
 * @param int|null $ownerId Store owner ID
 * @return array ['success' => bool, 'message' => string, 'id' => string|null]
 */
function syncCustomerToGoogleContacts($customer, $ownerId = null)
{
    if (empty($ownerId)) {
        $currentUser = getCurrentUser();
        $ownerId = $currentUser['owner_id'] ?? null;
    }

    if (empty($ownerId)) {
        return ['success' => false, 'message' => 'Owner ID not found'];
    }

    $settings = getGoogleContactsSettings($ownerId);
    if (($settings['google_contacts_enabled'] ?? '0') !== '1') {
        return ['success' => false, 'message' => 'Google Contacts sync is disabled in settings.'];
    }

    $result = ['success' => false, 'message' => 'No sync method configured'];

    try {
        if (!empty($settings['google_access_token']) || !empty($settings['google_refresh_token'])) {
            $result = sendCustomerToGooglePeopleApi($settings, $customer, $ownerId);
        }

        // If successful and we have the customer's database ID, record the sync
        if ($result['success'] && !empty($customer['id'])) {
            $db = getDB();
            $contactId = $result['id'] ?? 'synced';
            $stmt = $db->prepare("UPDATE customers SET google_contact_id = ?, google_synced_at = NOW() WHERE id = ?");
            $stmt->execute([$contactId, $customer['id']]);
        }

        // Record status in settings
        $statusMsg = ($result['success'] ? 'Success: ' : 'Failed: ') . ($result['message'] ?? 'Unknown');
        saveGoogleContactsSetting('google_last_sync_status', $statusMsg, $ownerId);
        saveGoogleContactsSetting('google_last_sync_time', date('Y-m-d H:i:s'), $ownerId);

    } catch (Exception $e) {
        $result = ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        saveGoogleContactsSetting('google_last_sync_status', $result['message'], $ownerId);
        error_log("Google Contacts sync error: " . $e->getMessage());
    }

    return $result;
}

/**
 * Send contact via Google People API (OAuth 2.0)
 */
function sendCustomerToGooglePeopleApi($settings, $customer, $ownerId)
{
    $accessToken = $settings['google_access_token'] ?? '';
    $expiresAt = (int)($settings['google_token_expires_at'] ?? 0);

    // If token is expired or close to expiring, refresh it
    if (time() >= ($expiresAt - 120)) {
        $refreshRes = refreshGoogleAccessToken($settings, $ownerId);
        if ($refreshRes['success']) {
            $accessToken = $refreshRes['access_token'];
        } else {
            return ['success' => false, 'message' => 'Token refresh failed: ' . $refreshRes['message']];
        }
    }

    if (empty($accessToken)) {
        return ['success' => false, 'message' => 'No valid Google access token available.'];
    }

    $nameParts = explode(' ', trim($customer['name']), 2);
    $givenName = $nameParts[0];
    $familyName = $nameParts[1] ?? '';

    $personData = [
        'names' => [
            [
                'givenName' => $givenName,
                'familyName' => $familyName
            ]
        ],
        'userDefined' => [
            ['key' => 'Source', 'value' => 'POS Customer']
        ]
    ];

    if (!empty($customer['phone'])) {
        $personData['phoneNumbers'] = [
            ['value' => $customer['phone'], 'type' => 'mobile']
        ];
    }

    if (!empty($customer['email'])) {
        $personData['emailAddresses'] = [
            ['value' => $customer['email'], 'type' => 'work']
        ];
    }

    if (!empty($customer['address'])) {
        $personData['postalAddresses'] = [
            ['formattedValue' => $customer['address'], 'type' => 'home']
        ];
    }

    $url = 'https://people.googleapis.com/v1/people:createContact';

    // See marketingBridgeCall() for why this is checked before the call and not
    // caught after it: a missing php-curl extension makes curl_init() throw an
    // Error, which no catch (Exception) will intercept.
    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return [
            'success' => false,
            'message' => 'This server has no cURL support (php-curl is not enabled), so Google Contacts cannot be reached. The customer was still saved.',
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($personData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => 'cURL error: ' . $curlError];
    }

    $resJson = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'message' => 'Contact saved in Google People API',
            'id'      => $resJson['resourceName'] ?? null
        ];
    }

    $errMsg = $resJson['error']['message'] ?? "People API error (HTTP $httpCode)";
    return ['success' => false, 'message' => $errMsg];
}

/**
 * Refresh Google OAuth Access Token
 */
function refreshGoogleAccessToken($settings, $ownerId)
{
    $refreshToken = $settings['google_refresh_token'] ?? '';
    $clientId = $settings['google_client_id'] ?? '';
    $clientSecret = $settings['google_client_secret'] ?? '';

    if (empty($refreshToken) || empty($clientId) || empty($clientSecret)) {
        return ['success' => false, 'message' => 'Missing refresh token or client credentials'];
    }

    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return ['success' => false, 'message' => 'This server has no cURL support (php-curl is not enabled).'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Certificate verification stays ON. It used to be disabled here, which
    // meant the bearer token and the customer's name/phone/address travelled
    // over a connection any network between the shop and Google could
    // read or rewrite. Every other call in this file does the same.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => $curlError];
    }

    $json = json_decode($response, true);
    if ($httpCode === 200 && !empty($json['access_token'])) {
        $newToken = $json['access_token'];
        $expiresAt = time() + (int)($json['expires_in'] ?? 3600);
        saveGoogleContactsSetting('google_access_token', $newToken, $ownerId);
        saveGoogleContactsSetting('google_token_expires_at', $expiresAt, $ownerId);

        return [
            'success'      => true,
            'access_token' => $newToken,
            'expires_at'   => $expiresAt
        ];
    }

    $oauthError = $json['error'] ?? '';

    // invalid_grant means the grant is gone for good, not that this request was
    // malformed. Google returns it when the user revoked the app, and also -
    // predictably on a schedule - when the consent screen is still in "Testing"
    // status, where refresh tokens are deliberately cut after 7 days. Left
    // alone the stored token stays put, google_contacts_enabled stays '1', and
    // every single customer save from then on pays for a doomed round trip to
    // oauth2.googleapis.com and records a failure. Clearing the connection here
    // makes the shop page flip back to "Not Connected" with the Connect button,
    // so the fix is visible instead of a silent dead feature.
    if ($oauthError === 'invalid_grant') {
        clearGoogleConnection($ownerId, 'invalid_grant');
        return [
            'success' => false,
            'message' => 'Your Google connection has expired and was disconnected. '
                . 'This is expected while the OAuth consent screen is in "Testing" status - Google kills those '
                . 'refresh tokens after 7 days. Click "Connect with Google" to link again, or publish the app to '
                . '"In production" so the connection stops breaking.'
        ];
    }

    $err = $json['error_description'] ?? ($oauthError ?: 'Token refresh failed');
    return ['success' => false, 'message' => $err];
}

/**
 * Exchange Authorization Code for Access & Refresh Tokens
 */
function exchangeGoogleAuthCode($code, $redirectUri, $ownerId)
{
    $settings = getGoogleContactsSettings($ownerId);
    $clientId = $settings['google_client_id'] ?? '';
    $clientSecret = $settings['google_client_secret'] ?? '';

    if (empty($clientId) || empty($clientSecret)) {
        return ['success' => false, 'message' => 'Client ID and Secret are not configured.'];
    }

    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return ['success' => false, 'message' => 'This server has no cURL support (php-curl is not enabled).'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message' => $curlError];
    }

    $json = json_decode($response, true);
    if ($httpCode === 200 && !empty($json['access_token'])) {
        saveGoogleContactsSetting('google_access_token', $json['access_token'], $ownerId);
        if (!empty($json['refresh_token'])) {
            saveGoogleContactsSetting('google_refresh_token', $json['refresh_token'], $ownerId);
        }
        $expiresAt = time() + (int)($json['expires_in'] ?? 3600);
        saveGoogleContactsSetting('google_token_expires_at', $expiresAt, $ownerId);
        saveGoogleContactsSetting('google_contacts_enabled', '1', $ownerId);
        saveGoogleContactsSetting('google_contacts_mode', 'oauth', $ownerId);

        return ['success' => true, 'message' => 'Google Account connected successfully!'];
    }

    $err = $json['error_description'] ?? ($json['error'] ?? "OAuth exchange failed (HTTP $httpCode)");
    return ['success' => false, 'message' => $err];
}

/**
 * Sync all unsynced customers for an owner
 */
function syncAllUnsyncedCustomers($ownerId)
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, phone, email, address FROM customers WHERE owner_id = ? AND (google_synced_at IS NULL OR google_contact_id IS NULL) ORDER BY id ASC");
    $stmt->execute([$ownerId]);
    $unsynced = $stmt->fetchAll();

    $syncedCount = 0;
    $failedCount = 0;

    foreach ($unsynced as $cust) {
        $res = syncCustomerToGoogleContacts($cust, $ownerId);
        if ($res['success']) {
            $syncedCount++;
        } else {
            $failedCount++;
        }
        // Small delay to prevent rate limits
        usleep(100000); // 0.1s
    }

    return [
        'total'  => count($unsynced),
        'synced' => $syncedCount,
        'failed' => $failedCount
    ];
}
