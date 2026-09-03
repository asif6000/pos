<?php
/**
 * POS System - Get Sale for Return API
 * Fetches sale details for processing returns
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

$invoice = sanitize($_GET['invoice'] ?? '');

if (empty($invoice)) {
    echo json_encode(['success' => false, 'message' => 'Invoice number is required']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId     = $currentUser['owner_id'] ?? $currentUser['id'];

try {
    // Scope to owner so a tenant cannot access another tenant's sales
    $stmt = $db->prepare("
        SELECT s.* FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.invoice_number = ?
          AND (s.owner_id = ? OR u.owner_id = ?)
    ");
    $stmt->execute([$invoice, $ownerId, $ownerId]);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit;
    }

    // Get sale items
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
    foreach ($items as &$item) {
        $alreadyReturned = $existingReturns[$item['product_id']] ?? 0;
        $item['quantity'] = max(0, $item['quantity'] - $alreadyReturned);
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
            'id' => $sale['id'],
            'invoice_number' => $sale['invoice_number'],
            'date' => date('d M Y, h:i A', strtotime($sale['created_at'])),
            'total' => $sale['total'],
            'items' => array_values($items)
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>