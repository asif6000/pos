<?php
/**
 * Handing a signed-in phone a web session, once.
 *
 * WHY THIS EXISTS
 * ---------------
 * The staff app is four screens; the web admin is twenty-odd, and all of them are
 * now usable on a phone. So an account that the menu offers a back-office page to
 * gets to open that page from inside the app rather than being told to go find a
 * laptop, and this is the bridge that does it.
 *
 * A WebView cannot authenticate with the app's bearer token, though: it navigates
 * with a URL and proves itself with a cookie, and the two session stores are
 * deliberately separate directories (see config/mobile_auth.php for why). So the
 * app asks this server for a short one-time code, and the WebView redeems that
 * code for an ordinary web session - the same keys auth/login.php writes after a
 * correct password, so every page's isLoggedIn()/hasPermission() gate applies
 * unchanged and nothing about authorisation is weaker inside the WebView than on
 * the desktop.
 *
 * WHY A CODE AND NOT THE TOKEN ITSELF
 * -----------------------------------
 * The mobile token is good for 30 days and is the master key to the account. A
 * URL is a leaky carrier for something like that: it lands in Apache access logs,
 * in the WebView's history, and in any Referer header the page's assets produce.
 * So what travels in the URL is a 128-bit value that is already spent the second
 * it is read, and that dies unused after MOBILE_SSO_TTL seconds. The long-lived
 * secret only ever travels in a header.
 *
 * The codes live in the mobile session directory, which is inside the document
 * root but denied by the .htaccess mobileSessionStart() writes - so they are
 * reachable by URL no more than the sessions themselves are.
 */

require_once __DIR__ . '/db.php';

/** How long an unredeemed code stays valid. Enough for one page load. */
define('MOBILE_SSO_TTL', 120);

/**
 * The directory the codes are kept in, created and denied-by-URL on demand.
 */
function mobileSsoDir()
{
    $dir = __DIR__ . '/../sessions_mobile';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $deny = $dir . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/**
 * Issue a code for an account that has just been verified by mobileAuth().
 *
 * @param array $auth the scope mobileAuth() returned
 * @return string 32 hex characters
 */
function mobileSsoIssue(array $auth)
{
    $dir = mobileSsoDir();

    // Anything older than a few minutes can no longer be redeemed, and a client
    // that requests codes and never uses them would otherwise leave a growing
    // pile of live credentials on disk.
    foreach ((array)@glob($dir . '/sso_*.json') as $stale) {
        if (@filemtime($stale) < time() - 900) {
            @unlink($stale);
        }
    }

    $code = bin2hex(random_bytes(16));
    $payload = [
        'user_id'   => (int)$auth['user_id'],
        'owner_id'  => (int)$auth['owner_id'],
        'store_id'  => $auth['store_id'],
        'role'      => (string)$auth['role'],
        'issued_at' => time(),
    ];

    // 0600 because the file is a credential, and the web server is the only
    // reader that needs it.
    @file_put_contents($dir . '/sso_' . $code . '.json', json_encode($payload), 0600);

    return $code;
}

/**
 * Spend a code, returning who it was issued to, or null when it is unusable.
 *
 * rename() is the claim: two simultaneous requests for the same code cannot both
 * succeed, because only one of them moves the file.
 *
 * @param string $code
 * @return array{user_id:int,owner_id:int,store_id:int|null,role:string}|null
 */
function mobileSsoRedeem($code)
{
    $code = trim((string)$code);
    if (!preg_match('/^[a-f0-9]{32}$/', $code)) {
        return null;
    }

    $dir  = mobileSsoDir();
    $file = $dir . '/sso_' . $code . '.json';
    $used = $file . '.used';

    if (!@rename($file, $used)) {
        return null; // never existed, or already spent
    }

    $raw = @file_get_contents($used);
    @unlink($used);

    $payload = json_decode((string)$raw, true);
    if (!is_array($payload) || empty($payload['user_id'])) {
        return null;
    }
    if ((int)($payload['issued_at'] ?? 0) + MOBILE_SSO_TTL < time()) {
        return null;
    }

    return [
        'user_id'  => (int)$payload['user_id'],
        'owner_id' => (int)$payload['owner_id'],
        'store_id' => $payload['store_id'] ?? null,
        'role'     => (string)$payload['role'],
    ];
}
