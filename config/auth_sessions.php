<?php
/**
 * Authentication: the bearer tokens the mobile app signs in with.
 *
 * WHY A TABLE, WHEN THE TOKEN IS ALREADY A PHP SESSION ID
 * -------------------------------------------------------
 * config/mobile_auth.php made the app's "token" a PHP session id from a private
 * session directory, which fixed the hole it was written for: the API stopped
 * believing an owner_id typed into the request body. What it did not give
 * anybody is a way to switch a token off. A phone that walks away stays signed in
 * for thirty days whether the cashier went home, changed jobs, or lost the
 * handset, because the only thing that ends the session is the file expiring -
 * and the admin panel has no way to know a token exists, let alone name the
 * device it belongs to. "Log out everywhere" and "this account cannot sign in
 * any more" both need a row to write.
 *
 * WHAT A MISSING ROW MEANS
 * ------------------------
 * It means "this token was issued before this table existed", not "this token is
 * not allowed". Every phone that is signed in right now holds a token with no
 * row behind it, so a lookup that answered "deny" for an unknown token would log
 * the entire app out the moment this file is uploaded - and a host that refused
 * migration_007_auth.sql would have no table to put rows in either. Callers get
 * 'missing' and are expected to fall back to the session file itself, which is
 * still the credential and still expires. Revoking an old token is the one thing
 * that cannot be done until the phone signs in again; from then on it is in the
 * table like everything else.
 *
 * WHAT IS NEVER STORED HERE
 * -------------------------
 * The token. Only its SHA-256, which is what a lookup compares against and is
 * useless to anybody who reads the table, since the token is only ever handed to
 * the client once, in the response that created it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_core.php';

/** A phone is left alone this long before its token dies; matches MOBILE_SESSION_TTL. */
define('AUTH_SESSION_MOBILE_TTL', 2592000);

/** Rows are dropped this many days after they stopped being usable. */
define('AUTH_SESSION_PRUNE_DAYS', 30);

/**
 * The value that goes into token_hash.
 *
 * hash() rather than password hashing on purpose: this is not a secret to be
 * guessed slowly, it is a 32-character random string compared exactly. Anyone
 * holding the table still has to invert SHA-256 to use a row.
 */
function authTokenHash($token)
{
    return hash('sha256', trim((string)$token));
}

/**
 * Register a token that has just been handed to a client.
 *
 * Never fatal for the sign-in that called it: an account whose session exists but
 * whose row did not is signed in and simply not revocable, which is the state
 * every token was in before this file existed.
 *
 * @param string $scope 'mobile' or 'web'
 * @return int the row id, 0 when nothing could be written
 */
