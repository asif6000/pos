<?php
/**
 * Mobile API - Process a return, optionally with an exchange
 *
 * Mirrors the web returns handler (admin|cashier/returns.php) so a return
 * created from the app is indistinguishable from one created on the web:
 * same tables, same stock movements, same cashbook entries, same sale
 * adjustment.
 *
 * One deliberate difference: the web handler validates a return quantity
 * against the ORIGINAL sale line quantity, so the same units can be returned
 * twice. Here the check is against what is still outstanding, which is what
 * get-sale-for-return.php reports to the UI.
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth();
$data     = json_decode(file_get_contents('php://input'), true) ?: [];

$owner_id       = $auth['owner_id'];
$user_id        = $auth['user_id'];
$sale_id        = (int)($data['sale_id'] ?? 0);
$returnItems    = $data['items'] ?? [];            // [sale_item_id => qty]
$exchangeItems  = $data['exchange_items'] ?? [];    // [{product_id, qty}]
$refundMethod   = trim($data['refund_method'] ?? 'cash');
$reason         = trim($data['reason'] ?? '');

const ALLOWED_REFUND_METHODS = ['cash','bkash','nagad','rocket','card','bank','store_credit'];

if (!$sale_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!in_array($refundMethod, ALLOWED_REFUND_METHODS, true)) {
    $refundMethod = 'cash';
}
if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

if (empty($returnItems) || !is_array($returnItems)) {
    echo json_encode(['success' => false, 'message' => 'Select at least one item to return']);
    exit;
}

try {
    // DDL must not run inside this transaction - an ALTER would commit it early.
    ensureCashbookSourceColumns();
    ensureCashbookDetailColumns();
    ensureReturnExchangeColumns();

    $db->beginTransaction();

    // The sale must belong to this owner. FOR UPDATE holds the row so two submits
    // of the same invoice cannot both pass the remaining-value check below.
    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total AS sale_total, " . saleStoreExpr('s', 'u') . " AS sale_store_id
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.owner_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$sale_id, $owner_id]);
    $sale = $stmt->fetch();
    if (!$sale) {
        throw new Exception('Sale not found');
    }

    // Unique return number. returns.return_number is uniquely indexed, so check
    // before inserting rather than letting the insert blow up.
    $returnNumber = null;
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $candidate = 'RET-' . date('Ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $chk = $db->prepare("SELECT id FROM returns WHERE return_number = ? LIMIT 1");
        $chk->execute([$candidate]);
        if (!$chk->fetchColumn()) { $returnNumber = $candidate; break; }
    }
    if ($returnNumber === null) {
        throw new Exception('Could not generate a return number, please try again');
    }

    // ── Validate and price the return lines ──
    $totalAmount = 0.0;
    $returnRows  = [];

    foreach ($returnItems as $saleItemId => $qty) {
        $qty = (int)$qty;
        if ($qty <= 0) continue;

        $stmt = $db->prepare("SELECT * FROM sale_items WHERE id = ? AND sale_id = ?");
        $stmt->execute([(int)$saleItemId, $sale_id]);
        $line = $stmt->fetch();
        if (!$line) continue;

        // How much of this line has already been returned on earlier returns.
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(ri.quantity), 0)
            FROM return_items ri
            JOIN returns r ON r.id = ri.return_id
            WHERE r.sale_id = ? AND ri.product_id = ? AND ri.product_name NOT LIKE '[EXCHANGE]%'
        ");
        $stmt->execute([$sale_id, $line['product_id']]);
        $already = (int)$stmt->fetchColumn();

        $remaining = (int)$line['quantity'] - $already;
        if ($remaining <= 0) {
            throw new Exception('"' . $line['product_name'] . '" has already been fully returned');
        }
        if ($qty > $remaining) {
            throw new Exception('Cannot return ' . $qty . ' × "' . $line['product_name'] . '" - only ' . $remaining . ' left');
        }

        $itemTotal = round($qty * $line['unit_price'], 2);
        $totalAmount += $itemTotal;

        $returnRows[] = [
            'product_id'   => (int)$line['product_id'],
            'product_name' => $line['product_name'],
            'quantity'     => $qty,
            'unit_price'   => $line['unit_price'],
            'total_price'  => $itemTotal,
        ];
    }

    if (empty($returnRows)) {
        throw new Exception('No valid items to return');
    }
    $totalAmount = round($totalAmount, 2);

    // ── Validate and price the exchange lines ──
    // Prices always come from the products table, never from the client.
    $exchangeTotal = 0.0;
    $exchangeRows  = [];

    foreach ($exchangeItems as $ex) {
        $productId = (int)($ex['product_id'] ?? 0);
        $qty       = (int)($ex['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) continue;

        $stmt = $db->prepare("SELECT id, name, sell_price FROM products WHERE id = ? AND owner_id = ? AND status = 'active'");
        $stmt->execute([$productId, $owner_id]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new Exception('Product #' . $productId . ' is unavailable');
        }

        $itemTotal = round($qty * $product['sell_price'], 2);
        $exchangeTotal += $itemTotal;

        $exchangeRows[] = [
            'product_id'   => (int)$product['id'],
            'product_name' => $product['name'],
            'quantity'     => $qty,
            'unit_price'   => $product['sell_price'],
            'total_price'  => $itemTotal,
        ];
    }
    $exchangeTotal = round($exchangeTotal, 2);

    // Positive = customer pays extra, negative = customer gets money back.
    $exchangeBalance = round($exchangeTotal - $totalAmount, 2);
    $extraReceived = $exchangeBalance > 0 ? $exchangeBalance : 0.0;
    $refundPaid    = $exchangeBalance < 0 ? -$exchangeBalance : 0.0;

    // A refund can never outrun the invoice it came from - sales.total is already
    // reduced by every earlier return, so this blocks a replayed submit too.
    if ($totalAmount > (float)$sale['sale_total'] + 0.005) {
        throw new Exception('Return value is more than the remaining sale amount');
    }

    // Stock is restored in the store the sale came from, same fallback chain
    // as the web handler. Resolved before the money is booked, because the drawer a
    // refund comes out of is that shop's, not the cashier's.
    $saleStoreId = (int)($sale['sale_store_id'] ?? 0);
    if ($saleStoreId <= 0) {
        $saleStoreId = (int)($auth['store_id'] ?? 0);
    }
    if ($saleStoreId <= 0) {
        $fb = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
        $fb->execute([$owner_id]);
        $saleStoreId = (int)($fb->fetchColumn() ?: 0);
    }

    // Cash only leaves a till that can spare it; the same rule the web pages apply,
    // so a refund cannot be talked through the app that the till would refuse.
    if ($refundPaid > 0.009) {
        $guard = cashRefundGuard($owner_id, $saleStoreId ?: null, $refundMethod, $refundPaid);
        if (!$guard['ok']) {
            throw new Exception($guard['message']);
        }
    }

    // ── Create the return ──
    $isExchange = !empty($exchangeItems);
    $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, user_id, total_amount, transaction_type, exchange_amount, extra_amount_received, refund_amount, refund_method, reason, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $returnNumber, $sale_id, $user_id, $totalAmount,
        $isExchange ? 'exchange' : 'return',
        $exchangeTotal, $extraReceived, $refundPaid,
        $refundMethod, $reason !== '' ? $reason : null, $owner_id
    ]);
    $returnId = (int)$db->lastInsertId();

    $ensureStockRow = $db->prepare("SELECT id FROM store_stocks WHERE store_id = ? AND product_id = ? LIMIT 1");
    $insertStockRow = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
    $addStock       = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
    $subStock       = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
    $logHistory     = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    $insertRetItem  = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");

    // Returned goods go back into stock.
    foreach ($returnRows as $item) {
        $insertRetItem->execute([$returnId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);

        if ($saleStoreId > 0) {
            $ensureStockRow->execute([$saleStoreId, $item['product_id']]);
            if (!$ensureStockRow->fetchColumn()) {
                $insertStockRow->execute([$saleStoreId, $item['product_id']]);
            }
            $addStock->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
        }

        $logHistory->execute([$item['product_id'], $item['quantity'], 'return', $returnId, 'Return: ' . $returnNumber, $user_id]);
    }

    // Exchange goods leave stock. Marked with an [EXCHANGE] prefix on the name,
    // exactly as the web does, so there is no separate exchange table.
    foreach ($exchangeRows as $item) {
        $insertRetItem->execute([$returnId, $item['product_id'], '[EXCHANGE] ' . $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);

        if ($saleStoreId > 0) {
            $ensureStockRow->execute([$saleStoreId, $item['product_id']]);
            if (!$ensureStockRow->fetchColumn()) {
                $insertStockRow->execute([$saleStoreId, $item['product_id']]);
            }
            $subStock->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
        }

        $logHistory->execute([$item['product_id'], -$item['quantity'], 'adjustment', $returnId, 'Exchange deduction: ' . $returnNumber, $user_id]);
    }

    // Take the returned value off the original sale.
    $stmt = $db->prepare("UPDATE sales SET subtotal = subtotal - ?, total = total - ?, paid_amount = paid_amount - ? WHERE id = ?");
    $stmt->execute([$totalAmount, $totalAmount, $totalAmount, $sale_id]);

    // ── Cashbook ──
    // The shared helper writes the one linked movement per return. It takes the
    // scope as an argument because the mobile API has no session for it to read,
    // and the till this moves is the SALE's store, not the cashier's.
    exchangeBalanceSettle($returnId, $exchangeBalance, $returnNumber, [
        'owner_id'       => $owner_id,
        'store_id'       => $saleStoreId ?: null,
        'user_id'        => $user_id,
        'payment_method' => $refundMethod,
        'invoice_no'     => $sale['invoice_number'],
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Return processed successfully',
        'return' => [
            'id'             => $returnId,
            'return_number'  => $returnNumber,
            'sale_id'        => $sale_id,
            'invoice_number' => $sale['invoice_number'],
            'return_total'   => $totalAmount,
            'exchange_total' => $exchangeTotal,
            'balance'        => $exchangeBalance,
            'refund_amount'  => $refundPaid,
            'extra_paid'     => $extraReceived,
            'refund_method'  => $refundMethod,
            'reason'         => $reason,
            'return_items'   => $returnRows,
            'exchange_items' => $exchangeRows,
        ],
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}