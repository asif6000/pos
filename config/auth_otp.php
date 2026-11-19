<?php
/**
 * One-time codes for phone numbers: issuing one, sending one, checking one, and
 * stopping anybody from wearing the door down.
 *
 * WHAT THIS FILE EXISTS FOR
 * -------------------------
 * "This number is mine" is the one fact the application cannot take a user's word
 * for. Typing a number into a profile - any profile, including your own - proves
 * nothing except that somebody can type. The only proof is a code that arrived on
 * that handset, entered back by the person holding it. So every path that puts a
 * phone number into `users.phone` goes through here first, and the number is
 * written as verified only when a challenge row is spent.
 *
 * NO CODE IS EVER KEPT IN THE CLEAR
 * ---------------------------------
 * `otp_challenges.code_hash` is an HMAC-SHA256 of purpose|number|code, keyed with
 * a secret that is minted on first use and kept in `system_settings`. Two things
 * follow. A database dump, a phpMyAdmin window left open, or a read of
 * information_schema contains no usable code - and a stolen database cannot be
 * brute-forced offline, because without the key a six-digit space over a
 * five-minute window is not worth attacking, while with it nothing in the table
 * helps.
 *
 * The code is also never echoed back to the browser and never written to
 * sms_logs, error_log or auth_audit_log. Every other SMS in this product logs its
 * body so the shop can read its history; an OTP body in that table would put a
 * live credential in a place that is read for a completely different reason. The
 * send is still counted, with the text "OTP code" in the message column, so
 * usage figures stay honest while the code itself stays nowhere.
 *
 * SINGLE USE IS A TIMESTAMP, NOT A DELETE
 * ---------------------------------------
 * consumed_at answers "was this code ever spent, and when", which is exactly what
 * a support conversation asks. A row that disappears cannot. Starting a new send
 * also stamps the previous live codes for that number and purpose as spent, so
 * only one code can ever be valid at a time - the old one is not "also still
 * works", which is how resend buttons turn into six open doors.
 *
 * ATTEMPTS ARE CAPPED TWICE
 * -------------------------
 * Per code: max_attempts (five) in otp_challenges, and the code is burned when it
 * runs out, so the cost of the next five guesses is a new SMS. Per number and per
 * address: auth_throttle, which is what stops that SMS being bought a hundred
 * times. A wrong code never says whether the number exists or what the code was;
 * every refusal on this file is deliberately the same shape.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_core.php';
// marketing.php first, and not for its own functions: getSmsSettings() - the one
// that says whether this shop is on a gateway at all - is built on
// getMarketingSettings(), which lives there, and sms_gateway.php does not require
// it. Loading only the gateway file makes every OTP send a fatal on a host where
// nothing else pulled marketing in first, which is exactly what an API endpoint
// is.
require_once __DIR__ . '/marketing.php';
require_once __DIR__ . '/sms_usage.php';

// A code that lives long enough to be read from a second handset and short enough
// that an intercepted one is worthless by the time it is useful.
define('OTP_TTL_SECONDS', 300);
// The wait between two sends to the same number. Below half the TTL, because the
// usual reason for pressing again is that the first code has not arrived yet.
define('OTP_RESEND_SECONDS', 45);
define('OTP_SEND_PER_NUMBER', 3);
define('OTP_SEND_NUMBER_WINDOW', 900);
define('OTP_SEND_PER_IP', 10);
define('OTP_SEND_IP_WINDOW', 3600);
define('OTP_VERIFY_MAX_ATTEMPTS', 5);
define('OTP_VERIFY_LOCK_SECONDS', 900);

/**
 * The key every code hash is made with.
 *
 * Minted once, stored in system_settings, and read back after the insert rather
 * than trusted from the variable that was just written: if the insert was refused
 * (a host that grants only SELECT/INSERT/UPDATE/DELETE usually allows this one,
 * but a table that does not exist does not) the value that verifies codes has to
 * be the value that is in the table, not a fresh random one that only this
 * request knows. Nothing here can work without a storable secret, so the honest
 * answer when there is none is an error, not a weak fallback.
 */
