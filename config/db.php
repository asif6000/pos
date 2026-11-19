<?php
/**
 * Database Configuration
 * POS System - Bangladesh
 *
 * Configure your database connection here
 */

// Database credentials
// UPDATE THESE FOR LIVE HOSTING
define('DB_HOST', 'localhost');
define('DB_NAME', 'oznfsceg_smart');           // Local XAMPP DB name
define('DB_USER', 'oznfsceg_smart');            // XAMPP default user
define('DB_PASS', 'oznfsceg_smart');                // XAMPP default (no password)
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'POS System');
define('APP_VERSION', '1.0.0');
define('CURRENCY', '');   // no currency symbol: amounts are shown as plain numbers
define('CURRENCY_CODE', 'BDT');

// Every refunded sale is booked in the Expense list under this one name, so a shop
// can total what returns cost it. Per shop, created on the first refund.
define('EXPENSE_REFUND_CATEGORY', 'Product Refund');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

/**
 * PDO Database Connection
 * Uses prepared statements for security
 */
class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            // Ask for buffered results explicitly.
            //
            // With unbuffered results a connection may only have one statement
            // in flight, so any code that reads a value and then runs another
            // query without closing the first is refused with
            //     SQLSTATE[HY000] General error: 2014
            //     Cannot execute queries while other unbuffered queries are active
            // That is what killed the Variable Name page on the live host, while
            // XAMPP - which buffers by default - carried on serving it, which is
            // why it looked like a hosting problem rather than a code problem.
            //
            // Buffering is requested here so the whole application inherits it.
            // The individual statements still close their cursors: buffering
            // makes the pattern work, closing the cursor makes it correct, and
            // this project has a lot of both.
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }
            try {
                $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // The XAMPP fallback that used to live here - retrying the same
                // database as root with an empty password - has been removed.
                //
                // It was a development convenience: on a laptop where the app
                // user was mistyped, root would open the door anyway. On a host
                // it is three problems at once. It cannot help, because shared
                // hosting does not hand out a passwordless root. It is a
                // credential-stuffing habit in the source of a live site. And
                // worst of all it hides the real fault: when the configured
                // user is refused for a genuine reason - wrong password, or
                // missing privileges - the fallback connects as root, the pages
                // start working, and the privilege problem stays invisible until
                // the day root is not available either. Better to fail loudly
                // here, once, with the reason in the log.
                throw $e;
            }
            
            // Auto-apply timezone from settings
            try {
                $stmt = $this->connection->prepare("SELECT setting_value FROM settings WHERE setting_key = 'timezone' LIMIT 1");
                $stmt->execute();
                $tz = $stmt->fetchColumn();
                if ($tz && in_array($tz, DateTimeZone::listIdentifiers())) {
                    date_default_timezone_set($tz);
                } else {
                    date_default_timezone_set('Asia/Dhaka');
                }
            } catch (Exception $e) {
                date_default_timezone_set('Asia/Dhaka');
            }

            // Put MySQL on the same clock as PHP, once the PHP side is known.
            //
            // The two are configured independently and shared hosts disagree:
            // cPanel runs MySQL in UTC while PHP gets Asia/Dhaka from the
            // block above. Nothing in the app notices, because almost every
            // timestamp is written and read by MySQL on both sides - until a
            // row written by NOW() is compared against PHP's time().
            //
            // Any staleness check does exactly that: it compares a timestamp
            // written by NOW() against PHP's clock:
            //
            //     $age = time() - strtotime($row['last_seen']);
            //
            // With MySQL six hours behind, a row written seconds before
            // measured as 360 minutes stale - the exact UTC+6 offset - so the
            // page declared live data dead and returned before it ever
            // reached the answer it was looking for. The data was there the
            // whole time, unreachable behind a staleness check that could
            // never pass.
            //
            // Aligning the session here fixes the cause rather than the
            // symptom: last_seen is then written and read in the same zone as
            // every other timestamp, and the staleness check means what it says.
            //
            // Asia/Dhaka is a fixed +06:00 with no daylight saving, so the
            // offset is a constant and needs no timezone tables - which is
            // exactly what shared hosting tends not to have loaded. The
            // numeric offset is used on purpose: `SET time_zone = 'Asia/Dhaka'`
            // returns NULL and silently keeps UTC on a server whose mysql.time_zone
            // tables are empty, which is the same bug in a quieter form.
            //
            // Best effort. A host that refuses SET time_zone still serves the
            // app; it just leaves the staleness arithmetic to be wrong there.
            try {
                $offset = (new DateTimeZone(date_default_timezone_get()))
                    ->getOffset(new DateTime('now', new DateTimeZone('UTC')));
                $sign    = $offset < 0 ? '-' : '+';
                $offset  = abs($offset);
                $hours   = str_pad((string)intdiv($offset, 3600), 2, '0', STR_PAD_LEFT);
                $minutes = str_pad((string)intdiv($offset % 3600, 60), 2, '0', STR_PAD_LEFT);

                $this->connection->exec("SET time_zone = '{$sign}{$hours}:{$minutes}'");
            } catch (Exception $e) {
                // Nothing to do: the connection is open, so the app works. Only
                // the cross-zone timestamp comparisons are affected, and those
                // are all defensive checks that fail towards "assume stale".
                error_log('Could not set MySQL session time_zone: ' . $e->getMessage());
            }
        } catch (PDOException $e) {
            // Log error instead of displaying sensitive info in production
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please check configuration.");
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    // Prevent cloning
    private function __clone()
    {
    }

    // Prevent unserialization
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Get database connection
 * @return PDO
 */
function getDB()
{
    return Database::getInstance()->getConnection();
}

/**
 * Never show a blank page again.
 *
 * WHY
 * ---
 * This project produced a run of HTTP 500s that all looked identical from the
 * browser: a white page reading "unable to handle this request". The file and
 * line were in admin/error_log, which is 403 over HTTP on purpose, so the shop
 * could not see them and neither could anyone diagnosing it remotely. Every one
 * of those pages looked the same: "the server is broken".
 *
 * An uncaught exception is now caught, written to the log with a short
 * reference code, and rendered as a page that says what actually went wrong. For
 * an admin it includes the file, line and message, because that is the person
 * who can act on it. For anyone else it is a plain apology plus the reference
 * code - enough to quote, nothing more.
 *
 * Installed from db.php because every page in the project includes it, so there
 * is no way for a request to arrive that has not been through here.
 */
function appInstallErrorHandler()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Deliberately NO set_error_handler() that promotes notices to exceptions.
    //
    // An earlier version of this file did that, on the theory that it would
    // surface problems. It was a mistake: this codebase carries a long tail of
    // harmless notices (1,648 of them in one day, all four from the Print Labels
    // modal reading settings it had not loaded), and promoting those to fatal
    // turned pages that had always rendered into HTTP 500s. cashier/products.php
    // reads an undefined $user once and had been working for months; the
    // "improvement" broke it.
    //
    // Notices and warnings are left to PHP, which logs them and carries on. Only
    // genuinely uncaught errors reach the handler below. A real fault should be
    // fixed, not converted into a louder version of itself.

    set_exception_handler(function ($e) {
        $ref = substr(str_replace('-', '', uniqid('', true)), 0, 8);
        $entry = sprintf(
            "[%s] FATAL %s | ref=%s | %s%s in %s:%d\n%s\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $ref,
            $e->getCode() ? '(' . $e->getCode() . ') ' : '',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        @file_put_contents(__DIR__ . '/../admin/error_log', $entry, FILE_APPEND);
        error_log('appFatal ref=' . $ref . ' ' . $e->getFile() . ':' . $e->getLine() . ' ' . $e->getMessage());

        appFatalReported(true);
        appRenderError($e, $ref);
        exit;
    });

    // The handler above only sees what PHP raises as a catchable Error or
    // Exception, which is most things but not everything.
    //
    // A memory-limit exhaustion, a parse error in an included file, or a script
    // killed while building an enormous result set is a hard fatal: PHP prints
    // nothing, writes nothing here, and the browser is left with a blank page or
    // a half-painted one. That is the shape of the reports that arrive as
    // "the page is empty under the shop name" - and the log has no line for it,
    // so there is nothing to quote and nothing to read.
    appInstallFatalShutdown();
}

/**
 * Say something when PHP dies in a way the exception handler cannot see.
 *
 * Writes the same kind of log line, with the same kind of reference code, as
 * appInstallErrorHandler() does for a caught error, and renders the same page, so
 * a hard fatal is diagnosable from the browser instead of needing server logs
 * nobody can reach from a shop.
 */
function appInstallFatalShutdown()
{
    register_shutdown_function(function () {
        if (appFatalReported()) {
            return; // the exception handler already wrote this request's line
        }
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        // An out-of-memory fatal has already spent the allowance, and rendering a
        // page needs a little more. Without this the handler dies exactly as
        // quietly as the page did.
        if (stripos($err['message'], 'memory') !== false) {
            $limit = (int)(ini_get('memory_limit') ?: 128);
            @ini_set('memory_limit', ($limit + 64) . 'M');
        }

        $ref = substr(str_replace('-', '', uniqid('', true)), 0, 8);
        $entry = sprintf(
            "[%s] FATAL %s | ref=%s | %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            appFatalKind($err['type']),
            $ref,
            $err['message'],
            $err['file'],
            $err['line']
        );
        @file_put_contents(__DIR__ . '/../admin/error_log', $entry, FILE_APPEND);
        error_log('appFatal ref=' . $ref . ' ' . $err['file'] . ':' . $err['line'] . ' ' . $err['message']);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $entry);
            return;
        }

        appFatalReported(true);
        appRenderError($err, $ref);
    });
}

/**
 * Whether this request has already had its fatal written and shown.
 *
 * Both reporters run at the end of a request, and a page that raised a caught
 * Error and then tripped over something on the way out would otherwise log twice
 * with two different reference codes - the shop would quote the wrong one.
 */
function appFatalReported($set = false)
{
    static $done = false;
    if ($set) {
        $done = true;
    }
    return $done;
}

/**
 * The name of a fatal, because the number alone does not say how serious it was.
 */
function appFatalKind($type)
{
    switch ($type) {
        case E_ERROR:        return 'E_ERROR (unrecoverable)';
        case E_PARSE:        return 'E_PARSE (could not read the file)';
        case E_COMPILE_ERROR: return 'E_COMPILE_ERROR';
        case E_CORE_ERROR:   return 'E_CORE_ERROR (PHP itself)';
        case E_USER_ERROR:   return 'E_USER_ERROR';
    }
    return 'FATAL';
}

