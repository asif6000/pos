<?php
header('Content-Type: application/json');
require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasPermission('transfers')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: Your plan does not include this feature.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$fromStoreId = (int) ($input['from_store_id'] ?? 0);
$toStoreId = (int) ($input['to_store_id'] ?? 0);
$items = $input['items'] ?? [];
$note = sanitize($input['note'] ?? '');
$userId = $_SESSION['user_id'];

if (!$fromStoreId || !$toStoreId || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

if ($fromStoreId == $toStoreId) {
    echo json_encode(['success' => false, 'message' => 'Cannot transfer to same store']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'];

// Verify store ownership AND that both stores belong to this tenant
$stmt = $db->prepare("SELECT id FROM stores WHERE id IN (?, ?) AND owner_id = ?");
$stmt->execute([$fromStoreId, $toStoreId, $ownerId]);
$validStores = $stmt->fetchAll();
if (count($validStores) < 2) {
    echo json_encode(['success' => false, 'message' => 'Invalid store association or access denied']);
    exit;
}

try {
    $db->beginTransaction();

    // Generate Reference
    $stmt = $db->query("SELECT MAX(id) FROM transfers");
    $lastId = $stmt->fetchColumn() ?: 0;
    $ref = 'TRF-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);

    // Create Transfer Record - Include owner_id
    $stmt = $db->prepare("INSERT INTO transfers (reference_no, from_store_id, to_store_id, status, note, created_by, owner_id) VALUES (?, ?, ?, 'completed', ?, ?, ?)");
    $stmt->execute([$ref, $fromStoreId, $toStoreId, $note, $userId, $ownerId]);
    $transferId = $db->lastInsertId();

    // Process Items
    $stmtItem = $db->prepare("INSERT INTO transfer_items (transfer_id, product_id, quantity) VALUES (?, ?, ?)");
    
    // Stock Updates
    $stmtCheckSource = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ? FOR UPDATE");
    $stmtDeductSource = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
    
    $stmtCheckDest = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
    $stmtInsertDest = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)");
    $stmtAddDest = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
    
    $stmtHistory = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'transfer', ?, ?, ?)");

    foreach ($items as $item) {
        $productId = (int) $item['id'];
        $qty = (int) $item['quantity'];

        if ($qty <= 0) continue;

        // Verify Source Stock
        $stmtCheckSource->execute([$fromStoreId, $productId]);
        $currentStock = $stmtCheckSource->fetchColumn();

        if ($currentStock === false) {
            throw new Exception("Product (ID $productId) not found in source store");
        }

        if ($currentStock < $qty) {
            throw new Exception("Insufficient stock for product ID $productId");
        }

        // Deduct from Source
        $stmtDeductSource->execute([$qty, $fromStoreId, $productId]);
        
        // Add to Destination
        $stmtCheckDest->execute([$toStoreId, $productId]);
        if ($stmtCheckDest->fetch() === false) {
            $stmtInsertDest->execute([$toStoreId, $productId, 0]);
        }
        $stmtAddDest->execute([$qty, $toStoreId, $productId]);

        // Record Item
        $stmtItem->execute([$transferId, $productId, $qty]);

        // History - Source
        $stmtHistory->execute([$productId, -$qty, $transferId, "Transfer OUT to Store $toStoreId ($ref)", $userId]);
        
        // History - Destination
        $stmtHistory->execute([$productId, $qty, $transferId, "Transfer IN from Store $fromStoreId ($ref)", $userId]);
    }

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Transfer completed', 'reference' => $ref]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
