<?php
/**
 * POS System - Process Return by Barcode API
 * Auto-returns the latest sale containing a scanned product barcode.
 * Updates stock and stores receipt data in session.
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$barcode = sanitize($_GET['barcode'] ?? '');

if (empty($barcode)) {
    echo json_encode(['success' => false, 'message' => 'Barcode is required']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'];

try {
    // Find product by barcode (case insensitive)
    $stmt = $db->prepare("SELECT id, name FROM products WHERE barcode = ? AND status = 'active'");
    $stmt->execute([$barcode]);

    if (!$stmt->rowCount()) {
        $stmt = $db->prepare("SELECT id, name FROM products WHERE LOWER(barcode) = LOWER(?) AND status = 'active'");
        $stmt->execute([$barcode]);
    }
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found for this barcode']);
        exit;
    }

    // Find the most recent sale containing this product.
    //
    // A barcode is shop-local money: unscoped, this took the newest bill of ANY
    // tenant that happened to sell the same product, wrote a return against it,
    // moved that shop's stock and cut its totals. So the search stays inside the
    // caller's own shop - or their own stores for an owner with several - and only
    // a platform Super Admin, who bills across shops at the till, looks wider.
    $isSuperAdmin = isSuperAdminUser($currentUser);
    $callerStoreId = $currentUser['store_id'] ?? null;
    $scope = '';
    $scopeParams = [$product['id']];
    if ($callerStoreId) {
        $scope = " AND " . saleStoreExpr('s', 'u') . " = ?";
        $scopeParams[] = $callerStoreId;
    } elseif (!$isSuperAdmin) {
        $scope = " AND " . saleOwnerScopeSql($ownerId, 's', 'u');
        $scopeParams[] = $ownerId;
        $scopeParams[] = $ownerId;
    }
    // A counter account (staff/cashier) looks for its own bills only, on top of
    // whatever shop/store scope above applied.
    list($ownScope, $ownParams) = ownSalesScopeSql($currentUser, 's');
    $scope .= $ownScope;
    $scopeParams = array_merge($scopeParams, $ownParams);

    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total, s.created_at,
               s.user_id AS maker_id,
               " . saleStoreExpr('s', 'u') . " AS store_id,
               " . saleOwnerExpr('s', 'u') . " AS sale_owner_id
        FROM sales s
        JOIN sale_items si ON si.sale_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE si.product_id = ?" . $scope . "
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute($scopeParams);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'No sale found for this product']);
        exit;
    }

    // A counter account (staff/cashier) returns only what it sold itself: a
    // colleague's bill scanned here would move this shop's stock and cash for a
    // return the colleague should have handled.
    if (isOwnSalesOnlyUser($currentUser) && (int) $sale['maker_id'] !== (int) $currentUser['id']) {
        echo json_encode(['success' => false, 'message' => 'Ei sale apnar nijer bikri na - return korte parben na.']);
        exit;
    }

    $saleId = (int) $sale['id'];

    // Get sale items
    $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $saleItems = $stmt->fetchAll();

    if (empty($saleItems)) {
        echo json_encode(['success' => false, 'message' => 'No items found in this sale']);
        exit;
    }

    // Check existing returns on this sale
    $stmt = $db->prepare("
        SELECT ri.product_id, SUM(ri.quantity) as returned_qty
        FROM return_items ri
        JOIN returns r ON ri.return_id = r.id
        WHERE r.sale_id = ?
        GROUP BY ri.product_id
    ");
    $stmt->execute([$saleId]);
    $existingReturns = [];
    while ($row = $stmt->fetch()) {
        $existingReturns[$row['product_id']] = (int) $row['returned_qty'];
    }

    // Find the scanned item and its remaining returnable quantity
    $scannedItem = null;
    foreach ($saleItems as $item) {
        if ((int) $item['product_id'] === (int) $product['id']) {
            $alreadyReturned = $existingReturns[$item['product_id']] ?? 0;
            $remaining = max(0, (int) $item['quantity'] - $alreadyReturned);
            if ($remaining > 0) {
                $scannedItem = [
                    'id' => (int) $item['id'],
                    'product_id' => (int) $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity' => $remaining,
                    'unit_price' => (float) $item['unit_price']
                ];
            }
            break;
        }
    }

    if (!$scannedItem) {
        echo json_encode(['success' => false, 'message' => 'This product has already been fully returned from its latest sale']);
        exit;
    }

    $returnQty = $scannedItem['quantity'];
    $unitPrice = $scannedItem['unit_price'];
    $totalAmount = round($returnQty * $unitPrice, 2);

    // Generate return number
    $returnNumber = 'RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // DDL must not run inside the transaction below - an ALTER would commit it early.
    ensureCashbookSourceColumns();
    ensureCashbookDetailColumns();
    ensureReturnExchangeColumns();

    $db->beginTransaction();

    // The bill's own owner and shelf, for the same reason as the returns page: a
    // Super Admin's return belongs on the shop's books, not on their own.
    $saleOwnerId = $sale['sale_owner_id'] !== null ? (int)$sale['sale_owner_id'] : (int)$sale['maker_id'];

    // Which shelf the goods go back to, and so whose drawer pays for them. Resolved
    // before anything is written, same fallback chain as the returns page.
    $saleStoreId = (int)($sale['store_id'] ?? 0);

    if (!$saleStoreId) {
        $stmtCur = $db->prepare("SELECT store_id FROM users WHERE id = ?");
        $stmtCur->execute([$currentUser['id']]);
        $saleStoreId = $stmtCur->fetchColumn();
    }
    if (!$saleStoreId) {
        $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
        $stmtFallback->execute([$ownerId]);
        $saleStoreId = $stmtFallback->fetchColumn() ?: 0;
    }

    // A barcode return always pays cash, so it always has to clear the guard the
    // till's own returns page applies.
    $guard = cashRefundGuard($saleOwnerId, $saleStoreId ?: null, 'cash', $totalAmount);
    if (!$guard['ok']) {
        throw new Exception($guard['message']);
    }

    // Create return record
    $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, user_id, total_amount, transaction_type, exchange_amount, extra_amount_received, refund_amount, refund_method, reason, owner_id) VALUES (?, ?, ?, ?, 'return', 0, 0, ?, 'cash', ?, ?)");
    $stmt->execute([$returnNumber, $saleId, $currentUser['id'], $totalAmount, $totalAmount, 'Barcode return: ' . $barcode, $saleOwnerId]);
    $returnId = $db->lastInsertId();

    // Insert return item and update stock (+)
    $stmt = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$returnId, $scannedItem['product_id'], $scannedItem['product_name'], $returnQty, $unitPrice, $totalAmount]);

    if ($saleStoreId > 0) {
        $stmtCheck = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
        $stmtCheck->execute([$saleStoreId, $scannedItem['product_id']]);
        if (!$stmtCheck->fetch()) {
            $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                ->execute([$saleStoreId, $scannedItem['product_id'], 0]);
        }
        $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?")
            ->execute([$returnQty, $saleStoreId, $scannedItem['product_id']]);
    }

    $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'return', ?, ?, ?)");
    $stmt->execute([$scannedItem['product_id'], $returnQty, $returnId, "Return: {$returnNumber}", $currentUser['id']]);

    // Update original sale record
    $stmt = $db->prepare("UPDATE sales SET subtotal = subtotal - ?, total = total - ?, paid_amount = paid_amount - ? WHERE id = ?");
    $stmt->execute([$totalAmount, $totalAmount, $totalAmount, $saleId]);

    // ── Cashbook ──
    // The shared helper books the refund once, under the shop's Product Refund
    // category, against the bill it came from and the method it was paid by.
    exchangeBalanceSettle($returnId, -$totalAmount, $returnNumber, [
        'owner_id'       => $saleOwnerId,
        'store_id'       => $saleStoreId ?: null,
        'user_id'        => $currentUser['id'],
        'payment_method' => 'cash',
        'invoice_no'     => $sale['invoice_number'],
    ]);

    $db->commit();

    // Store receipt data in session
    $_SESSION['return_receipt'] = [
        'return_number' => $returnNumber,
        'sale_invoice' => $sale['invoice_number'],
        'return_items' => [[
            'product_id' => $scannedItem['product_id'],
            'product_name' => $scannedItem['product_name'],
            'quantity' => $returnQty,
            'unit_price' => $unitPrice,
            'total_price' => $totalAmount
        ]],
        'exchange_items' => [],
        'return_total' => (float) $totalAmount,
        'exchange_total' => 0.0,
        'balance' => -(float) $totalAmount,
        'is_exchange' => false
    ];
    setFlash('success', "Return #{$returnNumber} processed successfully! Stock updated.");

    echo json_encode([
        'success' => true,
        'message' => "Return #{$returnNumber} processed successfully! Stock updated.",
        'return_number' => $returnNumber,
        'product_name' => $scannedItem['product_name'],
        'quantity' => $returnQty,
        'total' => $totalAmount,
        'sale_invoice' => $sale['invoice_number']
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error processing return: ' . $e->getMessage()]);
}
?>