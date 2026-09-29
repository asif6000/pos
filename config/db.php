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
            // marketingAgentStatus() does exactly that, to decide whether the
            // WhatsApp bridge is still reporting:
            //
            //     $age = time() - strtotime($row['last_seen']);
            //
            // With MySQL six hours behind, an agent polling every 2.5 seconds
            // measured as 360 minutes stale - the exact UTC+6 offset - so the
            // page declared a live bridge dead and returned before it ever
            // reached the QR it had been sent. The agent was reporting
            // perfectly; the login code was sitting in the database the whole
            // time, unreachable behind a staleness check that could never pass.
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

        appRenderError($e, $ref);
        exit;
    });
}

/**
 * The page shown instead of a blank 500.
 */
function appRenderError($e, $ref)
{
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
                    get_class($e) . ($e->getCode() ? ' (' . $e->getCode() . ')' : '') . "\n\n"
                    . $e->getMessage() . "\n\n"
                    . 'in ' . $e->getFile() . ':' . $e->getLine() . "\n\n"
                    . $e->getTraceAsString()
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

        if ($secure) {
            @ini_set('session.cookie_secure', 1);
        }

        if (!headers_sent()) {
            session_start();
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
 * answer the same for landing.php and for auth/login.php.
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
    // queries (customers, settings, products â€¦) work correctly.
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
 * The navigation a role should be offered, for the two sidebars that are not the
 * admin one.
 *
 * The staff and cashier pages have their own much smaller sidebars, and both listed
 * a fixed set of links regardless of what the role could open - so a cashier
 * granted Products and Stock saw neither, and saw Customers whether or not they
 * had it. The admin sidebar already derives its menu from hasPermission(); this
 * gives the other two the same behaviour without copying the whole header.
 *
 * @return array<int, array{page:string,label:string,icon:string,need:string}>
 */
function permissionNavItems()
{
    return array(
        array('page' => 'dashboard.php',  'label' => 'Dashboard',     'icon' => 'fa-tachometer-alt', 'need' => 'dashboard'),
        array('page' => 'pos.php',        'label' => 'POS / Billing', 'icon' => 'fa-cash-register',   'need' => 'pos'),
        array('page' => 'sales.php',      'label' => 'My Sales',      'icon' => 'fa-receipt',         'need' => 'sales'),
        array('page' => 'products.php',   'label' => 'Products',      'icon' => 'fa-box',             'need' => 'products'),
        array('page' => 'categories.php', 'label' => 'Categories',    'icon' => 'fa-tags',            'need' => 'categories'),
        array('page' => 'stock.php',      'label' => 'Stock',         'icon' => 'fa-warehouse',       'need' => 'stock'),
        array('page' => 'customers.php',  'label' => 'Customers',     'icon' => 'fa-users',           'need' => 'customers'),
        array('page' => 'expense.php',    'label' => 'Expense',       'icon' => 'fa-book',            'need' => 'cashbook'),
        array('page' => 'returns.php',    'label' => 'Returns',       'icon' => 'fa-undo',            'need' => 'returns'),
        array('page' => 'reports.php',    'label' => 'Reports',       'icon' => 'fa-chart-bar',       'need' => 'reports'),
        array('page' => 'marketing.php',  'label' => 'Marketing',     'icon' => 'fa-bullhorn',        'need' => 'marketing'),
    );
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
 * Ensure the cashbook_entries table has the auto-entry tracking columns
 */
function ensureCashbookSourceColumns()
{
    $db = getDB();
    try {
        $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'source_type'")->fetchAll();
        if (empty($cols)) {
            appSafeDdl($db, "ALTER TABLE cashbook_entries ADD COLUMN source_type VARCHAR(20) NULL AFTER category_id, ADD COLUMN source_id INT NULL AFTER source_type");
        }
    } catch (PDOException $e) {
        // Table or columns may not exist yet; ignore
    }
}

/**
 * Add an auto-generated cashbook entry linked to a source (sale, purchase, return).
 * Returns true on success, false otherwise.
 */
function addAutoCashbookEntry($type, $amount, $note, $sourceType, $sourceId)
{
    if ($amount <= 0) return false;
    try {
        ensureCashbookSourceColumns();
        $db = getDB();
        $currentUser = getCurrentUser();
        $owner_id = $currentUser['owner_id'] ?? $currentUser['id'];
        $store_id = $currentUser['store_id'] ?? null;
        $user_id = $currentUser['id'] ?? 0;

        $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note, category_id, source_type, source_id) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)");
        $stmt->execute([$owner_id, $store_id, $user_id, $type, $amount, $note, $sourceType, $sourceId]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Update an auto-generated cashbook entry amount by its source link.
 * Inserts a new entry if none exists (e.g. legacy sales edited after deployment).
 */
function updateAutoCashbookEntry($sourceType, $sourceId, $amount)
{
    try {
        ensureCashbookSourceColumns();
        $db = getDB();
        $stmt = $db->prepare("UPDATE cashbook_entries SET amount = ? WHERE source_type = ? AND source_id = ?");
        $stmt->execute([$amount, $sourceType, $sourceId]);
        if ($stmt->rowCount() === 0) {
            $currentUser = getCurrentUser();
            $owner_id = $currentUser['owner_id'] ?? $currentUser['id'];
            $store_id = $currentUser['store_id'] ?? null;
            $user_id = $currentUser['id'] ?? 0;
            $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note, category_id, source_type, source_id) VALUES (?, ?, ?, 'cash_in', ?, NULL, NULL, ?, ?)");
            $stmt->execute([$owner_id, $store_id, $user_id, $amount, $sourceType, $sourceId]);
        }
        return true;
    } catch (PDOException $e) {
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
