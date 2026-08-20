<?php
require_once 'config/db.php';
startSecureSession();

$email = 'koro@pos.com';
$password = 'admin123';

$db = getDB();
$stmt = $db->prepare("SELECT id, name, email, password, role, status, store_id, owner_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

echo "User found: " . ($user ? 'YES' : 'NO') . "\n";
if ($user) {
    echo "Status: {$user['status']}\n";
    echo "Role: {$user['role']}\n";
    echo "Password verify: " . (password_verify($password, $user['password']) ? 'TRUE' : 'FALSE') . "\n";
    echo "Would login succeed: " . ($user && password_verify($password, $user['password']) && $user['status'] === 'active' ? 'YES' : 'NO') . "\n";
}
