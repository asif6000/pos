<?php
require_once 'config/db.php';
$db = getDB();
$stmt = $db->query("SELECT id, name, email, role, subscription_plan FROM users WHERE role = 'admin' LIMIT 1");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
