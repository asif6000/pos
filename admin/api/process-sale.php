<?php
/**
 * API - Process Sale (Create or Update)
 * Handles sale transaction, stock updates, and invoice generation
 */

// Buffer all output so any stray PHP warnings don't corrupt JSON
ob_start();

// Suppress all error output to browser — log only
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (empty($input['items']) || !is_array($input['items'])) {
    if (!$input) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No items in cart']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$ownerId = $currentUser['owner_id'];
$shopName = 'POS System'; // Default
$shopAddress = '';
$shopPhone = '';

// Get shop settings - Filter by owner
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$ownerId]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$shopName = $settings['shop_name'] ?? 'POS System';
$shopAddress = $settings['shop_address'] ?? '';
$shopPhone = $settings['shop_phone'] ?? '';

$editSaleId = isset($input['edit_sale_id']) ? (int) $input['edit_sale_id'] : 0;
// Determine Store ID
$store_id = $_SESSION['store_id'] ?? 0;
if (!$store_id) {
    $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
    $stmtFallback->execute([$ownerId]);
    $store_id = $stmtFallback->fetchColumn();
    if (!$store_id) {
        $stmtFallback = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
        $store_id = $stmtFallback->fetchColumn();
    }
}

if (!$store_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No active store found. Please create a store first in Admin → Stores.']);
    exit;
}