function otpSigningSecret($db = null)
{
    static $cached = null;
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }
    if ($db === null) {
        try {
            $db = getDB();
        } catch (Throwable $e) {
            return '';
        }
    }

    $secret = authSystemSetting($db, 'otp_signing_secret', '');
    if ($secret === '') {
        // system_settings predates the auth tables (it is where
        // permissions_version lives), so it is in the base schema but not in
        // migration_007_auth.sql - and an install that was set up before that
        // table existed, or that was rebuilt from an older dump, simply does not
        // have it. Created on demand like every other table here, because on the
        // live host this is refused and the honest answer below is what tells the
        // shopkeeper to import config/migration_007_auth.sql.
        appSafeDdl($db, "CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $candidate = bin2hex(random_bytes(32));
        try {
            // setting_key is unique (the same table permissions_version lives in),
            // so a second call updates nothing instead of stacking rows. The update
            // writes the key that is already there - the point is only to not fail.
            $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value)
                                VALUES ('otp_signing_secret', ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $st->execute(array($candidate));
        } catch (Throwable $e) {
            error_log('otpSigningSecret: could not store the key - ' . $e->getMessage());
        }
        $secret = authSystemSetting($db, 'otp_signing_secret', '');
        if ($secret === '') {
            return '';
        }
    }

    $cached = $secret;
    return $cached;
}

/**
 * Fold the ways a shopkeeper writes their own number into one storable form.
 *
 * 01712345678, 8801712345678, +880 1712-345678 and 1712345678 are the same
 * handset, and a uniqueness rule that compares the strings the user typed is a
 * rule that can be walked around with a space. The stored form is the local one,
 * because that is what the admin recognises on the page and on an invoice.
 *
 * @return string 11 digits starting 01, or '' when it is not a BD mobile number
 */
function otpNormalizePhone($raw)
{
    $digits = preg_replace('/[^0-9]/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 13 && strpos($digits, '880') === 0) {
        $digits = '0' . substr($digits, 3);
    } elseif (strlen($digits) === 12 && strpos($digits, '80') === 0) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && $digits[0] === '1') {
        $digits = '0' . $digits;
    }
    // Mobile numbers only: 01, then a real operator digit (3-9), then eight.
    if (strlen($digits) !== 11 || strpos($digits, '01') !== 0) {
        return '';
    }
    if ((int)$digits[2] < 3) {
        return '';
    }
    return $digits;
}

/**
 * The same number in the form SMS gateways expect (8801712345678).
 *
 * The trunk 0 stays on: 01712345678 becomes 8801712345678, thirteen digits, which
 * is what marketingNormalizePhone() stores for every other message this product
 * sends. Dropping it here would send a 12-digit number that no gateway recognises.
 */
function otpGatewayPhone($local)
{
    $local = otpNormalizePhone($local);
    return $local === '' ? '' : '88' . $local;
}

/**
 * Enough of a number to name it on screen without printing it in full.
 */
function otpMaskPhone($local)
{
    $local = otpNormalizePhone($local);
    if ($local === '') {
        return (string)$local;
    }
    return substr($local, 0, 4) . '***' . substr($local, -3);
}

