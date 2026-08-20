<?php
require_once 'config/db.php';
$db = getDB();

$email = 'koro@pos.com';
$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->execute([$hash, $email]);
echo "Rows updated: {$stmt->rowCount()}\n";

// Verify
$stmt2 = $db->prepare("SELECT password FROM users WHERE email = ?");
$stmt2->execute([$email]);
$row = $stmt2->fetch();
echo "New hash: {$row['password']}\n";
echo "Verify: " . (password_verify($password, $row['password']) ? 'TRUE' : 'FALSE') . "\n";

// Also ensure status is active
$db->prepare("UPDATE users SET status = 'active' WHERE email = ?")->execute([$email]);
echo "Status set to active.\n";
