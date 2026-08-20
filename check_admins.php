<?php
require_once 'config/db.php';
$db = getDB();
$stmt = $db->query("SELECT id, name, email, subscription_plan FROM users WHERE role = 'admin'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
