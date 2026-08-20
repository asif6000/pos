<?php
require_once 'config/db.php';

echo "<h2>Initial Store Debug</h2>";

$db = getDB();

// Check current user data
echo "<h3>Current Users:</h3>";
$stmt = $db->query("SELECT id, name, email, store_id, owner_id FROM users ORDER BY id");
$users = $stmt->fetchAll();

echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Store ID</th><th>Owner ID</th></tr>";
foreach($users as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>" . htmlspecialchars($user['name']) . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>" . ($user['store_id'] ?: 'NULL') . "</td>";
    echo "<td>{$user['owner_id']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check stores for owner 3
echo "<h3>Stores for Owner ID 3:</h3>";
$stmt = $db->prepare("SELECT id, name, status, owner_id FROM stores WHERE owner_id = ? ORDER BY id");
$stmt->execute([3]);
$stores = $stmt->fetchAll();

echo "<table border='1'>";
echo "<tr><th>ID</th><th>Name</th><th>Status</th><th>Owner ID</th></tr>";
foreach($stores as $store) {
    echo "<tr>";
    echo "<td>{$store['id']}</td>";
    echo "<td>" . htmlspecialchars($store['name']) . "</td>";
    echo "<td>{$store['status']}</td>";
    echo "<td>{$store['owner_id']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<p>Total stores found: " . count($stores) . "</p>";

// Simulate the products.php logic
echo "<h3>Products.php Store Logic Simulation:</h3>";
$userStoreId = null; // User ID 3 has NULL store_id
echo "<p>User store_id: " . ($userStoreId ?: 'NULL') . "</p>";

if (!$userStoreId) {
    echo "<p><strong>Initial Store dropdown should SHOW</strong></p>";
    echo "<p>Available stores for selection:</p>";
    echo "<select>";
    foreach($stores as $s) {
        echo "<option value='{$s['id']}'>" . htmlspecialchars($s['name']) . "</option>";
    }
    echo "</select>";
} else {
    echo "<p><strong>Initial Store dropdown should be HIDDEN</strong></p>";
    echo "<p>Using store ID: $userStoreId</p>";
}

echo "<h3>Debug Summary:</h3>";
echo "<ul>";
echo "<li>User ID 3 has store_id = NULL</li>";
echo "<li>There are " . count($stores) . " stores available for owner 3</li>";
echo "<li>Initial Store dropdown should be visible</li>";
echo "</ul>";
?>