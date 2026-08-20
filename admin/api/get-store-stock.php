<?php
header('Content-Type: application/json');
require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$store_id = (int) ($_GET['store_id'] ?? 0);
$product_id = (int) ($_GET['product_id'] ?? 0);

if (!$store_id || !$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$db = getDB();
$user = getCurrentUser();

$stmt = $db->prepare("SELECT ss.quantity FROM store_stocks ss JOIN stores s ON ss.store_id = s.id WHERE ss.store_id = ? AND ss.product_id = ? AND s.owner_id = ?");
$stmt->execute([$store_id, $product_id, $user['owner_id']]);
$stock = $stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'store_id' => $store_id,
    'product_id' => $product_id,
    'stock' => $stock !== false ? (int)$stock : 0
]);
