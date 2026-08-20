<?php
require_once 'config/db.php';

try {
    $db = getDB();

    $email = 'koro@pos.com';
    $password = 'admin123';
    $name = 'Koro';
    $storeName = 'Koro Store';

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        echo "Admin user 'Koro' already exists (ID: {$user['id']})\n";
    } else {
        $db->beginTransaction();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'admin', 'active')");
        $stmt->execute([$name, $email, $hash]);
        $ownerId = $db->lastInsertId();

        $db->prepare('UPDATE users SET owner_id = ? WHERE id = ?')->execute([$ownerId, $ownerId]);

        $stmt = $db->prepare("INSERT INTO stores (name, status, owner_id) VALUES (?, 'active', ?)");
        $stmt->execute([$storeName, $ownerId]);
        $storeId = $db->lastInsertId();

        $db->prepare('UPDATE users SET store_id = ? WHERE id = ?')->execute([$storeId, $ownerId]);

        $db->commit();

        echo "========================================\n";
        echo "  Admin user 'Koro' created successfully!\n";
        echo "========================================\n";
        echo "Email:    {$email}\n";
        echo "Password: {$password}\n";
        echo "Role:     admin\n";
        echo "Store:    {$storeName}\n";
        echo "========================================\n";
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
