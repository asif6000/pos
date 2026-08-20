<?php
/**
 * Fix Sale Processing Errors
 * Run once: http://localhost/pos/pos/pos/migrate_fix_sale.php
 * Delete after running.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';

$db = getDB();
$log = [];

// ── 1. Fix stock_history.type ENUM ─────────────────────────────────────────
try {
    $db->exec("ALTER TABLE stock_history 
        MODIFY COLUMN type ENUM(
            'purchase','sale','adjustment','return','transfer','sale_delete'
        ) NOT NULL");
    $log[] = ['ok', 'stock_history.type ENUM updated (added sale_delete).'];
} catch (Exception $e) {
    $log[] = ['warn', 'stock_history.type: ' . $e->getMessage()];
}

// ── 2. Fix sales.payment_status ENUM (add 'unpaid' if missing, ensure all values) ─
try {
    $db->exec("ALTER TABLE sales 
        MODIFY COLUMN payment_status ENUM('paid','partial','unpaid','pending') NOT NULL DEFAULT 'paid'");
    $log[] = ['ok', "sales.payment_status ENUM updated (added 'pending')."];
} catch (Exception $e) {
    $log[] = ['warn', 'sales.payment_status: ' . $e->getMessage()];
}

// ── 3. Ensure store_stocks table exists ──────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS store_stocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        UNIQUE KEY idx_store_product (store_id, product_id)
    ) ENGINE=InnoDB");
    $log[] = ['ok', 'store_stocks table ensured.'];
} catch (Exception $e) {
    $log[] = ['warn', 'store_stocks: ' . $e->getMessage()];
}

// ── 4. Ensure role_permissions table exists ──────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_slug VARCHAR(100) NOT NULL,
        permission VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_perm (role_slug, permission),
        INDEX idx_role_slug (role_slug)
    ) ENGINE=InnoDB");
    $log[] = ['ok', 'role_permissions table ensured.'];
} catch (Exception $e) {
    $log[] = ['warn', 'role_permissions: ' . $e->getMessage()];
}

// ── 5. Populate default admin & cashier permissions ─────────────────────────
try {
    $adminPerms = [
        'dashboard','pos','products','categories','stock','transfers',
        'sales','sales_delete','returns','reports',
        'customers','users','stores','roles','settings','barcode_settings','vouchers'
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES ('admin', ?)");
    foreach ($adminPerms as $p) { $stmt->execute([$p]); }
    $log[] = ['ok', 'Admin permissions set (' . count($adminPerms) . ').'];

    $cashierPerms = ['pos', 'sales', 'customers'];
    $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES ('cashier', ?)");
    foreach ($cashierPerms as $p) { $stmt->execute([$p]); }
    $log[] = ['ok', 'Cashier permissions set (' . count($cashierPerms) . ').'];
} catch (Exception $e) {
    $log[] = ['warn', 'Permissions: ' . $e->getMessage()];
}

// ── 6. Check if active store exists, create one if not ──────────────────────
try {
    $storeCount = $db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn();
    if ($storeCount == 0) {
        // Find the first admin user to set as owner
        $adminUser = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
        $ownerId = $adminUser ?: 1;
        $db->prepare("INSERT INTO stores (name, address, status, owner_id) VALUES ('Main Store', 'Main Branch', 'active', ?)")
            ->execute([$ownerId]);
        $log[] = ['ok', 'Default store created (Main Store).'];
    } else {
        $log[] = ['ok', "Active stores found: {$storeCount}."];
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Store check: ' . $e->getMessage()];
}

// ── 7. Sync store_stocks from products table ─────────────────────────────────
// For any product with stock > 0 in products table but no store_stocks entry, add it
try {
    $stores = $db->query("SELECT id FROM stores WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    if ($stores) {
        $firstStore = $stores[0];
        $syncStmt = $db->prepare("
            INSERT IGNORE INTO store_stocks (store_id, product_id, quantity)
            SELECT ?, p.id, p.stock
            FROM products p
            WHERE p.status = 'active' AND p.stock > 0
        ");
        $syncStmt->execute([$firstStore]);
        $synced = $syncStmt->rowCount();
        if ($synced > 0) {
            $log[] = ['ok', "Synced {$synced} products into store_stocks for store ID {$firstStore}."];
        } else {
            $log[] = ['ok', 'store_stocks already populated — no sync needed.'];
        }
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Stock sync: ' . $e->getMessage()];
}

// ── 8. Check customers table has Walk-in Customer ───────────────────────────
try {
    $walkin = $db->query("SELECT id FROM customers WHERE id=1 LIMIT 1")->fetchColumn();
    if (!$walkin) {
        $db->exec("INSERT INTO customers (id, name, phone, address) VALUES (1, 'Walk-in Customer', 'N/A', 'N/A')");
        $log[] = ['ok', 'Walk-in Customer (id=1) inserted.'];
    } else {
        $log[] = ['ok', 'Walk-in Customer exists (id=1).'];
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Walk-in customer: ' . $e->getMessage()];
}

// ── 9. Quick diagnostic ──────────────────────────────────────────────────────
try {
    $storeStockCount = $db->query("SELECT COUNT(*) FROM store_stocks")->fetchColumn();
    $productCount    = $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
    $storeCount2     = $db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn();
    $log[] = ['info', "Diagnostic → Active products: {$productCount} | Active stores: {$storeCount2} | Store stock rows: {$storeStockCount}"];
} catch (Exception $e) {
    $log[] = ['warn', 'Diagnostic: ' . $e->getMessage()];
}

$log[] = ['ok', '<strong>All done! You can now process sales.</strong>'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fix Sale Processing</title>
    <style>
        body { font-family: monospace; padding: 2rem; background: #f8fafc; }
        .ok   { color: #10b981; }
        .warn { color: #f59e0b; }
        .info { color: #3b82f6; }
        li { margin: 7px 0; font-size: 14px; }
        .box { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:1.5rem; max-width:750px; }
        h2 { margin-top:0; color:#111827; }
        a { color:#4f46e5; }
    </style>
</head>
<body>
<div class="box">
    <h2>Sale Fix Migration</h2>
    <ul>
    <?php foreach ($log as [$type, $msg]): ?>
        <li class="<?php echo $type; ?>">
            <?php echo $type === 'ok' ? '✓' : ($type === 'info' ? 'ℹ' : '⚠'); ?>
            <?php echo $msg; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <p style="margin-top:1.5rem;">
        <a href="admin/pos.php">→ Go to POS</a> &nbsp;|&nbsp;
        <a href="admin/dashboard.php">→ Dashboard</a>
    </p>
    <p style="color:#9ca3af;font-size:12px;">⚠ Delete this file after running.</p>
</div>
</body>
</html>
