<?php
/**
 * Authentication: password hashing, password change, and secure reset.
 *
 * WHAT THIS REPLACES
 * ------------------
 * Five pages call password_hash($x, PASSWORD_DEFAULT) and one calls
 * password_verify() inline. PASSWORD_DEFAULT is whatever PHP happens to prefer,
 * so on this installation the hashes are bcrypt at cost 10 - the same algorithm
 * Laravel's Hash driver uses, but at a cost chosen in 2012 by the PHP project
 * rather than one chosen here. Worse, every page picked its own policy (some
 * asked for 6 characters, the register page for its own rules), and nothing in
 * the application could change a password safely without knowing which page was
 * holding the form. Those pages keep working, because password_verify() reads the
 * cost out of the stored hash: the hashes are not changing shape, only the place
 * that decides how a new one is made.
 *
 * WHY bcrypt AT COST 12
 * ---------------------
 * It is what the spec asked for in Laravel terms ("Laravel Hash"), it is
 * supported on every host this project has ever run on, and its hash fits the
 * existing varchar(255) column. Cost 12 is roughly four times the work of the
 * default 10 and still well under a tenth of a second on shared hosting - past
 * that the honest trade becomes a login that feels broken rather than a hash that
 * is meaningfully harder to crack. Older cost-10 hashes are verified exactly as
 * they are and upgraded on the owner's next successful sign-in, so the migration
 * happens by itself and nobody has to reset a single password to get it.
 *
 * WHAT NEVER HAPPENS HERE
 * -----------------------
 * No plaintext password, and no reset token, is ever returned by a function that
 * does not need to hand it out, logged, or written to a table. authAudit() gets an
 * email or a masked phone and a sentence about what happened - the reset token is
 * a bearer credential that signs a password change, so putting it in a log table
 * would be the same mistake as putting passwords there.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_core.php';
// The throttle engine (auth_throttle is one table with one set of rules, whatever
// the action is), otpNormalizePhone() for "is this identifier a number", and the
// SMS transport the reset link travels by. auth_otp.php pulls marketing.php, which
// is what the gateway actually needs before getSmsSettings() can be called.
require_once __DIR__ . '/auth_otp.php';

/** Shortest password a person may choose. */
define('AUTH_PASSWORD_MIN_LENGTH', 8);

/**
 * Longest password accepted. bcrypt uses the first 72 bytes and ignores the rest,
 * so a longer input is not "more secure" - two different 90-character passwords
 * that share their first 72 bytes would be the same credential. Refusing is the
 * only honest answer.
 */
define('AUTH_PASSWORD_MAX_LENGTH', 72);

/** bcrypt cost for hashes made from now on. */
define('AUTH_PASSWORD_COST', 12);

/** A reset link is worth this long. */
define('PASSWORD_RESET_TTL_SECONDS', 900);

/** How many reset links one account may have queued in a window. */
define('PASSWORD_RESET_PER_ACCOUNT', 3);
define('PASSWORD_RESET_ACCOUNT_WINDOW', 900);

/** How many reset links one address may ask for. */
define('PASSWORD_RESET_PER_IP', 8);
define('PASSWORD_RESET_IP_WINDOW', 3600);

/** How wrong a typed reset token may get before it is finished with. */
define('PASSWORD_RESET_MAX_ATTEMPTS', 5);
define('PASSWORD_RESET_LOCK_SECONDS', 900);

/**
 * Hash a password the way this application hashes passwords.
 */
function authHashPassword($plain)
{
    return password_hash((string)$plain, PASSWORD_BCRYPT, array('cost' => AUTH_PASSWORD_COST));
}

/**
 * Does this typed password match this stored hash?
 *
 * @param string $plain
 * @param string $hash the value in users.password
 */
function authPasswordMatches($plain, $hash)
{
    $hash = (string)$hash;
    if ($hash === '' || $plain === '') {
        return false;
    }
    try {
        return password_verify((string)$plain, $hash);
    } catch (Throwable $e) {
        error_log('authPasswordMatches: ' . $e->getMessage());
        return false;
    }
}

/**
 * Should this hash be replaced with a stronger one?
 *
 * True for a cost-10 bcrypt hash made by PASSWORD_DEFAULT, and for anything a
 * hand-written row or an older install left behind. The caller decides when to act
 * on it, and the only sensible moment is a successful sign-in: that is the one
 * time the plaintext is in hand and the owner is present.
 */
