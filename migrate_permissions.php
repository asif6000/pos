<?php
/**
 * Run this once to create the role_permissions table and set default permissions.
 * Visit: http://localhost/pos/pos/pos/migrate_permissions.php
 * Delete this file after running.
 */
require_once 'config/db.php';

$db = getDB();
$log = [];

try {
    // 1. Create role_permissions table
    $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_slug VARCHAR(100) NOT NULL,
        permission VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_role_perm (role_slug, permission),
        INDEX idx_role_slug (role_slug),
        INDEX idx_permission (permission)
    ) ENGINE=InnoDB");
    $log[] = ['ok', 'Table role_permissions created (or already exists).'];

    // 2. Default admin permissions
    $adminPerms = [
        'dashboard','pos','products','categories','stock','transfers',
        'sales','sales_delete','returns','reports',
        'customers','users','stores','roles','settings','barcode_settings','vouchers'
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES ('admin', ?)");
    foreach ($adminPerms as $p) { $stmt->execute([$p]); }
    $log[] = ['ok', 'Admin permissions inserted (' . count($adminPerms) . ' permissions).'];

    // 3. Default cashier permissions
    $cashierPerms = ['pos', 'sales', 'customers'];
    $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES ('cashier', ?)");
    foreach ($cashierPerms as $p) { $stmt->execute([$p]); }
    $log[] = ['ok', 'Cashier permissions inserted (' . count($cashierPerms) . ' permissions).'];

    // 4. Fix stock_history type column to include sale_delete
    try {
        $db->exec("ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase','sale','adjustment','return','transfer','sale_delete') NOT NULL");
        $log[] = ['ok', 'stock_history.type column updated to include sale_delete.'];
    } catch (Exception $e) {
        $log[] = ['warn', 'stock_history.type update skipped: ' . $e->getMessage()];
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
    <title>Permission Migration</title>
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
    <h2>Role Permissions Migration</h2>
    <ul>
    <?php foreach ($log as [$type, $msg]): ?>
        <li class="<?php echo $type; ?>">
            <?php echo $type === 'ok' ? '✓' : ($type === 'warn' ? '⚠' : '✗'); ?>
            <?php echo $msg; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <p style="margin-top:1.5rem;">
        <a href="admin/roles.php">→ Go to Roles & Permissions</a> &nbsp;|&nbsp;
        <a href="admin/dashboard.php">→ Go to Dashboard</a>
    </p>
    <p style="color:#9ca3af; font-size:12px;">⚠ Delete this file after running for security.</p>
</div>
</body>
</html>