function authSessionIssue($db, $userId, $token, $scope = 'mobile', $ttlSeconds = AUTH_SESSION_MOBILE_TTL)
{
    $token = trim((string)$token);
    if ($token === '' || (int)$userId <= 0) {
        return 0;
    }
    try {
        authEnsureTables($db);
        if (!appTableExists($db, 'auth_sessions')) {
            return 0;
        }
        $st = $db->prepare("INSERT INTO auth_sessions
                            (user_id, scope, token_hash, ip, user_agent, expires_at)
                            VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
        $st->execute(array(
            (int)$userId,
            $scope === 'web' ? 'web' : 'mobile',
            authTokenHash($token),
            authClientIp(),
            authUserAgent(),
            max(60, (int)$ttlSeconds),
        ));
        // Read before anything else runs on this connection: PDO's lastInsertId()
        // reports the id of the *last* statement that could generate one, so a
        // DELETE in between answers 0 and the row is registered but unaccounted.
        $id = (int)$db->lastInsertId();
        authSessionPrune($db);
        return $id;
    } catch (Throwable $e) {
        error_log('authSessionIssue: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Is this token still allowed to be used?
 *
 * @return string active | revoked | expired | missing
 */
function authSessionState($db, $token)
{
    $token = trim((string)$token);
    if ($token === '') {
        return 'missing';
    }
    try {
        if (!appTableExists($db, 'auth_sessions')) {
            return 'missing';
        }
        $st = $db->prepare("SELECT revoked_at, expires_at FROM auth_sessions
                            WHERE token_hash = ? LIMIT 1");
        $st->execute(array(authTokenHash($token)));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'missing';
        }
        if (!empty($row['revoked_at'])) {
            return 'revoked';
        }
        if (strtotime((string)$row['expires_at']) <= time()) {
            return 'expired';
        }
        return 'active';
    } catch (Throwable $e) {
        // A lookup that could not run must not be reported as a revocation: the
        // honest answer is that nobody knows, and the caller's fallback is the
        // session file, which is a credential in its own right.
        error_log('authSessionState: ' . $e->getMessage());
        return 'missing';
    }
}

/**
 * Note that this token was used, and push its expiry forward.
 *
 * Called on every authenticated mobile request, so the app is never cut off in the
 * middle of a shift while it is being used, and a phone that goes into a drawer
 * stops being able to call within the same thirty days it would have anyway.
 */
function authSessionTouch($db, $token, $ttlSeconds = AUTH_SESSION_MOBILE_TTL)
{
    $token = trim((string)$token);
    if ($token === '') {
        return;
    }
    try {
        $st = $db->prepare("UPDATE auth_sessions
                            SET last_seen_at = NOW(),
                                expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                            WHERE token_hash = ? AND revoked_at IS NULL");
        $st->execute(array(max(60, (int)$ttlSeconds), authTokenHash($token)));
    } catch (Throwable $e) {
        error_log('authSessionTouch: ' . $e->getMessage());
    }
}

/**
 * Switch one token off.
 *
 * The row is marked rather than deleted: "which devices are signed in, and when
 * did each last call?" is the question an admin asks after losing a phone, and the
 * answer needs the row to stay.
 *
 * @return int rows revoked (0 or 1 for a single token)
 */
function authSessionRevoke($db, $token)
{
    $token = trim((string)$token);
    if ($token === '') {
        return 0;
    }
    try {
        $st = $db->prepare("UPDATE auth_sessions SET revoked_at = NOW()
                            WHERE token_hash = ? AND revoked_at IS NULL");
        $st->execute(array(authTokenHash($token)));
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        error_log('authSessionRevoke: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Switch every token an account holds off, except the one it is using now.
 *
 * For a password reset and for "sign out everywhere". The current token is passed
 * in and kept, because making somebody sign in again on the device that is in
 * their hand, immediately after they changed their own password, is a punishment
 * for the thing they just did right.
 *
 * @param string|null $exceptToken
 * @param string|null $scope     limit to 'mobile' or 'web', null for both
 * @return int rows revoked
 */
function authSessionRevokeForUser($db, $userId, $exceptToken = null, $scope = null)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }
    $sql = "UPDATE auth_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL";
    $args = array($userId);
    if ($scope === 'mobile' || $scope === 'web') {
        $sql .= " AND scope = ?";
        $args[] = $scope;
    }
    if ($exceptToken !== null && trim((string)$exceptToken) !== '') {
        $sql .= " AND token_hash <> ?";
        $args[] = authTokenHash($exceptToken);
    }
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        error_log('authSessionRevokeForUser: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The account's live sessions, newest first - for /me and for the Users page.
 *
 * Never selects token_hash. Nothing downstream has a use for it, and a list that
 * carries live hashes is a list that turns into a set of live tokens if it is ever
 * printed, exported or logged.
 *
 * @return array<int,array<string,mixed>>
 */
function authSessionListForUser($db, $userId, $scope = null)
{
    $sql = "SELECT id, scope, ip, user_agent, created_at, last_seen_at, expires_at, revoked_at
            FROM auth_sessions WHERE user_id = ?";
    $args = array((int)$userId);
    if ($scope === 'mobile' || $scope === 'web') {
        $sql .= " AND scope = ?";
        $args[] = $scope;
    }
    $sql .= " ORDER BY id DESC LIMIT 50";
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('authSessionListForUser: ' . $e->getMessage());
        return array();
    }
}

/**
 * Throw away rows nobody could use anyway.
 *
 * Run from the issue path rather than a cron job, because this installation has no
 * cron: a shared host runs PHP when a request arrives and nothing otherwise. It is
 * bounded (LIMIT 500) and only ever touches long-dead rows, so the cost lands on
 * the occasional sign-in and the table cannot grow without limit.
 */
function authSessionPrune($db)
{
    try {
        $db->prepare("DELETE FROM auth_sessions
                      WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                      LIMIT 500")->execute(array(AUTH_SESSION_PRUNE_DAYS));
    } catch (Throwable $e) {
        // A DELETE with a LIMIT is the one statement a restricted cPanel user is
        // most likely to be refused; the table simply stays bigger.
        error_log('authSessionPrune: ' . $e->getMessage());
    }
}
