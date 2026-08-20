<?php
/**
 * ONE-CLICK FIX: Run this to fix all known sale processing issues.
 * http://localhost/pos/pos/pos/fix_all.php
 * Delete after running.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';
startSecureSession();

$db = getDB();
$log = [];

function runSQL($db, $sql, $desc) {
    global $log;
    try {
        $db->exec($sql);
        $log[] = ['ok', $desc];
    } catch (Exception $e) {
        $log[] = ['warn', $desc . ' — ' . $e->getMessage()];
    }
}

// ── Fix 1: stock_history.type ENUM ─────────────────────────────────────────
runSQL($db,
    "ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase','sale','adjustment','return','transfer','sale_delete') NOT NULL",
    "stock_history.type ENUM fixed"
);

// ── Fix 2: sales.payment_status ENUM ───────────────────────────────────────
runSQL($db,
    "ALTER TABLE sales MODIFY COLUMN payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid'",
    "sales.payment_status ENUM fixed"
);

// ── Fix 3: Ensure stores table has at least one active store ────────────────
try {
    $storeCount = $db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn();
    if ($storeCount == 0) {
        $adminUser = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn() ?: 1;
        $db->prepare("INSERT INTO stores (name, address, status, owner_id) VALUES ('Main Store', 'Main Branch', 'active', ?)")
           ->execute([$adminUser]);
        $log[] = ['ok', 'Default store "Main Store" created'];
    } else {
        $log[] = ['ok', "Stores OK ({$storeCount} active)"];
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Store check: ' . $e->getMessage()];
}

// ── Fix 4: Ensure Walk-in Customer exists ───────────────────────────────────
try {
    $exists = $db->query("SELECT id FROM customers WHERE id=1 LIMIT 1")->fetchColumn();
    if (!$exists) {
        $db->exec("INSERT INTO customers (id, name, phone, address) VALUES (1, 'Walk-in Customer', 'N/A', 'N/A')");
        $log[] = ['ok', 'Walk-in Customer created (id=1)'];
    } else {
        $log[] = ['ok', 'Walk-in Customer exists'];
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Walk-in customer: ' . $e->getMessage()];
}

// ── Fix 5: Sync products → store_stocks ────────────────────────────────────
try {
    $stores = $db->query("SELECT id, name FROM stores WHERE status='active'")->fetchAll();
    $totalSynced = 0;
    foreach ($stores as $store) {
        $stmt = $db->prepare("
            INSERT IGNORE INTO store_stocks (store_id, product_id, quantity)
            SELECT ?, p.id, p.stock
            FROM products p
            WHERE p.status = 'active' AND p.stock > 0
        ");
        $stmt->execute([$store['id']]);
        $synced = $stmt->rowCount();
        $totalSynced += $synced;
        if ($synced > 0) {
            $log[] = ['ok', "Synced {$synced} products into store [{$store['id']}] {$store['name']}"];
        }
    }
    if ($totalSynced == 0) {
        $log[] = ['ok', 'store_stocks already in sync — no new rows needed'];
    }
} catch (Exception $e) {
    $log[] = ['warn', 'Stock sync: ' . $e->getMessage()];
}

// ── Fix 6: role_permissions table ──────────────────────────────────────────
runSQL($db,
    "CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_slug VARCHAR(100) NOT NULL,
        permission VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_perm (role_slug, permission),
        INDEX idx_role_slug (role_slug)
    ) ENGINE=InnoDB",
    "role_permissions table ensured"
);

try {
    $perms = [
        'admin' => ['dashboard','pos','products','categories','stock','transfers','sales','sales_delete','returns','reports','customers','users','stores','roles','settings','barcode_settings','vouchers'],
        'cashier' => ['pos','sales','customers']
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES (?,?)");
    foreach ($perms as $role => $list) {
        foreach ($list as $p) { $stmt->execute([$role, $p]); }
    }
    $log[] = ['ok', 'Default permissions seeded'];
} catch (Exception $e) {
    $log[] = ['warn', 'Permissions: ' . $e->getMessage()];
}

// ── Fix 7: Assign store to users who have no store ─────────────────────────
try {
    $firstStore = $db->query("SELECT id FROM stores WHERE status='active' LIMIT 1")->fetchColumn();
    if ($firstStore) {
        $updated = $db->prepare("UPDATE users SET store_id = ? WHERE store_id IS NULL AND status='active'");
        $updated->execute([$firstStore]);
        $count = $updated->rowCount();
        if ($count > 0) {
            $log[] = ['ok', "Assigned {$count} user(s) to store ID {$firstStore}"];
        } else {
            $log[] = ['ok', 'All users already have store assigned'];
        }
    }
} catch (Exception $e) {
    $log[] = ['warn', 'User store assignment: ' . $e->getMessage()];
}

// ── Diagnostics ────────────────────────────────────────────────────────────
try {
    $info = [
        'Active stores'      => $db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn(),
        'Active products'    => $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
        'store_stocks rows'  => $db->query("SELECT COUNT(*) FROM store_stocks")->fetchColumn(),
        'Customers'          => $db->query("SELECT COUNT(*) FROM customers")->fetchColumn(),
        'Session user_id'    => $_SESSION['user_id'] ?? 'NOT LOGGED IN',
        'Session store_id'   => $_SESSION['store_id'] ?? 'NULL',
        'Session owner_id'   => $_SESSION['owner_id'] ?? 'NULL',
    ];
    foreach ($info as $k => $v) {
        $log[] = ['info', "{$k}: {$v}"];
    }
} catch (Exception $e) {}

$allOk = !array_filter($log, fn($l) => $l[0] === 'error');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Fix All</title>
    <style>
        body{font-family:monospace;padding:2rem;background:#f8fafc;}
        .box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:780px;}
        .ok{color:#10b981;} .warn{color:#f59e0b;} .info{color:#3b82f6;} .error{color:#ef4444;}
        li{margin:6px 0;font-size:13px;}
        a{color:#4f46e5;text-decoration:none;font-weight:bold;}
        a:hover{text-decoration:underline;}
        .btn{display:inline-block;background:#4f46e5;color:#fff;padding:0.5rem 1.2rem;border-radius:6px;margin-right:8px;margin-top:1rem;}
    </style>
</head>
<body>
<div class="box">
    <h2>POS Fix — Migration Results</h2>
    <ul>
    <?php foreach ($log as [$type, $msg]): ?>
        <li class="<?php echo $type; ?>">
            <?php echo $type==='ok'?'✓':($type==='info'?'ℹ':'⚠'); ?>
            <?php echo htmlspecialchars($msg); ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <div style="margin-top:1.5rem;">
        <a class="btn" href="admin/pos.php">→ Go to POS</a>
        <a class="btn" href="admin/dashboard.php">→ Dashboard</a>
    </div>
    <p style="color:#9ca3af;font-size:11px;margin-top:1rem;">⚠ Delete this file after use: <code>fix_all.php</code></p>
</div>
</body>
</html>
