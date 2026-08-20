<?php
$base = 'C:\xampp1\htdocs\pos\pos\pos';
require $base . '\config\db.php';
$db = getDB();
// Remove the test purchase cash_out entry and revert stock (add 5 back for purchase, then the sale stock restored by delete already)
$db->prepare("DELETE FROM cashbook_entries WHERE id = 20")->execute();
// Revert the stock +5 from the test purchase and its history
$db->prepare("DELETE FROM stock_history WHERE note LIKE '%Test purchase%'")->execute();
$db->prepare("UPDATE store_stocks SET quantity = quantity - 5 WHERE product_id = 42")->execute();
// Remove test session file
@unlink($base . '\sessions\sess_testautoentry');
echo "Cleanup done";