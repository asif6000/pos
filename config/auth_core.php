<?php
/**
 * Authentication: the parts every sign-in method shares.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The application has one way in today - an email and password form - and that
 * form writes seven keys into $_SESSION, checks status, and decides where to
 * land. Google sign-in, phone + OTP and phone + password all have to do exactly
 * the same thing afterwards, and the moment two files write a session by hand is
 * the moment one of them forgets a key. The bug this prevents is the ordinary
 * one: a new login path that appears to work, opens the dashboard, and then
 * refuses every page because owner_id was never set, while the sidebar - which
 * reads permissions from the database rather than the session - still shows the
 * menu. Nothing looks wrong except the permissions.
 *
 * So the session is written in one place, by the same function the password form
 * now calls, and the roles and permissions a signed-in account holds cannot
 * differ by how it arrived.
 *
 * WHAT IS NOT HERE
 * ----------------
 * No password, no OTP and no token is stored or returned by anything in this
 * file. The credential checks live in the method's own file (auth_google.php for
 * Google, auth_otp.php for codes); this file takes a user row that has already
 * been proved and turns it into a session, and records that it happened.
 */

require_once __DIR__ . '/db.php';

/**
 * Create the tables and columns migration_007_auth.sql creates, where the
 * database user is allowed to.
 *
 * The same reason productVariablesEnsureTables() exists: the live host grants
 * only SELECT, INSERT, UPDATE and DELETE, so the import file is what the shop
 * runs through phpMyAdmin, while on XAMPP and any host that does allow CREATE
 * the schema arrives on first use. appSafeDdl() swallows a refusal rather than
 * turning a page into a 500, and every statement is preceded by a check, so a
 * second call costs two SELECTs from information_schema and changes nothing.
 */