/**
 * The page shown instead of a blank 500.
 *
 * Accepts either a Throwable from the exception handler or the array PHP hands to
 * a shutdown fatal, because the two look identical to the person watching an
 * empty screen and both have to be explained the same way.
 */
function appRenderError($e, $ref)
{
    if (is_array($e)) {
        $what  = appFatalKind(isset($e['type']) ? $e['type'] : 0);
        $code  = 0;
        $msg   = isset($e['message']) ? (string)$e['message'] : '';
        $file  = isset($e['file']) ? (string)$e['file'] : '';
        $line  = isset($e['line']) ? (int)$e['line'] : 0;
        // PHP gives no stack for these - the interpreter stopped rather than
        // unwound - so say that instead of leaving the reader to think the trace
        // was lost.
        $trace = '(no stack trace: PHP stopped at this line rather than raising the error)';
    } else {
        $what  = get_class($e);
        $code  = (int)$e->getCode();
        $msg   = $e->getMessage();
        $file  = $e->getFile();
        $line  = $e->getLine();
        $trace = $e->getTraceAsString();
    }

    // An API endpoint has to get JSON, not HTML, or the caller cannot tell what
    // happened and the browser shows a wall of markup.
    $isApi = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
          || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . ($isApi ? 'application/json' : 'text/html') . '; charset=utf-8');
    }

    if ($isApi) {
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Server error', 'ref' => $ref]);
        return;
    }

    // Detail only for a signed-in admin. Everyone else gets the reference code.
    $isAdmin = false;
    try {
        $isAdmin = isLoggedIn() && hasRole('admin');
    } catch (Throwable $ignored) {
        $isAdmin = false;
    }

    $detail = '';
    if ($isAdmin) {
        $detail = '<pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:8px;'
                . 'overflow:auto;font-size:.82rem;line-height:1.5;max-height:340px">'
                . htmlspecialchars(
                    $what . ($code ? ' (' . $code . ')' : '') . "\n\n"
                    . $msg . "\n\n"
                    . 'in ' . $file . ':' . $line . "\n\n"
                    . $trace
                )
                . '</pre>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Server error</title>'
       . '<style>body{font-family:-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;'
       . 'margin:0;padding:2rem}.w{max-width:840px;margin:0 auto}h1{font-size:1.25rem;margin:0 0 .4rem}'
       . '.s{color:#6b7280;margin:0 0 1.4rem;font-size:.92rem}'
       . '.b{background:#fff;border-left:4px solid #dc2626;padding:1rem 1.2rem;border-radius:8px;'
       . 'box-shadow:0 1px 3px rgba(0,0,0,.08)}'
       . 'code{background:#f3f4f6;padding:.1rem .4rem;border-radius:3px}'
       . 'a{color:#2563eb}</style></head><body><div class="w">'
       . '<h1>The page could not be completed</h1>'
       . '<p class="s">This is a fault in the application or the server, not something you did.</p>'
       . '<div class="b"><p>Quote this reference if you report it:</p>'
       . '<p><code>' . htmlspecialchars($ref) . '</code></p>'
       . $detail
       . '<p style="font-size:.86rem;color:#6b7280;margin-bottom:0">'
       . 'The full detail, including the file and line, is written to '
       . '<code>admin/error_log</code>.</p></div>'
       . '<p style="margin-top:1.4rem"><a href="../index.php">&larr; Back</a></p>'
       . '</div></body></html>';
}

/**
 * Refuse to run a page that needs a table the database does not have, and say so
 * in words.
 *
 * appSafeDdl() stops a page dying because the database user may not run CREATE.
 * That is only half the problem. If the table genuinely is not there - a fresh
 * install, or a migration that was never imported - the CREATE is skipped
 * (correctly, no privilege to create it) and the very next SELECT raises
 *     SQLSTATE[42S02]: Base table or view not found
 * which is still an HTTP 500, just a different one.
 *
 * So the tables that used to be created at page load are checked here, and a
 * missing one produces an instruction naming the file to import rather than a
 * stack trace.
 *
 * @param array $tables table names this page cannot work without
 * @return bool true when everything needed is present
 */
function appRequireTables($tables, $db = null)
{
    if ($db === null) {
        try {
            $db = getDB();
        } catch (Throwable $e) {
            return true; // no connection; the normal path reports that
        }
    }
    $missing = [];
    foreach ($tables as $t) {
        if (!appTableExists($db, $t)) {
            $missing[] = $t;
        }
    }
    if (!$missing) {
        return true;
    }

    $isAdmin = false;
    try {
        $isAdmin = isLoggedIn() && hasRole('admin');
    } catch (Throwable $ignored) {
        $isAdmin = false;
    }

    $how = $isAdmin
        ? 'Import <code>config/migration_002_runtime_tables.sql</code> through phpMyAdmin. It is safe to run more than once.'
        : 'Tell your administrator - the database is missing a table this page needs.';

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Database not set up</title>'
       . '<style>body{font-family:-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;'
       . 'margin:0;padding:2rem}.w{max-width:760px;margin:0 auto}h1{font-size:1.25rem;margin:0 0 .4rem}'
       . '.s{color:#6b7280;font-size:.92rem}.b{background:#fff;border-left:4px solid #d97706;'
       . 'padding:1rem 1.2rem;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
       . 'code{background:#f3f4f6;padding:.1rem .4rem;border-radius:3px}</style></head><body><div class="w">'
       . '<h1>Database not set up</h1>'
       . '<p class="s">This page cannot run because the database is missing '
       . count($missing) . ' table(s) it needs. The page itself is fine.</p>'
       . '<div class="b"><p><strong>Missing:</strong> '
       . htmlspecialchars(implode(', ', $missing)) . '</p><p>' . $how . '</p></div>'
       . '<p style="margin-top:1.4rem"><a href="../index.php">&larr; Back</a></p>'
       . '</div></body></html>';
    exit;
}

// Installed here, after the handlers exist and before any page gets a chance to
// fail. Every page in this project includes config/db.php, so from this line on
// an uncaught error anywhere in the application is reported rather than shown
// as a blank page.
appInstallErrorHandler();

/**
 * Run a schema statement (CREATE / ALTER) without ever being able to take the
 * page down.
 *
 * WHY THIS EXISTS
 * ---------------
 * This project grew its tables at page-load time: products.php and
 * variables.php call productVariablesEnsureTables(), and roles.php, staff.php,
 * cashbook.php, expense.php, sales.php, mark-printed.php and db.php itself all
 * issued CREATE TABLE or ALTER TABLE inline, each behind a comment calling it a
 * "safe migration". On XAMPP that is harmless. On cPanel it is fatal, and the
 * usual grant there is only SELECT, INSERT, UPDATE and DELETE.
 *
 * The trap is that IF NOT EXISTS does not help. MySQL checks the privilege
 * first and only then looks at whether the table is already there, so
 * "CREATE TABLE IF NOT EXISTS" is refused with
 *     1142 CREATE command denied to user 'x'@'localhost'
 * even on a database where the table has been sitting there for a year. Every
 * one of those pages was a blank HTTP 500 with nothing in the browser to
 * explain it, because the exception was never caught.
 *
 * So: swallow it, remember it, and let the page carry on. If the table really
 * is missing the query that needs it will still fail, but it will fail with the
 * table's own error rather than a privilege error from a migration that had
 * already done its job.
 *
 * @return bool true if the statement ran, false if it was refused or failed
 */
