<?php
require_once '../config/db.php';
$db = getDB();
$stocks = $db->query("SELECT * FROM store_stocks WHERE quantity > 0")->fetchAll();
print_r($stocks);
