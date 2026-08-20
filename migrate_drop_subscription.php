<?php
/**
 * One-time Migration: Drop Subscription Columns
 * Run this once via browser: http://localhost/pos/migrate_drop_subscription.php
 * Then delete this file.
 */
require_once 'config/db.php';
$db = getDB();

$sqls = [
    "ALTER TABLE users DROP COLUMN IF EXISTS subscription_plan",
    "ALTER TABLE users DROP COLUMN IF EXISTS subscription_status",
    "ALTER TABLE users DROP COLUMN IF EXISTS subscription_end",
    "ALTER TABLE users DROP COLUMN IF EXISTS payment_trx_id",
];

echo "<h2>Subscription Column Cleanup</h2>";
echo "<ul>";
foreach ($sqls as $sql) {
    try {
        $db->exec($sql);
        echo "<li style='color:green'>✓ " . htmlspecialchars($sql) . "</li>";
    } catch (Exception $e) {
        echo "<li style='color:orange'>⚠ " . htmlspecialchars($sql) . " — " . htmlspecialchars($e->getMessage()) . "</li>";
    }
}
echo "</ul>";

// Also drop the index if it exists
try {
    $db->exec("ALTER TABLE users DROP INDEX idx_subscription_status");
    echo "<p style='color:green'>✓ Dropped index idx_subscription_status</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠ Index already removed or not found: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><strong>Migration complete!</strong> You can now delete this file.</p>";
echo "<p><a href='auth/login.php'>Go to Login</a></p>";
