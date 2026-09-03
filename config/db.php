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
define('DB_NAME', 'smart');           // Local XAMPP DB name
define('DB_USER', 'root');            // XAMPP default user
define('DB_PASS', '');                // XAMPP default (no password)
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

            // Ensure version-tracking rows exist in system_settings
            try {
                $this->connection->exec(
                    "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
                        ('permissions_version',   '1'),
                        ('plan_modules_version',  '1')"
                );
            } catch (Exception $e) { /* ignore if table doesn't exist yet */ }
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
 * Check whether the current user is the SaaS super-admin.
 * The super-admin has role = 'admin' AND owner_id IS NULL (they own the platform itself).
 * Super-admins bypass all plan-based gates.
 * @return bool
 */
function isSuperAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    $role     = $_SESSION['user_role']  ?? '';
    $ownerId  = $_SESSION['owner_id']   ?? null;
    return $role === 'admin' && empty($ownerId);
}

/**
 * Get the list of modules allowed by the current user's active subscription.
 * Returns null when there is NO active subscription (access should be denied).
 * Super-admins always receive ['*'] – a wildcard array indicating full access.
 * Caches the result for the duration of the current HTTP request only.
 * @return array|null
 */
function getUserPlanModules(): ?array
{
    static $cache    = null;
    static $resolved = false;
    if ($resolved) {
        return $cache;
    }
    $resolved = true;

    // Super-admin owns the SaaS platform: grant everything
    if (isSuperAdmin()) {
        $cache = ['*'];
        return $cache;
    }

    $user = getCurrentUser();
    if (!$user) {
        return null;
    }

    // Determine effective owner ID:
    // - For tenant admins / users the owner_id is set to themselves.
    // - For cashier / staff accounts the owner_id points to the tenant owner.
    $ownerId = !empty($user['owner_id']) ? (int)$user['owner_id'] : (int)$user['id'];
    if (!$ownerId) {
        return null;
    }

    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT sp.modules
            FROM subscriptions s
            JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.owner_id = ?
              AND s.status   = 'active'
              AND (s.end_date IS NULL OR s.end_date >= CURDATE())
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['modules'])) {
            $modules = array_values(
                array_filter(array_map('trim', explode(',', $row['modules'])))
            );
            $cache = $modules ?: null;
        }
    } catch (Exception $e) {
        // Leave $cache as null so access is denied on DB error
    }
    return $cache;
}

/**
 * Check if the current user has access to a specific permission/module.
 *
 * Access rules (layered, each layer is a hard gate):
 *   1. Must be logged in.
 *   2. Super-admins (role=admin, owner_id=NULL) bypass all plan/role gates.
 *   3. An active plan subscription is required; the module must be listed in
 *      the plan's `modules` column (live DB query — no session cache).
 *   4. For non-admin roles the user's role must also have the permission in
 *      `role_permissions` (session-cached, version-busted via system_settings).
 *   5. Plan-level entitlement is the UPPER BOUND: even if a role grants a
 *      permission, the plan must also grant it.
 *
 * @param  string $permission  Module/permission slug, e.g. 'pos', 'reports'
 * @return bool
 */
