<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Minimal DB Test</h1>";

// Hardcoded check using your credentials
$host = 'localhost';
$db   = 'iksavwdx_newpos';
$user = 'iksavwdx_newpos';
$pass = 'iksavwdx_newpos'; // Are you sure the password is the same as the name?
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     echo "<p style='color:green'>SUCCESS: Connection worked!</p>";
     
     $stmt = $pdo->query("SHOW TABLES");
     $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
     echo "<h3>Tables found:</h3><ul>";
     foreach($tables as $table) {
         echo "<li>$table</li>";
     }
     echo "</ul>";

} catch (\PDOException $e) {
     echo "<p style='color:red'>FAILURE: " . $e->getMessage() . "</p>";
}
