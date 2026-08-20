<?php
require_once 'config/db.php';
$db = getDB();
$stmt = $db->query("DESCRIBE settings");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
