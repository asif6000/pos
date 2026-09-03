<?php
/**
 * POS System - Get Latest Sale by Product Barcode API
 * Finds the most recent sale containing a product (by barcode) for quick returns
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasPermission('returns')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: Your plan does not include this feature.']);
    exit;
}

$barcode = sanitize($_GET['barcode'] ?? '');

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => 'Barcode is required']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId     = $currentUser['owner_id'] ?? $currentUser['id'];

try {
    // Scope product lookup to this tenant's products
    $stmt = $db->prepare("SELECT id, name FROM products WHERE barcode = ? AND status = 'active' AND owner_id = ?");
    $stmt->execute([$barcode, $ownerId]);

    if (!$stmt->rowCount()) {
        $stmt = $db->prepare("SELECT id, name FROM products WHERE LOWER(barcode) = LOWER(?) AND status = 'active' AND owner_id = ?");
        $stmt->execute([$barcode, $ownerId]);
    }
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found for this barcode']);
        exit;
    }

    // Find the most recent sale containing this product (scoped to this tenant)
    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total, s.created_at
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE si.product_id = ?
          AND (s.owner_id = ? OR u.owner_id = ?)
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$product['id'], $ownerId, $ownerId]);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'No sale found for this product']);
        exit;
    }

    // Get all sale items
    $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$sale['id']]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items found in this sale']);
        exit;
    }

    // Check for existing returns on this sale
    $stmt = $db->prepare("
        SELECT ri.product_id, SUM(ri.quantity) as returned_qty
        FROM return_items ri
        JOIN returns r ON ri.return_id = r.id
        WHERE r.sale_id = ?
        GROUP BY ri.product_id
    ");
    $stmt->execute([$sale['id']]);
    $existingReturns = [];
    while ($row = $stmt->fetch()) {
        $existingReturns[$row['product_id']] = (int) $row['returned_qty'];
    }

    // Adjust available quantities based on existing returns
    $scannedItemId = null;
    foreach ($items as &$item) {
        $alreadyReturned = $existingReturns[$item['product_id']] ?? 0;
        $item['quantity'] = max(0, $item['quantity'] - $alreadyReturned);
        if ((int) $item['product_id'] === (int) $product['id']) {
            $scannedItemId = (int) $item['id'];
        }
    }

    // Filter out fully returned items
    $items = array_filter($items, function ($item) {
        return $item['quantity'] > 0;
    });

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'All items from this sale have already been returned']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'sale' => [
            'id' => (int) $sale['id'],
            'invoice_number' => $sale['invoice_number'],
            'date' => date('d M Y, h:i A', strtotime($sale['created_at'])),
            'total' => $sale['total'],
            'items' => array_values($items)
        ],
        'scanned_item_id' => $scannedItemId,
        'product_name' => $product['name']
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>