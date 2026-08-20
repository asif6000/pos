<?php
// Simulate stock.php purchase POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'add_stock';
$_POST['productId'] = '42';
$_POST['quantity'] = '5';
$_POST['type'] = 'purchase';
$_POST['note'] = 'Test purchase';

$_SERVER['HTTP_REFERER'] = 'stock.php';
session_save_path(__DIR__ . '/sessions');
session_id('testautoentry');
session_start();
$_SESSION['user_id'] = 29;
$_SESSION['user_name'] = 'yasin ali';
$_SESSION['user_email'] = 'yeasinali512629@gmail.com';
$_SESSION['user_role'] = 'admin';
$_SESSION['store_id'] = null;
$_SESSION['owner_id'] = 29;
session_write_close();

require __DIR__ . '/admin/stock.php';
