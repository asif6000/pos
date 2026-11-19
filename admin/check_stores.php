<?php
require_once '../config/db.php';
startSecureSession();
// The .htaccess filename rule is not the guard - see the note in debug_stocks.php.
if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden');
}
$db = getDB();
$stores = $db->query("SELECT * FROM stores")->fetchAll();
print_r($stores);
