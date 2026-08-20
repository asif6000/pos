<?php
/**
 * Run this once to add the missing 'comment' column to the products table.
 * Visit: http://localhost/pos/pos/pos/migrate_product_comment.php  (or your hosted URL)
 * Delete this file after running.
 */
require_once 'config/db.php';

$db = getDB();
$log = [];

try {
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'comment'");
    $hasComment = (int)$stmt->fetchColumn() > 0;

    if ($hasComment) {
        $log[] = ['ok', 'products.comment column already exists. Nothing to do.'];
    } else {
        $db->exec("ALTER TABLE products ADD COLUMN comment TEXT NULL AFTER description");
        $log[] = ['ok', 'Added products.comment column (TEXT NULL).'];
    }

    $log[] = ['ok', '<strong>Migration completed successfully!</strong> You can delete this file now.'];
} catch (PDOException $e) {
    $log[] = ['error', 'Error: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Comment Column Migration</title>
    <style>
        body { font-family: monospace; padding: 2rem; background: #f8fafc; }
        .ok    { color: #10b981; }
        .warn  { color: #f59e0b; }
        .error { color: #ef4444; }
        li { margin: 6px 0; font-size: 14px; }
        .box { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.5rem; max-width: 700px; }
        h2 { margin-top: 0; color: #111827; }
        a { color: #4f46e5; }
    </style>
</head>
<body>
<div class="box">
    <h2>Product Comment Column Migration</h2>
    <ul>
    <?php foreach ($log as [$type, $msg]): ?>
        <li class="<?php echo $type; ?>">
            <?php echo $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗'); ?>
            <?php echo $msg; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <p style="margin-top:1.5rem;">
        <a href="admin/products.php">→ Go to Products</a> &nbsp;|&nbsp;
        <a href="admin/dashboard.php">→ Go to Dashboard</a>
    </p>
    <p style="color:#9ca3af; font-size:12px;">⚠ Delete this file after running for security.</p>
</div>
</body>
</html>