<?php
require_once '../config/db.php';
startSecureSession();
// The .htaccess filename rule is not the guard - see the note in debug_stocks.php.
if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}
$db = getDB();

try {
    echo "Checking stock_history type column...\n";
    $stmt = $db->query("DESCRIBE stock_history");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($col['Field'] === 'type') {
            echo "Type: " . $col['Type'] . "\n";
        }
    }

    echo "\nChecking tables...\n";
    $tables = ['transfers', 'transfer_items', 'stores', 'store_stocks'];
    foreach ($tables as $table) {
        try {
            $db->query("SELECT 1 FROM $table LIMIT 1");
            echo "$table: EXISTS\n";
        } catch (Exception $e) {
            echo "$table: MISSING\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
