-- ============================================================================
--  Migration 007 - authentication: OTP, verified phones, revocable sessions
--  Smart Collection POS
--
--  WHAT THIS ADDS
--  --------------
--  The application signed people in with one email+password form and never
--  signed them out again: logout.php called session_destroy(), which ends the
--  current browser session and leaves the mobile bearer token - a PHP session id
--  with a 30-day lifetime - perfectly usable until it expires on its own. There
--  was no way to revoke a stolen phone, no way to prove a phone number belongs
--  to the person using it, and users.phone - present in the schema since
--  migration 003 - was read and written by no PHP at all.
--
--  This file adds the six things the new api/auth/* endpoints need:
--    users.phone_verified   the number has been proved to belong to this account
--    users.google_sub       a Google account can sign in as this user
--    auth_sessions          every issued bearer token, so one can be revoked
--    otp_challenges         expiring, single-use, attempt-capped, hashed OTPs
--    auth_throttle          per-subject lockouts, so a password guesser is stopped
--    password_resets        expiring single-use reset tokens
--    auth_audit_log         who signed in, from where, and what failed
--    system_settings        where the OTP signing secret is kept (created only if
--                           this database has never had it - it normally lives in
--                           the base schema, and the auth feature cannot start
--                           without it)
--
--  WHAT THIS DOES NOT TOUCH
--  ------------------------
--  Nothing. No user id, store, sale, return, product, package or permission is
--  read, moved, renamed or retyped by any statement below - it is DDL only. The
--  four foreign keys that point at users.id (sales, returns, stock_history,
--  transfers.created_by) are untouched: users.id keeps its current
--  INT AUTO_INCREMENT PRIMARY KEY, so adding columns cannot disturb them.
--  phone_verified defaults to 0 rather than 1 so an existing row is never
--  silently claimed as verified by this script.
--
--  SAFETY
--  ------
--  Every statement checks before it writes, so a second run is a no-op. Two
--  shapes are used deliberately:
--    - CREATE TABLE IF NOT EXISTS, and
--    - ADD COLUMN guarded by information_schema + PREPARE, because MySQL 5.7
--      does not accept "ADD COLUMN IF NOT EXISTS" and would abort the import
--      half-way through, leaving the tables present but the columns missing.
--  Take a backup first; importing into the wrong database is the one mistake
--  this file cannot protect against.
--
--  HOW TO USE
--  Import with phpMyAdmin (cPanel -> phpMyAdmin -> select database -> Import).
--  On XAMPP the same objects are created on first use by authEnsureTables(), so
--  this file only matters where the database user has no CREATE privilege -
--  which is the live cPanel grant.
--
--  After importing, sign every account out and back in. The session written at
--  login carries the values the endpoints read, and an OTP sent before this ran
--  is not in the database.
--
--  Verified against MariaDB 10.4.32.
-- ============================================================================

-- Capture the schema this file runs against, once, here, while the connection is
-- still pointed at it. Every check and every ALTER below uses @shop_db rather
-- than DATABASE(), because phpMyAdmin moves the connection's active schema to
-- information_schema as soon as a statement names a table inside it. After that
-- DATABASE() answers 'information_schema', a conditional check then reports the
-- column as missing, and the ALTER that follows dies with
--     #1044 - Access denied for user ... to database 'information_schema'
-- Selecting the shop database in the left panel before importing is still the
-- right thing to do; this only stops a stale context from half-applying the file.
--
-- It cannot be trusted to be correct even here, though: the tab may already have
-- run a query that names information_schema before this file was imported, in
-- which case DATABASE() answers 'information_schema' on line 1 of the file. So
-- the capture falls back to the literal when DATABASE() reports a system schema.
--
-- The literal is the only repair available. Resetting the context with USE would
-- be tidier, but MariaDB refuses USE inside a prepared statement
--     #1295 - This command is not supported in the prepared statement protocol yet
-- and USE cannot be written plainly here without hardcoding a schema name into a
-- file that has to run on both the local copy and the live one.
SET @shop_db := IF(DATABASE() IS NULL
    OR DATABASE() IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
    'oznfsceg_smart', DATABASE());


-- ----------------------------------------------------------------------------
-- 1. users.phone_verified
--
--    "This account owns that number" is a fact that can only come from an OTP
--    delivered to it, so it is stored as a flag rather than inferred from
--    phone IS NOT NULL. A number typed in by an admin on the Users page is
--    unverified until the owner completes the link flow.
-- ----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @shop_db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'phone_verified') = 0,
    CONCAT('ALTER TABLE `', @shop_db, '`.`users` ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone`'),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ----------------------------------------------------------------------------
-- 2. users.google_sub
--
--    The stable Google account identifier (the "sub" claim of the id_token). An
--    email address is not safe to key an account link on: Google lets people
--    change and re-claim addresses, so matching on email would hand one person's
--    shop to another after a rename. sub never changes.
--
--    Nullable and unique. MySQL and MariaDB both treat NULLs as distinct inside
--    a unique index, so every account that has never used Google keeps NULL and
--    nothing collides.
-- ----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @shop_db AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'google_sub') = 0,
    CONCAT('ALTER TABLE `', @shop_db, '`.`users` ADD COLUMN `google_sub` VARCHAR(191) NULL AFTER `phone_verified`'),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @shop_db AND TABLE_NAME = 'users'
        AND INDEX_NAME = 'uq_google_sub') = 0,
    CONCAT('ALTER TABLE `', @shop_db, '`.`users` ADD UNIQUE KEY `uq_google_sub` (`google_sub`)'),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ----------------------------------------------------------------------------
-- 3. users.password is long enough already - checked, not changed.
--
--    A bcrypt hash is exactly 60 characters. It has to be able to say so, or the
--    hash is silently truncated to 255... which it is not, but a future column
--    narrowing would break every sign-in the way migration 002 documented for
--    users.role. The report at the end prints the real width so this can be
--    re-checked on any database.
-- ----------------------------------------------------------------------------


-- ----------------------------------------------------------------------------
-- 4. auth_sessions - the list that makes logout mean something
--
--    A row is written when a bearer token is issued. Logout sets revoked_at;
--    mobileAuth() refuses any token whose row is revoked or past expires_at.
--    "Revoke every device" is one UPDATE over this table.
--
--    WHY token_hash AND NOT token
--    ----------------------------
--    The token IS a PHP session id. Written in the clear, a database read - a
--    sqlBackup dump, a leaked information_schema query, a shared hosting panel -
--    becomes a list of live signed-in sessions an attacker can paste into a
--    header. SHA-256 is one-way, so the table can prove a token is revoked
--    without being able to produce it. Nothing that verifies a hash needs the
--    original back.
--
--    Grace for sessions issued before this file ran: they have no row. The
--    lookup denies only when a row EXISTS and is revoked or expired, so a phone
--    signed in last week is not cut off by a deployment. That is the whole
--    migration rule, and it needs no flag or backfill.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_sessions` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`      INT(11)     NOT NULL,
  `scope`        ENUM('web','mobile') NOT NULL DEFAULT 'mobile',
  `token_hash`   CHAR(64)    NOT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `user_agent`   VARCHAR(255) DEFAULT NULL,
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME    DEFAULT NULL,
  `expires_at`   DATETIME    NOT NULL,
  `revoked_at`   DATETIME    DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_expiry` (`expires_at`),
  KEY `idx_revoked` (`revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 5. otp_challenges
--
--    One row per code sent. The code itself never appears: only an
--    HMAC-SHA256 of it, keyed with a secret generated on first use and kept in
--    system_settings. A database dump therefore does not contain a single usable
--    OTP, and the key cannot be brute-forced offline because 6 digits over a
--    10-minute window is not a searchable space without it.
--
--    Single use is consumed_at, not a delete: "was this code ever spent, and
--    when" is the question a support conversation asks, and a row that vanishes
--    cannot answer it.
--
--    attempts/max_attempts cap guessing at five per code. A six-digit code five
--    ways is 0.0005%, and the row is burned for the rest of its window, so the
--    cost of an attack is a new SMS per attempt - which auth_throttle then stops.
--
--    There is deliberately no FOREIGN KEY on user_id. users.id is the primary key
--    every other table already leans on, and an FK here would make deleting a
--    user (admin/users.php does) fail on a child row that is only an audit
--    record of a code nobody used.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_challenges` (
  `id`           BIGINT(20)  NOT NULL AUTO_INCREMENT,
  `purpose`      ENUM('login','link') NOT NULL,
  `phone`        VARCHAR(20) NOT NULL,
  `user_id`      INT(11)     DEFAULT NULL,
  `code_hash`    CHAR(64)    NOT NULL,
  `expires_at`   DATETIME    NOT NULL,
  `attempts`     INT(11)     NOT NULL DEFAULT 0,
  `max_attempts` TINYINT(4)  NOT NULL DEFAULT 5,
  `consumed_at`  DATETIME    DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `created_at`   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`phone`,`purpose`,`expires_at`),
  KEY `idx_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 6. auth_throttle
--
--    The only rate limit in this project before now was in contact.php, and it
--    keyed on the session cookie - which is not an identity an attacker has to
--    present, since they simply do not send one. This keys on the thing the
--    request cannot avoid: the source address, and the credential being aimed at.
--
--    subject_hash is SHA-256 of "ip|identifier", so a row cannot be used to
--    discover which addresses tried which email, and the primary key is fixed
--    width. Counting in the database rather than in the session is what makes the
--    limit work against a client that keeps starting fresh sessions.
--
--    window_started_at is when the count began, so the counter is a fixed window
--    that resets rather than a list of timestamps to prune.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_throttle` (
  `action`            VARCHAR(40) NOT NULL,
  `subject_hash`      CHAR(64)    NOT NULL,
  `attempts`          INT(11)     NOT NULL DEFAULT 0,
  `window_started_at` DATETIME    NOT NULL,
  `last_attempt_at`   DATETIME    NOT NULL,
  `locked_until`      DATETIME    DEFAULT NULL,
  PRIMARY KEY (`action`,`subject_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 7. password_resets
--
--    Same reasoning as otp_challenges: the token in the email is high-value and
--    short-lived, so only its hash is stored, and "used" is a timestamp rather
--    than a delete so a link that gets clicked twice is visibly refused.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     NOT NULL,
  `token_hash` CHAR(64)    NOT NULL,
  `expires_at` DATETIME    NOT NULL,
  `used_at`    DATETIME    DEFAULT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 8. auth_audit_log
--
--    Sign-ins, failures, OTP sends, revocations and phone links. Kept because
--    "when did this account get a new number" is a question a shop owner asks
--    their provider, and the answer has to exist somewhere after the fact.
--
--    identifier holds the email or phone as presented. It is PII and that is the
--    point - a log that cannot name the account it refused is not evidence. No
--    password, code or token ever goes in this column, and nothing below can
--    write one: the endpoints pass the value through authAudit(), which is the
--    only writer.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_audit_log` (
  `id`         BIGINT(20)  NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     DEFAULT NULL,
  `action`     VARCHAR(40) NOT NULL,
  `identifier` VARCHAR(191) DEFAULT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `success`    TINYINT(1)  NOT NULL DEFAULT 0,
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`,`created_at`),
  KEY `idx_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- 9. sms_logs.msg_type gains 'otp'
--
--    Every SMS the system sends is recorded in sms_logs and the Marketing page's
--    counts are aggregated straight out of it, so an OTP that is not logged is an
--    SMS the shop paid for and cannot see. The ENUM does not currently allow the
--    value, and MySQL would store an empty string for it - the same silent
--    failure that made staff accounts see an empty menu (config/db.php,
--    adminSlugs()).
--
--    Guarded because a MODIFY on an ENUM that already carries 'otp' is a rebuild
--    of the column for nothing.
--
--    The check is written inline in the IF() rather than as its own
--    `SET @needs_otp := (SELECT ... FROM information_schema...)` statement, which
--    is the shape this file used to have here. That separate read was the last
--    statement to touch information_schema before the PREPARE below, and it is
--    exactly where the import died on both the local copy and live: phpMyAdmin
--    reported "Import has been successfully finished, 27 queries executed" over
--    the top of a #1044, so the file applied everything except this one ALTER and
--    looked complete. The three ALTERs above never broke because their checks were
--    always inline.
--
--    The OTP's CODE is never written to `message`: authOtpSend() hands the sender
--    a redacted line, so this stays a delivery record rather than a table of
--    live credentials.
-- ----------------------------------------------------------------------------
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @shop_db AND TABLE_NAME = 'sms_logs'
        AND COLUMN_NAME = 'msg_type'
        AND COLUMN_TYPE LIKE '%otp%') = 0,
    CONCAT('ALTER TABLE `', @shop_db, '`.`sms_logs` MODIFY COLUMN `msg_type` ENUM(''invoice'',''marketing'',''manual'',''otp'') NOT NULL DEFAULT ''manual'''),
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ----------------------------------------------------------------------------
-- 10. system_settings
--
--    Not an auth table, but the OTP cannot exist without it: the HMAC key every
--    code hash is made with is minted on first use and stored here as
--    otp_signing_secret, next to the permissions_version row the menu code has
--    always read. An install whose database predates that table - or was rebuilt
--    from an older dump - gets "system_settings e OTP secret likha jay ni", and
--    the application cannot fix it itself because this host grants no CREATE.
--
--    The row itself is deliberately NOT inserted here. It is a signing secret, so
--    it must be generated by the application (random_bytes) and must never appear
--    in a file that gets committed, pasted, or left in phpMyAdmin's query history.
--    Create the table, and the first OTP request mints its own key.
--
--    setting_key is UNIQUE on purpose - the insert that stores the secret is an
--    ON DUPLICATE KEY UPDATE, and without the unique index a second call would
--    stack a second row and then verify codes against whichever one it read back.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT        DEFAULT NULL,
  `updated_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
--  REPORT
--
--  Ordinary column aliases only - see the note in migration_004 about empty
--  backtick aliases and the phpMyAdmin parser warnings they produce.
--
--  The order below matters, and the reason is not obvious. On the live host's
--  phpMyAdmin a statement that names information_schema makes the NEXT
--  unqualified statement resolve inside information_schema too - the breadcrumb
--  changes to "Table: COLUMNS" or "Table: TABLES" and a plain "FROM users" then
--  dies with
--      #1109 - Unknown table 'users' in information_schema
--  which reads as though the shop's tables had been dropped. They have not; only
--  the schema context moved. So every query that names one of the app's own
--  tables runs first, and the information_schema reads run last, because those
--  name the schema explicitly and cannot be moved by it.
-- ============================================================================

-- Nothing here was made to be read by an application; it is the evidence the
-- shop still owns its data. If any of these moved, restore from the backup.
SELECT (SELECT COUNT(*) FROM users)  AS users,
       (SELECT COUNT(*) FROM stores) AS stores,
       (SELECT COUNT(*) FROM sales)  AS sales,
       (SELECT COUNT(*) FROM products) AS products;

-- Phone state, so the link workflow can be planned rather than guessed. Every
-- number here is unverified by definition until its owner confirms it.
SELECT COUNT(*)                                            AS users_total,
       SUM(phone IS NULL OR phone = '')                   AS no_phone,
       SUM(phone IS NOT NULL AND phone <> '')             AS has_phone,
       SUM(phone_verified = 1)                            AS verified_phones,
       SUM(google_sub IS NOT NULL)                        AS google_linked
  FROM users;

-- The one thing SQL cannot promise: a UNIQUE index treats NULLs as distinct, so
-- two accounts may hold the same non-empty number only if the index was dropped.
-- Any row printed here is a real collision to resolve before enabling phone
-- sign-in, because the login lookup must return exactly one account.
SELECT phone AS phone_shared_by_many, COUNT(*) AS accounts,
       GROUP_CONCAT(id ORDER BY id) AS user_ids
  FROM users
 WHERE phone IS NOT NULL AND phone <> ''
 GROUP BY phone
HAVING COUNT(*) > 1
 ORDER BY accounts DESC;

-- From here down it is information_schema only, so nothing that follows can be
-- moved by the context switch described at the top of this section.

-- The two new user columns, as the database actually has them now.
SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
       IS_NULLABLE AS nullable, COLUMN_DEFAULT AS ci_default
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = @shop_db
   AND TABLE_NAME   = 'users'
   AND COLUMN_NAME IN ('phone', 'phone_verified', 'google_sub', 'password')
 ORDER BY ORDINAL_POSITION;

-- Every table this migration owns, and whether it arrived. Seven rows means the
-- import reached the end.
--
-- information_schema rather than the tables themselves, on purpose: a table that
-- did not arrive then shows up as a missing row, instead of aborting the rest of
-- the report with "table doesn't exist". The bare derived-table alias the earlier
-- copy of this query used ("FROM ( ... ) found") is also gone - phpMyAdmin's own
-- parser refuses it, which is what made a finished import look like a failed one.
SELECT TABLE_NAME AS table_name, TABLE_ROWS AS rows_now
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = @shop_db
   AND TABLE_NAME IN ('auth_sessions', 'otp_challenges', 'auth_throttle',
                      'password_resets', 'auth_audit_log', 'sms_logs',
                      'system_settings')
 ORDER BY TABLE_NAME;
