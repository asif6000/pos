<?php
require __DIR__ . '/config/db.php';
$db = getDB();
$p = $db->query("SELECT id, name, sell_price, buy_price, owner_id FROM products LIMIT 10")->fetchAll();
echo json_encode($p);
