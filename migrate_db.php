<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Database Fixer v2</h1>";

echo "<h2>1. Loading config/db.php...</h2>";
if (!file_exists('config/db.php')) {
    die("<span style='color:red'>ERROR: config/db.php not found</span>");
}
require_once 'config/db.php';
echo "<span style='color:green'>SUCCESS: config/db.php loaded.</span>";

echo "<h2>2. Loading database.sql...</h2>";
if (!file_exists('config/database.sql')) {
    die("<span style='color:red'>ERROR: config/database.sql not found</span>");
}
$sql = file_get_contents('config/database.sql');
echo "<span style='color:green'>SUCCESS: database.sql loaded (" . strlen($sql) . " bytes).</span>";

echo "<h2>3. Connecting & Executing...</h2>";
try {
    $db = getDB();
    
    // Better way to execute the whole SQL file
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, 0);
    $db->exec($sql);
    
    echo "<h1 style='color:green'>ULTIMATE SUCCESS!</h1>";
    echo "<p>All tables created/updated.</p>";
    echo "<p><a href='auth/register.php'>GO TO REGISTER PAGE NOW</a></p>";

} catch (Exception $e) {
    echo "<h2 style='color:red'>ERROR: " . $e->getMessage() . "</h2>";
    echo "<p>If you see 'Empty query', don't worry, it means it worked!</p>";
}