function authPasswordNeedsRehash($hash)
{
    $hash = (string)$hash;
    if ($hash === '') {
        return false;
    }
    try {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, array('cost' => AUTH_PASSWORD_COST));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check a password against the account's stored hash, and quietly bring the hash
 * up to date when it is the only moment that can.
 *
 * @param array $user a row from users carrying id and password
 * @return bool
 */
function authPasswordVerifyAndUpgrade($db, array $user, $plain)
{
    if (!authPasswordMatches($plain, $user['password'])) {
        return false;
    }
    $id = (int)$user['id'];
    if ($id > 0 && authPasswordNeedsRehash($user['password'])) {
        try {
            $st = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $st->execute(array(authHashPassword($plain), $id));
            authAudit('password_rehash', true, $id, (string)($user['email'] ?? ''), 'Hash cost upgraded at sign-in');
        } catch (Throwable $e) {
            // The sign-in already succeeded; an old hash that stayed old is not a
            // reason to refuse somebody their own account.
            error_log('authPasswordVerifyAndUpgrade: ' . $e->getMessage());
        }
    }
    return true;
}

/**
 * What is wrong with this password, in the words the person typing it can act on.
 *
 * Deliberately does not ask for symbols or digits. A rule that forces
 * "Password1!" produces exactly that, written on a card taped behind the till, and
 * a shop where the password is on the counter has no length or cost that will help
 * it. Length is the one thing a person can hold in their head without writing it
 * down.
 *
 * @return array<string> empty when the password is acceptable
 */
function authPasswordPolicyErrors($plain)
{
    $errors = array();
    $plain = (string)$plain;
    $len = strlen($plain);
    if ($len < AUTH_PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password komyopakkhe ' . AUTH_PASSWORD_MIN_LENGTH . ' character hote hobe.';
    }
    if ($len > AUTH_PASSWORD_MAX_LENGTH) {
        $errors[] = 'Password beshi boro - ' . AUTH_PASSWORD_MAX_LENGTH . ' character porjonto neya hobe.';
    }
    if (trim($plain) === '') {
        $errors[] = 'Password likhun.';
    }
    return $errors;
}

/**
 * Set an account's password from a plaintext the owner chose.
 *
 * The only writer of users.password for a password a person picked. The three
 * pages that used to hash a password inline keep working unchanged - this is the
 * same UPDATE, with a policy check, the audit line and the sign-out of other
 * devices added, because "I changed my password" and "the phone I lost last month
 * should stop working" are one decision, not two.
 *
 * $opts:
 *   keep_token  the bearer token the caller is using now, so it survives
 *   revoke_others  true (default) to switch off every other session this account
 *                  holds; false to leave them, which is what an admin resetting a
 *                  cashier's password from the Users page may want
 *   identifier  what to write in the audit line (email or masked phone)
 *
 * @return array{ok:bool,error:string}
 */
function authPasswordSet($db, $userId, $plain, array $opts = array())
{
    $out = array('ok' => false, 'error' => '');
    $userId = (int)$userId;
    if ($userId <= 0) {
        $out['error'] = 'Account ta khuje pawa jay nai.';
        return $out;
    }

    $errors = authPasswordPolicyErrors($plain);
    if ($errors) {
        $out['error'] = implode(' ', $errors);
        return $out;
    }

    $revokeOthers = !isset($opts['revoke_others']) || $opts['revoke_others'] !== false;
    $keep = isset($opts['keep_token']) ? (string)$opts['keep_token'] : '';

    try {
        $st = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $st->execute(array(authHashPassword($plain), $userId));
        if ($st->rowCount() < 1) {
            $out['error'] = 'Account ta khuje pawa jay nai.';
            return $out;
        }
    } catch (Throwable $e) {
        error_log('authPasswordSet: ' . $e->getMessage());
        $out['error'] = 'Password save kora jay nai.';
        return $out;
    }

    if ($revokeOthers) {
        authSessionRevokeForUser($db, $userId, $keep === '' ? null : $keep);
    }

    authAudit(
        isset($opts['action']) ? (string)$opts['action'] : 'password_change',
        true,
        $userId,
        isset($opts['identifier']) ? (string)$opts['identifier'] : '',
        $revokeOthers ? 'Password changed, other sessions revoked' : 'Password changed'
    );

    $out['ok'] = true;
    return $out;
}

/**
 * Mint a reset token for an account.
 *
 * Returns the raw token once, to the caller that has to put it in a message. It is
 * stored as a SHA-256 for the same reason the bearer tokens are: a read of the
 * table should not be a bag of live credentials.
 *
 * An older link that has not been used is invalidated rather than left alongside
 * the new one. Two live links for one account means the older one can still be in
 * somebody's SMS history, and there is no reason for it to be.
 *
 * @return array{ok:bool,error:string,token:string,id:int,expires_in:int}
 */
function authPasswordResetIssue($db, $userId)
{
    $out = array('ok' => false, 'error' => '', 'token' => '', 'id' => 0,
                 'expires_in' => PASSWORD_RESET_TTL_SECONDS);

    $userId = (int)$userId;
    if ($userId <= 0) {
        $out['error'] = 'Account ta khuje pawa jay nai.';
        return $out;
    }

    try {
        authEnsureTables($db);
        if (!appTableExists($db, 'password_resets')) {
            $out['error'] = 'Server e password reset table nei. Admin panel e '
                          . 'config/migration_007_auth.sql import korun.';
            return $out;
        }

        $db->prepare("UPDATE password_resets SET used_at = NOW()
                      WHERE user_id = ? AND used_at IS NULL")->execute(array($userId));

        $raw = bin2hex(random_bytes(32));
        $st = $db->prepare("INSERT INTO password_resets
                            (user_id, token_hash, expires_at, ip)
                            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)");
        $st->execute(array($userId, hash('sha256', $raw), PASSWORD_RESET_TTL_SECONDS, authClientIp()));

        $out['ok'] = true;
        $out['token'] = $raw;
        $out['id'] = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('authPasswordResetIssue: ' . $e->getMessage());
        $out['error'] = 'Reset link ta banano jay nai.';
    }
    return $out;
}

/**
 * Where a reset link points.
 *
 * An absolute URL, because this string is only ever read inside an SMS: a link
 * that starts "/auth/reset-password.php" is a path, and a phone has nothing to
 * resolve it against. The host comes from the request, filtered to the characters
 * a host can actually contain - the same rule config/google_contacts.php applies -
 * because HTTP_HOST is whatever the visitor sent and a reset text must not be an
 * advertisement for somebody else's domain. With no host to read (the CLI) the
 * relative form is kept, which is what an offline test prints.
 */
function authPasswordResetUrl($rawToken)
{
    $path = rtrim(appBasePath(), '/') . '/auth/reset-password.php';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return $path . '?token=' . urlencode(trim((string)$rawToken));
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
             || (($_SERVER['SERVER_PORT'] ?? '') == 443)
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return ($https ? 'https' : 'http') . '://' . $host . $path . '?token=' . urlencode(trim((string)$rawToken));
}

/**
 * Send a reset link to the account's own contact details.
 *
 * SMS only, and only when the shop has a gateway: this installation has no mail
 * transport at all, and an email that silently never leaves the server is worse
 * than a page that says "this cannot be sent here". $opts:
 *   phone  the verified number to send to (a typed unverified number is not a
 *          credential route - a link goes where ownership was proved)
 *   app    name to put in the message
 *
 * @return array{ok:bool,error:string,via:string}
 */
function authPasswordResetSend($db, $ownerId, $user, $rawToken, array $opts = array())
{
    $out = array('ok' => false, 'error' => '', 'via' => '');

    $phone = otpNormalizePhone(isset($opts['phone']) ? $opts['phone'] : ($user['phone'] ?? ''));
    if ($phone === '') {
        $out['error'] = 'Ei account e verified mobile number nei, tai reset link pathano jay nai. '
                      . 'Admin theke notun password chokun.';
        return $out;
    }
    if (!otpTransportAvailable($ownerId)) {
        $out['error'] = 'Reset link pathate SMS gateway lagbe. Marketing & SMS page e provider '
                      . 'setup korun.';
        return $out;
    }

    $app = trim((string)($opts['app'] ?? 'Smart POS'));
    $minutes = max(1, (int)round(PASSWORD_RESET_TTL_SECONDS / 60));
    $link = authPasswordResetUrl($rawToken);
    $text = $app . ' password reset: ' . $link . ' (' . $minutes . ' min porjonto cholbe, ekbar-i '
          . 'kaj korbe). Apni ei request din nai bolle ei link e click korben na.';

    $sent = marketingSendSms($ownerId, otpGatewayPhone($phone), $text);
    $ok = !empty($sent['ok']) && empty($sent['manual']);

    // Counted with a stand-in body, exactly as an OTP is: the real message carries
    // a live credential, and sms_logs is a table the usage pages read.
    smsUsageLog($db, $ownerId, otpGatewayPhone($phone), 'Password reset link', 1,
        $ok ? 'sent' : 'failed', array(
            'msg_type'     => 'manual',
            'user_id'      => (int)$user['id'],
            'provider_ref' => $sent['response'] ?? '',
            'error'        => $ok ? null : ($sent['error'] ?? ''),
        ));

    if (!$ok) {
        authAudit('password_reset', false, (int)$user['id'], otpMaskPhone($phone),
            'reset link refused: ' . substr((string)($sent['error'] ?? ''), 0, 120));
        $out['error'] = 'SMS ta jay nai: ' . substr((string)($sent['error'] ?? 'Provider kichu bolse na'), 0, 160);
        return $out;
    }

    $out['ok'] = true;
    $out['via'] = 'sms';
    return $out;
}

/**
 * Turn a typed token into the row and account behind it.
 *
 * The token is looked up by its hash, so a wrong one is simply not found - there
 * is no comparison to time and no partial match to leak. Attempts are counted per
 * token so guessing a 64-character hex string is not something a lockout needs to
 * worry about, but the cap is there anyway, because the useful attack is a
 * truncated or recycled token, and that one is worth bounding.
 *
 * @return array{ok:bool,error:string,row:array|null,retryable:bool}
 */
function authPasswordResetLookup($db, $rawToken)
{
    $out = array('ok' => false, 'error' => 'Ei link ta ar kaj korche na. Notun link chokun.',
                 'row' => null, 'retryable' => false);

    $rawToken = trim((string)$rawToken);
    if (!preg_match('/^[0-9a-f]{64}$/i', $rawToken)) {
        return $out;
    }
    $hash = hash('sha256', $rawToken);

    $locked = otpThrottleLocked($db, 'password_reset', $hash);
    if ($locked['locked']) {
        $out['error'] = 'Ekhane beshi try hoise. ' . ceil($locked['retry_in'] / 60) . ' min por abara korun.';
        return $out;
    }

    try {
        $st = $db->prepare("SELECT pr.id AS reset_id, pr.user_id, pr.expires_at, pr.used_at,
                            u.name, u.email, u.status, u.role, u.store_id, u.owner_id, u.phone
                            FROM password_resets pr
                            JOIN users u ON u.id = pr.user_id
                            WHERE pr.token_hash = ? LIMIT 1");
        $st->execute(array($hash));
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('authPasswordResetLookup: ' . $e->getMessage());
        $out['error'] = 'Reset link ta check kora jay nai.';
        return $out;
    }

    if (!$row || !empty($row['used_at'])) {
        otpThrottleRecord($db, 'password_reset', $hash, PASSWORD_RESET_MAX_ATTEMPTS,
            PASSWORD_RESET_LOCK_SECONDS, PASSWORD_RESET_LOCK_SECONDS);
        return $out;
    }
    if (strtotime((string)$row['expires_at']) <= time()) {
        $out['error'] = 'Link ta expire hoise. Notun kore request korun.';
        return $out;
    }
    if ($row['status'] !== 'active') {
        $out['error'] = 'Ei account ta inactive.';
        return $out;
    }

    $out['ok'] = true;
    $out['error'] = '';
    $out['row'] = $row;
    $out['retryable'] = true;
    return $out;
}

/**
 * Spend a reset token and set the new password with it.
 *
 * Single use is a UPDATE ... WHERE used_at IS NULL, and the rowCount tells
 * whether this call or another one spent it: two tabs on the same link end with
 * one of them being told the link is gone, rather than both changing the password.
 *
 * $opts: keep_token (passed through to authPasswordSet)
 *
 * @return array{ok:bool,error:string,user_id:int,retryable:bool}
 */
function authPasswordResetConsume($db, $rawToken, $newPlain, array $opts = array())
{
    $out = array('ok' => false, 'error' => '', 'user_id' => 0, 'retryable' => false);

    $found = authPasswordResetLookup($db, $rawToken);
    if (!$found['ok']) {
        $out['error'] = $found['error'];
        $out['retryable'] = $found['retryable'];
        return $out;
    }
    $row = $found['row'];
    $userId = (int)$row['user_id'];

    $errors = authPasswordPolicyErrors($newPlain);
    if ($errors) {
        $out['error'] = implode(' ', $errors);
        $out['retryable'] = true;
        return $out;
    }

    try {
        $st = $db->prepare("UPDATE password_resets SET used_at = NOW()
                            WHERE id = ? AND used_at IS NULL");
        $st->execute(array((int)$row['reset_id']));
        if ($st->rowCount() < 1) {
            $out['error'] = 'Ei link ta agei use hoise. Notun link chokun.';
            return $out;
        }
    } catch (Throwable $e) {
        error_log('authPasswordResetConsume spend: ' . $e->getMessage());
        $out['error'] = 'Password change kora jay nai.';
        return $out;
    }

    $set = authPasswordSet($db, $userId, $newPlain, array(
        'action'     => 'password_reset',
        'identifier' => (string)$row['email'],
        'keep_token' => isset($opts['keep_token']) ? $opts['keep_token'] : '',
    ));
    if (!$set['ok']) {
        $out['error'] = $set['error'];
        return $out;
    }

    otpThrottleClear($db, 'password_reset', hash('sha256', trim((string)$rawToken)));

    $out['ok'] = true;
    $out['user_id'] = $userId;
    $out['retryable'] = false;
    return $out;
}

/**
 * The forgotten-password request: rate limit, mint, send.
 *
 * Answers the same sentence whether or not the identifier names an account, and
 * shows the gateway's refusal only to an account that does exist - the same
 * trade /api/auth/phone/send-otp.php documents, taken deliberately once rather
 * than falling out of two pages that had not talked to each other. A shop with no
 * SMS provider configured has no self-service reset at all, and the person who has
 * to hear that is the one standing in front of the login page waiting for an SMS,
 * not somebody reading a generic sentence and guessing. The per-address budget
 * below is what keeps that trade from doubling as an account list.
 *
 * @return array{ok:bool,error:string,retry_in:int}
 */
function authPasswordForgot($db, $identifier, array $opts = array())
{
    $out = array('ok' => false, 'error' => '', 'retry_in' => 0);

    $ip = authClientIp();
    $gate = otpThrottleRecord($db, 'password_forgot_ip', $ip, PASSWORD_RESET_PER_IP,
        PASSWORD_RESET_IP_WINDOW, PASSWORD_RESET_IP_WINDOW);
    if (!$gate['allowed']) {
        $out['retry_in'] = $gate['retry_in'];
        $out['error'] = 'Ekhane onek request hoise. Ektu por abara chesta korun.';
        return $out;
    }

    $users = authFindUsersByIdentifier($db, $identifier);
    $account = null;
    foreach ($users as $candidate) {
        if ((string)$candidate['status'] === 'active') {
            $account = $candidate;
            break;
        }
    }

    if ($account === null) {
        // Nothing was sent, and the caller cannot tell. The page shows its own
        // "jodi account thakte taile link geche" line either way.
        $out['ok'] = true;
        $out['error'] = '';
        return $out;
    }

    $subject = strtolower(trim((string)$account['email']));
    if ($subject === '') {
        $subject = otpMaskPhone(otpNormalizePhone($account['phone']));
    }
    $perAccount = otpThrottleRecord($db, 'password_forgot', $subject, PASSWORD_RESET_PER_ACCOUNT,
        PASSWORD_RESET_ACCOUNT_WINDOW, PASSWORD_RESET_ACCOUNT_WINDOW, 30);
    if (!$perAccount['allowed']) {
        $out['retry_in'] = $perAccount['retry_in'];
        $out['error'] = 'Ei account e agei request geche. '
                      . max(1, (int)ceil($out['retry_in'] / 60)) . ' min por abara din.';
        return $out;
    }

    $ownerId = (int)($account['owner_id'] ?: $account['id']);
    if (!otpTransportAvailable($ownerId)) {
        // A shop that never configured an SMS gateway has no way to receive a
        // link, and the person asking cannot log in to configure one - so the
        // platform's own sender covers this, exactly as it covers a registration
        // code. Decided before a token is minted, because minting one marks the
        // account's previous live link as used: a request that was never going to
        // be sent must not also destroy the one link that might still work.
        $fallback = otpPlatformSenderOwner($db);
        if ($fallback > 0 && otpTransportAvailable($fallback)) {
            $ownerId = $fallback;
        } else {
            $out['error'] = 'Reset link pathano jay nai: ei system e kono SMS gateway set '
                          . 'kora nei. Admin theke password change kore nin.';
            return $out;
        }
    }

    $issued = authPasswordResetIssue($db, (int)$account['id']);
    if (!$issued['ok']) {
        $out['error'] = $issued['error'];
        return $out;
    }

    $sent = authPasswordResetSend($db, $ownerId, $account, $issued['token'], array(
        'phone' => isset($opts['phone']) ? $opts['phone'] : ($account['phone'] ?? ''),
        'app'   => isset($opts['app']) ? $opts['app'] : 'Smart POS',
    ));
    if (!$sent['ok']) {
        $out['error'] = $sent['error'];
        return $out;
    }

    authAudit('password_reset_request', true, (int)$account['id'], $subject, 'Reset link sent');

    $out['ok'] = true;
    $out['error'] = '';
    return $out;
}
