<?php
/**
 * API - Delete Sale
 * Deletes a sale and restores stock
 */

// Disable error reporting for cleaner JSON output
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only admin or users with sales_delete permission can delete sales
if (!hasRole('admin') && !hasPermission('sales_delete')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have permission to delete sales']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get raw POST data
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Fallback to $_POST if JSON body is empty (though fetch sends JSON)
$saleId = 0;
if (isset($input['id'])) {
    $saleId = (int) $input['id'];
} elseif (isset($_POST['id'])) {
    $saleId = (int) $_POST['id'];
}

if (!$saleId) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
    exit;
}

$db = getDB();

try {
    $db->beginTransaction();

    // Get already-returned quantities per product for this sale
    $stmtReturned = $db->prepare("
        SELECT ri.product_id, SUM(ri.quantity) as returned_qty
        FROM return_items ri
        JOIN returns r ON ri.return_id = r.id
        WHERE r.sale_id = ?
        GROUP BY ri.product_id
    ");
    $stmtReturned->execute([$saleId]);
    $returnedQtys = [];
    while ($row = $stmtReturned->fetch()) {
        $returnedQtys[$row['product_id']] = (int)$row['returned_qty'];
    }

    // Get sale items and store to restore stock
    $stmt = $db->prepare("SELECT si.product_id, si.quantity, s.user_id FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.sale_id = ?");
    $stmt->execute([$saleId]);
    $items = $stmt->fetchAll();

    // Get store_id from sale user
    $stmtStore = $db->prepare("SELECT store_id FROM users WHERE id = (SELECT user_id FROM sales WHERE id = ?)");
    $stmtStore->execute([$saleId]);
    $storeId = $stmtStore->fetchColumn();

    // Fallback: use current user's store or first active store
    if (!$storeId) {
        $stmtCur = $db->prepare("SELECT store_id FROM users WHERE id = ?");
        $stmtCur->execute([$_SESSION['user_id']]);
        $storeId = $stmtCur->fetchColumn();
    }
    if (!$storeId) {
        $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
        $stmtFallback->execute();
        $storeId = $stmtFallback->fetchColumn() ?: 0;
    }

    foreach ($items as $item) {
        // Subtract already-returned quantity to avoid double restoration
        $restoreQty = $item['quantity'];
        if (isset($returnedQtys[$item['product_id']])) {
            $restoreQty -= $returnedQtys[$item['product_id']];
        }

        // Restore stock in store_stocks if store_id available
        if ($storeId && $restoreQty > 0) {
            $check = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
            $check->execute([$storeId, $item['product_id']]);
            if (!$check->fetch()) {
                $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                    ->execute([$storeId, $item['product_id'], 0]);
            }
            $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?")
                ->execute([$restoreQty, $storeId, $item['product_id']]);
        }

        // Record stock history
        $history = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'sale_delete', ?, 'Sale Deleted', ?)");
        $history->execute([$item['product_id'], $restoreQty, $saleId, $_SESSION['user_id']]);
    }

    // Delete associated returns first to avoid foreign key constraints
    $stmt = $db->prepare("SELECT id FROM returns WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $returns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($returns as $returnId) {
        $db->prepare("DELETE FROM return_items WHERE return_id = ?")->execute([$returnId]);
        $db->prepare("DELETE FROM returns WHERE id = ?")->execute([$returnId]);
        deleteAutoCashbookEntries('return', $returnId);
    }

    // Delete sale items
    $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$saleId]);

    // Delete returns associated with this sale (optional, but good practice to clean up)
    // For now, if returns exist, we might have an issue. Ideally check for returns first.
    // If returns exist, we should probably block deletion or handle it.
    // Assuming simple deletion for now as per request.

    // Delete sale
    $db->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);

    // Remove auto cashbook entries linked to this sale and its returns
    deleteAutoCashbookEntries('sale', $saleId);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Sale deleted and stock restored']);

} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
