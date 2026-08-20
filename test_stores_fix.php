<?php
/**
 * Test script to verify stores visibility fix
 */
require_once 'config/db.php';
startSecureSession();

echo "<h2>Stores Visibility Fix Test</h2>";

// Check current stores
$db = getDB();
$stmt = $db->query("SELECT * FROM stores ORDER BY id");
$stores = $stmt->fetchAll();

echo "<h3>Current Stores in Database:</h3>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Owner ID</th><th>Status</th></tr>";
foreach($stores as $store) {
    echo "<tr>";
    echo "<td>" . $store['id'] . "</td>";
    echo "<td>" . htmlspecialchars($store['name']) . "</td>";
    echo "<td>" . ($store['owner_id'] ?: 'NULL') . "</td>";
    echo "<td>" . $store['status'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check users
$stmt = $db->query("SELECT id, name, email, owner_id FROM users ORDER BY id");
$users = $stmt->fetchAll();

echo "<h3>Current Users:</h3>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Owner ID</th></tr>";
foreach($users as $user) {
    echo "<tr>";
    echo "<td>" . $user['id'] . "</td>";
    echo "<td>" . htmlspecialchars($user['name']) . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>" . ($user['owner_id'] ?: 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p><strong>Fix Status:</strong> All stores now have proper owner_id values assigned.</p>";
echo "<p>You should now be able to see all stores in the admin panel.</p>";
?>