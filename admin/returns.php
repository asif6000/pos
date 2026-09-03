<?php
/**
 * POS System - Returns Management
 * Handle product returns with automatic stock adjustment
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Returns');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

$db = getDB();
$user = getCurrentUser();

// Load settings for receipt
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$user['owner_id']]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle return submission (with exchange support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return') {
    $saleId = (int) ($_POST['sale_id'] ?? 0);
    $items = $_POST['return_items'] ?? [];
    $exchangeProducts = isset($_POST['exchange_products']) ? json_decode($_POST['exchange_products'], true) : [];
    $refundMethod = sanitize($_POST['refund_method'] ?? 'cash');
    $reason = sanitize($_POST['reason'] ?? '');

    $isExchange = !empty($exchangeProducts);

    if ($saleId && !empty($items)) {
        try {
            $db->beginTransaction();

            // Generate return number
            $returnNumber = 'RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Calculate total return amount
            $totalAmount = 0;
            $returnItemsData = [];

            foreach ($items as $itemId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0)
                    continue;

                // Get sale item details
                $stmt = $db->prepare("SELECT * FROM sale_items WHERE id = ? AND sale_id = ?");
                $stmt->execute([$itemId, $saleId]);
                $saleItem = $stmt->fetch();

                if ($saleItem && $qty <= $saleItem['quantity']) {
                    $itemTotal = $qty * $saleItem['unit_price'];
                    $totalAmount += $itemTotal;

                    $returnItemsData[] = [
                        'product_id' => $saleItem['product_id'],
                        'product_name' => $saleItem['product_name'],
                        'quantity' => $qty,
                        'unit_price' => $saleItem['unit_price'],
                        'total_price' => $itemTotal
                    ];
                }
            }

            if (empty($returnItemsData)) {
                throw new Exception('No valid items to return');
            }

            // Process exchange items (if any)
            $exchangeTotal = 0;
            $exchangeItemsData = [];

            if ($isExchange) {
                foreach ($exchangeProducts as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $qty = (int) ($item['quantity'] ?? 0);
                    if ($productId <= 0 || $qty <= 0)
                        continue;

                    $stmt = $db->prepare("SELECT id, name, sell_price FROM products WHERE id = ? AND status = 'active'");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        throw new Exception("Product ID {$productId} not found");
                    }

                    $itemTotal = $qty * $product['sell_price'];
                    $exchangeTotal += $itemTotal;

                    $exchangeItemsData[] = [
                        'product_id' => $product['id'],
                        'product_name' => $product['name'],
                        'quantity' => $qty,
                        'unit_price' => $product['sell_price'],
                        'total_price' => $itemTotal
                    ];
                }
            }

            // Calculate exchange balance (only when exchange items exist)
            // positive = customer pays extra, negative = customer gets refund, 0 = pure return
            $exchangeBalance = $isExchange ? ($exchangeTotal - $totalAmount) : 0;

            // Determine the actual payment/refund method
            $finalRefundMethod = $refundMethod;

            // Create return record
            $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, user_id, total_amount, refund_method, reason, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$returnNumber, $saleId, $user['id'], $totalAmount, $finalRefundMethod, $reason, $user['owner_id']]);
            $returnId = $db->lastInsertId();

            // Get sale store_id via user for store_stocks update (moved outside loop)
            $stmtSale = $db->prepare("SELECT s.user_id, u.store_id FROM sales s JOIN users u ON s.user_id = u.id WHERE s.id = ?");
            $stmtSale->execute([$saleId]);
            $saleData = $stmtSale->fetch();
            $saleStoreId = $saleData['store_id'] ?? 0;

            // Fallback: use current user's store or first active store
            if (!$saleStoreId) {
                $stmtCur = $db->prepare("SELECT store_id FROM users WHERE id = ?");
                $stmtCur->execute([$user['id']]);
                $saleStoreId = $stmtCur->fetchColumn();
            }
            if (!$saleStoreId) {
                $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
                $stmtFallback->execute([$user['owner_id']]);
                $saleStoreId = $stmtFallback->fetchColumn() ?: 0;
            }

            // Insert return items and update stock (+)
            foreach ($returnItemsData as $item) {
                $stmt = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$returnId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);

                if ($saleStoreId > 0) {
                    $stmtCheck = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
                    $stmtCheck->execute([$saleStoreId, $item['product_id']]);
                    if (!$stmtCheck->fetch()) {
                        $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                            ->execute([$saleStoreId, $item['product_id'], 0]);
                    }
                    $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?")
                        ->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
                }

                $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'return', ?, ?, ?)");
                $stmt->execute([$item['product_id'], $item['quantity'], $returnId, "Return: {$returnNumber}", $user['id']]);
            }

            // Insert exchange items into return_items with [EXCHANGE] prefix (no separate table needed)
            if (!empty($exchangeItemsData)) {
                foreach ($exchangeItemsData as $item) {
                    $exchangeName = '[EXCHANGE] ' . $item['product_name'];
                    $stmt = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$returnId, $item['product_id'], $exchangeName, $item['quantity'], $item['unit_price'], $item['total_price']]);

                    if ($saleStoreId > 0) {
                        $stmtCheck = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
                        $stmtCheck->execute([$saleStoreId, $item['product_id']]);
                        if (!$stmtCheck->fetch()) {
                            $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                                ->execute([$saleStoreId, $item['product_id'], 0]);
                        }
                        $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?")
                            ->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
                    }

                    $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'adjustment', ?, ?, ?)");
                    $stmt->execute([$item['product_id'], -$item['quantity'], $returnId, "Exchange deduction: {$returnNumber}", $user['id']]);
                }
            }

            // Update original sale record (deduct returned amount)
            $stmt = $db->prepare("UPDATE sales SET subtotal = subtotal - ?, total = total - ?, paid_amount = paid_amount - ? WHERE id = ?");
            $stmt->execute([$totalAmount, $totalAmount, $totalAmount, $saleId]);

            // Auto Cashbook entries for return/exchange net cash flow
            // refund amount = money returned to customer (only when exchange value < returned value)
            $refundAmount = (float) max(0, -$exchangeBalance);
            // extra paid = customer pays extra when exchange value > returned value
            $extraPaid = (float) max(0, $exchangeBalance);

            if ($refundAmount > 0) {
                addAutoCashbookEntry('cash_out', $refundAmount, 'Return ' . $returnNumber . ' (refund)', 'return', $returnId);
            }
            if ($extraPaid > 0) {
                addAutoCashbookEntry('cash_in', $extraPaid, 'Return ' . $returnNumber . ' (exchange extra)', 'return', $returnId);
            }

            $db->commit();

            // Store receipt data in session for display after redirect
            $stmtInv = $db->prepare("SELECT invoice_number FROM sales WHERE id = ?");
            $stmtInv->execute([$saleId]);
            $saleInvoiceNumber = $stmtInv->fetchColumn();

            startSecureSession();
            $_SESSION['return_receipt'] = [
                'return_number' => $returnNumber,
                'sale_invoice' => $saleInvoiceNumber ?: 'N/A',
                'return_items' => $returnItemsData,
                'exchange_items' => $exchangeItemsData,
                'return_total' => (float) $totalAmount,
                'exchange_total' => (float) $exchangeTotal,
                'balance' => (float) $exchangeBalance,
                'is_exchange' => $isExchange
            ];
            setFlash('success', "Return #{$returnNumber} processed successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Error processing return: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Please select items to return.');
    }
    redirect('returns.php');
}

// Get returns list - Filter by owner
$sql = "
    SELECT r.*, s.invoice_number, u.name as processed_by,
    (SELECT COUNT(*) FROM return_items WHERE return_id = r.id) as item_count,
    (SELECT COUNT(*) FROM return_items WHERE return_id = r.id AND product_name LIKE '[EXCHANGE] %') as exchange_count
    FROM returns r
    JOIN sales s ON r.sale_id = s.id
    JOIN users u ON r.user_id = u.id
    WHERE u.owner_id = ?
    ORDER BY r.created_at DESC
    LIMIT 100
";
$stmt = $db->prepare($sql);
$stmt->execute([$user['owner_id']]);
$returns = $stmt->fetchAll();

// Check for return receipt data to show print modal
$returnReceipt = null;
if (isset($_SESSION['return_receipt'])) {
    $returnReceipt = $_SESSION['return_receipt'];
    unset($_SESSION['return_receipt']);
}

include 'includes/header.php';
?>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <p class="text-muted">Process product returns, exchanges and track refunds</p>
    <button class="btn btn-primary" onclick="openReturnModal()">
        <i class="fas fa-undo"></i> New Return / Exchange
    </button>
</div>

<?php
// Load products for exchange feature (all active products, stock not needed for dropdown)
$stmtProds = $db->prepare("SELECT p.id, p.name, p.sell_price FROM products p WHERE p.status = 'active' AND p.owner_id = ? ORDER BY p.name");
$stmtProds->execute([$user['owner_id']]);
$exchangeProducts = $stmtProds->fetchAll();
?>

<!-- Returns Summary - Filter by owner -->
<?php
$stmtToday = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(r.total_amount), 0) as total FROM returns r JOIN users u ON r.user_id = u.id WHERE u.owner_id = ? AND DATE(r.created_at) = CURDATE()");
$stmtToday->execute([$user['owner_id']]);
$todayReturns = $stmtToday->fetch();

$stmtMonth = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(r.total_amount), 0) as total FROM returns r JOIN users u ON r.user_id = u.id WHERE u.owner_id = ? AND MONTH(r.created_at) = MONTH(CURDATE()) AND YEAR(r.created_at) = YEAR(CURDATE())");
$stmtMonth->execute([$user['owner_id']]);
$monthReturns = $stmtMonth->fetch();
?>

<div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-undo"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Today's Returns</div>
            <div class="stat-value">
                <?php echo formatCurrency($todayReturns['total']); ?>
            </div>
            <small class="text-muted">
                <?php echo $todayReturns['count']; ?> returns
            </small>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">This Month</div>
            <div class="stat-value">
                <?php echo formatCurrency($monthReturns['total']); ?>
            </div>
            <small class="text-muted">
                <?php echo $monthReturns['count']; ?> returns
            </small>
        </div>
    </div>
</div>

<!-- Returns List -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list"></i> Recent Returns</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Return #</th>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Refund Method</th>
                    <th>Processed By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted">No returns found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($returns as $return): ?>
                        <tr>
                            <td><strong>
                                    <?php echo sanitize($return['return_number']); ?>
                                </strong></td>
                            <td>
                                <a href="sales.php?search=<?php echo urlencode($return['invoice_number']); ?>">
                                    <?php echo sanitize($return['invoice_number']); ?>
                                </a>
                            </td>
                            <td>
                                <?php echo date('d M Y, h:i A', strtotime($return['created_at'])); ?>
                            </td>
                            <td>
                                <?php echo $return['item_count']; ?> items
                                <?php if ($return['exchange_count'] > 0): ?>
                                    <br><small class="text-success">+<?php echo $return['exchange_count']; ?> exchange</small>
                                <?php endif; ?>
                            </td>
                            <td><strong class="text-danger">
                                    <?php echo formatCurrency($return['total_amount']); ?>
                                </strong></td>
                            <td>
                                <?php if ($return['exchange_count'] > 0): ?>
                                    <span class="badge badge-warning">Exchange</span>
                                <?php else: ?>
                                    <span class="badge badge-info">Return</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary">
                                    <?php echo ucfirst($return['refund_method']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo sanitize($return['processed_by']); ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="viewReturn(<?php echo $return['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Return Modal -->
<div class="modal-overlay" id="returnModal">
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo"></i> Process Return</h3>
            <button class="modal-close" onclick="closeReturnModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Step 1: Search Invoice -->
            <div id="step1">
                <div class="form-group">
                    <label class="form-label required">Invoice Number</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="invoiceSearch" class="form-control"
                            placeholder="Enter invoice number (e.g., INV-20260129-0001)">
                        <button class="btn btn-primary" onclick="searchInvoice()">
                            <i class="fas fa-search"></i> Find
                        </button>
                    </div>
                </div>
                <div id="invoiceResult"></div>
            </div>

            <!-- Step 2: Select Items + Exchange (hidden initially) -->
            <div id="step2" style="display: none;">
                <form method="POST" id="returnForm">
                    <input type="hidden" name="action" value="process_return">
                    <input type="hidden" name="sale_id" id="returnSaleId">
                    <input type="hidden" name="exchange_products" id="exchangeProductsInput">

                    <div class="alert alert-info" style="margin-bottom: 1rem;">
                        <i class="fas fa-info-circle"></i>
                        Invoice: <strong id="selectedInvoice"></strong>
                        <button type="button" class="btn btn-sm btn-outline" onclick="resetReturn()"
                            style="margin-left: 1rem;">
                            <i class="fas fa-times"></i> Change
                        </button>
                    </div>

                    <!-- Return Items Section -->
                    <h4 style="margin-bottom: 0.5rem;">
                        <i class="fas fa-undo"></i> Items to Return
                    </h4>
                    <div id="returnItemsList"></div>

                    <!-- Exchange Section -->
                    <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px solid var(--gray-200);">
                        <h4 style="margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-exchange-alt"></i> Exchange Items
                            <span
                                style="font-size: 0.8rem; font-weight: normal; color: var(--gray-500);">(optional)</span>
                        </h4>

                        <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem;">
                            <input type="text" id="exchangeSearch" class="form-control"
                                placeholder="Search product to exchange..." style="flex: 1;">
                            <select id="exchangeProductSelect" class="form-control" style="flex: 2;">
                                <option value="">-- Select product --</option>
                                <?php foreach ($exchangeProducts as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['sell_price']; ?>"
                                        data-name="<?php echo sanitize($p['name']); ?>">
                                        <?php echo sanitize($p['name']); ?> — <?php echo CURRENCY; ?>
                                        <?php echo number_format($p['sell_price'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" id="exchangeQty" class="form-control" value="1" min="1"
                                style="width: 70px;">
                            <button type="button" class="btn btn-primary" onclick="addExchangeItem()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>

                        <div id="exchangeItemsList">
                            <p class="text-muted" style="font-size: 0.85rem; font-style: italic;">
                                No exchange items added yet.
                            </p>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div
                        style="margin-top: 1rem; padding: 1rem; background: var(--gray-100); border-radius: var(--border-radius);">
                        <div class="pos-cart-row">
                            <span>Return Total</span>
                            <span id="totalRefund" class="text-danger"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                        <div class="pos-cart-row">
                            <span>Exchange Total</span>
                            <span id="totalExchange" class="text-success"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                        <div style="text-align: center; font-size: 0.75rem; color: var(--gray-400); padding: 2px 0;">
                            Exchange − Return = <span id="balanceFormula">0.00</span>
                        </div>
                        <div class="pos-cart-row total"
                            style="font-size: 1.1rem; border-top: 1px solid var(--gray-300); padding-top: 6px;">
                            <span id="balanceLabel">Balance</span>
                            <span id="totalBalance"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                    </div>

                    <!-- Payment/Refund Method -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                        <div class="form-group">
                            <label class="form-label required">Refund Method</label>
                            <select name="refund_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bkash">bKash</option>
                                <option value="nagad">Nagad</option>
                                <option value="store_credit">Store Credit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reason for Return</label>
                            <input type="text" name="reason" class="form-control"
                                placeholder="e.g., Defective, Wrong item">
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
            <button type="button" class="btn btn-danger" id="submitReturnBtn" onclick="submitReturn()"
                style="display: none;">
                <i class="fas fa-exchange-alt"></i> Process Return / Exchange
            </button>
        </div>
    </div>
</div>

<!-- View Return Modal -->
<div class="modal-overlay" id="viewReturnModal">
    <div class="modal" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">Return / Exchange Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="returnDetails">
            <div class="text-center">
                <div class="spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Barcode Return Confirm Modal -->
<div class="modal-overlay" id="barcodeReturnModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo"></i> Product Return</h3>
            <button class="modal-close" onclick="closeBarcodeReturnModal()">&times;</button>
        </div>
        <div class="modal-body" id="barcodeReturnContent"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeBarcodeReturnModal()">Cancel</button>
            <button class="btn btn-danger" id="barcodeReturnBtn" onclick="confirmBarcodeReturn()">
                <i class="fas fa-undo"></i> Return Now
            </button>
        </div>
    </div>
</div>

<!-- Receipt Print Modal -->
<?php if ($returnReceipt): ?>
    <div class="modal-overlay active" id="receiptModal">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-receipt"></i>
                    <?php echo $returnReceipt['is_exchange'] ? 'Exchange Receipt' : 'Return Receipt'; ?>
                </h3>
                <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="printableReceipt" style="font-family: 'Hind Siliguri', monospace; font-size: 12px;">
                    <div style="text-align: center; margin-bottom: 1rem;">
                        <h3 style="margin: 0;"><?php echo sanitize($settings['shop_name'] ?? 'POS System'); ?></h3>
                        <p style="margin: 0.25rem 0; font-size: 10px;">
                            <?php echo sanitize($settings['shop_address'] ?? ''); ?></p>
                        <svg id="returnReceiptBarcode"
                            data-barcode="<?php echo sanitize($returnReceipt['sale_invoice']); ?>"
                            style="height: 50px; margin-top: 8px;"></svg>
                    </div>
                    <hr style="border-style: dashed;">
                    <p><strong>Return #:</strong> <?php echo $returnReceipt['return_number']; ?></p>
                    <p><strong>Invoice #:</strong> <?php echo $returnReceipt['sale_invoice']; ?></p>
                    <p><strong>Date:</strong> <?php echo date('d M Y, h:i A'); ?></p>
                    <hr style="border-style: dashed;">

                    <h4 style="margin: 0.5rem 0;">Returned Items</h4>
                    <table style="width: 100%; font-size: 11px;">
                        <thead>
                            <tr>
                                <th style="text-align: left;">Item</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returnReceipt['return_items'] as $ri): ?>
                                <tr>
                                    <td><?php echo sanitize($ri['product_name']); ?></td>
                                    <td style="text-align: center;"><?php echo $ri['quantity']; ?></td>
                                    <td style="text-align: right;"><?php echo formatCurrency($ri['total_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($returnReceipt['exchange_items'])): ?>
                        <hr style="border-style: dashed;">
                        <h4 style="margin: 0.5rem 0; color: #059669;">Exchange Items</h4>
                        <table style="width: 100%; font-size: 11px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Item</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnReceipt['exchange_items'] as $ei): ?>
                                    <tr>
                                        <td><?php echo sanitize($ei['product_name']); ?></td>
                                        <td style="text-align: center;"><?php echo $ei['quantity']; ?></td>
                                        <td style="text-align: right;"><?php echo formatCurrency($ei['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <hr style="border-style: dashed;">
                    <table style="width: 100%; font-size: 11px;">
                        <tr>
                            <td>Subtotal (Return)</td>
                            <td style="text-align: right;"><?php echo formatCurrency($returnReceipt['return_total']); ?>
                            </td>
                        </tr>
                        <?php if (!empty($returnReceipt['exchange_items'])): ?>
                            <tr>
                                <td>Subtotal (Exchange)</td>
                                <td style="text-align: right;"><?php echo formatCurrency($returnReceipt['exchange_total']); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="text-align: center; font-size: 10px; padding: 4px 0;">
                                    ───────────────────────────────────────
                                </td>
                            </tr>
                            <tr style="font-weight: bold;">
                                <td>
                                    Exchange − Return =
                                    <?php
                                    $bal = $returnReceipt['balance'];
                                    if ($bal > 0) {
                                        echo '<span class="text-danger">(Customer pays extra)</span>';
                                    } elseif ($bal < 0) {
                                        echo '<span class="text-success">(Customer gets refund)</span>';
                                    } else {
                                        echo '<span>(No balance)</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($bal > 0): ?>
                                        <span class="text-danger">+ <?php echo formatCurrency($bal); ?></span>
                                    <?php elseif ($bal < 0): ?>
                                        <span class="text-success">− <?php echo formatCurrency(abs($bal)); ?></span>
                                    <?php else: ?>
                                        <span>৳ 0.00</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <hr style="border-style: dashed;">
                    <p style="text-align: center; font-size: 10px;">
                        <?php echo sanitize($settings['receipt_footer'] ?? 'Thank you!'); ?></p>
                    <?php if (!empty($settings['voucher_terms'])): ?>
                        <div style="margin-top: 8px; border-top: 1px dashed #000; padding-top: 6px;">
                            <p style="margin: 0 0 2px 0; font-size: 10px; font-weight: bold;">Terms & Conditions</p>
                            <p style="margin: 0; font-size: 9px; white-space: pre-line;">
                                <?php echo sanitize($settings['voucher_terms']); ?></p>
                        </div>
                    <?php endif; ?>
                    <div style="margin-top: 8px; border-top: 1px dashed #000; padding-top: 6px; font-size: 9px;">
                        <p style="margin: 0 0 2px 0; font-size: 10px; font-weight: bold;">পণ্য পরিবর্তন নীতি</p>
                        <p style="margin: 0; white-space: pre-line;">ক্রয়ের তারিখ থেকে ৭ দিনের মধ্যে পণ্য পরিবর্তন করা যাবে।\nপণ্যটি অবশ্যই অব্যবহৃত, অরিজিনাল এবং রসিদসহ হতে হবে।\nকোনো নগদ টাকা ফেরত দেওয়া হবে না।\nপণ্য পরিবর্তন পণ্যের প্রাপ্যতা ও দোকানের নীতিমালার ওপর নির্ভরশীল।</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeReceiptModal()">Close</button>
                <button class="btn btn-primary" onclick="printReceipt()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="<?php echo $baseUrl; ?>/assets/js/jsbarcode.min.js"></script>
<script>
    const currency = '<?php echo CURRENCY; ?>';
    let exchangeItems = [];
    let pendingBarcodeReturn = null;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const barcode = document.getElementById('returnReceiptBarcode');
        if (barcode && typeof JsBarcode !== 'undefined' && barcode.dataset.barcode) {
            try {
                JsBarcode(barcode, barcode.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                });
            } catch (e) { }
        }
    });

    function openReturnModal() {
        document.getElementById('returnModal').classList.add('active');
        document.getElementById('invoiceSearch').focus();
    }

    function closeReturnModal() {
        document.getElementById('returnModal').classList.remove('active');
        resetReturn();
    }

    function resetReturn() {
        document.getElementById('step1').style.display = 'block';
        document.getElementById('step2').style.display = 'none';
        document.getElementById('submitReturnBtn').style.display = 'none';
        document.getElementById('invoiceSearch').value = '';
        document.getElementById('invoiceResult').innerHTML = '';
        exchangeItems = [];
        updateExchangeDisplay();
        document.querySelectorAll('.return-qty').forEach(el => el.value = 0);
    }

    async function searchInvoice() {
        const invoice = document.getElementById('invoiceSearch').value.trim();
        if (!invoice) {
            alert('Please enter an invoice number');
            return;
        }

        document.getElementById('invoiceResult').innerHTML = '<div class="text-center"><div class="spinner"></div></div>';

        try {
            const response = await fetch(`api/get-sale-for-return.php?invoice=${encodeURIComponent(invoice)}`);
            const data = await response.json();

            if (data.success) {
                showReturnItems(data.sale);
            } else {
                document.getElementById('invoiceResult').innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${data.message}</div>`;
            }
        } catch (error) {
            document.getElementById('invoiceResult').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error searching invoice</div>';
        }
    }

    function showReturnItems(sale, prefilledItemId) {
        document.getElementById('step1').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
        document.getElementById('submitReturnBtn').style.display = 'inline-flex';
        document.getElementById('returnSaleId').value = sale.id;
        document.getElementById('selectedInvoice').textContent = sale.invoice_number;

        let html = '<table class="table"><thead><tr><th>Product</th><th>Sold Qty</th><th>Return Qty</th><th>Amount</th></tr></thead><tbody>';

        sale.items.forEach(item => {
            const isScanned = prefilledItemId && (parseInt(item.id) === parseInt(prefilledItemId));
            const defaultValue = item.quantity;
            html += `
            <tr>
                <td>${item.product_name}${isScanned ? ' <span class="badge badge-success">Scanned</span>' : ''}</td>
                <td>${item.quantity}</td>
                <td>
                    <input type="number" name="return_items[${item.id}]"
                           class="form-control return-qty"
                           value="${defaultValue}" min="0" max="${item.quantity}"
                           data-price="${item.unit_price}"
                           style="width: 80px;"
                           onchange="calculateTotals()">
                </td>
                <td class="item-refund">${currency} 0.00</td>
            </tr>
        `;
        });

        html += '</tbody></table>';
        document.getElementById('returnItemsList').innerHTML = html;
        exchangeItems = [];
        updateExchangeDisplay();
        calculateTotals();
    }

    // Exchange Functions
    function addExchangeItem() {
        const select = document.getElementById('exchangeProductSelect');
        const qtyInput = document.getElementById('exchangeQty');

        const productId = parseInt(select.value);
        if (!productId) { alert('Please select a product'); return; }

        const qty = parseInt(qtyInput.value) || 1;
        if (qty < 1) { alert('Invalid quantity'); return; }

        const option = select.options[select.selectedIndex];
        if (!option) { alert('Invalid selection'); return; }

        const name = option.dataset.name || '';
        const price = parseFloat(option.dataset.price) || 0;

        if (price < 0 || isNaN(price)) { alert('Invalid product price'); return; }

        // Check if already added
        const existing = exchangeItems.findIndex(item => item.product_id === productId);
        if (existing > -1) {
            exchangeItems[existing].quantity += qty;
            exchangeItems[existing].total_price = exchangeItems[existing].quantity * price;
        } else {
            exchangeItems.push({
                product_id: productId,
                product_name: name,
                quantity: qty,
                unit_price: price,
                total_price: qty * price
            });
        }

        updateExchangeDisplay();
        calculateTotals();
        qtyInput.value = 1;
        select.value = '';
    }

    function removeExchangeItem(index) {
        exchangeItems.splice(index, 1);
        updateExchangeDisplay();
        calculateTotals();
    }

    function updateExchangeDisplay() {
        const container = document.getElementById('exchangeItemsList');
        if (!container) return;
        if (exchangeItems.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size: 0.85rem; font-style: italic;">No exchange items added yet.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead><tbody>';
        exchangeItems.forEach((item, index) => {
            const up = isNaN(item.unit_price) ? 0 : item.unit_price;
            const tp = isNaN(item.total_price) ? 0 : item.total_price;
            html += `
            <tr>
                <td>${item.product_name || ''}</td>
                <td>${item.quantity || 0}</td>
                <td>${currency} ${up.toFixed(2)}</td>
                <td>${currency} ${tp.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeExchangeItem(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function calculateTotals() {
        let returnTotal = 0;
        document.querySelectorAll('.return-qty').forEach(input => {
            const qty = parseInt(input.value) || 0;
            const price = parseFloat(input.dataset.price) || 0;
            const itemTotal = qty * price;
            returnTotal += itemTotal;
            const el = input.closest('tr').querySelector('.item-refund');
            if (el) el.textContent = `${currency} ${itemTotal.toFixed(2)}`;
        });

        let exchangeTotal = 0;
        exchangeItems.forEach(item => {
            const tp = isNaN(item.total_price) ? 0 : item.total_price;
            exchangeTotal += tp;
        });

        const balance = exchangeTotal - returnTotal;
        const totalExchangeEl = document.getElementById('totalExchange');
        const totalRefundEl = document.getElementById('totalRefund');
        const balanceEl = document.getElementById('totalBalance');
        const formulaEl = document.getElementById('balanceFormula');
        const balanceLabel = document.getElementById('balanceLabel');

        if (totalRefundEl) totalRefundEl.textContent = `${currency} ${returnTotal.toFixed(2)}`;
        if (totalExchangeEl) totalExchangeEl.textContent = `${currency} ${exchangeTotal.toFixed(2)}`;

        if (formulaEl) {
            formulaEl.textContent = `${currency} ${exchangeTotal.toFixed(2)} - ${currency} ${returnTotal.toFixed(2)} = ${balance >= 0 ? '+' : ''}${currency} ${balance.toFixed(2)}`;
        }

        if (balance > 0) {
            if (balanceEl) {
                balanceEl.textContent = `${currency} ${balance.toFixed(2)} (Customer pays extra)`;
                balanceEl.style.color = 'var(--danger)';
            }
            if (balanceLabel) balanceLabel.textContent = 'Due from Customer';
        } else if (balance < 0) {
            if (balanceEl) {
                balanceEl.textContent = `${currency} ${Math.abs(balance).toFixed(2)} (Refund to Customer)`;
                balanceEl.style.color = 'var(--success)';
            }
            if (balanceLabel) balanceLabel.textContent = 'Customer Gets Refund';
        } else {
            if (balanceEl) {
                balanceEl.textContent = `${currency} 0.00 (No balance)`;
                balanceEl.style.color = '';
            }
            if (balanceLabel) balanceLabel.textContent = 'Balance';
        }
    }

    function submitReturn() {
        let hasItems = false;
        const qtyInputs = document.querySelectorAll('.return-qty');

        qtyInputs.forEach(input => {
            if (parseInt(input.value) > 0) hasItems = true;
        });

        // Auto-fill remaining quantities from the sale if none were entered
        if (!hasItems && qtyInputs.length > 0) {
            qtyInputs.forEach(input => {
                input.value = parseInt(input.max) || 0;
            });
            calculateTotals();
            hasItems = qtyInputs.length > 0;
        }

        if (!hasItems) {
            alert('Please select at least one item to return');
            return;
        }

        // Serialize exchange items
        document.getElementById('exchangeProductsInput').value = JSON.stringify(exchangeItems);

        const hasExchange = exchangeItems.length > 0;
        const msg = hasExchange
            ? 'Are you sure you want to process this exchange? Returned items stock will be restored and exchange items stock will be deducted.'
            : 'Are you sure you want to process this return? Stock will be automatically updated.';

        if (confirm(msg)) {
            document.getElementById('returnForm').submit();
        }
    }

    async function viewReturn(returnId) {
        document.getElementById('viewReturnModal').classList.add('active');

        try {
            const response = await fetch(`api/get-return-details.php?id=${returnId}`);
            const data = await response.json();

            if (data.success) {
                const r = data.return;
                let html = `
                <p><strong>Return #:</strong> ${r.return_number}</p>
                <p><strong>Invoice #:</strong> ${r.invoice_number}</p>
                <p><strong>Date:</strong> ${r.date}</p>
                <p><strong>Reason:</strong> ${r.reason || 'Not specified'}</p>
                <hr>
                <h4>Returned Items</h4>
                <table class="table">
                    <thead><tr><th>Product</th><th>Qty</th><th>Amount</th></tr></thead>
                    <tbody>
                        ${r.items.map(item => `
                            <tr>
                                <td>${item.product_name}</td>
                                <td>${item.quantity}</td>
                                <td>${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;

                // Show exchange items if present
                if (r.exchange_items && r.exchange_items.length > 0) {
                    html += `
                    <hr>
                    <h4 style="color: var(--success);">Exchange Items</h4>
                    <table class="table">
                        <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                        <tbody>
                            ${r.exchange_items.map(item => `
                                <tr>
                                    <td>${item.product_name}</td>
                                    <td>${item.quantity}</td>
                                    <td>${currency} ${parseFloat(item.unit_price).toFixed(2)}</td>
                                    <td>${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
                }

                html += `<hr>
                <p><strong>Total Refund:</strong> <span class="text-danger">${currency} ${parseFloat(r.total_amount).toFixed(2)}</span></p>`;

                if (r.exchange_balance && parseFloat(r.exchange_balance) !== 0) {
                    const bal = parseFloat(r.exchange_balance);
                    if (bal > 0) {
                        html += `<p><strong>Customer Pays Extra:</strong> <span class="text-danger">${currency} ${bal.toFixed(2)}</span></p>`;
                    } else {
                        html += `<p><strong>Customer Gets Refund:</strong> <span class="text-success">${currency} ${Math.abs(bal).toFixed(2)}</span></p>`;
                    }
                }

                html += `<p><strong>Refund Method:</strong> ${r.refund_method.toUpperCase()}</p>`;
                document.getElementById('returnDetails').innerHTML = html;
            }
        } catch (error) {
            document.getElementById('returnDetails').innerHTML = '<p class="text-danger">Error loading details</p>';
        }
    }

    function closeViewModal() {
        document.getElementById('viewReturnModal').classList.remove('active');
    }

    // Receipt print functions
    function closeReceiptModal() {
        const el = document.getElementById('receiptModal');
        if (el) el.classList.remove('active');
    }

    function printReceipt() {
        const content = document.getElementById('printableReceipt');
        if (!content) return;
        const win = window.open('', '_blank');
        win.document.write(`
        <html><head><title>Receipt</title>
        <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hind-siliguri.css">
        <style>
            body { font-family: 'Hind Siliguri', monospace; font-size: 12px; margin: 0; padding: 10px; }
            #returnReceiptBarcode { height: 50px; }
            hr { border-style: dashed; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 2px 0; }
        </style>
        <script src="<?php echo $baseUrl; ?>\/assets\/js\/jsbarcode.min.js"><\/script>
        </head>
        <body>${content.innerHTML}</body></html>
        `);
        win.document.close();
        win.onload = function () {
            const barcode = win.document.getElementById('returnReceiptBarcode');
            if (barcode && typeof win.JsBarcode !== 'undefined' && barcode.dataset.barcode) {
                try {
                    win.JsBarcode(barcode, barcode.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                    });
                } catch (e) { }
            }
            win.print();
            win.close();
        };
    }

    // Close receipt modal on overlay click
    const receiptModal = document.getElementById('receiptModal');
    if (receiptModal) {
        receiptModal.addEventListener('click', function (e) {
            if (e.target === this) closeReceiptModal();
        });
    }

    // Exchange product search filter
    const exchSearch = document.getElementById('exchangeSearch');
    if (exchSearch) {
        exchSearch.addEventListener('input', function () {
            const query = this.value.toLowerCase();
            const select = document.getElementById('exchangeProductSelect');
            Array.from(select.options).forEach(opt => {
                if (!opt.value) return;
                opt.style.display = opt.dataset.name.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    }

    // Close modals on overlay click
    document.getElementById('returnModal').addEventListener('click', function (e) {
        if (e.target === this) closeReturnModal();
    });
    document.getElementById('viewReturnModal').addEventListener('click', function (e) {
        if (e.target === this) closeViewModal();
    });
    document.getElementById('barcodeReturnModal').addEventListener('click', function (e) {
        if (e.target === this) closeBarcodeReturnModal();
    });

    // Handle Enter key in search
    document.getElementById('invoiceSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchInvoice();
        }
    });

    // USB Barcode Scanner Support for product barcodes
    let productBarcodeBuffer = '';
    let lastScanTime = 0;
    let isScannerTyping = false;

    document.addEventListener('keydown', function (e) {
        const returnModalOpen = document.getElementById('returnModal').classList.contains('active');
        const activeEl = document.activeElement;

        // Skip when typing in the exchange search field
        if (activeEl === document.getElementById('exchangeSearch')) return;

        // When modal is closed, only intercept scans if focus is not on a text input
        if (!returnModalOpen && activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) return;

        const now = Date.now();
        const dt = now - lastScanTime;
        lastScanTime = now;

        if (e.key === 'Enter') {
            const barcode = productBarcodeBuffer.trim();
            productBarcodeBuffer = '';
            isScannerTyping = false;

            if (barcode.length >= 3) {
                e.preventDefault();
                e.stopImmediatePropagation();

                if (barcode.toUpperCase().startsWith('INV-')) {
                    document.getElementById('invoiceSearch').value = barcode;
                    searchInvoice();
                } else {
                    lookupSaleByBarcode(barcode);
                }
            }
            return;
        }

        if (dt > 50) {
            productBarcodeBuffer = '';
            isScannerTyping = false;
        }

        if (e.key.length === 1) {
            if (dt <= 50) {
                isScannerTyping = true;
                e.preventDefault();
            }
            productBarcodeBuffer += e.key;
        }
    }, true);

    async function lookupSaleByBarcode(barcode) {
        try {
            const response = await fetch(`api/get-sale-by-barcode.php?barcode=${encodeURIComponent(barcode)}`);
            const data = await response.json();

            if (!data.success) {
                alert(data.message || 'Product not found');
                return;
            }

            const sale = data.sale;
            const scannedItem = data.scanned_item_id
                ? sale.items.find(i => parseInt(i.id) === parseInt(data.scanned_item_id))
                : null;
            if (!scannedItem) {
                alert(data.message || 'This product has already been fully returned from its latest sale');
                return;
            }

            const qty = parseInt(scannedItem.quantity) || 0;
            const unitPrice = parseFloat(scannedItem.unit_price) || 0;
            const total = qty * unitPrice;

            pendingBarcodeReturn = {
                barcode: barcode,
                invoice: sale.invoice_number,
                product: scannedItem.product_name,
                quantity: qty,
                total: total
            };

            document.getElementById('barcodeReturnContent').innerHTML = `
                <p><strong>Invoice:</strong> ${escapeHtml(sale.invoice_number)}</p>
                <p><strong>Date:</strong> ${escapeHtml(sale.date)}</p>
                <hr>
                <table class="table">
                    <thead><tr><th>Product</th><th>Qty</th><th>Amount</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>${escapeHtml(scannedItem.product_name)}</td>
                            <td>${qty}</td>
                            <td>${currency} ${total.toFixed(2)}</td>
                        </tr>
                    </tbody>
                </table>
            `;
            document.getElementById('barcodeReturnBtn').disabled = false;
            document.getElementById('barcodeReturnBtn').innerHTML = '<i class="fas fa-undo"></i> Return Now';
            document.getElementById('barcodeReturnModal').classList.add('active');
        } catch (error) {
            console.error('Error finding sale by barcode:', error);
            alert('Error scanning product barcode');
        }
    }

    function closeBarcodeReturnModal() {
        document.getElementById('barcodeReturnModal').classList.remove('active');
        pendingBarcodeReturn = null;
    }

    async function confirmBarcodeReturn() {
        if (!pendingBarcodeReturn) return;

        const btn = document.getElementById('barcodeReturnBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const response = await fetch(`api/process-barcode-return.php?barcode=${encodeURIComponent(pendingBarcodeReturn.barcode)}`);
            const data = await response.json();

            if (data.success) {
                document.getElementById('invoiceSearch').value = '';
                document.getElementById('invoiceResult').innerHTML = '';
                location.reload();
            } else {
                alert(data.message || 'Return failed');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-undo"></i> Return Now';
            }
        } catch (error) {
            console.error('Error processing return:', error);
            alert('Error processing return');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo"></i> Return Now';
        }
    }

    // Auto-open modal if invoice parameter is present
    document.addEventListener('DOMContentLoaded', function () {
        const urlParams = new URLSearchParams(window.location.search);
        const invoiceParam = urlParams.get('invoice');
        if (invoiceParam) {
            document.getElementById('invoiceSearch').value = invoiceParam;
            openReturnModal();
            searchInvoice();
        }
    });
</script>

<?php include 'includes/footer.php'; ?>