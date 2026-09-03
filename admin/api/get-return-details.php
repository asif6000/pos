<?php
/**
 * POS System - Get Return Details API
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

$returnId = (int) ($_GET['id'] ?? 0);

if (!$returnId) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID']);
    exit;
}

$db = getDB();

try {
    // Get return details
    $stmt = $db->prepare("
        SELECT r.*, s.invoice_number, u.name as processed_by
        FROM returns r
        JOIN sales s ON r.sale_id = s.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$returnId]);
    $return = $stmt->fetch();

    if (!$return) {
        echo json_encode(['success' => false, 'message' => 'Return not found']);
        exit;
    }

    // Get return items (excluding exchange items)
    $stmt = $db->prepare("SELECT * FROM return_items WHERE return_id = ? AND product_name NOT LIKE '[EXCHANGE] %'");
    $stmt->execute([$returnId]);
    $items = $stmt->fetchAll();

    // Get exchange items (stored in return_items with [EXCHANGE] prefix)
    $stmt = $db->prepare("SELECT * FROM return_items WHERE return_id = ? AND product_name LIKE '[EXCHANGE] %'");
    $stmt->execute([$returnId]);
    $exchangeRows = $stmt->fetchAll();

    // Clean up exchange item names (remove prefix)
    $exchangeItems = [];
    $exchangeTotal = 0;
    foreach ($exchangeRows as $row) {
        $row['product_name'] = preg_replace('/^\[EXCHANGE\]\s*/', '', $row['product_name']);
        $exchangeItems[] = $row;
        $exchangeTotal += (float)$row['total_price'];
    }

    // Calculate exchange_balance dynamically
    $returnTotal = (float)$return['total_amount'];
    $exchangeBalance = $exchangeTotal - $returnTotal;

    echo json_encode([
        'success' => true,
        'return' => [
            'id' => $return['id'],
            'return_number' => $return['return_number'],
            'invoice_number' => $return['invoice_number'],
            'date' => date('d M Y, h:i A', strtotime($return['created_at'])),
            'total_amount' => $return['total_amount'],
            'exchange_balance' => $exchangeBalance,
            'refund_method' => $return['refund_method'],
            'reason' => $return['reason'],
            'processed_by' => $return['processed_by'],
            'items' => $items,
            'exchange_items' => $exchangeItems
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>