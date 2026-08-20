<?php
/**
 * Store Management Script
 * Helps with reassigning users and deleting stores safely
 */

require_once 'config/db.php';

$db = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'reassign_users') {
        $from_store = (int)$_POST['from_store'];
        $to_store = (int)$_POST['to_store'];
        
        try {
            $db->beginTransaction();
            
            // Move users from one store to another
            $stmt = $db->prepare("UPDATE users SET store_id = ? WHERE store_id = ?");
            $stmt->execute([$to_store, $from_store]);
            
            $db->commit();
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
            echo "Users successfully reassigned from store ID $from_store to store ID $to_store";
            echo "</div>";
        } catch (Exception $e) {
            $db->rollback();
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0;'>";
            echo "Error: " . $e->getMessage();
            echo "</div>";
        }
    } elseif ($action === 'delete_store') {
        $store_id = (int)$_POST['store_id'];
        
        try {
            $db->beginTransaction();
            
            // Check if store has users
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE store_id = ?");
            $stmt->execute([$store_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception("Cannot delete store because it has $user_count assigned users. Please reassign users first.");
            }
            
            // Delete the store
            $stmt = $db->prepare("DELETE FROM stores WHERE id = ?");
            $stmt->execute([$store_id]);
            
            $db->commit();
            echo "<div style='color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0;'>";
            echo "Store ID $store_id deleted successfully";
            echo "</div>";
        } catch (Exception $e) {
            $db->rollback();
            echo "<div style='color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0;'>";
            echo "Error: " . $e->getMessage();
            echo "</div>";
        }
    }
}

// Get current data
$stores = $db->query("SELECT id, name FROM stores ORDER BY id")->fetchAll();
$store_users = [];
foreach ($stores as $store) {
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE store_id = ?");
    $stmt->execute([$store['id']]);
    $store_users[$store['id']] = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Store Management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin: 15px 0; }
        .form-group { margin: 10px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, button { padding: 8px; margin: 5px 0; }
        button { background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .user-list { background: #f8f9fa; padding: 10px; border-radius: 3px; margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Store Management</h1>
        
        <!-- Reassign Users Form -->
        <div class="card">
            <h2>Reassign Users Between Stores</h2>
            <form method="POST">
                <input type="hidden" name="action" value="reassign_users">
                <div class="form-group">
                    <label>Move users FROM store:</label>
                    <select name="from_store" required>
                        <option value="">Select source store</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?> (ID: <?php echo $store['id']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Move users TO store:</label>
                    <select name="to_store" required>
                        <option value="">Select destination store</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['name']); ?> (ID: <?php echo $store['id']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Reassign Users</button>
            </form>
        </div>
        
        <!-- Delete Store Form -->
        <div class="card">
            <h2>Delete Store</h2>
            <p><strong>Note:</strong> You can only delete stores that have no assigned users.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_store">
                <div class="form-group">
                    <label>Select store to delete:</label>
                    <select name="store_id" required>
                        <option value="">Select store</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>">
                                <?php echo htmlspecialchars($store['name']); ?> (ID: <?php echo $store['id']; ?>) 
                                - <?php echo count($store_users[$store['id']]); ?> users
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" onclick="return confirm('Are you sure you want to delete this store?')">Delete Store</button>
            </form>
        </div>
        
        <!-- Current Store Status -->
        <div class="card">
            <h2>Current Store Status</h2>
            <?php foreach ($stores as $store): ?>
                <div style="margin: 15px 0; padding: 10px; border: 1px solid #eee;">
                    <h3><?php echo htmlspecialchars($store['name']); ?> (ID: <?php echo $store['id']; ?>)</h3>
                    <p><strong>Assigned Users (<?php echo count($store_users[$store['id']]); ?>):</strong></p>
                    <div class="user-list">
                        <?php if (empty($store_users[$store['id']])): ?>
                            <p>No users assigned</p>
                        <?php else: ?>
                            <?php foreach ($store_users[$store['id']] as $user): ?>
                                <div>- <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars($user['email']); ?>)</div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>