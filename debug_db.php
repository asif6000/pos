<?php
require_once 'config/db.php';
try {
    $db = getDB();
    echo "Connection Success!<br>";
    
    // Check tables
    $stmt = $db->query("SHOW TABLES");
    echo "Tables: " . implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) . "<br>";
    
    // Check users table columns
    $stmt = $db->query("DESCRIBE users");
    echo "Users columns: <br>";
    foreach($stmt->fetchAll() as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
