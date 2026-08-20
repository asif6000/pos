<?php
/**
 * Simple Store Deletion Fix
 * This script will help you delete stores by automatically reassigning users
 */

require_once 'config/db.php';

$db = getDB();

// Handle deletion request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_store'])) {
    $store_id = (int)$_POST['store_id'];
    $target_store_id = (int)$_POST['target_store'];
    
    try {
        $db->beginTransaction();
        
        // Get store name for confirmation
        $stmt = $db->prepare("SELECT name FROM stores WHERE id = ?");
        $stmt->execute([$store_id]);
        $store_name = $stmt->fetchColumn();
        
        if (!$store_name) {
            throw new Exception("Store not found");
        }
        
        // Reassign all users from the store to be deleted to the target store
        $stmt = $db->prepare("UPDATE users SET store_id = ? WHERE store_id = ?");
        $stmt->execute([$target_store_id, $store_id]);
        $affected_users = $stmt->rowCount();
        
        // Delete the store
        $stmt = $db->prepare("DELETE FROM stores WHERE id = ?");
        $stmt->execute([$store_id]);
        
        $db->commit();
        
        echo "<div style='color: green; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; margin: 15px 0; border-radius: 5px;'>";
        echo "<strong>Success!</strong> Store '$store_name' (ID: $store_id) has been deleted.";
        if ($affected_users > 0) {
            echo "<br>$affected_users users were reassigned to store ID $target_store_id.";
        }
        echo "</div>";
        
    } catch (Exception $e) {
        $db->rollback();
        echo "<div style='color: red; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 15px 0; border-radius: 5px;'>";
        echo "<strong>Error:</strong> " . $e->getMessage();
        echo "</div>";
    }
}

// Get all stores with user counts
$stmt = $db->query("
    SELECT s.id, s.name, 
           (SELECT COUNT(*) FROM users u WHERE u.store_id = s.id) as user_count,
           (SELECT name FROM stores s2 WHERE s2.id = s.id) as store_name
    FROM stores s 
    ORDER BY s.id
");
$stores = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Store Deletion Fix</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; margin-bottom: 30px; }
        .store-item { 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 5px; 
            background: #fafafa;
        }
        .store-name { font-weight: bold; font-size: 18px; color: #007bff; }
        .store-info { color: #666; margin: 5px 0; }
        .delete-form { 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            padding: 15px; 
            border-radius: 5px; 
            margin-top: 10px;
        }
        select, button { 
            padding: 10px; 
            margin: 5px; 
            border: 1px solid #ddd; 
            border-radius: 4px; 
            font-size: 14px;
        }
        button { 
            background: #dc3545; 
            color: white; 
            border: none; 
            cursor: pointer;
            font-weight: bold;
        }
        button:hover { background: #c82333; }
        .warning { 
            color: #856404; 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            padding: 10px; 
            border-radius: 4px; 
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ Store Deletion Tool</h1>
        
        <div class="warning">
            <strong>⚠️ Warning:</strong> This tool will permanently delete stores. All users from the deleted store will be moved to another store.
        </div>
        
        <?php foreach ($stores as $store): ?>
            <div class="store-item">
                <div class="store-name"><?php echo htmlspecialchars($store['name']); ?></div>
                <div class="store-info">ID: <?php echo $store['id']; ?> | Users: <?php echo $store['user_count']; ?></div>
                
                <?php if ($store['user_count'] > 0): ?>
                    <div class="delete-form">
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this store and move <?php echo $store['user_count']; ?> users to another store?')">
                            <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
                            <strong>Move users to:</strong>
                            <select name="target_store" required>
                                <option value="">Select target store</option>
                                <?php foreach ($stores as $target_store): ?>
                                    <?php if ($target_store['id'] != $store['id']): ?>
                                        <option value="<?php echo $target_store['id']; ?>">
                                            <?php echo htmlspecialchars($target_store['name']); ?> (ID: <?php echo $target_store['id']; ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="delete_store">Delete Store</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="color: green; margin-top: 10px;">
                        ✅ No users assigned - Can be deleted directly
                    </div>
                    <form method="POST" style="margin-top: 10px;" onsubmit="return confirm('Delete this store permanently?')">
                        <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
                        <button type="submit" name="delete_store" style="background: #28a745;">Delete Store</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($stores)): ?>
            <div style="text-align: center; padding: 30px; color: #666;">
                <h3>No stores found</h3>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>