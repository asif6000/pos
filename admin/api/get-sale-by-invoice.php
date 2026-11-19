<?php
/**
 * POS System - Get Sale by Invoice Number API
 * Loads a previous sale's items into the POS cart (repeat sale)
 * when an invoice barcode (INV-...) is scanned.
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
require_once '../../config/sale_split.php';
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

    // Whose sale this was, and off which shelf. An invoice number is a barcode a
    // customer carries, so this endpoint is read by whoever scans it - and before
    // this it answered for any shop's sale and priced the stock against whoever
    // happened to ask. A row written before sales.store_id existed is filed under
    // the store the cashier who made it stood in, which is where the reports put it.
    $saleOwner = (int) ($sale['owner_id'] ?? 0);
    $storeId = (int) ($sale['store_id'] ?? 0);
    if ($saleOwner <= 0 || $storeId <= 0) {
        $stmtMaker = $db->prepare("SELECT owner_id, store_id FROM users WHERE id = ?");
        $stmtMaker->execute([(int) $sale['user_id']]);
        $maker = $stmtMaker->fetch();
        if ($saleOwner <= 0) {
            $saleOwner = (int) ($maker['owner_id'] ?? 0);
        }
        if ($storeId <= 0) {
            $storeId = (int) ($maker['store_id'] ?? 0);
        }
    }

    if (!saleIsSuperAdmin($currentUser) && $saleOwner !== (int) $ownerId) {
        echo json_encode(['success' => false, 'message' => 'Ei invoice apnar shop-er na: ' . $invoice]);
        exit;
    }

    // A counter account (staff/cashier) loads only its own bills into the till -
    // another counter's sale is their record, and a repeat sale of it would book
    // its stock and its money under this counter's own hands.
    if (isOwnSalesOnlyUser($currentUser) && (int) ($sale['user_id'] ?? 0) !== (int) $currentUser['id']) {
        echo json_encode(['success' => false, 'message' => 'Ei invoice apnar nijer bikri na.']);
        exit;
    }

    // A repeat sale still needs a shelf to price stock against, and a till with no
    // store of its own has none - the shop's first active one is what the POS page
    // would have offered them anyway.
    if ($storeId <= 0) {
        $stmtStore = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
        $stmtStore->execute([$ownerId]);
        $storeId = (int) ($stmtStore->fetchColumn() ?: 0);
    }

    // Get sale items
    $stmt = $db->prepare("SELECT product_id, product_name, quantity, unit_price FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$sale['id']]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'No items found in this sale']);
        exit;
    }

    // Attach current stock for each product - off the shelf the sale was rung up
    // from, and judged by that shop's goods. A row written before products carried
    // an owner is as much this shop's as it ever was.
    $stmtStock = $db->prepare("SELECT ss.quantity FROM store_stocks ss JOIN products p ON p.id = ss.product_id WHERE ss.store_id = ? AND ss.product_id = ? AND p.status = 'active' AND (p.owner_id = ? OR p.owner_id IS NULL)");

    foreach ($items as &$item) {
        $stock = 0;
        if ($storeId > 0) {
            $stmtStock->execute([$storeId, $item['product_id'], $saleOwner]);
            $stockRow = $stmtStock->fetch();
            if ($stockRow) {
                $stock = (int) $stockRow['quantity'];
            }
        }
        $item['stock'] = $stock;
        $item['store_id'] = $storeId;
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
        'store_id' => $storeId,
        'items' => $items
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
