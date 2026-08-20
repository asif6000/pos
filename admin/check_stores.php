<?php
require_once '../config/db.php';
$db = getDB();
$stores = $db->query("SELECT * FROM stores")->fetchAll();
print_r($stores);
