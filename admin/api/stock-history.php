<?php
/**
 * POS System - Stock History API
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasPermission('stock')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: Your plan does not include this feature.']);
    exit;
}

$productId = (int) ($_GET['product_id'] ?? 0);

if (!$productId) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId     = $currentUser['owner_id'] ?? $currentUser['id'];

try {
    // Verify the product belongs to this tenant before returning history
    $ownerCheck = $db->prepare("SELECT id FROM products WHERE id = ? AND owner_id = ?");
    $ownerCheck->execute([$productId, $ownerId]);
    if (!$ownerCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT sh.*, u.name as user_name
        FROM stock_history sh
        LEFT JOIN users u ON sh.user_id = u.id
        WHERE sh.product_id = ?
        ORDER BY sh.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$productId]);
    $history = $stmt->fetchAll();

    // Format dates
    foreach ($history as &$h) {
        $h['date'] = date('d M Y, H:i', strtotime($h['created_at']));
    }

    echo json_encode(['success' => true, 'history' => $history]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>