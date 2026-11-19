<?php
/**
 * Google sign-in: verifying the credential the browser hands us.
 *
 * WHY THE TOKEN IS CHECKED HERE AT ALL
 * ------------------------------------
 * Google Identity Services runs in the page and calls a JavaScript function with
 * a JWT. Any client can put anything in that POST body, so treating the browser's
 * word as proof of who the visitor is means trusting a request anyone can forge -
 * the exact mistake the mobile API made until config/mobile_auth.php started
 * reading the shop out of the session instead of out of the request body. The id
 * token is therefore verified here, against Google's own public keys, before a
 * single line of it is believed: the signature, then the issuer, then that it was
 * minted for THIS client, then that it is still fresh, then that it came back to
 * this browser and nowhere else (the nonce).
 *
 * WHAT THE KEYS ARE AND WHY THEY ARE CACHED
 * -----------------------------------------
 * Google publishes its signing keys at https://www.googleapis.com/oauth2/v3/certs
 * and rotates them every few weeks. Fetching them per sign-in would make Google a
 * dependency of every login - and a slow or blocked host would then be unable to
 * sign anybody in. They are cached on disk for twelve hours, and a token whose
 * "kid" is not in the cache forces exactly one refresh, which is how a rotation
 * that lands mid-day is absorbed without asking Google on every request.
 *
 * RS256 and ES256 are both verified because Google's certificate set contains
 * both kinds of key. Any other algorithm in a token header is refused before the
 * keys are even looked at - "alg":"none" is the classic way of asking a library
 * to skip the signature.
 *
 * WHY THE EMAIL IS NOT THE IDENTITY
 * --------------------------------
 * users.google_sub (the token's "sub") is what an account is matched on. An email
 * address can be released, re-claimed and reassigned by Google, so keying an
 * account link on it would eventually hand one shop to whoever got the address
 * next. Email is used only as a one-time bridge: an existing password account may
 * adopt a Google identity when Google itself says the address is verified and
 * attached to that account, and after that the sub is the only thing that matters.
 *
 * WHAT THIS FILE DOES NOT DECIDE
 * ------------------------------
 * Nothing about sessions, roles or where to send the visitor - that is
 * authWebSessionFromUser() in config/auth_core.php, shared with every other
 * sign-in method so a Google login cannot quietly hold different permissions from
 * a password one.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_core.php';

/** Google's published keys. The kid/alg/usage fields are what we read from it. */
define('GOOGLE_SIGNIN_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs');

/** How long a fetched key set is reused. Rotation is absorbed by the kid miss path. */
define('GOOGLE_SIGNIN_JWKS_TTL', 43200); // 12 hours

/** Clock drift allowed on exp/nbf, because a shared host is not always exactly synced. */
define('GOOGLE_SIGNIN_CLOCK_LEEWAY', 120);

/** The two algorithms Google actually signs id tokens with. Nothing else is accepted. */
function googleSignInAllowedAlgs()
{
    return array('RS256', 'ES256');
}

/**
 * The installation's Google sign-in settings.
 *
 * Env first, then system_settings, because the two exist for different people:
 * a host with an environment panel can set GOOGLE_CLIENT_ID once, and a shop that
 * has none can put the same value in the database with one row. Both routes end
 * with the client ID, which is not a secret (it appears in the page's JavaScript)
 * - what must never be stored here is the client SECRET, which the browser flow
 * does not need and which a leaked settings row would then hand over.
 *
 * The per-owner google_client_id used by Google Contacts sync is deliberately not
 * consulted: one shop's OAuth client is not a site-wide login credential, and
 * reading it here would let any owner's row decide who can sign in to the whole
 * installation.
 *
 * @return array{enabled:bool,client_id:string,hosted_domain:string}
 */
function googleSignInConfig($db = null)
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $clientId = trim((string)getenv('GOOGLE_SIGNIN_CLIENT_ID'));
    if ($clientId === '') {
        $clientId = trim((string)getenv('GOOGLE_CLIENT_ID'));
    }
    $hosted  = trim((string)getenv('GOOGLE_HOSTED_DOMAIN'));
    $enabled = null;

    try {
        if ($db === null) {
            $db = getDB();
        }
        if ($clientId === '') {
            $clientId = authSystemSetting($db, 'google_signin_client_id');
        }
        if ($hosted === '') {
            $hosted = authSystemSetting($db, 'google_signin_hosted_domain');
        }
        $flag = authSystemSetting($db, 'google_signin_enabled', '');
        if ($flag !== '') {
            $enabled = ($flag === '1');
        }
    } catch (Throwable $e) {
        $db = null;
    }

    // Presence of a client ID is the switch. A separate enabled flag can only
    // turn the button off, so an install that sets the value without touching
    // settings cannot look configured and do nothing.
    $cached = array(
        'client_id'      => $clientId,
        'enabled'        => $clientId !== '' && $enabled !== false,
        'hosted_domain'  => $hosted,
    );
    return $cached;
}

