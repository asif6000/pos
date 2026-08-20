<?php
$base = 'C:\xampp1\htdocs\pos\pos\pos';
require $base . '\config\db.php';
$db = getDB();
// The test purchase added +5 to store 11. Revert it.
$db->prepare("UPDATE store_stocks SET quantity = quantity - 5 WHERE store_id = 11 AND product_id = 42")->execute();
$q = $db->query("SELECT store_id, quantity FROM store_stocks WHERE product_id = 42");
echo "After fix:\n";
foreach ($q->fetchAll() as $r) { echo "  store " . $r['store_id'] . ": " . $r['quantity'] . "\n"; }
