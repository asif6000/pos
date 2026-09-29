<?php
require_once '../config/db.php';
$db = getDB();

try {
    echo "Updating stock_history enum...\n";
    $db->exec("ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase', 'sale', 'adjustment', 'return', 'transfer') NOT NULL");
    echo "Done!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