/**
 * The value the page is allowed to know. Only ever the client ID - a token
 * request must be made against the same ID this file verifies with, so the
 * browser cannot be given one and the server quietly check another.
 *
 * @return array{enabled:bool,client_id:string}
 */
function googleSignInPublicConfig()
{
    $cfg = googleSignInConfig();
    return array('enabled' => $cfg['enabled'], 'client_id' => $cfg['client_id']);
}

/**
 * The nonce for this browser's sign-in, minted when the login page is drawn.
 *
 * Google puts it back inside the signed token, so a credential captured from
 * somebody else's session - or an old one replayed - fails here rather than
 * signing somebody in. One value per session, reused across reloads, and
 * removed as soon as it has been spent.
 */
function googleSignInNonce()
{
    startSecureSession();
    if (empty($_SESSION['google_signin_nonce'])) {
        $_SESSION['google_signin_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['google_signin_nonce'];
}

/**
 * Spend the nonce. True only when it matches.
 *
 * A wrong value is refused but leaves the stored nonce alone, because the one who
 * posted a wrong nonce proves nothing about the browser that holds the right one:
 * burning it there would turn a single hostile POST into a login page whose Google
 * button has stopped working for the shopkeeper sitting in front of it.
 */
function googleSignInConsumeNonce($claimed)
{
    startSecureSession();
    $stored = isset($_SESSION['google_signin_nonce']) ? (string)$_SESSION['google_signin_nonce'] : '';
    if ($stored === '' || !is_string($claimed) || !hash_equals($stored, $claimed)) {
        return false;
    }
    unset($_SESSION['google_signin_nonce']);
    return true;
}

/**
 * base64url, which is what JOSE uses and what PHP's base64_decode does not do:
 * a different alphabet, and no padding.
 */
function googleSignInB64UrlDecode($data)
{
    $b64 = strtr((string)$data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($b64, true);
    return $out === false ? null : $out;
}

/**
 * Google's public keys, from disk when the cache is fresh.
 *
 * @param bool $forceRefetch after a kid nobody recognises
 * @return array<int, array> JWKs
 */
function googleSignInJwks($forceRefetch = false)
{
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-google-jwks.json';

    if (!$forceRefetch && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < GOOGLE_SIGNIN_JWKS_TTL) {
        $decoded = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
            return $decoded['keys'];
        }
    }

    $body = '';
    if (appHasCurl()) {
        $ch = curl_init(GOOGLE_SIGNIN_JWKS_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $body = (string)curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('googleSignInJwks curl: ' . curl_error($ch));
        }
        curl_close($ch);
    }
    if ($body === '') {
        // A host without cURL still has to be able to sign people in. The default
        // SSL context verifies the peer, which is the point of the setting.
        $body = @file_get_contents(GOOGLE_SIGNIN_JWKS_URL, false, stream_context_create(array(
            'http' => array('timeout' => 6),
            'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
        )));
        if ($body === false) {
            $body = '';
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
        if ($body !== '') {
            error_log('googleSignInJwks: response was not a key set');
        }
        return array();
    }

    // Written whole rather than appended, and world-readable on purpose not at
    // all: these are public keys, but a directory a visitor can list is a
    // directory a visitor can read, and there is nothing here worth exposing.
    @file_put_contents($cacheFile, json_encode($decoded), LOCK_EX);
    @chmod($cacheFile, 0600);

    return $decoded['keys'];
}

/**
 * A DER length, which changes encoding at 128 and 256 bytes.
 */
function googleSignInDerLength($len)
{
    if ($len < 128) {
        return chr($len);
    }
    if ($len < 256) {
        return "\x81" . chr($len);
    }
    return "\x82" . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
}

/**
 * A DER sequence or primitive: tag, length, contents.
 */
function googleSignInDer($tag, $contents)
{
    return chr($tag) . googleSignInDerLength(strlen($contents)) . $contents;
}

/**
 * A DER INTEGER, with the leading zero that keeps a high bit a bit rather than a
 * sign.
 */
function googleSignInDerInteger($bytes)
{
    if ($bytes === '' ) {
        $bytes = "\x00";
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00" . $bytes;
    }
    return googleSignInDer(0x02, $bytes);
}

/**
 * PEM from a JWK, for the two key types Google signs with.
 *
 * openssl_verify() wants a PEM key, and the certificate endpoint publishes JWKs,
 * so the numbers have to be assembled into the DER each PEM wraps. Done by hand
 * because this installation has no composer, no vendor directory and no library
 * to do it - which is also why the code below is short enough to read at a glance
 * and check for the one mistake that matters: trusting the attacker's algorithm.
 *
 * @return string|null PEM, or null for a key type we do not handle
 */
function googleSignInJwkToPem(array $jwk)
{
    $kty = isset($jwk['kty']) ? $jwk['kty'] : '';

    if ($kty === 'RSA') {
        $n = googleSignInB64UrlDecode(isset($jwk['n']) ? $jwk['n'] : '');
        $e = googleSignInB64UrlDecode(isset($jwk['e']) ? $jwk['e'] : '');
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $der = googleSignInDer(0x30, googleSignInDerInteger($n) . googleSignInDerInteger($e));
        return "-----BEGIN RSA PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END RSA PUBLIC KEY-----\n";
    }

    if ($kty === 'EC' && (isset($jwk['crv']) ? $jwk['crv'] : '') === 'P-256') {
        $x = googleSignInB64UrlDecode(isset($jwk['x']) ? $jwk['x'] : '');
        $y = googleSignInB64UrlDecode(isset($jwk['y']) ? $jwk['y'] : '');
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        // id-ecPublicKey 1.2.840.10045.2.1 and prime256v1 1.2.840.10045.3.1.7,
        // the two OIDs a P-256 public key is described by.
        $algo = googleSignInDer(0x06, "\x2A\x86\x48\xCE\x3D\x02\x01")
              . googleSignInDer(0x06, "\x2A\x86\x48\xCE\x3D\x03\x01\x07");
        // A BIT STRING, prefixed by its unused-bit count, holding the uncompressed
        // point 0x04 || x || y.
        $point = googleSignInDer(0x03, "\x00\x04" . $x . $y);
        $der = googleSignInDer(0x30, googleSignInDer(0x30, $algo) . $point);
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    return null;
}

/**
 * A JWS signature is the fixed-width pair R || S; OpenSSL wants a DER sequence.
 */
function googleSignInSigRawToDer($sig)
{
    if (strlen($sig) !== 64) {
        return $sig;
    }
    return googleSignInDer(0x30,
        googleSignInDerInteger(ltrim(substr($sig, 0, 32), "\x00"))
      . googleSignInDerInteger(ltrim(substr($sig, 32), "\x00")));
}

/**
 * Try every key in the set until one of them verifies the signature.
 *
 * When the token names a "kid", only that key is tried - the rest of Google's
 * keys cannot have signed it, and trying them would let a token signed by a key
 * the site does not recognise pass simply because a later key in the list
 * happens to match.
 */
function googleSignInVerifyAgainstKeys(array $keys, $kid, $alg, $signingInput, $sig)
{
    $payload = $alg === 'ES256' ? googleSignInSigRawToDer($sig) : $sig;
    foreach ($keys as $jwk) {
        if (!is_array($jwk)) {
            continue;
        }
        if ($kid !== '' && (!isset($jwk['kid']) || (string)$jwk['kid'] !== $kid)) {
            continue;
        }
        if (isset($jwk['alg']) && $jwk['alg'] !== '' && (string)$jwk['alg'] !== $alg) {
            continue;
        }
        $pem = googleSignInJwkToPem($jwk);
        if ($pem === null) {
            continue;
        }
        // RS256 and ES256 are both SHA-256 over "header.payload".
        if (@openssl_verify($signingInput, $payload, $pem, OPENSSL_ALGO_SHA256) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Verify an id token and return its claims, or refuse it.
 *
 * Every refusal says why in a string meant for the log, and the visitor is given
 * the same plain sentence either way - a sign-in failure page that names which
 * check tripped is a free oracle for whoever is probing the endpoint.
 *
 * @return array{ok:bool, error:string, claims:array}
 *
 * @param bool $requireNonce compare the token's nonce with this browser session's
 *                           and spend it. Every web caller needs it; a native app
 *                           that fetches its own credential has no session nonce
 *                           to spend and passes false, taking the replay defence
 *                           from its own storage instead.
 */
function googleSignInVerifyIdToken($idToken, $clientId, $requireNonce = true)
{
    $fail = function ($reason) {
        return array('ok' => false, 'error' => $reason, 'claims' => array());
    };

    $parts = explode('.', (string)$idToken);
    if (count($parts) !== 3) {
        return $fail('The credential from Google was not complete.');
    }
    list($h, $p, $s) = $parts;

    $header = json_decode((string)googleSignInB64UrlDecode($h), true);
    $claims = json_decode((string)googleSignInB64UrlDecode($p), true);
    $sig    = googleSignInB64UrlDecode($s);
    if (!is_array($header) || !is_array($claims) || $sig === null) {
        return $fail('The credential from Google could not be read.');
    }

    $alg = isset($header['alg']) ? (string)$header['alg'] : '';
    if (!in_array($alg, googleSignInAllowedAlgs(), true)) {
        return $fail('The credential used an algorithm this site does not accept.');
    }

    $kid = isset($header['kid']) ? (string)$header['kid'] : '';
    $signingInput = $h . '.' . $p;

    $verified = googleSignInVerifyAgainstKeys(googleSignInJwks(false), $kid, $alg, $signingInput, $sig);

    if (!$verified && $kid !== '') {
        // A key we have never seen: refresh the cache once and try only that key,
        // which is how a rotation during business hours is absorbed.
        $verified = googleSignInVerifyAgainstKeys(googleSignInJwks(true), $kid, $alg, $signingInput, $sig);
    }

    if (!$verified) {
        return $fail('The credential from Google could not be verified.');
    }

    $iss = isset($claims['iss']) ? (string)$claims['iss'] : '';
    if ($iss !== 'https://accounts.google.com' && $iss !== 'accounts.google.com') {
        return $fail('The credential did not come from Google.');
    }

    $aud = isset($claims['aud']) ? $claims['aud'] : '';
    $auds = is_array($aud) ? $aud : array($aud);
    if (!in_array((string)$clientId, $auds, true)) {
        return $fail('The credential was issued for a different site.');
    }

    $now = time();
    if (!isset($claims['exp']) || $now > (int)$claims['exp'] + GOOGLE_SIGNIN_CLOCK_LEEWAY) {
        return $fail('The credential had expired. Please try again.');
    }
    if (isset($claims['nbf']) && $now + GOOGLE_SIGNIN_CLOCK_LEEWAY < (int)$claims['nbf']) {
        return $fail('The credential is not valid yet.');
    }

    if (empty($claims['sub'])) {
        return $fail('The credential did not identify an account.');
    }

    if ($requireNonce) {
        $claimedNonce = isset($claims['nonce']) ? (string)$claims['nonce'] : '';
        if ($claimedNonce === '' || !googleSignInConsumeNonce($claimedNonce)) {
            return $fail('The credential did not belong to this browser session.');
        }
    }

    $cfg = googleSignInConfig();
    if ($cfg['hosted_domain'] !== '') {
        $hd = isset($claims['hd']) ? (string)$claims['hd'] : '';
        if (strtolower($hd) !== strtolower($cfg['hosted_domain'])) {
            return $fail('Only accounts in the configured workspace may sign in here.');
        }
    }

    return array('ok' => true, 'error' => '', 'claims' => $claims);
}

/**
 * Which account this verified Google identity belongs to.
 *
 * Three answers, and the order is the security design:
 *   signin - the sub is already on an account, so this is the same person who
 *            used the password form yesterday and gets exactly their own rights;
 *   link   - no sub match, but the email Google says is verified and attached
 *            already has an account here. That account adopts the sub, once, and
 *            from then on only the sub matters. Never for an inactive account.
 *   none   - nobody here knows this person. Not an error and not a login: the
 *            caller hands them to registration, which is where a new account -
 *            and its shop - is decided, with terms accepted and a role chosen.
 *            Minting an owner from a button would reopen the hole
 *            auth/register.php was closed for.
 *
 * @return array{action:string, user:array|null, reason:string}
 */
function googleSignInResolve(array $claims)
{
    $none = function ($reason) {
        return array('action' => 'none', 'user' => null, 'reason' => $reason);
    };

    try {
        $db = getDB();
        authEnsureTables($db);

        $sub = (string)$claims['sub'];
        $st = $db->prepare("SELECT id, name, email, role, status, store_id, owner_id, google_sub
                            FROM users WHERE google_sub = ? LIMIT 1");
        $st->execute(array($sub));
        $user = $st->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['status'] !== 'active') {
                return $none('This account is inactive. Please contact administrator.');
            }
            return array('action' => 'signin', 'user' => $user, 'reason' => '');
        }

        $email = isset($claims['email']) ? trim((string)$claims['email']) : '';
        $verifiedEmail = !empty($claims['email_verified']);
        if ($email !== '' && $verifiedEmail) {
            $st = $db->prepare("SELECT id, name, email, role, status, store_id, owner_id, google_sub
                                FROM users WHERE email = ? LIMIT 1");
            $st->execute(array($email));
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                if ($user['status'] !== 'active') {
                    return $none('This account is inactive. Please contact administrator.');
                }
                if ($user['google_sub'] !== null && $user['google_sub'] !== '') {
                    // The row already belongs to a different Google account. Two
                    // identities cannot share one set of permissions.
                    return $none('This Google account is already linked to another user here.');
                }
                $link = $db->prepare("UPDATE users SET google_sub = ? WHERE id = ? AND google_sub IS NULL");
                $link->execute(array($sub, $user['id']));
                if ($link->rowCount() !== 1) {
                    return $none('This Google account could not be linked. Please sign in with your password.');
                }
                $user['google_sub'] = $sub;
                authAudit('google_link', true, $user['id'], $email, 'Google identity attached to an existing account');
                return array('action' => 'link', 'user' => $user, 'reason' => '');
            }
            return $none('');
        }

        if ($email !== '' && !$verifiedEmail) {
            // Google does not vouch for this address, so it cannot open a door in
            // someone else's shop even if the string happens to match a row.
            return $none('Google could not confirm this email address. Please sign in with your password.');
        }

        return $none('');
    } catch (Throwable $e) {
        error_log('googleSignInResolve: ' . $e->getMessage());
        return $none('Google sign-in is not available right now. Please use your password.');
    }
}

/**
 * Remember a Google profile that has no account yet, so the registration form can
 * start from it.
 *
 * Held in the session and never in a URL or a form field: the email a new account
 * is created with has to come from the verified token, not from whatever the
 * visitor typed into the address bar on the way to the page.
 */
function googleSignInRemember(array $claims)
{
    startSecureSession();
    $_SESSION['google_signin_pending'] = array(
        'sub'   => (string)$claims['sub'],
        'name'  => isset($claims['name']) ? (string)$claims['name'] : '',
        'email' => isset($claims['email']) ? trim((string)$claims['email']) : '',
        'photo' => isset($claims['picture']) ? (string)$claims['picture'] : '',
    );
}

/**
 * The pending profile, only when it is still verified and unused.
 */
function googleSignInPending()
{
    startSecureSession();
    if (empty($_SESSION['google_signin_pending']) || !is_array($_SESSION['google_signin_pending'])) {
        return null;
    }
    $p = $_SESSION['google_signin_pending'];
    return empty($p['email']) ? null : $p;
}

/**
 * Attach the pending identity to the account registration just created.
 * Called by the registration path only, with a user id that page has itself
 * inserted, so nothing here decides who the account is.
 */
function googleSignInAttachToNewUser($userId)
{
    $pending = googleSignInPending();
    if ($pending === null || $userId === null) {
        return false;
    }
    startSecureSession();
    unset($_SESSION['google_signin_pending']);
    try {
        $db = getDB();
        authEnsureTables($db);
        if (!authHasColumn($db, 'users', 'google_sub', true)) {
            return false;
        }
        $st = $db->prepare("UPDATE users SET google_sub = ? WHERE id = ? AND google_sub IS NULL");
        $st->execute(array($pending['sub'], (int)$userId));
        authAudit('google_link', $st->rowCount() === 1, (int)$userId, $pending['email'], 'Created from Google sign-in');
        return $st->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('googleSignInAttachToNewUser: ' . $e->getMessage());
        return false;
    }
}
