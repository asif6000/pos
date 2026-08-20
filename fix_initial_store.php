<?php
/**
 * Initial Store Fix
 * This script will help diagnose and fix initial store issues
 */

require_once 'config/db.php';
startSecureSession();

echo "<h2>Initial Store Fix Tool</h2>";

// Check if user is logged in
if (!isLoggedIn()) {
    echo "<div style='color: red; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 15px 0; border-radius: 5px;'>";
    echo "<strong>Error:</strong> You must be logged in to use this tool.";
    echo "<br><a href='auth/login.php'>Login here</a>";
    echo "</div>";
    exit;
}

$currentUser = getCurrentUser();
$db = getDB();

echo "<div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Current User Information:</h3>";
echo "<p><strong>User ID:</strong> " . $currentUser['id'] . "</p>";
echo "<p><strong>Name:</strong> " . htmlspecialchars($currentUser['name']) . "</p>";
echo "<p><strong>Email:</strong> " . htmlspecialchars($currentUser['email']) . "</p>";
echo "<p><strong>Store ID:</strong> " . ($currentUser['store_id'] ?: 'NULL') . "</p>";
echo "<p><strong>Owner ID:</strong> " . $currentUser['owner_id'] . "</p>";
echo "<p><strong>Role:</strong> " . $currentUser['role'] . "</p>";
echo "</div>";

// Check stores for this user
$stmt = $db->prepare("SELECT id, name, status FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$currentUser['owner_id']]);
$stores = $stmt->fetchAll();

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Available Stores:</h3>";
echo "<p>Found " . count($stores) . " active stores for your account.</p>";

if (empty($stores)) {
    echo "<p style='color: red;'><strong>No stores found!</strong> You need at least one active store to use the Initial Store feature.</p>";
    
    // Offer to create a default store
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_default_store'])) {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO stores (name, status, owner_id) VALUES (?, 'active', ?)");
            $stmt->execute(['Default Store', $currentUser['owner_id']]);
            $newStoreId = $db->lastInsertId();
            $db->commit();
            
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
            echo "<strong>Success!</strong> Created default store with ID: $newStoreId";
            echo "</div>";
            
            // Refresh the stores list
            $stmt = $db->prepare("SELECT id, name, status FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
            $stmt->execute([$currentUser['owner_id']]);
            $stores = $stmt->fetchAll();
        } catch (Exception $e) {
            $db->rollback();
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0;'>";
            echo "<strong>Error:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
    
    echo "<form method='POST'>";
    echo "<button type='submit' name='create_default_store' style='background: #28a745; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer;'>";
    echo "Create Default Store";
    echo "</button>";
    echo "</form>";
} else {
    echo "<ul>";
    foreach ($stores as $store) {
        echo "<li><strong>ID:</strong> {$store['id']} | <strong>Name:</strong> " . htmlspecialchars($store['name']) . " | <strong>Status:</strong> {$store['status']}</li>";
    }
    echo "</ul>";
    
    echo "<p><strong>Initial Store Logic:</strong></p>";
    if ($currentUser['store_id']) {
        echo "<p style='color: orange;'>Your user account is assigned to store ID: " . $currentUser['store_id'] . "</p>";
        echo "<p>The Initial Store dropdown will be <strong>HIDDEN</strong> because you already have a default store assigned.</p>";
    } else {
        echo "<p style='color: green;'>Your user account has no default store assigned (store_id is NULL)</p>";
        echo "<p>The Initial Store dropdown will be <strong>VISIBLE</strong> when adding new products.</p>";
        echo "<p>Available stores for selection:</p>";
        echo "<select style='padding: 8px; margin: 5px;'>";
        foreach ($stores as $store) {
            echo "<option value='{$store['id']}'>" . htmlspecialchars($store['name']) . "</option>";
        }
        echo "</select>";
    }
}

echo "</div>";

// Test the products page logic
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Products Page Simulation:</h3>";

$userStoreId = $currentUser['store_id'];
echo "<p><strong>User store_id:</strong> " . ($userStoreId ?: 'NULL') . "</p>";

if (!$userStoreId) {
    echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
    echo "<strong>✓ Initial Store dropdown should SHOW</strong>";
    echo "</div>";
    
    if (!empty($stores)) {
        echo "<p><strong>Dropdown content:</strong></p>";
        echo "<select name='store_id' style='padding: 8px; margin: 5px;'>";
        foreach ($stores as $s) {
            echo "<option value='{$s['id']}'>" . htmlspecialchars($s['name']) . "</option>";
        }
        echo "</select>";
    }
} else {
    echo "<div style='color: orange; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; margin: 10px 0;'>";
    echo "<strong>⚠ Initial Store dropdown will be HIDDEN</strong>";
    echo "<br>Your default store is: <strong>" . htmlspecialchars($stores[0]['name'] ?? 'Unknown') . "</strong> (ID: $userStoreId)";
    echo "</div>";
}

echo "</div>";

// Quick fix options
echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Quick Fix Options:</h3>";

if ($currentUser['store_id']) {
    echo "<p>Your account has a default store assigned. If you want to see the Initial Store dropdown:</p>";
    echo "<form method='POST' style='display: inline-block; margin: 5px;'>";
    echo "<input type='hidden' name='action' value='remove_default_store'>";
    echo "<button type='submit' style='background: #dc3545; color: white; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer;'>";
    echo "Remove Default Store Assignment";
    echo "</button>";
    echo "</form>";
} else {
    echo "<p>Your account has no default store. The Initial Store dropdown should be working.</p>";
    echo "<p>If you're still having issues:</p>";
    echo "<ul>";
    echo "<li>Try clearing your browser cache</li>";
    echo "<li>Check browser console for JavaScript errors</li>";
    echo "<li>Verify you're accessing the correct products.php page</li>";
    echo "</ul>";
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'remove_default_store') {
        try {
            $stmt = $db->prepare("UPDATE users SET store_id = NULL WHERE id = ?");
            $stmt->execute([$currentUser['id']]);
            
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
            echo "<strong>Success!</strong> Default store assignment removed. Refresh the page to see changes.";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0;'>";
            echo "<strong>Error:</strong> " . $e->getMessage();
            echo "</div>";
        }
    }
}

echo "</div>";

echo "<div style='margin: 20px 0;'>";
echo "<a href='admin/products.php' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>Go to Products Page</a>";
echo "<a href='admin/stores.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px;'>Manage Stores</a>";
echo "</div>";
?>