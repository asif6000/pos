<?php
require_once '../config/db.php';
startSecureSession();
// This one alters tables, so it answers nobody who is not signed in as admin.
if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}
$db = getDB();

try {
    echo "Updating stock_history enum...\n";
    $db->exec("ALTER TABLE stock_history MODIFY COLUMN type ENUM('purchase', 'sale', 'adjustment', 'return', 'transfer') NOT NULL");
    echo "Done!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
