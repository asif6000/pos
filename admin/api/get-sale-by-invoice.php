<?php
/**
 * POS System - Get Sale by Invoice Number API
 * Loads a previous sale's items into the POS cart (repeat sale)
 * when an invoice barcode (INV-...) is scanned.
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$invoice = sanitize($_GET['invoice'] ?? '');

if (empty($invoice)) {
    echo json_encode(['success' => false, 'message' => 'Invoice number is required']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'];

try {
    // Find sale by invoice number (cross-store lookup)
    $stmt = $db->prepare("SELECT s.* FROM sales s WHERE s.invoice_number = ?");
    $stmt->execute([$invoice]);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found: ' . $invoice]);
        exit;
    }

    // Get the current user's store for stock lookup
    $storeId = $currentUser['store_id'] ?? 0;
    if (!$storeId) {
        $stmtStore = $db->prepare("SELECT store_id FROM users WHERE id = ?");
        $stmtStore->execute([$currentUser['id']]);
        $storeId = $stmtStore->fetchColumn();
    }
    if (!$storeId) {
        $stmtStore = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
        $stmtStore->execute([$ownerId]);
        $storeId = $stmtStore->fetchColumn() ?: 0;
    }

    // Get sale items
    $stmt = $db->prepare("SELECT product_id, product_name, quantity, unit_price FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$sale['id']]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items found in this sale']);
        exit;
    }

    // Attach current stock for each product
    $stmtStock = $db->prepare("SELECT ss.quantity FROM store_stocks ss JOIN products p ON p.id = ss.product_id WHERE ss.store_id = ? AND ss.product_id = ? AND p.status = 'active' AND p.owner_id = ?");

    foreach ($items as &$item) {
        $stock = 0;
        if ($storeId > 0) {
            $stmtStock->execute([$storeId, $item['product_id'], $ownerId]);
            $stockRow = $stmtStock->fetch();
            if ($stockRow) {
                $stock = (int) $stockRow['quantity'];
            }
        }
        $item['stock'] = $stock;
        $item['product_id'] = (int) $item['product_id'];
        $item['quantity'] = (int) $item['quantity'];
        $item['unit_price'] = (float) $item['unit_price'];
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'invoice_number' => $sale['invoice_number'],
        'sale_id' => (int) $sale['id'],
        'sale_date' => date('d M Y, h:i A', strtotime($sale['created_at'])),
        'items' => $items
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