/**
 * The accounts a single typed identifier could name: an email, or a phone number.
 *
 * WHY ONE FUNCTION OWNS THIS
 * --------------------------
 * A sign-in box that takes "email or phone" cannot ask the visitor which they
 * meant, and every place that lets somebody in with a credential has to resolve
 * the same string the same way. The password form, the OTP form, the API login and
 * the forgotten-password page that used to each hold their own lookup always
 * disagreed in some corner - one of them compared emails only, so a shopkeeper who
 * had verified a number found their password refused as "no such account", while
 * the page above it accepted the very same number for a code.
 *
 * WHAT IT INTENTIONALLY DOES NOT SELECT
 * ------------------------------------
 * phone_verified and google_sub. Those two columns come from
 * migration_007_auth.sql, and on the live host the import is a step somebody has
 * to take by hand; a shared SELECT that named them would throw on every login
 * until it is done. Anything that needs them asks for them where it can afford to
 * fail, and says which migration is missing when it does.
 *
 * A number may match more than one row: a legacy account where an admin typed the
 * number in by hand, and the account that later proved it with a code, can hold
 * the same digits. The caller gets them all and must refuse to choose - the whole
 * point of verifying a number is that one handset means one account.
 *
 * @return array<int,array<string,mixed>> zero, one or a few user rows
 */
function authFindUsersByIdentifier($db, $identifier)
{
    $id = trim((string)$identifier);
    if ($id === '' || strlen($id) > 191) {
        return array();
    }

    $columns = "id, name, email, password, role, status, store_id, owner_id, phone";

    try {
        if (filter_var($id, FILTER_VALIDATE_EMAIL)) {
            $st = $db->prepare("SELECT $columns FROM users WHERE email = ? LIMIT 1");
            $st->execute(array($id));
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }

        $phone = otpNormalizePhone($id);
        if ($phone === '') {
            return array();
        }
        // The three forms a number has ever been stored in: as it is kept today,
        // as a gateway writes it, and as somebody typed it without the trunk 0.
        $st = $db->prepare("SELECT $columns FROM users WHERE phone IN (?, ?, ?) LIMIT 5");
        $st->execute(array($phone, otpGatewayPhone($phone), substr($phone, 1)));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('authFindUsersByIdentifier: ' . $e->getMessage());
        return array();
    }
}

/**
 * Is the state this needs actually on this server?
 *
 * The live host grants no CREATE, so otp_challenges arrives only when
 * config/migration_007_auth.sql has been imported through phpMyAdmin. Saying so
 * in words is the whole difference between "OTP feature broken" and "import the
 * auth migration first" - and it is checked here rather than left to a PDO
 * exception, because a missing table must not turn the Settings page into a 500.
 *
 * @return string '' when everything is in place, otherwise what to do about it
 */
function otpSchemaProblem($db)
{
    authEnsureTables($db);
    if (!appTableExists($db, 'otp_challenges')) {
        return 'Server e OTP table nei. Admin panel e config/migration_007_auth.sql import korun.';
    }
    if (!authHasColumn($db, 'users', 'phone_verified', true)) {
        return 'Server e users.phone_verified column nei. Admin panel e config/migration_007_auth.sql import korun.';
    }
    if (otpSigningSecret($db) === '') {
        // Two different faults, and only one of them is the shopkeeper's to fix.
        // "system_settings table lagbe" was said even when the table was there and
        // the INSERT had been refused, which sent people hunting for a table that
        // was already in front of them.
        if (!appTableExists($db, 'system_settings')) {
            return 'Server e system_settings table nei. Admin panel e config/migration_007_auth.sql import korun.';
        }
        return 'system_settings e OTP secret likha jay ni - oi table-tay INSERT permission lagbe. Hosting account theke database user er permission dei dite hobe.';
    }
    return '';
}

/**
 * Already locked out? Read-only - the verify path asks before every try.
 *
 * @return array{locked:bool,retry_in:int}
 */