function hasPermission(string $permission): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    // ── Layer 1: SaaS super-admin has unrestricted access ────────────────────
    if (isSuperAdmin()) {
        return true;
    }

    $role = $_SESSION['user_role'] ?? '';

    // ── Layer 2: Plan gate (live DB query every request) ─────────────────────
    // getUserPlanModules() queries subscription_plans.modules fresh each request
    // so any change an admin makes to a plan propagates on the user's next load.
    $modules = getUserPlanModules();

    if ($modules === null) {
        // No active subscription → access denied for everything
        return false;
    }

    // Wildcard means super-admin path (already handled above, safety net)
    $inPlan = in_array('*', $modules) || in_array($permission, $modules);
    if (!$inPlan) {
        return false;
    }

    // ── Layer 3: Role-based gate for non-admin roles ─────────────────────────
    // Admin role AND 'user' role (plan buyer / tenant owner) within a tenant bypass
    // role_permissions (they have full access to whatever their plan allows).
    if ($role === 'admin' || $role === 'user') {
        return true; // plan already checked above
    }

    // For cashier / staff / custom roles: check role_permissions table.
    // Session-cached with version-busting so changes take effect quickly.
    try {
        $db             = getDB();
        $currentVersion = null;
        try {
            $stmt           = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'permissions_version' LIMIT 1");
            $stmt->execute();
            $currentVersion = $stmt->fetchColumn();
        } catch (Exception $e) { /* table may not exist yet */ }

        $cachedVersion = $_SESSION['_permissions_version'] ?? null;

        if (!isset($_SESSION['_permissions']) || $cachedVersion !== $currentVersion) {
            $stmt = $db->prepare("SELECT permission FROM role_permissions WHERE role_slug = ?");
            $stmt->execute([$role]);
            $_SESSION['_permissions']         = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $_SESSION['_permissions_version'] = $currentVersion;
        }
    } catch (Exception $e) {
        $_SESSION['_permissions'] = [];
    }

    $roleGranted = in_array($permission, $_SESSION['_permissions']);

    // Both plan AND role must grant the permission.
    return $roleGranted;
}

/**
 * Get the store limit for the current user's active subscription plan.
 * Super-admins get unlimited (PHP_INT_MAX).
 * Returns 1 as the safe default when no plan is found.
 *
 * @return int
 */
function getPlanStoreLimit(): int
{
    if (isSuperAdmin()) {
        return PHP_INT_MAX;
    }

    $user    = getCurrentUser();
    $ownerId = !empty($user['owner_id']) ? (int)$user['owner_id'] : (int)($user['id'] ?? 0);
    if (!$ownerId) return 1;

    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT sp.store_limit
            FROM subscriptions s
            JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.owner_id = ?
              AND s.status   = 'active'
              AND (s.end_date IS NULL OR s.end_date >= CURDATE())
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();
        return $row ? max(1, (int)$row['store_limit']) : 1;
    } catch (Exception $e) {
        return 1;
    }
}

/**
 * Check if the current user can add another store based on their plan limit.
 *
 * @return bool
 */
