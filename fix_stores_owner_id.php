<?php
/**
 * Fix script for stores owner_id issues
 * This script will:
 * 1. Ensure all users have proper owner_id values (their own ID)
 * 2. Update stores to have proper owner_id values (matching their creator)
 * 3. Fix any data inconsistencies
 */

require_once 'config/db.php';

echo "Starting stores owner_id fix...\n";

try {
    $db = getDB();
    $db->beginTransaction();
    
    // 1. Fix users - ensure all users have owner_id = their own ID
    echo "1. Fixing user owner_id values...\n";
    $stmt = $db->query("SELECT id FROM users WHERE owner_id IS NULL OR owner_id != id");
    $usersToFix = $stmt->fetchAll();
    
    foreach ($usersToFix as $user) {
        $db->prepare("UPDATE users SET owner_id = ? WHERE id = ?")->execute([$user['id'], $user['id']]);
        echo "  - Fixed user ID {$user['id']}\n";
    }
    
    // 2. Fix stores - assign proper owner_id values
    echo "2. Fixing store owner_id values...\n";
    $stmt = $db->query("SELECT id, name, owner_id FROM stores ORDER BY id");
    $stores = $stmt->fetchAll();
    
    foreach ($stores as $store) {
        // If store has owner_id = 1 (non-existent user) or NULL, assign to first available user
        if ($store['owner_id'] == 1 || $store['owner_id'] === null) {
            // Get the first user as default owner
            $stmt = $db->query("SELECT id FROM users ORDER BY id LIMIT 1");
            $firstUser = $stmt->fetch();
            
            if ($firstUser) {
                $db->prepare("UPDATE stores SET owner_id = ? WHERE id = ?")->execute([$firstUser['id'], $store['id']]);
                echo "  - Updated store '{$store['name']}' (ID: {$store['id']}) to owner_id {$firstUser['id']}\n";
            }
        } else {
            // Verify the owner exists
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$store['owner_id']]);
            if (!$stmt->fetch()) {
                // Owner doesn't exist, assign to first user
                $stmt = $db->query("SELECT id FROM users ORDER BY id LIMIT 1");
                $firstUser = $stmt->fetch();
                
                if ($firstUser) {
                    $db->prepare("UPDATE stores SET owner_id = ? WHERE id = ?")->execute([$firstUser['id'], $store['id']]);
                    echo "  - Reassigned store '{$store['name']}' (ID: {$store['id']}) to owner_id {$firstUser['id']}\n";
                }
            }
        }
    }
    
    $db->commit();
    echo "Fix completed successfully!\n\n";
    
    // Display results
    echo "=== RESULTS ===\n";
    $stmt = $db->query("SELECT id, name, email, owner_id FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    echo "Users:\n";
    foreach ($users as $user) {
        echo "  ID: {$user['id']} - {$user['name']} ({$user['email']}) - Owner ID: {$user['owner_id']}\n";
    }
    
    echo "\nStores:\n";
    $stmt = $db->query("SELECT id, name, owner_id FROM stores ORDER BY id");
    $stores = $stmt->fetchAll();
    foreach ($stores as $store) {
        echo "  ID: {$store['id']} - {$store['name']} - Owner ID: {$store['owner_id']}\n";
    }
    
} catch (Exception $e) {
    $db->rollback();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>