function appSafeDdl($db, $sql)
{
    static $denied = [];
    // MySQL finishes the open transaction before it runs a DDL statement, and PDO
    // then reports that there is no transaction at all. A migration attempted
    // mid-sale therefore does not quietly add a column: it commits the half-built
    // sale, and the caller's own commit() dies with "There is no active
    // transaction" on a bill that has already been written. Refusing costs a column
    // that the next page load creates; running it can cost a sale.
    if ($db->inTransaction()) {
        error_log('appSafeDdl deferred, a transaction is open: '
            . substr(preg_replace('~\s+~', ' ', $sql), 0, 60));
        return false;
    }
    try {
        $db->exec($sql);
        return true;
    } catch (Throwable $e) {
        // 1142/1144 are privilege errors, 1050 a duplicate table and 1060 a
        // duplicate column - all of which mean "already fine, carry on".
        $key = substr(preg_replace('~\s+~', ' ', $sql), 0, 60);
        if (!isset($denied[$key])) {
            $denied[$key] = true;
            error_log('appSafeDdl skipped: ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Does this table exist? Used to decide whether to say "table missing, run the
 * installer" instead of letting a query die with an unreadable error.
 */
function appTableExists($db, $table)
{
    static $known = [];
    $table = strtolower($table);
    if (array_key_exists($table, $known)) {
        return $known[$table];
    }
    try {
        $st = $db->prepare('SELECT 1 FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $st->execute(array($table));
        $known[$table] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // No information_schema access: assume present rather than block the page.
        $known[$table] = true;
    }
    return $known[$table];
}

/**
 * Cross-request memory for "the schema is already settled" answers.
 *
 * WHY THIS EXISTS
 * ---------------
 * The sale door runs its schema checks on every post: ensureSaleStoreColumns()
 * alone is five SHOW statements, and ensureCashbookModuleTables() behind it is
 * two CREATE TABLE IF NOT EXISTS, more SHOWs and a backfill scan over
 * cashbook_entries. Their static caches only live for one request - PHP throws
 * them away the moment the sale is saved - so a till paid that tax on every
 * single customer. On a remote database each of those round trips is real
 * time, and a basket of a few items was spending longer settling the schema
 * than writing the sale.
 *
 * The answer a check found does not change between requests: a column that
 * exists today did not exist yesterday's way. So each checker stamps a small
 * file here once it has verified (or built) what it needs, and every later
 * request trusts that stamp and skips straight to the work. The stamp expires
 * after a day, so a column someone drops by hand is re-noticed, and a check
 * that FAILED is never stamped at all - a host without DDL rights keeps
 * retrying rather than caching a lie.
 *
 * Best effort in both directions: a read-only temp dir or a failed write just
 * means the checks run again next time, which is exactly today's behaviour.
 */
function appSchemaFlagPath($key)
{
    static $dir = null;
    if ($dir === null) {
        // The database name is in the key so two installs sharing a temp dir
        // (a dev copy beside a live one) never inherit each other's answer.
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pos-schema-' . md5(__DIR__ . '|' . DB_NAME);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
    return $dir . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.flag';
}

/** Was this schema check already settled recently? $ttlHours of distrust. */
function appSchemaFlagOk($key, $ttlHours = 24)
{
    $t = @filemtime(appSchemaFlagPath($key));
    return $t !== false && (time() - $t) < ($ttlHours * 3600);
}

/** Stamp a schema check as settled. Never stamped for a failed check. */
function appSchemaFlagSet($key)
{
    @file_put_contents(appSchemaFlagPath($key), (string)time(), LOCK_EX);
}

/**
 * Is cURL available?
 *
 * curl_init() is called from config/marketing.php, config/sms_gateway.php and
 * config/google_contacts.php. On a host without the extension it raises
 * "Error: Call to undefined function curl_init()" - and that is an Error, not
 * an Exception, so a catch (Exception) around it catches nothing and the page
 * is a blank 500. Every caller has to ask here first.
 */
function appHasCurl()
{
    static $has = null;
    if ($has === null) {
        $has = function_exists('curl_init');
    }
    return $has;
}

/**
 * Start secure session
 */
function startSecureSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Set session save path within project to avoid cPanel tmp issues
        $sessionPath = __DIR__ . '/../sessions';
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0755, true);
        }
        session_save_path($sessionPath);

        // Set session cookie parameters for security
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

        @ini_set('session.cookie_httponly', 1);
        @ini_set('session.use_only_cookies', 1);
        // A session id nobody created is rejected instead of silently minting an
        // empty session file for it.
        @ini_set('session.use_strict_mode', 1);

        // How long a shift is, not how long a coffee break.
        //
        // XAMPP and most cPanel hosts ship gc_maxlifetime=1440, and this project
        // keeps its own session directory, so that timer applies to it: twenty-four
        // minutes spent reading a price list, helping a customer, or just leaving the
        // tab open logged the shopkeeper out with no message and no redirect - the
        // next menu click answered with the login form. Every link then looked dead,
        // which is not a broken menu but an expired one, and the difference is
        // invisible to the person standing at the counter.
        @ini_set('session.gc_maxlifetime', 28800);

        if ($secure) {
            @ini_set('session.cookie_secure', 1);
        }

        if (!headers_sent()) {
            session_start();
            // GC compares a session file's mtime, and a page that only READS the
            // session never rewrites it, so an actively used login still aged out.
            // Touching the file is how it says "still here" - deliberately not a
            // $_SESSION write, which would lock the session file for every request
            // and make a background poll queue behind a page load.
            if (isset($_SESSION['user_id'])) {
                @touch($sessionPath . '/sess_' . session_id());
            }
        }
    }
}

/**
 * Sanitize input
 * @param string $input
 * @return string
 */
function sanitize($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * The URL prefix this application is served under.
 *
 * WHY THIS IS CALCULATED INSTEAD OF WRITTEN DOWN
 * ----------------------------------------------
 * The same code is served from two quite different places. Under XAMPP the
 * project is a folder INSIDE the document root, so its assets live at
 * http://localhost/admin/assets/... . On the live host the project IS the
 * document root, so the same assets live at https://the-shop.example/assets/...
 * A hard-coded "/admin" fixes the shopkeeper's machine and breaks the shop; a
 * hard-coded "" does the reverse. Neither constant is a fix, it is a coin toss.
 *
 * So the prefix is measured rather than assumed. DOCUMENT_ROOT says which URL
 * maps to the server's document root; dirname(__DIR__) says where this project
 * sits on disk; the difference between the two is the prefix. Neither end of
 * that comparison depends on which page is asking, which is what makes the
 * answer the same for home.php and for auth/login.php.
 *
 * The obvious alternative - walking up from the current script - is the trap
 * this replaces. It has to know how deep the page is, and the one page that is
 * one folder deeper than every other looks exactly like a project that lives
 * one folder deeper. See getAppBaseUrl() below, which did exactly that.
 *
 * @return string  "" when the project is the document root, otherwise a path
 *                 that starts with "/" and does not end with one, e.g. "/admin".
 */
function appBasePath()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    // Escape hatch for a host that serves this directory from a URL the disk
    // layout does not describe. Deliberately not defined by default, so it can
    // never be wrong by accident - a wrong constant is worse than a wrong guess.
    if (defined('APP_BASE_PATH')) {
        $base = rtrim((string)APP_BASE_PATH, '/');
        return $base;
    }

    $projectDir = str_replace('\\', '/', dirname(__DIR__));

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
    if ($documentRoot === '') {
        // No document root to compare against: the CLI, or a SAPI that does not
        // report one. A relative URL is the only answer that can be right here.
        $base = '';
        return $base;
    }

    $documentRoot = str_replace('\\', '/', rtrim($documentRoot, '/'));

    // realpath() settles two things DOCUMENT_ROOT gets wrong on Windows: the
    // 8.3 short form (HTDOCS~1) that will not prefix-match the long name, and
    // a trailing separator. Compared case-insensitively because that is the
    // rule on Windows, and on Linux the two spellings match anyway.
    $projectReal = str_replace('\\', '/', realpath($projectDir) ?: $projectDir);
    $documentReal = str_replace('\\', '/', realpath($documentRoot) ?: $documentRoot);

    if (strcasecmp($projectReal, $documentReal) === 0) {
        $base = '';
        return $base;
    }

    // The trailing slash on the needle is what stops "/srv/shop" from matching a
    // document root of "/srv" and reporting "/shop" for a project that is
    // actually a sibling of it.
    if (stripos($projectReal . '/', $documentReal . '/') === 0) {
        $base = substr($projectReal, strlen($documentReal));
        return $base;
    }

    // The project lives outside the document root, so no URL prefix can be
    // correct. "" degrades to the old relative behaviour rather than inventing
    // a path that is certainly wrong.
    $base = '';
    return $base;
}

/**
 * A URL for a local asset that the browser will not serve from an old cache.
 *
 * WHY THE URL IS ROOT-RELATIVE
 * ----------------------------
 * The returned URL starts at the server root, not at the current directory.
 * This was the second half of a 404 that had been happening on every page in
 * auth/, admin/, cashier/ and staff/: a bare "assets/css/style.css" is resolved
 * by the browser against the page it appears on, so the stylesheet on
 * /admin/marketing.php was requested as /admin/assets/css/style.css - which is
 * right - while the very same include on /auth/login.php asked for
 * /auth/assets/css/style.css, and on the live host, where the project is the
 * document root, every page asked for /admin/assets/... and got nothing. A
 * relative path is only correct by coincidence, and only for pages in the
 * project root. A leading slash makes it correct on purpose, everywhere.
 *
 * WHY THE VERSION IS THERE
 * ------------------------
 * Not one script or stylesheet in this project was included with a version. That
 * means a fixed JavaScript file can sit in the shopkeeper's browser cache
 * indefinitely: the file on the server is correct, the file the browser runs is
 * not, and the error on screen refers to a line number that no longer exists in
 * the source. It happened with assets/js/app.js - a fix went in, the page was
 * reloaded, and the identical error was reported because the old file was still
 * being executed. Hard-reloading works right up until it does not, and telling
 * a shopkeeper to try Ctrl+F5 is not a fix.
 *
 * The version is the file's own mtime, so it changes exactly when the file does.
 * Nothing to bump by hand, and a stale URL cannot be written by accident: replace
 * a file and every page that includes it asks for the new one.
 *
 * External URLs - a CDN, a font - are returned untouched. Appending a query to
 * somebody else's file does nothing useful and can break their caching.
 *
 * @param string $path  project-relative, e.g. assets/js/app.js
 * @return string
 */
function assetUrl($path)
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    // Whether this is somebody else's file is decided BEFORE the leading slash is
    // stripped, not after. "//cdn.example.com/x.js" is a protocol-relative URL;
    // trimming first turns it into "cdn.example.com/x.js", which the external
    // check no longer recognises and which the browser then resolves against the
    // current directory - a CDN include silently becomes a broken path.
    if (preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $path)) {
        return $path;
    }

    $path = ltrim($path, '/');

    // Already versioned, so a caller passing "?v=3" is respected.
    if (strpos($path, '?') !== false) {
        return $path;
    }

    $root = dirname(__DIR__);
    $full = $root . '/' . $path;

    // The version is looked up on disk with the project-relative path, and the
    // prefix is added only to the URL - one is a file, the other is a location.
    $url = appBasePath() . '/' . $path;

    // A missing file is returned as-is. Appending a version to a path that does
    // not exist only makes the 404 harder to read in the server log.
    $mtime = @filemtime($full);
    if ($mtime === false) {
        return $url;
    }

    return $url . '?v=' . $mtime;
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn()
{
    startSecureSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user data
 * Returns effective_owner_id: for admin/owner users (owner_id=NULL), uses their own id.
 * @return array|null
 */
function getCurrentUser()
{
    if (!isLoggedIn()) {
        return null;
    }
    $userId   = $_SESSION['user_id']    ?? null;
    $ownerId  = $_SESSION['owner_id']   ?? null;
    // Admins who ARE the owner have owner_id = NULL in DB.
    // Use their own id as the effective owner_id so all owner-scoped
    // queries (customers, settings, products …) work correctly.
    $effectiveOwnerId = $ownerId ?? $userId;
    return [
        'id'               => $userId,
        'name'             => $_SESSION['user_name']  ?? null,
        'email'            => $_SESSION['user_email'] ?? null,
        // The live role, so a page that branches on it agrees with what
        // hasPermission() decided on this same request rather than with
        // whatever the session was written as at login.
        'role'             => currentRole() ?: ($_SESSION['user_role'] ?? null),
        'store_id'         => $_SESSION['store_id']   ?? null,
        'owner_id'         => $effectiveOwnerId,   // always non-null for logged-in users
        'raw_owner_id'     => $ownerId,             // actual DB value (may be null for top-level admins)
    ];
}

/**
 * The role this session's user actually holds right now.
 *
 * Read from the users table rather than from the session, because the session
 * copy is written once at login and never refreshed. Permissions were re-read on
 * every request through the permissions_version check, so ticking a box on the
 * Roles page took effect immediately - but changing somebody's ROLE did not,
 * and did not take effect until they logged out and back in. An account moved
 * from cashier to staff went on resolving as a cashier: wrong menu, wrong landing
 * page, wrong everything, and nothing on screen to say why.
 *
 * Once per request. Falls back to the session if the row cannot be read, so a
 * database problem cannot lock everybody out of their own account.
 *
 * @return string
 */
function currentRole()
{
    static $role = null;
    if ($role !== null) { return $role; }
    $uid = $_SESSION['user_id'] ?? null;
    if (!$uid) { return $role = ($_SESSION['user_role'] ?? ''); }
    try {
        $st = getDB()->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute(array((int)$uid));
        $live = $st->fetchColumn();
        if ($live !== false && $live !== null && $live !== '') {
            return $role = (string)$live;
        }
    } catch (Exception $e) {
        // Fall through to the session copy below.
    }
    return $role = (string)($_SESSION['user_role'] ?? '');
}

/**
 * Check if user has specific role
 * @param string|array $roles
 * @return bool
 */
function hasRole($roles)
{
    if (!isLoggedIn()) {
        return false;
    }
    if (is_string($roles)) {
        $roles = [$roles];
    }
    return in_array(currentRole(), $roles);
}

/**
 * Roles that bypass the permission table entirely.
 *
 * Admin was the only one, and that left every staff account dead on arrival:
 * admin/staff.php creates them with role 'staff', but users.role was an
 * ENUM that did not list 'staff' and silently stored an empty string. They
 * logged in and hasPermission() matched no rows, so they saw no menu at all.
 *
 * Read from the roles table rather than hardcoded, so deleting the owner role
 * takes the blanket access with it.
 *
 * @return string[]
 */
function adminSlugs()
{
    static $slugs = null;
    if ($slugs === null) {
        $slugs = ['admin'];
        try {
            $stmt = getDB()->query(
                "SELECT slug FROM roles WHERE status = 'active'
                 AND slug IN ('admin', 'owner')"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $slugs[] = $slug;
            }
        } catch (Exception $e) {
            // No roles table: admin still works, owner falls through to the
            // lookup below, which is the old behaviour and not harmful.
        }
    }
    return $slugs;
}





/**
 * Does this login account have a staff record?
 *
 * The staff pages are for a person on the payroll, and the staff table is created
 * by admin/staff.php. An account can exist without one - made from the Users page,
 * or given the 'staff' role by hand - and that account has no profile to show.
 *
 * Checks the table's existence too: staff.php creates it on first load, so a shop
 * that has never opened that page has no staff table at all, and asking about it
 * unconditionally would be a fatal rather than a false.
 *
 * @return bool
 */
function staffProfileExists($user = null)
{
    static $missingTable = false;
    if ($missingTable) { return false; }
    if (!is_array($user)) { $user = getCurrentUser(); }
    if (!$user) { return false; }
    try {
        $st = getDB()->prepare("SELECT 1 FROM staff WHERE user_id = ? LIMIT 1");
        $st->execute(array($user['id']));
        return (bool)$st->fetchColumn();
    } catch (Exception $e) {
        // A missing table means the Staff page has never been opened in this shop.
        $missingTable = true;
        return false;
    }
}
/**
 * Decide where a user lands, by permission rather than by role name.
 *
 * The login page picked a destination by comparing users.role against the literal
 * strings 'admin' and 'staff'. That produced both symptoms being reported: a role
 * the shop had granted permissions to still landed on a fixed page, and a user
 * with role 'staff' created from the Users page rather than the Staff page had no
 * staff row, so their home page was an error.
 *
 * The order is the one the shop expects to meet: the dashboard first, because it
 * is the only page whose sidebar is driven by permissions, then the screens a
 * counter actually works on. Null means the role can open nothing here, so the
 * caller can say so rather than guess.
 *
 * Returns a filename relative to admin/, not a path - the callers sit in different
 * directories and each knows its own prefix.
 *
 * @return string|null
 */
function defaultLandingPage()
{
    $order = array(
        'dashboard.php' => 'dashboard',
        'pos.php'       => 'pos',
        'sales.php'     => 'sales',
        'products.php'  => 'products',
        'customers.php' => 'customers',
        'stock.php'     => 'stock',
        'expense.php'   => 'cashbook',
        'reports.php'   => 'reports',
        'marketing.php' => 'marketing',
        'staff.php'     => 'staff',
    );
    foreach ($order as $page => $need) {
        if (hasPermission($need)) { return $page; }
    }
    return null;
}

/**
 * The navigation a role should be offered, for the sidebars that are not the admin
 * one.
 *
 * The staff pages used to carry their own hand-written list of links, and it drifted
 * from the admin menu the same way the phone app's copy did - a cashier granted
 * Products and Stock saw neither, and saw Customers whether or not they had it. The
 * list is therefore read from config/nav-menu.php, the one table the admin sidebar
 * and mobile_api/menu.php also use, and filtered here by the one visibility rule
 * (navItemVisible) all three share. Whatever a role may open is the same list on
 * every screen, and adding an entry to the menu is a one-line change in one file.
 *
 * The rows come back already filtered, because a row gated on a role rather than a
 * permission (My Package) has no permission key for a caller to test.
 *
 * @return array<int, array{page:string,label:string,icon:string,child:bool}>
 */
function permissionNavItems()
{
    require_once __DIR__ . '/nav-menu.php';
    $nav = array();
    foreach (navGroups() as $group) {
        $rows = array(array($group, false));
        foreach (isset($group['children']) ? $group['children'] : array() as $child) {
            $rows[] = array($child, true);
        }
        foreach ($rows as $pair) {
            $row = $pair[0];
            if (!navItemVisible($row)) { continue; }
            $nav[] = array(
                'page'  => basename((string)$row['href']),
                'label' => (string)$row['label'],
                // The full FontAwesome class ("fas fa-box", "fas fa-chart-line"):
                // a brand glyph needs its own prefix, so these sidebars echo the
                // whole thing instead of adding a style class of their own.
                'icon'  => (string)$row['icon'],
                'child' => $pair[1],
            );
        }
    }
    return $nav;
}
/**
 * The plan slug that gates this request's features, or null when nothing gates.
 *
 * null means "do not gate": not logged in, or the owner has no subscription row
 * yet. That last case is deliberate - accounts that existed before the package
 * system are grandfathered and keep working until they buy, matching the
 * "existing users just renew to continue" rule. A real slug means the shop is on
 * that plan and is limited to its feature set.
 *
 * Read once per request. Any DB problem (including the table not existing yet)
 * fails open so a packaging issue can never lock a shop out of its own panel.
 */
function currentPlanSlug()
{
    static $loaded = false;
    static $slug = null;
    if ($loaded) { return $slug; }
    $loaded = true;
    if (!isLoggedIn()) { return $slug = null; }
    try {
        $u = getCurrentUser();
        $ownerId = (int)($u['owner_id'] ?: $u['id']);
        if ($ownerId <= 0) { return $slug = null; }
        $st = getDB()->prepare("SELECT plan_slug FROM subscriptions WHERE owner_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$ownerId]);
        $row = $st->fetchColumn();
        $slug = ($row === false || $row === null || $row === '') ? null : (string)$row;
    } catch (Exception $e) {
        $slug = null;
    }
    return $slug;
}

/**
 * May the current shop's plan use this permission key?
 *
 * The one place plan-based feature gating lives, called from hasPermission() so it
 * covers both the sidebar menu and every page's own access guard at once.
 */
function planAllows($permission)
{
    $slug = currentPlanSlug();
    if ($slug === null) { return true; }   // no plan row -> ungated (legacy / not bought yet)

    // dashboard + settings stay reachable so an account can always view its plan
    // and configure itself - it can never gate itself into a dead end.
    if ($permission === 'dashboard' || $permission === 'settings') { return true; }

    static $perms = null;
    if ($perms === null) {
        $perms = [];
        $plans = @include __DIR__ . '/plans.php';
        if (is_array($plans)) {
            foreach ($plans as $key => $plan) {
                $perms[$key] = (isset($plan['perms']) && is_array($plan['perms'])) ? $plan['perms'] : null;
            }
        }
    }
    if (!isset($perms[$slug]) || $perms[$slug] === null) { return true; }  // unknown plan -> fail open
    return in_array($permission, $perms[$slug], true);
}

/**
 * How many branches this shop may run on its current package, or null for no cap.
 *
 * A store count cannot be a permission key: Business sells exactly two branches and
 * Pro sells as many as the owner opens, and one flag cannot say both. So the ceiling
 * lives next to the price and admin/stores.php compares it against the rows.
 *
 * null means "do not cap" - no subscription row (a shop that predates the packages),
 * an unknown plan, or a plan that lists no limit. Same fail-open rule as
 * planAllows(): a packaging mistake must never stop an owner from opening a till
 * they have already paid for.
 *
 * @return int|null
 */
function planStoreLimit()
{
    $slug = currentPlanSlug();
    if ($slug === null) { return null; }

    static $limits = null;
    if ($limits === null) {
        $limits = [];
        $plans = @include __DIR__ . '/plans.php';
        if (is_array($plans)) {
            foreach ($plans as $key => $plan) {
                $limits[$key] = isset($plan['store_limit']) ? (int)$plan['store_limit'] : null;
            }
        }
    }
    return isset($limits[$slug]) ? $limits[$slug] : null;
}

/**
 * Check if user's role has a specific permission
 * Admins always have full access (backward compatible)
 * Realtime: checks permissions_version to auto-refresh when permissions change
 * @param string $permission  e.g. 'sales', 'sales_delete', 'reports'
 * @return bool
 */
function hasPermission($permission)
{
    if (!isLoggedIn()) {
        return false;
    }

    // Plan gate first, so even an owner is limited to what their package includes.
    // Upgrading is the only way to widen it; a shop with no plan is not gated.
    if (!planAllows($permission)) {
        return false;
    }

    // The live role, not the session copy - see currentRole().
    $role = currentRole();

    // Admin and owner always have full access.
    if ($role !== '' && in_array($role, adminSlugs(), true)) {
        return true;
    }

    // Get current permissions version from DB
    $currentVersion = null;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'permissions_version' LIMIT 1");
        $stmt->execute();
        $currentVersion = $stmt->fetchColumn();
    } catch (Exception $e) {
        $currentVersion = null;
    }

    $cachedVersion = $_SESSION['_permissions_version'] ?? null;
    $cachedRole    = $_SESSION['_permissions_role'] ?? null;

    // Re-fetch when the version moved, when nothing is cached, or when the role is
    // not the one the cached list was built for.
    //
    // The role check is the one that was missing. permissions_version is only
    // bumped when somebody saves the Roles page, so ticking a box took effect at
    // once - but moving a user to a different role touches no version, and the list
    // was not keyed on the role either. The session went on answering with the old
    // role's permissions until they logged out and back in.
    if (!isset($_SESSION['_permissions'])
        || $cachedVersion !== $currentVersion
        || $cachedRole !== $role) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT permission FROM role_permissions WHERE role_slug = ?");
            $stmt->execute([$role]);
            $_SESSION['_permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $_SESSION['_permissions_version'] = $currentVersion;
            $_SESSION['_permissions_role'] = $role;
        } catch (Exception $e) {
            $_SESSION['_permissions'] = [];
            $_SESSION['_permissions_role'] = $role;
        }
    }

    return in_array($permission, $_SESSION['_permissions']);
}

/**
 * Clear cached permissions and bump version for realtime sync
 * Call this after updating role permissions
 */
function clearPermissionCache()
{
    unset($_SESSION['_permissions']);
    unset($_SESSION['_permissions_version']);
    unset($_SESSION['_permissions_role']);

    // Bump permissions version so ALL users re-fetch on next request
    try {
        $db = getDB();
        $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('permissions_version', '1') ON DUPLICATE KEY UPDATE setting_value = CAST((CAST(setting_value AS UNSIGNED) + 1) AS CHAR)");
    } catch (Exception $e) {
        // Table may not exist yet
    }
}

/**
 * Roles whose dashboard is limited to the KPI cards an admin ticked for them.
 *
 * These are the counter accounts (the same set as isOwnSalesOnlyUser): the
 * person behind the till is not the shop's keeper, so the dashboard is
 * narrowed one account at a time instead of one role at a time. Owner, admin
 * and manager keep the full dashboard, which is why nothing changes on their
 * screens.
 *
 * @return string[]
 */
function kpiGatedRoles()
{
    return array('staff', 'cashier');
}

/**
 * The KPI card keys this account has been granted, read once per request.
 *
 * Stored per user (user_kpi_permissions), not per role, because two people on
 * the same staff role can be trusted with different figures. An empty list is
 * the default: a staff account sees no cards until an admin ticks them. Read
 * per request rather than cached in the session, so unticking a box is felt on
 * the account's very next page load.
 *
 * A missing table or a read error answers "no cards" - failing closed is the
 * right direction for a permission, and it is why the SQL has to be imported
 * before the code is uploaded.
 *
 * @param int $userId
 * @return string[]
 */
function kpiPermissionsForUser($userId)
{
    static $cache = array();
    $userId = (int)$userId;
    if ($userId <= 0) {
        return array();
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $keys = array();
    try {
        $st = getDB()->prepare("SELECT kpi_key FROM user_kpi_permissions WHERE user_id = ?");
        $st->execute(array($userId));
        $keys = $st->fetchAll(PDO::FETCH_COLUMN) ?: array();
    } catch (Throwable $e) {
        $keys = array();
    }
    return $cache[$userId] = $keys;
}

/**
 * May this account see this dashboard KPI card?
 *
 * Everyone who is not a counter account always gets true, so the owner's and
 * manager's dashboard is exactly as it was. For staff/cashier the answer is
 * the ticked boxes on the Users page - and because the page only emits the
 * cards this returns true for, an unticked card leaves neither a placeholder
 * nor any figure in the HTML.
 *
 * @param string $key  one of config/kpi-permissions.php's keys
 * @param array|null $user  getCurrentUser(); null reads the logged-in user
 * @return bool
 */
function hasKpiPermission($key, $user = null)
{
    $user = $user !== null ? $user : getCurrentUser();
    if (!$user) {
        return false;
    }
    if (!in_array((string)($user['role'] ?? ''), kpiGatedRoles(), true)) {
        return true;
    }
    return in_array($key, kpiPermissionsForUser((int)$user['id']), true);
}

/**
 * Guard an admin page behind one permission key, the way the Roles page offers
 * them and the sidebar already reads them.
 *
 * Several pages asked for hasRole('admin') instead of their permission, so
 * ticking "Stores" or "Vouchers" for a manager produced a menu item that
 * bounced them to the login screen: the sidebar showed the link because
 * hasPermission() said yes, and the page then refused because the role was not
 * literally "admin". Going through this helper is what makes the checkbox the
 * single answer.
 *
 * admin and owner still pass on their own - they bypass the permission table
 * inside hasPermission() - so a shop can never lock itself out of its settings.
 *
 * @param string $permission Key as listed on the Roles page.
 */
function requirePermission($permission)
{
    if (!isLoggedIn()) {
        redirect('../auth/login.php');
    }
    if (hasPermission($permission)) {
        return;
    }
    // A missing package and a missing tick need different answers, and only the
    // first is fixed by paying - so say which one happened.
    $planBlocked = !planAllows($permission);
    $msg = $planBlocked
        ? 'This feature is not part of the shop�s current package. The owner can upgrade it from My Package.'
        : 'Your role does not have access to that page. The shop owner can grant it under Roles & Permissions.';

    // Only the people who can actually pay get sent to the package page. It
    // refuses every other role, so a cashier or stock clerk who clicked something
    // they were not allowed to open used to be thrown at the login screen while
    // still holding a live session - which read as "the permissions do not work"
    // rather than as the access refusal it was.
    if ($planBlocked && hasRole(['admin', 'owner', 'manager'])) {
        setFlash('warning', $msg);
        redirect('subscription.php?gate=' . rawurlencode($permission));
    }
    setFlash($planBlocked ? 'warning' : 'danger', $msg);
    redirect('dashboard.php');
}

/**
 * Redirect to URL
 * @param string $url
 */
function redirect($url)
{
    // Check if URL is absolute or relative from root
    if (strpos($url, 'http') !== 0 && strpos($url, '/') !== 0) {
        // It's a relative path, let it be handled by browser relative to current script
        // or check if we need to prefix /pos/ if we are in deep structure? 
        // No, standard relative redirect words best if files are structured correctly.
    }
    
    header("Location: $url");
    exit;
}

/**
 * Flash message helper
 */
function setFlash($type, $message)
{
    startSecureSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash()
{
    startSecureSession();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Ensure a sale row can say which store it came out of, and that the same basket
 * cannot be written twice.
 *
 * Both columns are nullable, and every reader of store_id falls back to the
 * cashier's store when a row has none, so an installation that has not run
 * config/migration_sale_store_scope.sql keeps working exactly as it did. The
 * return value is what tells the sale door whether it may split one basket into
 * one sale per store: without the column there is nowhere to put the second
 * store, and the basket is refused rather than filed under the wrong shop.
 *
 * @return bool true when sales.store_id and sales.idempotency_key both exist
 */
function ensureSaleStoreColumns()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    // Already settled this day - skip all five SHOWs and answer from the stamp.
    if (appSchemaFlagOk('sales-store-scope')) {
        return $ready = true;
    }

    $db = getDB();
    $ready = false;
    try {
        if (empty($db->query("SHOW COLUMNS FROM sales LIKE 'store_id'")->fetchAll())) {
            appSafeDdl($db, "ALTER TABLE sales ADD COLUMN store_id INT(11) DEFAULT NULL AFTER user_id");
        }
        if (empty($db->query("SHOW COLUMNS FROM sales LIKE 'idempotency_key'")->fetchAll())) {
            appSafeDdl($db, "ALTER TABLE sales ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL AFTER note");
            appSafeDdl($db, "ALTER TABLE sales ADD UNIQUE KEY uq_sales_idempotency (idempotency_key)");
        }
        if (empty($db->query("SHOW INDEX FROM sales WHERE Key_name = 'idx_sales_owner_store'")->fetchAll())) {
            appSafeDdl($db, "ALTER TABLE sales ADD KEY idx_sales_owner_store (owner_id, store_id, created_at)");
        }

        $hasStore = $db->query("SHOW COLUMNS FROM sales LIKE 'store_id'")->fetchAll();
        $hasKey   = $db->query("SHOW COLUMNS FROM sales LIKE 'idempotency_key'")->fetchAll();
        $ready = (!empty($hasStore) && !empty($hasKey));
        if ($ready) {
            appSchemaFlagSet('sales-store-scope');
        }
    } catch (PDOException $e) {
        // No read rights on the table definition: treat it as not ready.
        error_log('ensureSaleStoreColumns: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * The SQL that names the store a sale belongs to.
 *
 * A Super Admin till sells off every shop's shelf, so "which store" is no longer a
 * question about the person standing at the till: it is answered by the sale row
 * itself. Rows written before the column existed, and installations that have not
 * added it, keep the old answer - the cashier's own store - which is where the
 * reports have always filed them. Every reader that scopes money by store uses
 * this, so a list, its export and a dashboard total cannot describe different
 * sets of rows.
 *
 * @param string $saleAlias  the sales table alias in the caller's query
 * @param string $userAlias  the users table alias it is joined to
 * @return string an SQL expression, not a quoted literal
 */
function saleStoreExpr($saleAlias = 's', $userAlias = 'u')
{
    static $expr = [];

    $key = $saleAlias . '|' . $userAlias;
    if (!isset($expr[$key])) {
        $expr[$key] = ensureSaleStoreColumns()
            ? "COALESCE({$saleAlias}.store_id, {$userAlias}.store_id)"
            : "{$userAlias}.store_id";
    }

    return $expr[$key];
}

/**
 * The SQL that names the shop a sale belongs to.
 *
 * Same reasoning as saleStoreExpr(): a Super Admin till sells across shops, so the
 * tenant is decided by the goods, not by whoever was logged in when they were sold.
 * A sale row with no owner on it is judged by the cashier who made it, which is
 * where every report has always put it.
 *
 * @param string $saleAlias  the sales table alias in the caller's query
 * @param string $userAlias  the users table alias it is joined to
 * @return string an SQL expression, not a quoted literal
 */
function saleOwnerExpr($saleAlias = 's', $userAlias = 'u')
{
    return "COALESCE({$saleAlias}.owner_id, {$userAlias}.owner_id)";
}

/**
 * The WHERE fragment that means "rows this shop may read", for a query that joins
 * sales to its cashier.
 *
 * Two placeholders, so the caller binds the same owner id twice. The second arm is
 * how a top-level account is recognised at all: users.owner_id is NULL for the shop
 * keeper and for the platform Super Admin alike, and their effective owner id is
 * their own user id, so a bare comparison against the column never matches. That
 * arm only ever picks up rows this very person rang up, so it cannot open another
 * shop's records.
 *
 * @param int $owner  effective owner id (never null)
 * @return string SQL fragment with two '?' placeholders
 */
function saleOwnerScopeSql($owner, $saleAlias = 's', $userAlias = 'u')
{
    return "(" . saleOwnerExpr($saleAlias, $userAlias) . " = ?"
        . " OR ({$userAlias}.owner_id IS NULL AND {$userAlias}.id = ?))";
}

/**
 * The platform Super Admin: the one account that may bill across shops.
 *
 * "role = admin and users.owner_id IS NULL" used to be the whole test, and it is
 * not enough: a shop's own keeper is also an admin with a NULL owner (older
 * accounts, and whatever registration wrote before the owner column was filled),
 * so every one of those read as the platform admin - which at the till means free
 * access to other shops' products, stock, invoices and takings. The column that
 * decides a shop is stores.owner_id, so owning a shop is what disqualifies an
 * account, along with being pinned to a store.
 *
 * One small indexed query per account per request, cached here, because the same
 * test is needed at the doors and on the report pages and a fourth spelling of it
 * is how one of them ends up disagreeing with the others.
 *
 * @param array|null $user  getCurrentUser(); null reads the logged-in user
 * @return bool
 */
function isSuperAdminUser($user = null)
{
    $user = $user !== null ? $user : getCurrentUser();
    if (!$user) {
        return false;
    }
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0 || (($user['role'] ?? '') !== 'admin') || !empty($user['raw_owner_id'])) {
        return false;
    }
    // A till pinned to a store is somebody's shop, whatever the owner column says.
    if (!empty($user['store_id'])) {
        return false;
    }
    // A caller that already knows the answer - the ownership tests, which read
    // fabricated rows and must not reach the database - can say so.
    if (array_key_exists('owns_store', $user)) {
        return empty($user['owns_store']);
    }

    static $ownsShop = [];
    if (!isset($ownsShop[$id])) {
        $owns = true;
        try {
            $stmt = getDB()->prepare("SELECT 1 FROM stores WHERE owner_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $owns = (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $owns = true;  // cannot tell - stay inside the caller's own shops
        }
        $ownsShop[$id] = $owns;
    }
    return !$ownsShop[$id];
}

/**
 * Counter accounts that may only read the bills they rang up themselves.
 *
 * A staff login made on the Staff page (and the older cashier role, which its own
 * sales page already treats as "my sales") is a person behind the counter, not the
 * shop's keeper. The sales list, its totals, the export and the dashboard's sales
 * figures are all narrowed to `sales.user_id` for these accounts; owner, admin and
 * manager accounts still see the whole shop.
 *
 * @param array|null $user  getCurrentUser(); null reads the logged-in user
 * @return bool
 */
function isOwnSalesOnlyUser($user = null)
{
    $user = $user !== null ? $user : getCurrentUser();
    if (!$user) {
        return false;
    }
    return in_array((string)($user['role'] ?? ''), ['staff', 'cashier'], true);
}

/**
 * The extra WHERE fragment that narrows a sales query to the bills this account
 * rang up itself.
 *
 * A counter account (staff/cashier) is a person behind the counter, not the
 * shop's keeper: every list, report, export and record lookup they open reads
 * only their own bills, however wide the caller's own scope is. Everyone else -
 * an owner, a manager, the platform Super Admin - gets an empty fragment back
 * and keeps the scope the caller built, which is why every existing admin view
 * is untouched by this.
 *
 * The fragment answers for the SALE's cashier (sales.user_id), because that is
 * whose bill it is - not whoever processed a later return against it.
 *
 * @param array|null $user  getCurrentUser(); null reads the logged-in user
 * @param string $saleAlias the sales table alias in the caller's query
 * @return array{0:string,1:array}  WHERE fragment (leading AND) and its params
 */
function ownSalesScopeSql($user = null, $saleAlias = 's')
{
    $user = $user !== null ? $user : getCurrentUser();
    if (!isOwnSalesOnlyUser($user)) {
        return ['', []];
    }
    return [" AND {$saleAlias}.user_id = ?", [(int)($user['id'] ?? 0)]];
}

function ensureCashbookSourceColumns()
{
    // The stamp means source_type/source_id are in place; skip the SHOW.
    if (appSchemaFlagOk('cashbook-source-columns')) {
        return true;
    }
    $db = getDB();
    try {
        $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'source_type'")->fetchAll();
        if (empty($cols)) {
            appSafeDdl($db, "ALTER TABLE cashbook_entries ADD COLUMN source_type VARCHAR(20) NULL AFTER category_id, ADD COLUMN source_id INT NULL AFTER source_type");
            $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'source_type'")->fetchAll();
        }
        if (!empty($cols)) {
            appSchemaFlagSet('cashbook-source-columns');
        }
    } catch (PDOException $e) {
        // Table or columns may not exist yet; ignore
    }
}

/**
 * Bring the cashbook schema up to date, and do it before a transaction opens.
 *
 * A sale, a stock purchase or a supplier bill books its money side in
 * cashbook_entries inside its own transaction, and those writers need the module's
 * tables and columns. Building them is DDL, which cannot run while a transaction is
 * open (see appSafeDdl()), so each of those doors settles the schema here first.
 * Every one of these calls is guarded and cheap after the first, so a door that
 * calls this and then writes an entry pays nothing extra.
 *
 * @return bool false when it was called too late - a transaction is already open,
 *              so nothing was attempted and the caller's work stays intact.
 */
function ensureCashbookWriteReady()
{
    if (getDB()->inTransaction()) {
        error_log('ensureCashbookWriteReady skipped: called inside an open transaction');
        return false;
    }
    require_once __DIR__ . '/cashbook.php';
    ensureCashbookModuleTables();
    ensureCashbookSourceColumns();
    ensureCashbookDetailColumns();
    return true;
}

/**
 * Add the two columns that let a cashbook line say how the money moved and
 * against which bill.
 *
 * A refund needs both: "Product Refund" tells the Expense page what the line is,
 * and the method tells it whether cash actually left the drawer.
 *
 * @return bool whether the columns can be written - false on an install that has
 *              not run the migration and has no DDL rights, where the callers keep
 *              to the old column list rather than failing on an unknown column.
 */
function ensureCashbookDetailColumns()
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    // The stamp means payment_method/invoice_no are in place; skip the SHOW.
    if (appSchemaFlagOk('cashbook-detail-columns')) {
        return true;
    }
    try {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'payment_method'")->fetchAll();
        if (empty($cols)) {
            appSafeDdl($db, "ALTER TABLE cashbook_entries
                ADD COLUMN payment_method VARCHAR(20) NULL AFTER category_id,
                ADD COLUMN invoice_no VARCHAR(50) NULL AFTER payment_method");
            $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'payment_method'")->fetchAll();
        }
        $ready = !empty($cols);
        if ($ready) {
            appSchemaFlagSet('cashbook-detail-columns');
        }
    } catch (PDOException $e) {
        // No table or no DDL rights: the readers treat a missing column as
        // "the shop has no detail on its lines yet".
        $ready = false;
    }
    return $ready;
}

/**
 * The shop's "Product Refund" expense category, created on the first refund.
 *
 * A refund is money leaving the till, so it belongs in the same list as rent and
 * salary - under one name, so a shop can see what returns actually cost it. The
 * table has a unique key on (owner_id, name), so a race just loses the insert.
 *
 * @return int category id, 0 when the category cannot be used
 */
function refundExpenseCategoryId($db, $ownerId)
{
    $ownerId = (int)$ownerId;
    $find = "SELECT id FROM expense_categories WHERE owner_id = ? AND name = ? LIMIT 1";
    try {
        $stmt = $db->prepare($find);
        $stmt->execute([$ownerId, EXPENSE_REFUND_CATEGORY]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $db->prepare("INSERT INTO expense_categories (owner_id, name) VALUES (?, ?)")
           ->execute([$ownerId, EXPENSE_REFUND_CATEGORY]);
        return (int)$db->lastInsertId();
    } catch (PDOException $e) {
        try {
            $stmt = $db->prepare($find);
            $stmt->execute([$ownerId, EXPENSE_REFUND_CATEGORY]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e2) {
            // A shop that never opened the Expense page has no categories table.
            // The refund still books - it just sits uncategorised beside the rest.
            return 0;
        }
    }
}

/**
 * What the till actually holds: everything in, minus everything out.
 *
 * This is the figure a refund is checked against, and the reason the balance cards
 * read it rather than "sales minus expenses". A returned bill is already smaller on
 * the sales side, so subtracting its refund again on the expense side would book the
 * same notes as gone twice over; through the cashbook each movement is counted once,
 * wherever it came from.
 *
 * Rows that were booked before stores existed (store_id NULL) are added to a
 * store's figure rather than dropped: they are the same shop's money, and a legacy
 * drawer should never block a refund the shop can really pay.
 *
 * @param bool $forLock true inside the transaction that is about to pay cash out,
 *                      so two tills cannot both spend the same notes
 */
function cashAvailableBalance($ownerId, $storeId = null, $forLock = false)
{
    $state = cashLedgerState($ownerId, $storeId, $forLock);
    return $state['balance'];
}

/**
 * The same figure plus how much of it the shop has actually been recording.
 *
 * @return array{rows:int, cashIn:float, balance:float}
 */
function cashLedgerState($ownerId, $storeId = null, $forLock = false)
{
    $sql = "SELECT COUNT(*) AS rows_seen,
                   COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE 0 END), 0) AS cash_in,
                   COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END), 0) AS balance
              FROM cashbook_entries WHERE owner_id = ?";
    $params = [(int)$ownerId];
    if ($storeId) {
        $sql .= " AND (store_id = ? OR store_id IS NULL)";
        $params[] = (int)$storeId;
    }
    if ($forLock) {
        $sql .= " FOR UPDATE";
    }
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return ['rows' => 0, 'cashIn' => 0.0, 'balance' => 0.0];
    }
    return [
        'rows'    => (int)($row['rows_seen'] ?? 0),
        'cashIn'  => round((float)($row['cash_in'] ?? 0), 2),
        'balance' => round((float)($row['balance'] ?? 0), 2),
    ];
}

/**
 * Can this till pay this refund?
 *
 * Only a cash refund is checked: bKash, Nagad and the rest settle outside the
 * drawer, and store credit pays no cash at all. A shortfall is not an error to
 * swallow - the shop is told what it holds, what the refund needs, and the two
 * ways out (another method, or deposit cash first).
 *
 * A shop that has never recorded a cash coming in is not blocked. Its ledger cannot
 * say the drawer is empty, only that nothing has been written in it, and treating
 * that as zero cash would refuse refunds a real till can pay.
 *
 * @return array{ok:bool, tracked:bool, available:float, short:float, message:string}
 */
function cashRefundGuard($ownerId, $storeId, $method, $amount)
{
    $amount = round((float)$amount, 2);
    $out = ['ok' => true, 'tracked' => false, 'available' => 0.0, 'short' => 0.0, 'message' => ''];
    if ($amount <= 0.009 || strtolower((string)$method) !== 'cash') {
        return $out;
    }

    $state = cashLedgerState($ownerId, $storeId, true);
    $out['tracked'] = $state['cashIn'] > 0.009;
    $out['available'] = $state['balance'];
    if (!$out['tracked'] || $state['balance'] >= $amount - 0.009) {
        return $out;
    }

    $out['ok'] = false;
    $out['short'] = round($amount - $state['balance'], 2);
    $out['message'] = 'Cash balance is ' . formatCurrency($state['balance']) . ', this refund needs '
        . formatCurrency($amount) . ' (short ' . formatCurrency($out['short']) . ').'
        . ' Refund by another method, or deposit cash on the Expense page first.';
    return $out;
}

/**
 * Add an auto-generated cashbook entry linked to a source (sale, purchase, return).
 * Returns true on success, false otherwise.
 *
 * $scope lets the caller book the entry on a store it is not standing in, which a
 * split sale needs: the money belongs to the shop that owned the goods, not to
 * whoever typed at the till. Omitted, it is the session's own shop, as always.
 */
function addAutoCashbookEntry($type, $amount, $note, $sourceType, $sourceId, $scope = null)
{
    if ($amount <= 0) return false;
    try {
        require_once __DIR__ . '/cashbook.php';
        ensureCashbookModuleTables();
        $db = getDB();
        $currentUser = getCurrentUser();
        $owner_id = $currentUser['owner_id'] ?? $currentUser['id'] ?? 0;
        $store_id = $currentUser['store_id'] ?? null;
        $user_id = $currentUser['id'] ?? 0;
        $method = null;
        $invoiceNo = null;

        if (is_array($scope)) {
            if (array_key_exists('owner_id', $scope)) $owner_id = $scope['owner_id'];
            if (array_key_exists('store_id', $scope)) $store_id = $scope['store_id'];
            if (array_key_exists('user_id', $scope))  $user_id  = $scope['user_id'];
            if (array_key_exists('payment_method', $scope)) $method = $scope['payment_method'];
            if (array_key_exists('invoice_no', $scope)) $invoiceNo = $scope['invoice_no'];
        }

        // If source is sale, read payment_method and invoice_number if not provided
        if ($sourceType === 'sale' && (!$method || !$invoiceNo)) {
            $stmtS = $db->prepare("SELECT invoice_number, payment_method FROM sales WHERE id = ?");
            $stmtS->execute([(int)$sourceId]);
            $sRow = $stmtS->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                if (!$invoiceNo) $invoiceNo = $sRow['invoice_number'];
                if (!$method) $method = $sRow['payment_method'];
            }
        }

        $bookId = resolveCashbookId($db, $owner_id, $store_id, $method);

        // Check if already posted to avoid duplicates
        $stmtEx = $db->prepare("SELECT id FROM cashbook_entries WHERE source_type = ? AND source_id = ? LIMIT 1");
        $stmtEx->execute([$sourceType, (int)$sourceId]);
        $existingId = $stmtEx->fetchColumn();
        if ($existingId) {
            $stmtUp = $db->prepare("UPDATE cashbook_entries SET amount = ?, note = ?, cashbook_id = ?, payment_method = ?, invoice_no = ?, is_void = 0 WHERE id = ?");
            $stmtUp->execute([round($amount, 2), $note, $bookId, $method, $invoiceNo, (int)$existingId]);
            return true;
        }

        $stmt = $db->prepare("INSERT INTO cashbook_entries 
            (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, payment_method, invoice_no, source_type, source_id, is_void)
            VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$owner_id, $store_id, $bookId, $user_id, $type, round($amount, 2), $note, $method, $invoiceNo, $sourceType, (int)$sourceId]);
        return true;
    } catch (PDOException $e) {
        error_log('addAutoCashbookEntry error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update an auto-generated cashbook entry amount by its source link.
 * Inserts a new entry if none exists (e.g. legacy sales edited after deployment).
 */
function updateAutoCashbookEntry($sourceType, $sourceId, $amount, $scope = null)
{
    try {
        require_once __DIR__ . '/cashbook.php';
        ensureCashbookModuleTables();
        $db = getDB();
        $stmt = $db->prepare("UPDATE cashbook_entries SET amount = ? WHERE source_type = ? AND source_id = ?");
        $stmt->execute([round($amount, 2), $sourceType, (int)$sourceId]);
        if ($stmt->rowCount() === 0) {
            $currentUser = getCurrentUser();
            $owner_id = $currentUser['owner_id'] ?? $currentUser['id'] ?? 0;
            $store_id = $currentUser['store_id'] ?? null;
            $user_id = $currentUser['id'] ?? 0;
            $method = null;
            $invoiceNo = null;
            if (is_array($scope)) {
                if (array_key_exists('owner_id', $scope)) $owner_id = $scope['owner_id'];
                if (array_key_exists('store_id', $scope)) $store_id = $scope['store_id'];
                if (array_key_exists('user_id', $scope))  $user_id  = $scope['user_id'];
                if (array_key_exists('payment_method', $scope)) $method = $scope['payment_method'];
                if (array_key_exists('invoice_no', $scope)) $invoiceNo = $scope['invoice_no'];
            }
            if ($sourceType === 'sale' && (!$method || !$invoiceNo)) {
                $stmtS = $db->prepare("SELECT invoice_number, payment_method FROM sales WHERE id = ?");
                $stmtS->execute([(int)$sourceId]);
                $sRow = $stmtS->fetch(PDO::FETCH_ASSOC);
                if ($sRow) {
                    if (!$invoiceNo) $invoiceNo = $sRow['invoice_number'];
                    if (!$method) $method = $sRow['payment_method'];
                }
            }
            $bookId = resolveCashbookId($db, $owner_id, $store_id, $method);
            $note = ucfirst($sourceType) . ($invoiceNo ? ' ' . $invoiceNo : '');
            $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, payment_method, invoice_no, source_type, source_id, is_void) VALUES (?, ?, ?, ?, 'cash_in', ?, CURDATE(), ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$owner_id, $store_id, $bookId, $user_id, round($amount, 2), $note, $method, $invoiceNo, $sourceType, (int)$sourceId]);
        }
        return true;
    } catch (PDOException $e) {
        error_log('updateAutoCashbookEntry error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove auto-generated cashbook entries by their source link.
 */
function deleteAutoCashbookEntries($sourceType, $sourceId)
{
    try {
        ensureCashbookSourceColumns();
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM cashbook_entries WHERE source_type = ? AND source_id = ?");
        $stmt->execute([$sourceType, $sourceId]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Book the cash side of one exchange, exactly once.
 *
 * An exchange settles the difference between what the customer handed back and
 * what they took: positive means the shop collected that extra, negative means it
 * refunded it, zero means goods only and the till did not move.
 *
 * The movement is written against the return it belongs to (source_type 'return'
 * and its id), which is what keeps the money traceable to the document and lets
 * the balance pages add it back without guessing. Because it replaces rather than
 * appends, saving the same exchange twice - a re-post, or an edit that changes the
 * difference - leaves one movement with the final amount instead of a growing
 * pile of them.
 *
 * @param int    $returnId
 * @param float  $exchangeBalance  exchange value minus returned value
 * @param string $returnNumber     for the line note
 * @param array  $scope            owner_id / store_id / user_id, for callers with
 *                                 no web session (the mobile API). Missing keys
 *                                 fall back to the logged-in user. Two more keys
 *                                 describe the line: payment_method (the method the
 *                                 refund is paid by) and invoice_no (the bill the
 *                                 goods came back from). A refund paid as store
 *                                 credit books no cash movement at all - no cash
 *                                 leaves the till for it.
 * @return bool
 */
function exchangeBalanceSettle($returnId, $exchangeBalance, $returnNumber, $scope = [])
{
    $returnId = (int)$returnId;
    if ($returnId <= 0) {
        return false;
    }

    $balance  = round((float)$exchangeBalance, 2);
    $collected = $balance > 0 ? $balance : 0.0;
    $refunded  = $balance < 0 ? -$balance : 0.0;

    $method = isset($scope['payment_method']) ? strtolower(trim((string)$scope['payment_method'])) : '';
    if ($refunded > 0 && $method === 'store_credit') {
        $collected = 0.0;
        $refunded = 0.0;
    }

    $db = getDB();
    ensureCashbookSourceColumns();
    ensureCashbookDetailColumns();
    // Drop whatever was booked for this return before writing the final figure,
    // so a second save of the same exchange cannot leave two movements behind.
    $stmt = $db->prepare("DELETE FROM cashbook_entries WHERE source_type = 'return' AND source_id = ?");
    $stmt->execute([$returnId]);

    if ($collected <= 0 && $refunded <= 0) {
        // A zero difference does not change the balance, and writing a 0.00 row
        // would put an empty line in the shop's transaction list.
        return true;
    }

    if (!isset($scope['owner_id']) || !isset($scope['user_id'])) {
        $currentUser = getCurrentUser();
        $scope += [
            'owner_id' => $currentUser['owner_id'] ?? $currentUser['id'] ?? 0,
            'store_id' => $currentUser['store_id'] ?? null,
            'user_id'  => $currentUser['id'] ?? 0,
        ];
    }

    $type   = $collected > 0 ? 'cash_in' : 'cash_out';
    $amount = $collected > 0 ? $collected : $refunded;
    $note   = 'Return ' . $returnNumber . ($collected > 0 ? ' (exchange extra)' : ' (refund)');

    // The refund half is an expense like any other, so it is filed under the shop's
    // Product Refund category and appears in the Expense list's category totals.
    // The extra an exchange collected is money coming in, so it stays uncategorised.
    $categoryId = null;
    if ($refunded > 0) {
        $cid = refundExpenseCategoryId($db, $scope['owner_id']);
        if ($cid) {
            $categoryId = $cid;
        }
        if ($method === '') {
            $method = 'cash';
        }
    }

    require_once __DIR__ . '/cashbook.php';
    ensureCashbookModuleTables();
    $bookId = resolveCashbookId($db, $scope['owner_id'], $scope['store_id'] ?? null, $method);

    if (ensureCashbookDetailColumns()) {
        $stmt = $db->prepare("INSERT INTO cashbook_entries
                (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, category_id, source_type, source_id,
                 payment_method, invoice_no, is_void)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'return', ?, ?, ?, 0)");
        $params = [
            $scope['owner_id'],
            $scope['store_id'] ?? null,
            $bookId,
            $scope['user_id'],
            $type,
            $amount,
            $note,
            $categoryId,
            $returnId,
            $method !== '' ? $method : null,
            isset($scope['invoice_no']) && $scope['invoice_no'] !== '' ? (string)$scope['invoice_no'] : null,
        ];
    } else {
        // An install whose migration has not run yet still has to be able to book
        // the refund; the line just carries no method or bill reference on it.
        $stmt = $db->prepare("INSERT INTO cashbook_entries
                (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, category_id, source_type, source_id, is_void)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'return', ?, 0)");
        $params = [
            $scope['owner_id'],
            $scope['store_id'] ?? null,
            $bookId,
            $scope['user_id'],
            $type,
            $amount,
            $note,
            $categoryId,
            $returnId,
        ];
    }
    $stmt->execute($params);
    return true;
}

/**
 * What exchanges moved in cash over a window: collected on top, refunded back.
 *
 * Both come from the single linked movement per return, so a day's figures cannot
 * drift from the returns list that produced them.
 *
 * @param string|null $from 'Y-m-d', inclusive
 * @param string|null $to   'Y-m-d', inclusive
 * @return array collected, refunded, net
 */
function exchangeCashTotals($ownerId, $from = null, $to = null, $storeId = null)
{
    $zeros = ['collected' => 0.0, 'refunded' => 0.0, 'net' => 0.0];
    try {
        $sql = "SELECT COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE 0 END), 0) AS collected,
                       COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount ELSE 0 END), 0) AS refunded
                FROM cashbook_entries
                WHERE owner_id = ? AND source_type = 'return'";
        $params = [$ownerId];
        if ($from !== null && $from !== '') {
            $sql .= " AND created_at >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $sql .= " AND created_at <= ?";
            $params[] = $to . ' 23:59:59';
        }
        if ($storeId) {
            $sql .= " AND store_id = ?";
            $params[] = (int)$storeId;
        }
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // A shop whose cashbook has no source columns yet has no linked exchange
        // money to add, which is the same answer as an empty one - and a balance
        // card is not worth taking the page down for.
        return $zeros;
    }
    $collected = round((float)($row['collected'] ?? 0), 2);
    $refunded  = round((float)($row['refunded'] ?? 0), 2);
    return ['collected' => $collected, 'refunded' => $refunded, 'net' => round($collected - $refunded, 2)];
}

/**
 * The same figures per day, for a report that runs over a range.
 *
 * @return array 'Y-m-d' => ['extra' => float, 'refund' => float, 'net_effect' => float]
 */
function returnCashDaily($ownerId, $from, $to, $userId = null)
{
    ensureReturnExchangeColumns();

    try {
        // The optional sale join narrows a counter account to the refunds on its
        // OWN SALES - judged by who rang the bill up (s.user_id), the same rule
        // returnCashSummary() applies, so a day's bars and the summary card above
        // them can never disagree.
        $join = '';
        $params = [$ownerId, $from . ' 00:00:00', $to . ' 23:59:59'];
        if ($userId) {
            $join = " JOIN sales s ON s.id = r.sale_id AND s.user_id = ?";
            // The join's placeholder is the FIRST one in the SQL text (it sits in
            // the FROM, before WHERE), so the user id goes to the front of the
            // bindings, not between the owner id and the window.
            array_unshift($params, (int)$userId);
        }
        $stmt = getDB()->prepare("SELECT DATE(r.created_at) AS d,
                                         COALESCE(SUM(r.extra_amount_received), 0) AS extra,
                                         COALESCE(SUM(r.refund_amount), 0) AS refund,
                                         COALESCE(SUM(CASE WHEN r.transaction_type = 'exchange' THEN r.refund_amount ELSE 0 END), 0) AS exchange_refund
                                  FROM returns r" . $join . "
                                  WHERE r.owner_id = ? AND r.created_at BETWEEN ? AND ?
                                  GROUP BY DATE(r.created_at)");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $extra  = round((float)$row['extra'], 2);
        $refund = round((float)$row['exchange_refund'], 2);
        $out[(string)$row['d']] = [
            'extra'      => $extra,
            'refund'     => round((float)$row['refund'], 2),
            'net_effect' => round($extra - $refund, 2),
        ];
    }
    return $out;
}

/**
 * Ensure the returns table carries the exchange settlement columns.
 *
 * An install that has not run config/migration_return_exchange.sql still has to be
 * able to process a return, so the writer asks here instead of failing on an unknown
 * column - and the readers treat a missing column as "no exchange money".
 */
function ensureReturnExchangeColumns()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $db = getDB();
    try {
        $cols = $db->query("SHOW COLUMNS FROM returns LIKE 'transaction_type'")->fetchAll();
        if (empty($cols)) {
            appSafeDdl($db, "ALTER TABLE returns
                ADD COLUMN transaction_type VARCHAR(10) NOT NULL DEFAULT 'return' AFTER total_amount,
                ADD COLUMN exchange_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER transaction_type,
                ADD COLUMN extra_amount_received DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER exchange_amount,
                ADD COLUMN refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER extra_amount_received");
        }
    } catch (PDOException $e) {
        // Table not created yet, or no DDL rights; the readers fall back to zeros.
    }
}

/**
 * Return and exchange money over a window, read from the returns table itself.
 *
 * The window is the day the money moved (returns.created_at), which is what a
 * till figure needs. The cashbook cannot answer this question: a plain return
 * refund and an exchange difference refund are both a cash_out row against
 * source_type='return', and only one of them is already off sales.total.
 *
 * @return array gross, extra, refund, exchange_refund, count, exchange_count, net_effect
 */
function returnCashSummary($ownerId, $from = null, $to = null, $storeId = null, $userId = null)
{
    $zeros = [
        'gross' => 0.0, 'extra' => 0.0, 'refund' => 0.0, 'exchange_refund' => 0.0,
        'count' => 0, 'exchange_count' => 0, 'net_effect' => 0.0,
    ];

    ensureReturnExchangeColumns();

    try {
        $db = getDB();
        $sql = "SELECT COUNT(*) AS cnt,
                       COALESCE(SUM(r.total_amount), 0) AS gross,
                       COALESCE(SUM(r.extra_amount_received), 0) AS extra,
                       COALESCE(SUM(r.refund_amount), 0) AS refund,
                       COALESCE(SUM(CASE WHEN r.transaction_type = 'exchange' THEN r.refund_amount ELSE 0 END), 0) AS exchange_refund,
                       COALESCE(SUM(CASE WHEN r.transaction_type = 'exchange' THEN 1 ELSE 0 END), 0) AS exchange_count
                FROM returns r";
        $params = [];
        // `returns` has no store column, so a store-scoped shop is filtered through
        // the sale the goods came back from - the same store the cash was booked to,
        // which for a Super Admin bill is the shop whose shelf it went off, not the
        // till that rang it up.
        //
        // A counter account is filtered the same way, by the user who rang up the
        // original bill, so a staff login only sees the refunds on its own sales.
        // Both joins are added once and only when needed.
        $joinSales = $storeId || $userId;
        if ($joinSales) {
            $sql .= " JOIN sales s ON r.sale_id = s.id";
            $sql .= " JOIN users u ON s.user_id = u.id";
        }
        if ($storeId) {
            $sql .= " AND " . saleStoreExpr('s', 'u') . " = ?";
            $params[] = (int)$storeId;
        }
        $sql .= " WHERE r.owner_id = ?";
        $params[] = $ownerId;
        if ($userId) {
            $sql .= " AND s.user_id = ?";
            $params[] = (int)$userId;
        }
        if ($from !== null && $from !== '') {
            $sql .= " AND r.created_at >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $sql .= " AND r.created_at <= ?";
            $params[] = $to . ' 23:59:59';
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return $zeros;
    }

    $out = [
        'gross'           => round((float)($row['gross'] ?? 0), 2),
        'extra'           => round((float)($row['extra'] ?? 0), 2),
        'refund'          => round((float)($row['refund'] ?? 0), 2),
        'exchange_refund' => round((float)($row['exchange_refund'] ?? 0), 2),
        'count'           => (int)($row['cnt'] ?? 0),
        'exchange_count'  => (int)($row['exchange_count'] ?? 0),
    ];
    // What to fold into a sales figure. A plain return refund is deliberately absent:
    // returns.php already took that value off the original sale, so subtracting it
    // here would remove the same money a second time.
    $out['net_effect'] = round($out['extra'] - $out['exchange_refund'], 2);
    return $out;
}

/**
 * Format currency
 * @param float $amount
 * @return string
 */
function formatCurrency($amount)
{
    // CURRENCY is empty, so the old "SYMBOL . ' ' . number" would print a
    // leading space in front of every amount on every page and every receipt.
    // Join only when there is actually a symbol to show.
    $amount = number_format($amount, 2);
    return CURRENCY === '' ? $amount : CURRENCY . ' ' . $amount;
}

/**
 * Generate unique invoice number
 * @return string
 */
function generateInvoiceNumber()
{
    return 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}
/**
 * Apply timezone from settings
 * Call this after loading settings in each page
 * @param string|null $timezone Timezone string, e.g. 'Asia/Dhaka'
 */
function applyTimezone($timezone = null)
{
    if (!$timezone) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'timezone' LIMIT 1");
            $stmt->execute();
            $timezone = $stmt->fetchColumn();
        } catch (Exception $e) {
            $timezone = null;
        }
    }
    
    if ($timezone && in_array($timezone, DateTimeZone::listIdentifiers())) {
        date_default_timezone_set($timezone);
    } else {
        date_default_timezone_set('Asia/Dhaka');
    }
}

/**
 * Get the application's base URL
 * Prioritizes the custom domain setting if available
 */
function getAppBaseUrl() {
    static $baseUrl = null;
    if ($baseUrl !== null) return $baseUrl;

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'custom_domain'");
        $stmt->execute();
        $customDomain = $stmt->fetchColumn();
    } catch (Exception $e) {
        $customDomain = null;
    }

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    
    if (!empty($customDomain)) {
        // Clean the domain (remove protocol and trailing slash)
        $domain = trim($customDomain);
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        $domain = rtrim($domain, '/');
        $baseUrl = $protocol . "://" . $domain;
    } else {
        // Fallback to current host plus the measured base path.
        //
        // This used to walk up from $_SERVER['SCRIPT_NAME'] and pop the last
        // folder if it was named auth/, admin/, cashier/ or config/. That is a
        // guess about how deep the page happens to be, and it is wrong twice
        // over: a page one level deeper than its siblings is indistinguishable
        // from a project installed one level deeper, and on XAMPP - where the
        // project really is a folder called "admin" - it reported the base as
        // http://localhost and pointed every asset one level too high.
        // appBasePath() asks the filesystem instead, so the answer does not
        // change from page to page.
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $baseUrl = $protocol . "://" . $host . appBasePath();
    }

    return $baseUrl;
}