function canAddStore(): bool
{
    if (isSuperAdmin()) return true;

    $user    = getCurrentUser();
    $ownerId = !empty($user['owner_id']) ? (int)$user['owner_id'] : (int)($user['id'] ?? 0);
    if (!$ownerId) return false;

    $limit = getPlanStoreLimit();
    if ($limit === PHP_INT_MAX) return true;

    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM stores WHERE owner_id = ?");
        $stmt->execute([$ownerId]);
        $count = (int)$stmt->fetchColumn();
        return $count < $limit;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Map the current admin page filename to its module permission slug.
 * @return string|null
 */
function pageModule()
{
    $page = basename($_SERVER['PHP_SELF'] ?? '');
    $map = [
        'dashboard.php'          => 'dashboard',
        'pos.php'                => 'pos',
        'products.php'           => 'products',
        'print-labels.php'       => 'products',
        'categories.php'         => 'categories',
        'stock.php'              => 'stock',
        'transfers.php'          => 'transfers',
        'sales.php'              => 'sales',
        'returns.php'            => 'returns',
        'reports.php'            => 'reports',
        'discount-report.php'    => 'reports',
        'expense.php'            => 'cashbook',
        'cashbook.php'           => 'cashbook',
        'customers.php'          => 'customers',
        'customer-history.php'   => 'customers',
        'users.php'              => 'users',
        'stores.php'             => 'stores',
        'plans.php'              => 'plans',
        'pricing.php'            => 'plans',
        'staff.php'              => 'staff',
        'roles.php'              => 'roles',
        'settings.php'           => 'settings',
        'payment-settings.php'   => 'settings',
        'review-payments.php'    => 'settings',
        'barcode-settings.php'   => 'barcode_settings',
        'voucher-settings.php'   => 'vouchers',
        'vouchers.php'           => 'vouchers',
    ];
    return $map[$page] ?? null;
}

/**
 * Deny access (403) for a module not granted by the user's plan.
 */
function denyAccess()
{
    if (!headers_sent()) {
        http_response_code(403);
    }
    require __DIR__ . '/../admin/403.php';
    exit;
}

/**
 * Enforce plan-based module access for an admin page.
 *
 * Redirect rules:
 *   - Not logged in → /admin/login.php (admin pages) or /auth/login.php (others)
 *   - Super admin   → always allowed, no plan check needed
 *   - Logged in but no plan permission → 403 page
 *
 * @param string|null $module Module slug to check. Auto-detected from page filename if null.
 */
function requirePermission($module = null): void
{
    if (!isLoggedIn()) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        if (strpos($script, '/admin/') !== false) {
            redirect('../admin/login.php');
        }
        redirect('../auth/login.php');
    }

    // Super admin always has full access — skip plan/role check entirely
    if (isSuperAdmin()) {
        return;
    }

    // ── Subscription expiry gate ──────────────────────────────────────────────
    // If the user's subscription has expired, block ALL pages except
    // subscription.php itself (so they can renew).
    $currentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $allowedWithoutSub = ['subscription.php', 'logout.php', '403.php'];

    if (!in_array($currentPage, $allowedWithoutSub)) {
        $modules = getUserPlanModules();

        if ($modules === null) {
            // No active subscription — redirect to subscription page with a message
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
            if (strpos($script, '/admin/') !== false) {
                redirect('../admin/subscription.php?expired=1');
            }
            redirect('admin/subscription.php?expired=1');
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    $module = $module ?: pageModule();
    if ($module === null || !hasPermission($module)) {
        denyAccess();
    }
}

/**
 * Clear cached role-permissions and bump the global version counter.
 * Call this after updating role_permissions in the database.
 * All active sessions will re-fetch their role permissions on the next request.
 */
function clearPermissionCache(): void
{
    unset($_SESSION['_permissions'], $_SESSION['_permissions_version']);

    try {
        $db = getDB();
        $db->exec("INSERT INTO system_settings (setting_key, setting_value)
                   VALUES ('permissions_version', '1')
                   ON DUPLICATE KEY UPDATE setting_value = CAST((CAST(setting_value AS UNSIGNED) + 1) AS CHAR)");
    } catch (Exception $e) {
        // Table may not exist yet — ignore
    }
}

/**
 * Bump the plan-modules version counter in system_settings.
 * Call this every time a subscription plan's modules are changed.
 *
 * The frontend JS polls /admin/api/check-permissions.php, which returns the
 * current version numbers.  When the version changes the browser shows a
 * "Your access has been updated" banner and reloads to apply the new plan.
 */
function clearPlanModulesCache(): void
{
    try {
        $db = getDB();
        // Ensure the row exists first
        $db->exec("INSERT INTO system_settings (setting_key, setting_value)
                   VALUES ('plan_modules_version', '1')
                   ON DUPLICATE KEY UPDATE setting_value = CAST((CAST(setting_value AS UNSIGNED) + 1) AS CHAR)");
    } catch (Exception $e) {
        // Ignore — version bump is best-effort
    }
}

/**
 * Return the current version fingerprint for frontend polling.
 * Returns an associative array with both version counters so the JS
 * can detect any permission change (plan OR role) with a single call.
 *
 * @return array{permissions_version: string, plan_modules_version: string}
 */
function getPermissionVersions(): array
{
    $versions = ['permissions_version' => '0', 'plan_modules_version' => '0'];
    try {
        $db   = getDB();
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings
                            WHERE setting_key IN ('permissions_version','plan_modules_version')");
        while ($row = $stmt->fetch()) {
            $versions[$row['setting_key']] = (string)$row['setting_value'];
        }
    } catch (Exception $e) { /* ignore */ }
    return $versions;
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
