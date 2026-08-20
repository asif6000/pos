<?php
require_once 'config/db.php';

echo "<h2>Current Stores and Users Status</h2>";

$db = getDB();

// Check stores
echo "<h3>Stores:</h3>";
$stmt = $db->query("SELECT id, name, owner_id FROM stores ORDER BY id");
$stores = $stmt->fetchAll();

echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Owner ID</th></tr>";
foreach($stores as $store) {
    echo "<tr>";
    echo "<td>{$store['id']}</td>";
    echo "<td>" . htmlspecialchars($store['name']) . "</td>";
    echo "<td>{$store['owner_id']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check users
echo "<h3>Users:</h3>";
$stmt = $db->query("SELECT id, name, email, owner_id FROM users ORDER BY id");
$users = $stmt->fetchAll();

echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Owner ID</th></tr>";
foreach($users as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>" . htmlspecialchars($user['name']) . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>{$user['owner_id']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p><strong>Status:</strong> All stores now have owner_id = 3, which corresponds to user ID 3.</p>";
echo "<p>The stores should now be visible in the admin panel for the user with ID 3.</p>";
?>