function otpThrottleLocked($db, $action, $subject)
{
    $out = array('locked' => false, 'retry_in' => 0);
    try {
        authEnsureTables($db);
        if (!appTableExists($db, 'auth_throttle')) {
            return $out;
        }
        $st = $db->prepare("SELECT locked_until FROM auth_throttle
                            WHERE action = ? AND subject_hash = ? LIMIT 1");
        $st->execute(array(substr($action, 0, 40), hash('sha256', $action . '|' . $subject)));
        $until = $st->fetchColumn();
        if ($until !== false && $until !== null && strtotime((string)$until) > time()) {
            $out['locked'] = true;
            $out['retry_in'] = max(1, strtotime((string)$until) - time());
        }
    } catch (Throwable $e) {
        error_log('otpThrottleLocked: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Count one attempt against a subject, and say whether it is allowed.
 *
 * The window restarts itself when it runs out, so a row is never a permanent
 * record of somebody else's bad quarter-hour. $minIntervalSeconds is the resend
 * cooldown: it is the same table because a flood of sends and a flood of guesses
 * are the same problem seen from two ends.
 *
 * @return array{allowed:bool,retry_in:int}
 */
function otpThrottleRecord($db, $action, $subject, $maxAttempts, $windowSeconds,
                           $lockSeconds, $minIntervalSeconds = 0)
{
    $now = time();
    $out = array('allowed' => true, 'retry_in' => 0);
    try {
        authEnsureTables($db);
        if (!appTableExists($db, 'auth_throttle')) {
            return $out;
        }
        $hashKey = hash('sha256', $action . '|' . $subject);
        $st = $db->prepare("SELECT attempts, window_started_at, last_attempt_at, locked_until
                            FROM auth_throttle WHERE action = ? AND subject_hash = ? LIMIT 1");
        $st->execute(array(substr($action, 0, 40), $hashKey));
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $windowStart = $row ? strtotime((string)$row['window_started_at']) : 0;
        $lastAt      = ($row && $row['last_attempt_at']) ? strtotime((string)$row['last_attempt_at']) : 0;
        $lockedUntil = ($row && $row['locked_until']) ? strtotime((string)$row['locked_until']) : 0;
        $inWindow    = $row && ($now - $windowStart) < $windowSeconds;

        if ($lockedUntil > $now) {
            return array('allowed' => false, 'retry_in' => max(1, $lockedUntil - $now));
        }
        if ($minIntervalSeconds > 0 && $lastAt > 0 && ($now - $lastAt) < $minIntervalSeconds) {
            return array('allowed' => false, 'retry_in' => max(1, $minIntervalSeconds - ($now - $lastAt)));
        }
        if ($inWindow && $maxAttempts > 0 && (int)$row['attempts'] >= $maxAttempts) {
            // The window is full. Lock it, so hammering it past the count is not
            // an option either, and reset the count with the new window.
            $lock = $lockSeconds > 0 ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;
            if ($lock !== null) {
                $up = $db->prepare("UPDATE auth_throttle SET locked_until = ?, attempts = 0,
                                        window_started_at = ?, last_attempt_at = ?
                                    WHERE action = ? AND subject_hash = ?");
                $up->execute(array($lock, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now),
                    substr($action, 0, 40), $hashKey));
            }
            return array('allowed' => false, 'retry_in' => $lockSeconds > 0 ? $lockSeconds : $windowSeconds);
        }

        $attempts = $inWindow ? (int)$row['attempts'] + 1 : 1;
        $started  = $inWindow ? date('Y-m-d H:i:s', $windowStart) : date('Y-m-d H:i:s', $now);
        if ($row) {
            $up = $db->prepare("UPDATE auth_throttle SET attempts = ?, window_started_at = ?,
                                    last_attempt_at = ?, locked_until = NULL
                                WHERE action = ? AND subject_hash = ?");
            $up->execute(array($attempts, $started, date('Y-m-d H:i:s', $now),
                substr($action, 0, 40), $hashKey));
        } else {
            $in = $db->prepare("INSERT INTO auth_throttle
                                    (action, subject_hash, attempts, window_started_at, last_attempt_at)
                                VALUES (?, ?, ?, ?, ?)");
            $in->execute(array(substr($action, 0, 40), $hashKey, $attempts, $started,
                date('Y-m-d H:i:s', $now)));
        }
    } catch (Throwable $e) {
        // A throttle that cannot be written fails open here rather than locking
        // everybody out of their own settings page; the per-code attempt cap in
        // otp_challenges still bounds a guess, so this is a slower door, not an
        // open one.
        error_log('otpThrottleRecord: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Forget a subject's failures - the code was right after all.
 */
function otpThrottleClear($db, $action, $subject)
{
    try {
        if (!appTableExists($db, 'auth_throttle')) {
            return;
        }
        $st = $db->prepare("DELETE FROM auth_throttle WHERE action = ? AND subject_hash = ?");
        $st->execute(array(substr($action, 0, 40), hash('sha256', $action . '|' . $subject)));
    } catch (Throwable $e) {
        error_log('otpThrottleClear: ' . $e->getMessage());
    }
}

/**
 * The hash of one code, for one purpose, on one number.
 *
 * purpose and phone are inside the hash, not just alongside it in the row: a code
 * issued to log in must not be spendable to change a phone number, even by
 * somebody who has read both rows out of the same table.
 */
function otpCodeHash($db, $purpose, $phone, $code)
{
    return hash_hmac('sha256', $purpose . '|' . $phone . '|' . $code, otpSigningSecret($db));
}

/**
 * Make a code and keep its hash. Returns the code to the caller, once, in memory.
 *
 * Superseding the live challenges for this number first is what makes "single
 * use" mean single use: two valid codes for one handset is one more code an
 * attacker has to guess, and one more that a careless shopkeeper can mix up.
 *
 * @return array{ok:bool,error:string,code:string,id:int}
 */
function otpIssue($db, $purpose, $phone, $userId = null)
{
    $out = array('ok' => false, 'error' => '', 'code' => '', 'id' => 0);

    $problem = otpSchemaProblem($db);
    if ($problem !== '') {
        $out['error'] = $problem;
        return $out;
    }
    $phone = otpNormalizePhone($phone);
    if ($phone === '') {
        $out['error'] = 'Number ta thik nei.';
        return $out;
    }

    $code = sprintf('%06d', random_int(0, 999999));
    try {
        $db->prepare("UPDATE otp_challenges SET consumed_at = ?
                      WHERE phone = ? AND purpose = ? AND consumed_at IS NULL")
            ->execute(array(date('Y-m-d H:i:s'), $phone, $purpose));

        $in = $db->prepare("INSERT INTO otp_challenges
                                (purpose, phone, user_id, code_hash, expires_at, attempts, max_attempts, ip)
                            VALUES (?, ?, ?, ?, ?, 0, ?, ?)");
        $in->execute(array(
            $purpose,
            $phone,
            $userId === null ? null : (int)$userId,
            otpCodeHash($db, $purpose, $phone, $code),
            date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS),
            OTP_VERIFY_MAX_ATTEMPTS,
            authClientIp(),
        ));
        $out['ok'] = true;
        $out['code'] = $code;
        $out['id'] = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('otpIssue: ' . $e->getMessage());
        $out['error'] = 'Code generate kora jay nai.';
    }
    return $out;
}

/**
 * Burn a code that never left the building.
 *
 * When the gateway refuses the send, the hash is already in the table and the
 * code is known to nobody but this process - which is exactly the kind of row a
 * later "who could have used that code" question should be able to close.
 */
function otpAbandon($db, $challengeId)
{
    try {
        $st = $db->prepare("UPDATE otp_challenges SET consumed_at = ? WHERE id = ?");
        $st->execute(array(date('Y-m-d H:i:s'), (int)$challengeId));
    } catch (Throwable $e) {
        error_log('otpAbandon: ' . $e->getMessage());
    }
}

/**
 * Check a typed code against the number's live challenge.
 *
 * One error string for "no such code", "wrong code" and "that one expired": they
 * differ to the person guessing and must not differ to them. The correct answer to
 * all three, for anyone who is not the owner, is to try again with a new SMS -
 * which is rate limited, so guessing is expensive by design.
 *
 * @return array{ok:bool,error:string,user_id:int|null,phone:string,challenge_id:int,retryable:bool}
 */
function otpConsume($db, $purpose, $phone, $code)
{
    $out = array('ok' => false, 'error' => 'Code ta match kore nai. Notun kore OTP din.',
                 'user_id' => null, 'phone' => '', 'challenge_id' => 0, 'retryable' => true);

    $problem = otpSchemaProblem($db);
    if ($problem !== '') {
        $out['error'] = $problem;
        return $out;
    }
    $phone = otpNormalizePhone($phone);
    $code  = preg_replace('/[^0-9]/', '', (string)$code);
    if ($phone === '' || strlen($code) !== 6) {
        return $out;
    }

    $ip = authClientIp();
    $locked = otpThrottleLocked($db, 'otp_verify', $phone);
    if ($locked['locked']) {
        $out['error'] = 'Ektu beshi try hoise. ' . ceil($locked['retry_in'] / 60)
                      . ' min por abara korun.';
        $out['retryable'] = false;
        return $out;
    }

    try {
        $st = $db->prepare("SELECT id, user_id, code_hash, expires_at, attempts, max_attempts
                            FROM otp_challenges
                            WHERE phone = ? AND purpose = ? AND consumed_at IS NULL
                            ORDER BY id DESC LIMIT 1");
        $st->execute(array($phone, $purpose));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            otpThrottleRecord($db, 'otp_verify', $phone, OTP_VERIFY_MAX_ATTEMPTS,
                OTP_VERIFY_LOCK_SECONDS, OTP_VERIFY_LOCK_SECONDS);
            authAudit('otp_verify', false, null, otpMaskPhone($phone), 'no live challenge for ' . $purpose);
            $out['retryable'] = false;
            return $out;
        }

        $now = time();
        if (strtotime((string)$row['expires_at']) <= $now) {
            $out['error'] = 'Code ta ar cholche na. Notun kore OTP din.';
            $out['retryable'] = false;
            authAudit('otp_verify', false, (int)$row['user_id'], otpMaskPhone($phone), 'expired');
            return $out;
        }
        if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
            $db->prepare("UPDATE otp_challenges SET consumed_at = ? WHERE id = ?")
                ->execute(array(date('Y-m-d H:i:s'), (int)$row['id']));
            $out['error'] = 'Ai code e beshi try hoise. Notun kore OTP din.';
            $out['retryable'] = false;
            authAudit('otp_verify', false, (int)$row['user_id'], otpMaskPhone($phone), 'attempt cap');
            return $out;
        }

        if (!hash_equals((string)$row['code_hash'], otpCodeHash($db, $purpose, $phone, $code))) {
            $db->prepare("UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?")
                ->execute(array((int)$row['id']));
            otpThrottleRecord($db, 'otp_verify', $phone, OTP_VERIFY_MAX_ATTEMPTS,
                OTP_VERIFY_LOCK_SECONDS, OTP_VERIFY_LOCK_SECONDS);
            authAudit('otp_verify', false, (int)$row['user_id'], otpMaskPhone($phone), 'wrong code');
            return $out;
        }

        $db->prepare("UPDATE otp_challenges SET consumed_at = ? WHERE id = ?")
            ->execute(array(date('Y-m-d H:i:s'), (int)$row['id']));
        otpThrottleClear($db, 'otp_verify', $phone);

        $out['ok'] = true;
        $out['error'] = '';
        $out['phone'] = $phone;
        $out['user_id'] = $row['user_id'] === null ? null : (int)$row['user_id'];
        $out['challenge_id'] = (int)$row['id'];
    } catch (Throwable $e) {
        error_log('otpConsume: ' . $e->getMessage());
        $out['error'] = 'Code check kora jay nai.';
    }
    return $out;
}

/**
 * Can this shop be sent a code at all?
 *
 * Every other SMS in the product has a soft landing: with no gateway the text is
 * handed to the phone's SMS app, which is fine for an invoice the cashier is
 * standing over. An OTP has no such fallback - a code a shopkeeper reads off
 * their own screen proves nothing, and a code handed to a phone they then have to
 * type into is not a thing this interface can check. So a shop on manual mode
 * simply cannot verify a number until a gateway is configured, and says so.
 */
function otpTransportAvailable($ownerId)
{
    try {
        $settings = getSmsSettings($ownerId);
        return (($settings['marketing_sms_provider'] ?? 'manual') !== 'manual');
    } catch (Throwable $e) {
        error_log('otpTransportAvailable: ' . $e->getMessage());
        return false;
    }
}

/**
 * Whose SMS account pays for a code the shop itself cannot send.
 *
 * Every other send in this product is one shop's own gateway sending to that
 * shop's customers, and the credentials are found by owner_id. Two things in the
 * sign-in path have no shop gateway to use: a registration code, because the
 * account that would own one does not exist yet, and a forgotten-password link,
 * because the person at that page cannot sign in to configure the Marketing & SMS
 * page they cannot reach. So the installation names one account for this, once:
 *
 *   INSERT INTO system_settings (setting_key, setting_value) VALUES ('otp_sms_owner_id', '7');
 *
 * The id is not guessed from "whoever happens to have a gateway set up", because
 * that would spend one shop's paid credit on another shop's customer. With
 * nothing set this answers 0, which is not a working sender: otpSendCode() then
 * reports the transport as unavailable and registration stops at the code step
 * rather than creating an account whose phone nobody proved.
 */
function otpPlatformSenderOwner($db)
{
    return (int)authSystemSetting($db, 'otp_sms_owner_id', '0');
}

/**
 * The sender for a sign-in code: the account's own shop when it has a gateway,
 * otherwise the installation's account. Without the fallback, a number login is
 * unusable for every staff member of a shop that never set up SMS - the person who
 * would fix that is the one locked out.
 */
function otpLoginSenderOwner($db, $account)
{
    $own = (int)(($account['owner_id'] ?? 0) ?: ($account['id'] ?? 0));
    return otpTransportAvailable($own) ? $own : otpPlatformSenderOwner($db);
}

/**
 * The one call a page makes: rate limit, issue, send.
 *
 * $opts: user_id, app name for the message body.
 *
 * @return array{ok:bool,error:string,retry_in:int}
 */
function otpSendCode($db, $ownerId, $phone, $purpose, array $opts = array())
{
    $out = array('ok' => false, 'error' => '', 'retry_in' => 0);

    $problem = otpSchemaProblem($db);
    if ($problem !== '') {
        $out['error'] = $problem;
        return $out;
    }
    $phone = otpNormalizePhone($phone);
    if ($phone === '') {
        $out['error'] = 'Ekta thik mobile number din (01XXXXXXXXX).';
        return $out;
    }
    if (!otpTransportAvailable($ownerId)) {
        $out['error'] = 'OTP pathate SMS gateway lagbe. Marketing & SMS page e apnar SMS provider '
                      . 'setup korun, tarpor abara chesta korun.';
        return $out;
    }

    $ip = authClientIp();
    foreach (array(
                 array('otp_ip', $ip, OTP_SEND_PER_IP, OTP_SEND_IP_WINDOW, OTP_SEND_IP_WINDOW, 0),
                 array('otp_send', $phone, OTP_SEND_PER_NUMBER, OTP_SEND_NUMBER_WINDOW,
                       OTP_SEND_NUMBER_WINDOW, OTP_RESEND_SECONDS),
             ) as $gate) {
        $res = otpThrottleRecord($db, $gate[0], $gate[1], $gate[2], $gate[3], $gate[4], $gate[5]);
        if (!$res['allowed']) {
            $out['retry_in'] = $res['retry_in'];
            $out['error'] = $gate[0] === 'otp_send'
                ? 'Ei number e agey OTP geche. ' . max(1, (int)ceil($res['retry_in'] / 60))
                  . ' min por abara din.'
                : 'Ekhane onek OTP request hoise. Ektu por abara chesta korun.';
            return $out;
        }
    }

    $issued = otpIssue($db, $purpose, $phone, isset($opts['user_id']) ? $opts['user_id'] : null);
    if (!$issued['ok']) {
        $out['error'] = $issued['error'];
        return $out;
    }

    $app = trim((string)($opts['app'] ?? 'Smart POS'));
    $minutes = max(1, (int)round(OTP_TTL_SECONDS / 60));
    $text = $app . ' verification code: ' . $issued['code']
          . '. ' . $minutes . ' min er moddhe din. Keu sathe ei code share korben na.';

    $sent = marketingSendSms($ownerId, otpGatewayPhone($phone), $text);
    $ok = !empty($sent['ok']) && empty($sent['manual']);

    // Counted, but with a stand-in for the body: sms_logs is read for usage
    // reports, and a report that shows live codes is a credential leak in a table
    // that was never meant to hold one.
    smsUsageLog($db, $ownerId, otpGatewayPhone($phone), 'OTP verification code', 1,
        $ok ? 'sent' : 'failed', array(
            'msg_type'     => 'manual',
            'user_id'      => isset($opts['user_id']) ? (int)$opts['user_id'] : null,
            'provider_ref' => $sent['response'] ?? '',
            'error'        => $ok ? null : ($sent['error'] ?? ''),
        ));

    if (!$ok) {
        otpAbandon($db, $issued['id']);
        authAudit('otp_send', false, isset($opts['user_id']) ? (int)$opts['user_id'] : null,
            otpMaskPhone($phone), 'gateway refused: ' . substr((string)($sent['error'] ?? ''), 0, 120));
        $out['error'] = 'SMS ta jay nai: ' . substr((string)($sent['error'] ?? 'Provider kichu bolse na'), 0, 160);
        return $out;
    }

    authAudit('otp_send', true, isset($opts['user_id']) ? (int)$opts['user_id'] : null,
        otpMaskPhone($phone), $purpose . ' code sent');
    $out['ok'] = true;
    return $out;
}

/**
 * Does some other account already claim this handset?
 *
 * Checked across every shop on purpose. A phone number is how a person gets in
 * without a password, and two accounts holding one number is one account too many
 * - whoever receives the code owns both. An unverified row counts as a claim as
 * well: it may have been typed on the Users page, but freeing it is a decision
 * somebody should take on purpose, not one this form makes on the way past.
 *
 * @return array|null the conflicting row, or null when the number is free
 */
function otpPhoneTaken($db, $phone, $exceptUserId = null)
{
    try {
        $st = $db->prepare("SELECT id, name, email, role, phone_verified FROM users
                            WHERE phone = ? AND id <> ? LIMIT 1");
        $st->execute(array(otpNormalizePhone($phone), (int)$exceptUserId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Throwable $e) {
        error_log('otpPhoneTaken: ' . $e->getMessage());
        return null;
    }
}

/**
 * Record a proved-own number on the account it was proved for.
 *
 * The only writer of users.phone_verified=true. It takes the number from the
 * challenge that was just spent (not from whatever is in the form now), so a code
 * for one number can never end up blessing another - the reason otpConsume hands
 * the phone back rather than letting the caller assume it.
 *
 * @return bool
 */
function otpMarkUserPhone($db, $userId, $phone)
{
    $phone = otpNormalizePhone($phone);
    if ($phone === '') {
        return false;
    }
    try {
        authEnsureTables($db);
        $st = $db->prepare("UPDATE users SET phone = ?, phone_verified = 1 WHERE id = ?");
        $st->execute(array($phone, (int)$userId));
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('otpMarkUserPhone: ' . $e->getMessage());
        return false;
    }
}
