<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>POS Diagnostic Tool</h1>";

echo "<h2>1. Checking config/db.php...</h2>";
if (!file_exists('config/db.php')) {
    die("<span style='color:red'>ERROR: config/db.php NOT FOUND!</span>");
}
require_once 'config/db.php';
echo "<span style='color:green'>SUCCESS: config/db.php loaded.</span>";

echo "<h2>2. Testing Database Connection...</h2>";
try {
    $db = getDB();
    echo "<span style='color:green'>SUCCESS: Connected to database: " . DB_NAME . "</span>";
} catch (Exception $e) {
    die("<span style='color:red'>ERROR: Database Connection Failed: " . $e->getMessage() . "</span>");
}

echo "<h2>3. Fetching User Table...</h2>";
try {
    $stmt = $db->query("SELECT id, name, email, role, status FROM users");
    $users = $stmt->fetchAll();
    echo "<h3>Live Users Found: " . count($users) . "</h3>";
    echo "<table border='1' cellpadding='10'><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>" . $user['id'] . "</td>";
        echo "<td>" . $user['name'] . "</td>";
        echo "<td>" . $user['email'] . "</td>";
        echo "<td>" . $user['role'] . "</td>";
        echo "<td>" . $user['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<span style='color:red'>ERROR: Failed to fetch users: " . $e->getMessage() . "</span>";
}
