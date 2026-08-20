<?php
/**
 * Quick Fix for Multi-Product Sale
 * http://localhost/pos/pos/pos/quick_fix.php
 * DELETE after running.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';
startSecureSession();

$db = getDB();
$results = [];

// 1. Fix stock_history ENUM
try {
    $db->exec("ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase','sale','adjustment','return','transfer','sale_delete') NOT NULL");
    $results[] = ['ok', 'stock_history.type ENUM fixed'];
} catch(Exception $e) {
    $results[] = ['warn', 'stock_history.type: ' . $e->getMessage()];
}

// 2. Fix sales.payment_status ENUM — remove 'pending' if it exists
try {
    $db->exec("ALTER TABLE sales MODIFY COLUMN payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid'");
    $results[] = ['ok', 'sales.payment_status ENUM fixed'];
} catch(Exception $e) {
    $results[] = ['warn', 'sales.payment_status: ' . $e->getMessage()];
}

// 3. Ensure store exists
try {
    $count = $db->query("SELECT COUNT(*) FROM stores WHERE status='active'")->fetchColumn();
    if ($count == 0) {
        $adminId = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn() ?: 1;
        $db->prepare("INSERT INTO stores (name, status, owner_id) VALUES ('Main Store', 'active', ?)")->execute([$adminId]);
        $results[] = ['ok', 'Main Store created'];
    } else {
        $results[] = ['ok', "Active stores: {$count}"];
    }
} catch(Exception $e) {
    $results[] = ['warn', 'Store: ' . $e->getMessage()];
}

// 4. Sync products → store_stocks (most important fix)
try {
    $stores = $db->query("SELECT id FROM stores WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    $synced = 0;
    foreach ($stores as $sid) {
        $stmt = $db->prepare("
            INSERT IGNORE INTO store_stocks (store_id, product_id, quantity)
            SELECT ?, p.id, GREATEST(p.stock, 0)
            FROM products p
            WHERE p.status = 'active'
        ");
        $stmt->execute([$sid]);
        $synced += $stmt->rowCount();
    }
    $results[] = ['ok', "store_stocks synced: {$synced} rows added"];
} catch(Exception $e) {
    $results[] = ['warn', 'Sync: ' . $e->getMessage()];
}

// 5. Ensure Walk-in customer
try {
    $exists = $db->query("SELECT id FROM customers WHERE id=1")->fetchColumn();
    if (!$exists) {
        $db->exec("INSERT INTO customers (id,name,phone,address) VALUES (1,'Walk-in Customer','N/A','N/A')");
        $results[] = ['ok', 'Walk-in Customer created'];
    } else {
        $results[] = ['ok', 'Walk-in Customer OK'];
    }
} catch(Exception $e) {
    $results[] = ['warn', 'Customer: ' . $e->getMessage()];
}

// Show current store_stocks count per store
try {
    $rows = $db->query("SELECT st.name, COUNT(ss.id) as cnt FROM stores st LEFT JOIN store_stocks ss ON st.id = ss.store_id GROUP BY st.id, st.name")->fetchAll();
    foreach ($rows as $r) {
        $results[] = ['info', "Store '{$r['name']}': {$r['cnt']} products in stock"];
    }
} catch(Exception $e) {}

?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8"><title>Quick Fix</title>
<style>
body{font-family:monospace;padding:2rem;background:#f8fafc;}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:700px;}
.ok{color:#10b981;}.warn{color:#f59e0b;}.info{color:#3b82f6;}
li{margin:6px 0;font-size:13px;}
a{background:#4f46e5;color:#fff;padding:0.4rem 1rem;border-radius:5px;text-decoration:none;margin-right:8px;display:inline-block;margin-top:1rem;}
</style>
</head>
<body><div class="box">
<h2>Quick Fix Results</h2>
<ul>
<?php foreach($results as [$t,$m]): ?>
<li class="<?=$t?>">
<?=$t==='ok'?'✓':($t==='info'?'ℹ':'⚠')?> <?=htmlspecialchars($m)?>
</li>
<?php endforeach; ?>
</ul>
<a href="admin/pos.php">→ Go to POS</a>
<a href="debug_sale.php">→ Test Sale</a>
<p style="color:#9ca3af;font-size:11px;margin-top:1rem;">⚠ Delete quick_fix.php and debug_sale.php after use.</p>
</div></body></html>