try {
    $db->beginTransaction();

    // Prepare data
    $customerIdRaw = $input['customer_id'] ?? 0;
    $customerId = ((int) $customerIdRaw) > 0 ? (int) $customerIdRaw : null;
    $subtotal = (float) ($input['subtotal'] ?? 0);
    $discountPercent = (float) ($input['discount_percent'] ?? 0);
    $discountAmount = (float) ($input['discount_amount'] ?? 0);
    $vatPercent = (float) ($input['vat_percent'] ?? 0);
    $vatAmount = (float) ($input['vat_amount'] ?? 0);
    $total = (float) ($input['total'] ?? 0);
    $paidAmount = (float) ($input['paid_amount'] ?? 0);
    $changeAmount = (float) ($input['change_amount'] ?? 0);
    $paymentMethod = sanitize($input['payment_method'] ?? 'cash');
    $paymentStatus = $paidAmount >= $total ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid');

    if ($editSaleId > 0) {
        // --- EDIT MODE ---

        // 1. Restore stock from old items (subtracting already-returned quantities)
        $stmtReturned = $db->prepare("
            SELECT ri.product_id, SUM(ri.quantity) as returned_qty
            FROM return_items ri
            JOIN returns r ON ri.return_id = r.id
            WHERE r.sale_id = ?
            GROUP BY ri.product_id
        ");
        $stmtReturned->execute([$editSaleId]);
        $returnedQtys = [];
        while ($row = $stmtReturned->fetch()) {
            $returnedQtys[$row['product_id']] = (int)$row['returned_qty'];
        }

        $stmtOldItems = $db->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmtOldItems->execute([$editSaleId]);
        $oldItems = $stmtOldItems->fetchAll();

        foreach ($oldItems as $item) {
            // Subtract already-returned quantity to avoid double restoration
            $restoreQty = $item['quantity'];
            if (isset($returnedQtys[$item['product_id']])) {
                $restoreQty -= $returnedQtys[$item['product_id']];
            }

            if ($restoreQty <= 0) continue;

            // Restore to store_stocks
            // Check if record exists (it should, but safety first)
            $check = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
            $check->execute([$store_id, $item['product_id']]);
            if ($check->fetch()) {
                $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?")
                    ->execute([$restoreQty, $store_id, $item['product_id']]);
            } else {
                $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                    ->execute([$store_id, $item['product_id'], $restoreQty]);
            }

            // Log restoration
            $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'adjustment', ?, 'Sale Edit - Restored (Store $store_id)', ?)")
                ->execute([$item['product_id'], $restoreQty, $editSaleId, $userId]);
        }

        // 2. Delete old items
        $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$editSaleId]);

        // 3. Update Sale Record
        $stmtUpdate = $db->prepare("UPDATE sales SET 
            customer_id = ?, 
            subtotal = ?, 
            discount_percent = ?, 
            discount_amount = ?, 
            vat_percent = ?, 
            vat_amount = ?, 
            total = ?, 
            paid_amount = ?, 
            change_amount = ?, 
            payment_method = ?, 
            payment_status = ?,
            user_id = ?,
            updated_at = NOW() 
            WHERE id = ?");

        $stmtUpdate->execute([
            $customerId,
            $subtotal,
            $discountPercent,
            $discountAmount,
            $vatPercent,
            $vatAmount,
            $total,
            $paidAmount,
            $changeAmount,
            $paymentMethod,
            $paymentStatus,
            $userId,
            $editSaleId
        ]);

        $saleId = $editSaleId;

        // Fetch invoice number for response
        $stmtInv = $db->prepare("SELECT invoice_number, created_at FROM sales WHERE id = ?");
        $stmtInv->execute([$saleId]);
        $existingSale = $stmtInv->fetch();
        $invoiceNumber = $existingSale['invoice_number'];
        $saleDate = date('d M Y, h:i A', strtotime($existingSale['created_at']));

    } else {
        // --- NEW SALE MODE ---

        // Generate Invoice Number
        $stmt = $db->query("SELECT MAX(id) FROM sales");
        $lastId = $stmt->fetchColumn() ?: 0;
        $invoiceNumber = 'INV-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);

        // Insert Sale
        $stmt = $db->prepare("INSERT INTO sales (
            invoice_number, customer_id, user_id, 
            subtotal, discount_percent, discount_amount, 
            vat_percent, vat_amount, total, 
            paid_amount, change_amount, 
            payment_method, payment_status, owner_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $invoiceNumber,
            $customerId,
            $userId,
            $subtotal,
            $discountPercent,
            $discountAmount,
            $vatPercent,
            $vatAmount,
            $total,
            $paidAmount,
            $changeAmount,
            $paymentMethod,
            $paymentStatus,
            $ownerId
        ]);

        $saleId = $db->lastInsertId();
        $saleDate = date('d M Y, h:i A');
    }

    // Insert Sale Items and Deduct Stock (Common for both New and Edit)
    $stmtItem = $db->prepare("INSERT INTO sale_items (
        sale_id, product_id, product_name, 
        quantity, unit_price, total_price
    ) VALUES (?, ?, ?, ?, ?, ?)");

    $stmtHistory = $db->prepare("INSERT INTO stock_history (
        product_id, quantity_change, type, 
        reference_id, note, user_id
    ) VALUES (?, ?, 'sale', ?, ?, ?)");

    foreach ($input['items'] as $item) {
        $productId = (int) $item['product_id'];
        $qty       = (int) $item['quantity'];

        // Insert sale item
        $stmtItem->execute([
            $saleId,
            $productId,
            $item['product_name'],
            $qty,
            $item['unit_price'],
            $item['total_price']
        ]);

        // Check store stock — use a fresh query each iteration to avoid cursor issues
        $stockRow = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
        $stockRow->execute([$store_id, $productId]);
        $existing = $stockRow->fetch();

        if ($existing) {
            // Deduct from existing stock
            $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?")
               ->execute([$qty, $store_id, $productId]);
        } else {
            // Product not in store_stocks — add it with 0 stock first, then deduct
            $db->prepare("INSERT IGNORE INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)")
               ->execute([$store_id, $productId]);
            $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?")
               ->execute([$qty, $store_id, $productId]);
        }

        // Stock history
        $stmtHistory->execute([
            $productId,
            -$qty,
            $saleId,
            "Sale Transaction (Store $store_id)",
            $userId
        ]);
    }

    // Auto Cash In entry for the sale (full sale total)
    ensureCashbookSourceColumns();
    $cashNote = 'Sale ' . $invoiceNumber;
    if ($editSaleId > 0) {
        updateAutoCashbookEntry('sale', $saleId, $total);
    } else {
        addAutoCashbookEntry('cash_in', $total, $cashNote, 'sale', $saleId);
    }

    $db->commit();

    // Prepare Invoice Data
    $invoiceData = [
        'invoice_number' => $invoiceNumber,
        'date' => $saleDate,
        'customer_name' => $customerId > 0 ? ('Customer #' . $customerId) : '', // Simplified, ideally fetch name
        'cashier' => $_SESSION['user_name'] ?? 'Admin',
        'items' => $input['items'], // Use input items as they are fresh
        'subtotal' => $subtotal,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'vat_percent' => $vatPercent,
        'vat_amount' => $vatAmount,
        'total' => $total,
        'paid_amount' => $paidAmount,
        'change_amount' => $changeAmount,
        'payment_method' => $paymentMethod,
        'coupon_status' => $settings['coupon_status'] ?? '0',
        'coupon_title' => $settings['coupon_title'] ?? 'SMART COLLECTION MONTHLY LUCKY COUPON',
        'coupon_subtitle' => $settings['coupon_subtitle'] ?? 'প্রতিটি কেনাকাটায় নিশ্চিত Lucky Entry Coupon!',
        'coupon_prize_1' => $settings['coupon_prize_1'] ?? '🥇 ৳৫,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_2' => $settings['coupon_prize_2'] ?? '🥈 ৳৩,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_3' => $settings['coupon_prize_3'] ?? '🥉 ৳২,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_4' => $settings['coupon_prize_4'] ?? '🎁 ৳৫০০ Shopping Voucher — ১০ জন',
        'coupon_prize_5' => $settings['coupon_prize_5'] ?? '👕 Premium T-Shirt — ১০ জন',
        'coupon_total_winners' => $settings['coupon_total_winners'] ?? 'মোট বিজয়ী: ২৩ জন',
        'coupon_announcement' => $settings['coupon_announcement'] ?? '📅 প্রতি মাসের ১ তারিখ রাত ৮:০০ টায় Smart Collection-এর অফিসিয়াল Facebook Live-এ বিজয়ী ঘোষণা করা হবে।',
        'voucher_terms' => $settings['voucher_terms'] ?? '',
        'return_qr_url' => $settings['return_qr_url'] ?? ''
    ];

    $invoiceData['customer_phone'] = '';
    // Fetch customer name if available
    if ($customerId > 0) {
        $stmtCu = $db->prepare("SELECT name, phone FROM customers WHERE id = ?");
        $stmtCu->execute([$customerId]);
        $cu = $stmtCu->fetch();
        if ($cu) {
            $invoiceData['customer_name'] = $cu['name'];
            $invoiceData['customer_phone'] = $cu['phone'] ?? '';
        }
    }


    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Sale processed successfully',
        'invoice' => $invoiceData
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}