function authEnsureTables($db = null)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if ($db === null) {
        try {
            $db = getDB();
        } catch (Throwable $e) {
            return; // no connection: the page that needs one reports it properly
        }
    }

    if (!authHasColumn($db, 'users', 'phone_verified')) {
        appSafeDdl($db, "ALTER TABLE `users` ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`");
    }
    if (!authHasColumn($db, 'users', 'google_sub')) {
        appSafeDdl($db, "ALTER TABLE `users` ADD COLUMN `google_sub` VARCHAR(191) NULL AFTER `phone_verified`");
        authHasColumn($db, 'users', 'google_sub', true);
    }
    if (!authHasIndex($db, 'users', 'uq_google_sub')) {
        appSafeDdl($db, "ALTER TABLE `users` ADD UNIQUE KEY `uq_google_sub` (`google_sub`)");
    }

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS `auth_sessions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `scope` ENUM('web','mobile') NOT NULL DEFAULT 'mobile',
        `token_hash` CHAR(64) NOT NULL,
        `ip` VARCHAR(45) DEFAULT NULL,
        `user_agent` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_seen_at` DATETIME DEFAULT NULL,
        `expires_at` DATETIME NOT NULL,
        `revoked_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token_hash`),
        KEY `idx_user` (`user_id`),
        KEY `idx_expiry` (`expires_at`),
        KEY `idx_revoked` (`revoked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS `otp_challenges` (
        `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
        `purpose` ENUM('login','link') NOT NULL,
        `phone` VARCHAR(20) NOT NULL,
        `user_id` INT(11) DEFAULT NULL,
        `code_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `attempts` INT(11) NOT NULL DEFAULT 0,
        `max_attempts` TINYINT(4) NOT NULL DEFAULT 5,
        `consumed_at` DATETIME DEFAULT NULL,
        `ip` VARCHAR(45) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_lookup` (`phone`,`purpose`,`expires_at`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS `auth_throttle` (
        `action` VARCHAR(40) NOT NULL,
        `subject_hash` CHAR(64) NOT NULL,
        `attempts` INT(11) NOT NULL DEFAULT 0,
        `window_started_at` DATETIME NOT NULL,
        `last_attempt_at` DATETIME NOT NULL,
        `locked_until` DATETIME DEFAULT NULL,
        PRIMARY KEY (`action`,`subject_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `token_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME DEFAULT NULL,
        `ip` VARCHAR(45) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token` (`token_hash`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS `auth_audit_log` (
        `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) DEFAULT NULL,
        `action` VARCHAR(40) NOT NULL,
        `identifier` VARCHAR(191) DEFAULT NULL,
        `ip` VARCHAR(45) DEFAULT NULL,
        `user_agent` VARCHAR(255) DEFAULT NULL,
        `success` TINYINT(1) NOT NULL DEFAULT 0,
        `note` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`,`created_at`),
        KEY `idx_action` (`action`,`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Does this column exist?
 *
 * $assume when information_schema cannot be read. Callers that would then run an
 * ALTER have to be told yes, because a failed CREATE is refused quietly by
 * appSafeDdl() whereas a needless one is the same - so guessing wrong in either
 * direction is survivable, and guessing "present" keeps a page from trying to
 * re-add a column on every request.
 */
function authHasColumn($db, $table, $column, $assume = false)
{
    try {
        $st = $db->prepare("SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $st->execute(array($table, $column));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $assume;
    }
}

/**
 * Does this index exist? Same reasoning, and the same default.
 */
function authHasIndex($db, $table, $index, $assume = false)
{
    try {
        $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        $st->execute(array($table, $index));
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $assume;
    }
}

/**
 * One platform-wide setting, for the handful of values that belong to the whole
 * installation rather than to one shop: the Google sign-in client ID and the
 * signing secret the OTP hash uses.
 *
 * Read through a function instead of a query in each page so a missing row and a
 * missing table both answer '' rather than raising - the table exists on every
 * install, but a host that denies CREATE leaves it absent, and sign-in must still
 * work with the password form.
 */
function authSystemSetting($db, $key, $default = '')
{
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $st->execute(array($key));
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : trim((string)$v);
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * The address to record and to rate-limit against.
 *
 * Only REMOTE_ADDR. X-Forwarded-For is a header the client writes: a sign-in
 * failure log keyed on it is a log an attacker can address, and a lockout keyed
 * on it can be turned off by changing it or aimed at somebody else by naming
 * them. On this host LiteSpeed terminates TLS itself and does not rewrite
 * REMOTE_ADDR, so the value is the real one.
 */
function authClientIp()
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return preg_match('/^[0-9a-zA-Z:.]{1,45}$/', $ip) ? $ip : '';
}

/**
 * The caller's User-Agent, shortened to what the column can hold.
 */
function authUserAgent()
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr($ua, 0, 255);
}

/**
 * Write one line of the sign-in record.
 *
 * The only writer to auth_audit_log, so that "no password, code or token ever
 * goes in here" is true by construction rather than by every caller remembering.
 * Nothing it does can break a login: a missing table or a refused insert is
 * logged and ignored, because an account that failed to record a successful
 * sign-in must not also fail that sign-in.
 *
 * @param string     $action     google_login | google_link | login | logout | ...
 * @param bool       $success
 * @param int|null   $userId
 * @param string     $identifier email or phone as presented, for the failures
 *                               where there is no user id yet
 * @param string     $note       what happened, never a credential
 */
function authAudit($action, $success, $userId = null, $identifier = '', $note = '')
{
    try {
        $db = getDB();
        authEnsureTables($db);
        if (!appTableExists($db, 'auth_audit_log')) {
            return;
        }
        $st = $db->prepare("INSERT INTO auth_audit_log
                            (user_id, action, identifier, ip, user_agent, success, note)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute(array(
            $userId === null ? null : (int)$userId,
            substr((string)$action, 0, 40),
            substr((string)$identifier, 0, 191),
            authClientIp(),
            authUserAgent(),
            $success ? 1 : 0,
            substr((string)$note, 0, 255),
        ));
    } catch (Throwable $e) {
        error_log('authAudit skipped: ' . $e->getMessage());
    }
}

/**
 * Make this user the signed-in browser session.
 *
 * WHAT IT DELIBERATELY COPIES
 * ---------------------------
 * The key list and the order are what auth/login.php has always written, so an
 * account that signs in with Google, with a code, or with a password is the same
 * session to every page, sidebar and permission check in the application. The
 * keys are written from the row rather than from anything the caller asserts:
 * role, store_id and owner_id come out of the database, so no login path can be
 * talked into a session with a role it did not earn.
 *
 * regenerating the id is not decoration. Whatever session cookie the visitor
 * arrived with - including one set by a link - is thrown away and replaced, so a
 * fixed-session attack cannot ride through the sign-in.
 *
 * @param array $user a row from users carrying id, name, email, role, store_id,
 *                    owner_id and status; status must already have been checked
 */
function authWebSessionFromUser(array $user)
{
    startSecureSession();

    session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['store_id']      = isset($user['store_id']) ? $user['store_id'] : null;
    $_SESSION['owner_id']      = isset($user['owner_id']) ? $user['owner_id'] : null;
    $_SESSION['last_activity'] = time();

    try {
        $db = getDB();
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute(array($user['id']));
    } catch (Throwable $e) {
        // The timestamp is a nicety; the sign-in is not.
        error_log('authWebSessionFromUser last_login: ' . $e->getMessage());
    }
}

/**
 * Where a signed-in account should be sent, in the one order the whole
 * application already agrees on: a real staff member gets their own page,
 * otherwise the first page the role may open, otherwise the dashboard, which can
 * say so politely.
 *
 * @return string a URL relative to the project root, e.g. "admin/dashboard.php"
 */
function authWebLanding(array $user)
{
    if (staffProfileExists($user)) {
        return 'staff/dashboard.php';
    }
    $landing = defaultLandingPage();
    return $landing !== null ? 'admin/' . $landing : 'admin/dashboard.php';
}
