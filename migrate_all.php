<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Migration</h1>";
echo "<pre>";

require_once 'config/db.php';

try {
    $db = getDB();
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, 0);

    // Voucher feature removed from migrations. No voucher table changes are applied.

    echo "\n<h2 style='color:green'>SUCCESS: Migration complete!</h2>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>ERROR: " . $e->getMessage() . "</h2>";
}
echo "</pre>";
