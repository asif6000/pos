<?php
/**
 * Debug POS Sale Issues
 * Visit: http://localhost/pos/pos/pos/test_sale_debug.php
 * Delete after use.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';
startSecureSession();

$db = getDB();
$issues = [];
$ok = [];

// 1. Check stock_history ENUM
try {
    $col = $db->query("SHOW COLUMNS FROM stock_history LIKE 'type'")->fetch();
    $ok[] = "stock_history.type = " . $col['Type'];
    if (strpos($col['Type'], 'sale_delete') === false) {
        $issues[] = "❌ stock_history.type missing 'sale_delete' — run migrate_fix_sale.php";
    }
} catch (Exception $e) {
    $issues[] = "❌ stock_history check failed: " . $e->getMessage();
}

// 2. Check sales.payment_status ENUM
try {
    $col = $db->query("SHOW COLUMNS FROM sales LIKE 'payment_status'")->fetch();
    $ok[] = "sales.payment_status = " . $col['Type'];
    if (strpos($col['Type'], 'pending') !== false) {
        $issues[] = "⚠ sales.payment_status has 'pending' — process-sale.php now uses 'unpaid' (OK if migrated)";
    }
} catch (Exception $e) {
    $issues[] = "❌ sales check failed: " . $e->getMessage();
}

// 3. Check active stores
try {
    $stores = $db->query("SELECT id, name, owner_id FROM stores WHERE status='active'")->fetchAll();
    if (empty($stores)) {
        $issues[] = "❌ No active stores — create a store in Admin → Stores";
    } else {
        foreach ($stores as $s) {
            $ok[] = "Store: [{$s['id']}] {$s['name']} (owner_id={$s['owner_id']})";
        }
    }
} catch (Exception $e) {
    $issues[] = "❌ stores check: " . $e->getMessage();
}

// 4. Check store_stocks
try {
    $ssCount = $db->query("SELECT COUNT(*) FROM store_stocks")->fetchColumn();
    $ok[] = "store_stocks rows: {$ssCount}";
    if ($ssCount == 0) {
        $issues[] = "❌ store_stocks is empty — run migrate_fix_sale.php to sync products";
    }
} catch (Exception $e) {
    $issues[] = "❌ store_stocks check: " . $e->getMessage();
}

// 5. Check Walk-in customer
try {
    $walkin = $db->query("SELECT id, name FROM customers WHERE id=1")->fetch();
    if ($walkin) {
        $ok[] = "Walk-in customer: [{$walkin['id']}] {$walkin['name']}";
    } else {
        $issues[] = "❌ Walk-in customer (id=1) missing — run migrate_fix_sale.php";
    }
} catch (Exception $e) {
    $issues[] = "❌ customers check: " . $e->getMessage();
}

// 6. Check session / login
$ok[] = "Session user_id: " . ($_SESSION['user_id'] ?? 'NOT LOGGED IN');
$ok[] = "Session store_id: " . ($_SESSION['store_id'] ?? 'NULL (will use fallback)');
$ok[] = "Session owner_id: " . ($_SESSION['owner_id'] ?? 'NULL');
$ok[] = "Session role: " . ($_SESSION['user_role'] ?? 'NULL');

// 7. Try a test sale insert to catch real DB error
$testError = null;
try {
    $db->beginTransaction();
    $testInvoice = 'TEST-' . time();
    $userId = $_SESSION['user_id'] ?? 1;
    $ownerId = $_SESSION['owner_id'] ?? null;
    $db->prepare("INSERT INTO sales (invoice_number, customer_id, user_id, subtotal, discount_percent, discount_amount, vat_percent, vat_amount, total, paid_amount, change_amount, payment_method, payment_status, owner_id) VALUES (?,1,?,100,0,0,0,0,100,100,0,'cash','paid',?)")
        ->execute([$testInvoice, $userId, $ownerId]);
    $testSaleId = $db->lastInsertId();
    $db->rollBack(); // Don't save test data
    $ok[] = "✅ Test sale INSERT succeeded (rolled back) — sale_id would be {$testSaleId}";
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $testError = $e->getMessage();
    $issues[] = "❌ Test sale INSERT failed: {$testError}";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sale Debug</title>
    <style>
        body{font-family:monospace;padding:2rem;background:#f8fafc;}
        .box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:800px;}
        .ok{color:#10b981;} .issue{color:#ef4444;} .warn{color:#f59e0b;}
        li{margin:6px 0;font-size:13px;} h3{margin:1rem 0 0.5rem;}
        a{color:#4f46e5;}
    </style>
</head>
<body>
<div class="box">
    <h2>POS Sale Debug Report</h2>

    <?php if (!empty($issues)): ?>
    <h3 style="color:#ef4444;">Issues Found (<?php echo count($issues); ?>):</h3>
    <ul><?php foreach ($issues as $i): ?><li class="issue"><?php echo $i; ?></li><?php endforeach; ?></ul>
    <?php else: ?>
    <p class="ok"><strong>✅ No issues found! POS should work.</strong></p>
    <?php endif; ?>

    <h3>System Status:</h3>
    <ul><?php foreach ($ok as $o): ?><li class="ok">✓ <?php echo $o; ?></li><?php endforeach; ?></ul>

    <p style="margin-top:1.5rem;">
        <?php if (!empty($issues)): ?>
        <a href="migrate_fix_sale.php"><strong>→ Run Fix Migration</strong></a> &nbsp;|&nbsp;
        <?php endif; ?>
        <a href="admin/pos.php">→ Go to POS</a> &nbsp;|&nbsp;
        <a href="admin/dashboard.php">→ Dashboard</a>
    </p>
    <p style="color:#9ca3af;font-size:11px;">⚠ Delete this file after use.</p>
</div>
</body>
</html>
