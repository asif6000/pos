<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Isolated Database Fixer v3.1</h1>";

// Hardcoded check using your credentials
$host = 'localhost';
$db_name = 'pos_system';
$user = 'root';
$pass = ''; 
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;dbname=$db_name;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "<p style='color:green'>SUCCESS: Connected to DB!</p>";
    
    // Extra manual columns for isolation
    $isolationQueries = [
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER status",
        "ALTER TABLE stores ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER payment_trx_id",
        "ALTER TABLE users ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE categories ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER status",
        "ALTER TABLE categories ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE products ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER status",
        "ALTER TABLE products ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE customers ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER address",
        "ALTER TABLE customers ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE sales ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER note",
        "ALTER TABLE sales ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE transfers ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER created_by",
        "ALTER TABLE transfers ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE returns ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER status",
        "ALTER TABLE returns ADD INDEX IF NOT EXISTS idx_owner (owner_id)",
        "ALTER TABLE settings ADD COLUMN IF NOT EXISTS owner_id INT NULL AFTER setting_value",
        "ALTER TABLE settings DROP INDEX IF EXISTS setting_key",
        "ALTER TABLE settings ADD UNIQUE INDEX IF NOT EXISTS uk_owner_key (owner_id, setting_key)",
        "ALTER TABLE settings ADD INDEX IF NOT EXISTS idx_owner (owner_id)"
    ];

    echo "<h2>Running " . count($isolationQueries) . " isolation queries...</h2>";

    foreach ($isolationQueries as $q) {
        try { 
            $pdo->exec($q); 
            echo "<div style='color:green; font-size:10px; border-bottom:1px solid #eee;'>Success: " . htmlspecialchars($q) . "</div>";
        } catch (Exception $e) {
            echo "<div style='color:orange; font-size:10px; border-bottom:1px solid #eee;'>Info: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    echo "<h1 style='color:green'>COMPLETED!</h1>";
    echo "<p><a href='auth/register.php'>CLICK HERE TO REGISTER</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>CRITICAL ERROR: " . $e->getMessage() . "</h2>";
}
