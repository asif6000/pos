<?php
$base = 'C:\xampp1\htdocs\pos\pos\pos';
require $base . '\config\db.php';
$db = getDB();
$q = $db->prepare("SELECT quantity FROM store_stocks WHERE product_id = 42");
$q->execute();
echo "Product 42 stock: " . $q->fetchColumn() . "\n";
$c = $db->query("SELECT COUNT(*) FROM cashbook_entries WHERE source_type IS NOT NULL")->fetchColumn();
echo "Auto entries remaining: $c\n";