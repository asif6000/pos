<?php
$base = 'C:\xampp1\htdocs\pos\pos\pos';
require $base . '\config\db.php';
$db = getDB();
$q = $db->query("SELECT store_id, quantity FROM store_stocks WHERE product_id = 42");
echo "store_stocks rows for 42:\n";
foreach ($q->fetchAll() as $r) { echo "  store " . $r['store_id'] . ": " . $r['quantity'] . "\n"; }
$h = $db->query("SELECT id, product_id, quantity_change, type, reference_id, note FROM stock_history WHERE product_id = 42 ORDER BY id DESC LIMIT 8");
echo "history:\n";
foreach ($h->fetchAll() as $r) { echo "  #" . $r['id'] . " qty_change=" . $r['quantity_change'] . " type=" . $r['type'] . " ref=" . $r['reference_id'] . " note=" . $r['note'] . "\n"; }
