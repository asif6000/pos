<?php
require_once 'config/db.php';
$db = getDB();
$stmt = $db->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = ?');
$stmt->execute(['koro@pos.com']);
$user = $stmt->fetch();
if ($user) {
    print_r($user);
    echo "Password verify: " . (password_verify('admin123', $user['password']) ? 'TRUE' : 'FALSE') . "\n";
} else {
    echo "User not found!\n";
}
