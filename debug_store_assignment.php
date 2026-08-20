<?php
/**
 * Store Assignment Test and Debug Page
 * This page helps test and debug the store assignment functionality
 */

require_once 'config/db.php';
startSecureSession();

echo "<h2>Store Assignment Debug & Test</h2>";

// Check if user is logged in
if (!isLoggedIn()) {
    echo "<div style='color: red; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 15px 0; border-radius: 5px;'>";
    echo "<strong>Error:</strong> You must be logged in to test this feature.";
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
echo "<p><strong>Default Store ID:</strong> " . ($currentUser['store_id'] ?: 'NULL') . "</p>";
echo "<p><strong>Owner ID:</strong> " . $currentUser['owner_id'] . "</p>";
echo "<p><strong>Role:</strong> " . $currentUser['role'] . "</p>";
echo "</div>";

// Get user's stores
$stmt = $db->prepare("SELECT id, name, status FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$currentUser['owner_id']]);
$stores = $stmt->fetchAll();

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Available Stores:</h3>";
echo "<p>Found " . count($stores) . " active stores for your account.</p>";

if (empty($stores)) {
    echo "<p style='color: red;'><strong>No stores found!</strong> You need at least one active store to test product creation.</p>";
    
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
            echo "<br>Refresh the page to see the new store in the list.";
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
    
    if ($currentUser['store_id']) {
        $stmt = $db->prepare("SELECT name FROM stores WHERE id = ?");
        $stmt->execute([$currentUser['store_id']]);
        $defaultStoreName = $stmt->fetchColumn();
        echo "<div style='background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0;'>";
        echo "<strong>Default Store:</strong> " . htmlspecialchars($defaultStoreName) . " (ID: " . $currentUser['store_id'] . ")";
        echo "<br>Products will automatically be assigned to this store.";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0;'>";
        echo "<strong>No Default Store:</strong> You'll need to select a store when creating products.";
        echo "</div>";
    }
}

echo "</div>";

// Test the store assignment logic
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Store Assignment Simulation:</h3>";

if (empty($stores)) {
    echo "<p>Cannot simulate - no stores available.</p>";
} else {
    echo "<h4>When Adding a New Product:</h4>";
    
    if ($currentUser['store_id']) {
        echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
        echo "<strong>✓ Automatic Assignment:</strong> Product will be assigned to your default store: <strong>" . htmlspecialchars($defaultStoreName) . "</strong>";
        echo "<br><small>The system will automatically use store ID: " . $currentUser['store_id'] . "</small>";
        echo "</div>";
    } else {
        echo "<div style='color: orange; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; margin: 10px 0;'>";
        echo "<strong>⚠ Manual Selection Required:</strong> You must select a store from the dropdown:";
        echo "<br><br>";
        echo "<select style='padding: 8px; margin: 5px; border: 1px solid #ccc; border-radius: 4px;' required>";
        echo "<option value=''>Select a store...</option>";
        foreach ($stores as $store) {
            echo "<option value='{$store['id']}'>" . htmlspecialchars($store['name']) . "</option>";
        }
        echo "</select>";
        echo "<br><small style='color: #666;'>This dropdown is required when no default store is assigned</small>";
        echo "<br><small style='color: #dc3545; display: none;' class='invalid-feedback'>Please select a store for this product.</small>";
        echo "</div>";
        
        echo "<div style='margin: 10px 0;'>";
        echo "<button onclick='testStoreValidation()' style='background: #007bff; color: white; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer;'>Test Validation</button>";
        echo "</div>";
    }
}

echo "</div>";

// Show current products and their store assignments
echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Current Products & Store Assignments:</h3>";

$stmt = $db->prepare("
    SELECT p.id, p.name, p.barcode, s.name as store_name, ss.quantity as stock, p.created_at
    FROM products p
    LEFT JOIN store_stocks ss ON p.id = ss.product_id
    LEFT JOIN stores s ON ss.store_id = s.id
    WHERE p.owner_id = ?
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute([$currentUser['owner_id']]);
$products = $stmt->fetchAll();

if (empty($products)) {
    echo "<p>No products found for your account.</p>";
    echo "<p>After creating products, they will appear here with their store assignments.</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Product ID</th><th>Name</th><th>Barcode</th><th>Store</th><th>Stock</th><th>Created</th></tr>";
    foreach ($products as $product) {
        $storeInfo = $product['store_name'] ? htmlspecialchars($product['store_name']) : '<span style="color: red;">Unassigned</span>';
        echo "<tr>";
        echo "<td>{$product['id']}</td>";
        echo "<td>" . htmlspecialchars($product['name']) . "</td>";
        echo "<td>" . ($product['barcode'] ?: 'N/A') . "</td>";
        echo "<td>{$storeInfo}</td>";
        echo "<td>{$product['stock']}</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($product['created_at'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</div>";

echo "<div style='margin: 20px 0;'>";
echo "<a href='admin/products.php' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>Go to Products Page</a>";
echo "<a href='admin/stores.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px;'>Manage Stores</a>";
echo "</div>";

echo "<div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 15px 0;'>";
echo "<h3>Enhanced Features Implemented:</h3>";
echo "<ul>";
echo "<li><strong>Clear Store Assignment:</strong> When adding products, you'll see which store they'll be assigned to</li>";
echo "<li><strong>Required Selection:</strong> If you have no default store, store selection is required with validation</li>";
echo "<li><strong>Better UX:</strong> Improved messaging, visual feedback, and error handling</li>";
echo "<li><strong>Automatic Assignment:</strong> Users with default stores don't need to select manually</li>";
echo "<li><strong>Real-time Validation:</strong> Form validation with visual feedback</li>";
echo "<li><strong>Debug Information:</strong> This page shows exactly what's happening with your store assignments</li>";
echo "</ul>";
echo "</div>";

?>

<script>
function testStoreValidation() {
    const select = document.querySelector('select[required]');
    const feedback = document.querySelector('.invalid-feedback');
    
    if (!select.value) {
        select.classList.add('is-invalid');
        feedback.style.display = 'block';
        setTimeout(() => {
            select.classList.remove('is-invalid');
            feedback.style.display = 'none';
        }, 3000);
    } else {
        alert('Store selected: ' + select.value + ' - Validation working correctly!');
    }
}

// Add real-time validation to the test dropdown
document.addEventListener('DOMContentLoaded', function() {
    const select = document.querySelector('select[required]');
    if (select) {
        select.addEventListener('change', function() {
            const feedback = document.querySelector('.invalid-feedback');
            if (this.value) {
                this.classList.remove('is-invalid');
                feedback.style.display = 'none';
            }
        });
    }
});
</script>