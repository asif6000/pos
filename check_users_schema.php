<?php
require_once 'config/db.php';
$db = getDB();
$stmt = $db->query("DESCRIBE users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
