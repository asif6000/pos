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
define('DB_NAME', 'pos_system'); // Change to your live DB name
define('DB_USER', 'root');       // Change to your live DB user
define('DB_PASS', '');           // Change to your live DB password
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'POS System');
define('APP_VERSION', '1.0.0');
define('CURRENCY', '৳'); // BDT Symbol
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
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
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
 * @return array|null
 */
function getCurrentUser()
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'store_id' => $_SESSION['store_id'] ?? null,
        'owner_id' => $_SESSION['owner_id'] ?? null,
    ];
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
    return in_array($_SESSION['user_role'], $roles);
}

/**
 * Check if user's role has a specific permission
 * Admins always have full access (backward compatible)
 * @param string $permission  e.g. 'sales', 'sales_delete', 'reports'
 * @return bool
 */
function hasPermission($permission)
{
    if (!isLoggedIn()) {
        return false;
    }

    $role = $_SESSION['user_role'] ?? '';

    // Admin always has full access
    if ($role === 'admin') {
        return true;
    }

    // Cache permissions in session to avoid repeated DB hits
    if (!isset($_SESSION['_permissions'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT permission FROM role_permissions WHERE role_slug = ?");
            $stmt->execute([$role]);
            $_SESSION['_permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) {
            $_SESSION['_permissions'] = [];
        }
    }

    return in_array($permission, $_SESSION['_permissions']);
}

/**
 * Clear cached permissions (call after updating role permissions)
 */
function clearPermissionCache()
{
    unset($_SESSION['_permissions']);
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
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN source_type VARCHAR(20) NULL AFTER category_id, ADD COLUMN source_id INT NULL AFTER source_type");
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
    return CURRENCY . ' ' . number_format($amount, 2);
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
        // Fallback to current host
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // CONSISTENT root detection: 
        // We know this file is in /config/db.php
        // So we can find /config/ in the SCRIPT_NAME and take everything before it
        $scriptPath = $_SERVER['SCRIPT_NAME'];
        
        // Find where /config or /auth or /admin starts and get the part before it
        // Or simply, find the 'pos' keyword if it's there? No, better to detect the actual folder.
        // Let's use the folder where the index.php should be.
        $requestUri = $_SERVER['REQUEST_URI'];
        $scriptName = $_SERVER['SCRIPT_NAME'];
        
        // Get the directory of the currently running script
        $currentDir = str_replace('\\', '/', dirname($scriptName));
        
        // If we are in /auth or /admin or /cashier, go one level up
        // If we are in / (root), stay there
        $pathParts = explode('/', trim($currentDir, '/'));
        $relevantFolders = ['auth', 'admin', 'cashier', 'config'];
        
        // Check if the last part of current dir is a known subdirectory
        $lastPart = end($pathParts);
        if (in_array($lastPart, $relevantFolders)) {
            array_pop($pathParts);
        }
        
        $path = '/' . implode('/', $pathParts);
        $path = rtrim($path, '/');
        
        $baseUrl = $protocol . "://" . $host . $path;
    }

    return $baseUrl;
}
