<?php
/**
 * Mobile API - Submit Sale from POS
 *
 * Prices, discounts and totals are computed HERE, not taken from the request.
 *
 * WHY
 * ---
 * This endpoint used to store whatever the app sent for subtotal, discount,
 * vat_amount and total. That is the whole financial calculation in the client's
 * hands, so a modified app could sell a 100 taka item for 1 taka, or record a
 * discount of -50000. The web POS has the same weakness, but a phone app is
 * distributed as a file anyone can patch, which makes it a realistic attack
 * rather than a theoretical one.
 *
 * The figures the client sends are therefore treated as DISPLAY values only.
 * unit_price comes from products.sell_price, quantity is validated positive and
 * whole, and the money arithmetic below is the authority. What the app shows
 * before confirming is a preview; what lands in the database is this file's
 * arithmetic.
 *
 * The rules are the web POS rules (admin/pos.php, calculateTotals and
 * processCheckout) so a sale looks identical whichever end it was rung up on:
 *
 *   1. subtotal  = SUM(unit_price * quantity) over the lines
 *   2. discount is EITHER a percent OR a flat amount - never both. Whichever the
 *      cashier did not type is derived from the other, purely for the record.
 *   3. discount is clamped to the subtotal: it may zero a sale, never exceed it
 *   4. VAT is charged on the AFTER-DISCOUNT amount, not the subtotal
 *   5. change = max(0, paid - total), and only cash can have one
 *   6. payment_status: paid if paid >= total, partial if paid > 0, else unpaid
 *   7. cash underpayment is rejected outright; the five other methods allow it
 *      and are stored as partial. That asymmetry is existing POS behaviour.
 */
require_once '../config/mobile_auth.php';
mobileCors();

require_once '../config/push.php';
// The invoice SMS the shop may want sent after a mobile sale. These are the same
// files the web POS's invoice-sms endpoint uses, so a receipt built here reads
// identically to one built there - one itemiser, two callers.
require_once '../config/marketing.php';
require_once '../config/sms_gateway.php';
require_once '../config/sms_usage.php';
require_once '../config/invoice_token.php';
require_once '../config/invoice_image.php';
$db = getDB();

$auth  = mobileAuth('pos');
$data  = json_decode(file_get_contents('php://input'), true) ?: [];

$owner_id       = $auth['owner_id'];
$store_id       = (int)($auth['store_id'] ?? 0);
$user_id        = $auth['user_id'];
$items          = $data['items'] ?? [];
$discount_type  = ($data['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
$discount_value = (float)($data['discount_value'] ?? 0);
$paid           = (float)($data['paid'] ?? 0);
$payment_method = (string)($data['payment_method'] ?? 'cash');
$customer_id    = (int)($data['customer_id'] ?? 0) ?: null;
$customer_name  = trim($data['customer_name'] ?? '');
$customer_phone = trim($data['customer_phone'] ?? '');

// Only the six values sales.payment_method actually accepts. Anything else is
// refused rather than silently truncated by the ENUM, which on a non-strict
// MySQL would store '' and leave a sale with no readable payment method.
const ALLOWED_PAYMENT_METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'card', 'bank'];

if (empty($items) || !is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!in_array($payment_method, ALLOWED_PAYMENT_METHODS, true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown payment method']);
    exit;
}

try {
    $db->beginTransaction();

    // Resolve the caller's store. Kept server-side so a request cannot bill a
    // different shop's stock than the one the cashier is standing in.
    if ($store_id <= 0) {
        $st = $db->prepare("SELECT id FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY id LIMIT 1");
        $st->execute([$owner_id]);
        $store_id = (int)($st->fetchColumn() ?: 0);
    }

    // ---------------------------------------------------------------------
    // Price every line from the database and build the snapshot ourselves.
    // ---------------------------------------------------------------------
    $subtotal = 0.0;
    $lines    = [];

    $findPrice = $db->prepare(
        "SELECT id, name, sell_price, status FROM products
          WHERE id = ? AND owner_id = ? AND status = 'active' LIMIT 1"
    );

    foreach ($items as $item) {
        $product_id = (int)($item['id'] ?? 0);
        $qty        = (float)($item['qty'] ?? 0);

        // A zero or negative quantity used to be accepted and then deducted
        // from stock, which could ADD inventory by selling nothing.
        if ($product_id <= 0) {
            throw new Exception('A cart line is missing its product');
        }
        if ($qty <= 0) {
            throw new Exception('Quantity must be more than zero');
        }
        if ($qty != floor($qty)) {
            throw new Exception('Quantity must be a whole number');
        }
        $qty = (int)$qty;

        $findPrice->execute([$product_id, $owner_id]);
        $product = $findPrice->fetch();
        if (!$product) {
            throw new Exception('Product #' . $product_id . ' is unavailable');
        }

        $unitPrice = (float)$product['sell_price'];
        $lineTotal = round($unitPrice * $qty, 2);

        $subtotal += $lineTotal;
        $lines[] = [
            'product_id'   => $product_id,
            'product_name' => $product['name'],
            'quantity'     => $qty,
            'unit_price'   => $unitPrice,
            'total_price'  => $lineTotal,
        ];
    }

    $subtotal = round($subtotal, 2);

    // ---------------------------------------------------------------------
    // Discount: percent or flat, never both, clamped to the subtotal.
    // ---------------------------------------------------------------------
    if ($discount_value < 0) {
        throw new Exception('Discount cannot be negative');
    }

    $discountPercent = 0.0;
    $discountAmount  = 0.0;

    if ($discount_value > 0 && $subtotal > 0) {
        if ($discount_type === 'percent') {
            $discountPercent = min($discount_value, 100);
            $discountAmount  = $subtotal * ($discountPercent / 100);
        } else {
            $discountAmount  = min($discount_value, $subtotal);
            $discountPercent = ($discountAmount / $subtotal) * 100;
        }
        $discountAmount = round($discountAmount, 2);
        $discountPercent = round($discountPercent, 2);
    }

    // Clamp again after rounding: a flat discount of 99.999 on a 100 subtotal
    // rounds to 100.00, and 100.01 on a 100 subtotal must not survive as
    // negative money.
    if ($discountAmount > $subtotal) {
        $discountAmount = $subtotal;
    }

    $afterDiscount = round($subtotal - $discountAmount, 2);

    // ---------------------------------------------------------------------
    // VAT on the DISCOUNTED amount, from the shop setting.
    // ---------------------------------------------------------------------
    $vatPercent = 0.0;
    $vatSetting = $db->prepare("SELECT setting_value FROM settings
                                 WHERE setting_key = 'vat_percent' AND owner_id = ?
                                 ORDER BY id LIMIT 1");
    $vatSetting->execute([$owner_id]);
    $vatRaw = $vatSetting->fetchColumn();
    if ($vatRaw !== false && $vatRaw !== null && is_numeric($vatRaw)) {
        $vatPercent = (float)$vatRaw;
    }

    $vatAmount = round($afterDiscount * ($vatPercent / 100), 2);
    $total     = round($afterDiscount + $vatAmount, 2);

    // Underpayment is refused for cash only. bkash, nagad, rocket, card and bank
    // are allowed to store a partial sale, which is how the web POS behaves.
    $paid = round($paid, 2);

    if ($paid <= 0) {
        $paid = $total;                 // unpaid-for-now is read as exact tender
    } elseif ($paid < $total && $payment_method === 'cash') {
        throw new Exception(
            'Amount received is less than the total. Need ' . number_format($total, 2)
            . ', got ' . number_format($paid, 2)
        );
    }

    $changeAmount    = max(0, round($paid - $total, 2));
    $payment_status  = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

    // ---------------------------------------------------------------------
    // Invoice number.
    //
    // The original read MAX(id)+1 before inserting, so two tills selling at the
    // same instant both read the same number and one insert failed on the UNIQUE
    // index - that customer's sale was lost. A retry loop makes a collision
    // recover instead of fatal.
    // ---------------------------------------------------------------------
    $invoiceNumber = null;
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $lastId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM sales")->fetchColumn();
        $candidate = 'INV-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);

        $chk = $db->prepare("SELECT id FROM sales WHERE invoice_number = ? LIMIT 1");
        $chk->execute([$candidate]);
        if (!$chk->fetchColumn()) { $invoiceNumber = $candidate; break; }
    }
    if ($invoiceNumber === null) {
        throw new Exception('Could not allocate an invoice number, please try again');
    }

    // ---------------------------------------------------------------------
    // Persist the sale.
    // ---------------------------------------------------------------------
    $stmt = $db->prepare("INSERT INTO sales (
        invoice_number, customer_id, user_id,
        subtotal, discount_percent, discount_amount,
        vat_percent, vat_amount, total,
        paid_amount, change_amount,
        payment_method, payment_status, owner_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $invoiceNumber,
        $customer_id,
        $user_id,
        $subtotal,
        $discountPercent,
        $discountAmount,
        $vatPercent,
        $vatAmount,
        $total,
        $paid,
        $changeAmount,
        $payment_method,
        $payment_status,
        $owner_id,
    ]);

    $sale_id = (int)$db->lastInsertId();

    // Customer, created after the sale so a duplicate phone cannot abort the
    // whole transaction and lose a completed checkout.
    if (!$customer_id && ($customer_name !== '' || $customer_phone !== '')) {
        $stmtCust = $db->prepare("SELECT id FROM customers WHERE phone = ? AND owner_id = ? LIMIT 1");
        $stmtCust->execute([$customer_phone, $owner_id]);
        $existingCust = $stmtCust->fetchColumn();

        if ($existingCust) {
            $customer_id = (int)$existingCust;
        } else {
            $stmtCustIns = $db->prepare(
                "INSERT INTO customers (name, phone, owner_id) VALUES (?, ?, ?)"
            );
            $stmtCustIns->execute([
                $customer_name !== '' ? $customer_name : 'Walk-in Customer',
                $customer_phone,
                $owner_id,
            ]);
            $customer_id = (int)$db->lastInsertId();
        }

        $db->prepare("UPDATE sales SET customer_id = ? WHERE id = ?")
           ->execute([$customer_id, $sale_id]);
    }

    // ---------------------------------------------------------------------
    // Lines and stock.
    // ---------------------------------------------------------------------
    $stmtItem = $db->prepare("INSERT INTO sale_items
        (sale_id, product_id, product_name, quantity, unit_price, total_price)
        VALUES (?, ?, ?, ?, ?, ?)");

    $ensureStock = $db->prepare(
        "INSERT IGNORE INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)"
    );
    $deductStock = $db->prepare(
        "UPDATE store_stocks SET quantity = quantity - ?
          WHERE store_id = ? AND product_id = ?"
    );
    $logHistory = $db->prepare(
        "INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id)
         VALUES (?, ?, 'sale', ?, ?, ?)"
    );

    foreach ($lines as $line) {
        $stmtItem->execute([
            $sale_id,
            $line['product_id'],
            $line['product_name'],
            $line['quantity'],
            $line['unit_price'],
            $line['total_price'],
        ]);

        if ($store_id > 0) {
            $ensureStock->execute([$store_id, $line['product_id']]);
            $deductStock->execute([$line['quantity'], $store_id, $line['product_id']]);
        }

        $logHistory->execute([
            $line['product_id'],
            -$line['quantity'],
            $sale_id,
            'Sale: ' . $invoiceNumber,
            $user_id,
        ]);
    }

    // The cashbook entry the web POS writes for every sale, so the Cashbook and
    // Expense pages see mobile sales too. Written inline because
    // addAutoCashbookEntry() reads the PHP session and this API has none.
    if ($total > 0) {
        $db->prepare(
            "INSERT INTO cashbook_entries
                (owner_id, store_id, user_id, type, amount, note, category_id, source_type, source_id)
             VALUES (?, ?, ?, 'cash_in', ?, ?, NULL, 'sale', ?)"
        )->execute([
            $owner_id,
            $store_id ?: null,
            $user_id,
            $total,
            'Sale: ' . $invoiceNumber,
            $sale_id,
        ]);
    }

    $db->commit();

    // ---------------------------------------------------------------------
    // Tell the owner/managers' phones about the sale.
    //
    // Done AFTER the commit and wrapped in a helper that never throws, so a
    // push failure can only ever mean "no notification" - never a lost or
    // rolled-back sale. The seller's own device is excluded inside the helper.
    // ---------------------------------------------------------------------
    $cashierName = '';
    $custName    = $customer_name;
    try {
        $nm = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $nm->execute([$user_id]);
        $cashierName = (string)($nm->fetchColumn() ?: '');

        if ($custName === '' && $customer_id) {
            $cn = $db->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
            $cn->execute([$customer_id]);
            $custName = (string)($cn->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {
        // Names are cosmetic; a sale is never held up for them.
    }
    notifyNewSale($db, $owner_id, $user_id, $invoiceNumber, $total, $payment_method, $cashierName, $custName);

    // ---------------------------------------------------------------------
    // Optional auto invoice SMS.
    //
    // Runs entirely AFTER the commit and inside a catch-all, so the sale is
    // already safely recorded no matter what happens here - a failed SMS is
    // reported in the response, never rolled back into the checkout. The staff app
    // never talks to the gateway directly; this is the only way a mobile sale
    // sends a text, and the send is what the SMS counters on the admin pages are
    // counted from.
    //
    // Gated the same way the web POS gates its automatic receipt, so a shop that
    // ticked "SMS e pathao" gets the same behaviour at both counters:
    //   - auto_send_invoice + invoice_send_sms are the shop's own checkboxes, and
    //     a missing row means on, exactly like process-sale.php resolves them,
    //   - a real gateway must be configured (manual mode would only log a no-op).
    //
    // It used to read 'invoice_sms_autosend' - a key no page ever writes - so the
    // flag always fell back to '0' and a mobile sale silently never sent a receipt,
    // no matter what the shop had switched on.
    // ---------------------------------------------------------------------
    $smsReport = ['attempted' => false, 'sent' => false, 'reason' => ''];
    try {
        $data = invoiceLoadSale($db, $sale_id, $owner_id);
        if (!empty($data['ok'])) {
            $shopSettings = $data['settings'];
            $flag = function ($key, $default = '1') use ($shopSettings) {
                $v = $shopSettings[$key] ?? $default;
                return !($v === '0' || $v === 0 || $v === '');
            };
            $autoOn = $flag('auto_send_invoice') && $flag('invoice_send_sms');

            $smsTo = marketingNormalizePhone((string)($data['sale']['customer_phone'] ?? ''));

            if ($autoOn
                && $smsTo !== ''
                && (getSmsSettings($owner_id)['marketing_sms_provider'] ?? 'manual') !== 'manual') {

                $msg = invoiceSmsText([
                    'shop_name'      => (string)($shopSettings['shop_name'] ?? ''),
                    'invoice_number' => (string)$data['sale']['invoice_number'],
                    'date_label'     => date('d M Y, h:i A', strtotime((string)($data['sale']['created_at'] ?: 'now')) ?: time()),
                    'items'          => $data['items'],
                    'total'          => (float)$data['sale']['total'],
                    'paid'           => (float)$data['sale']['paid_amount'],
                    'paid_label'     => (string)($data['sale']['payment_method'] ?? ''),
                    'change'         => (float)$data['sale']['change_amount'],
                    'footer'         => (string)($shopSettings['receipt_footer'] ?? ''),
                    'promo'          => [],
                    'max_items'      => (int)($shopSettings['invoice_sms_max_items'] ?? 8),
                ]);

                $send = smsSendAndLog($db, $owner_id, $smsTo, $msg['text'], [
                    'segments' => (int)$msg['segments'],
                    'msg_type' => 'invoice',
                    'store_id' => $store_id ?: null,
                    'user_id'  => $user_id,
                ]);

                $smsReport = [
                    'attempted'    => true,
                    'sent'         => (bool)$send['ok'],
                    'manual'       => (bool)$send['manual'],
                    'segments'     => (int)$msg['segments'],
                    'reason'       => $send['ok'] ? '' : ($send['error'] ?? ''),
                ];
            } else {
                // Why nothing went out. The sale response already carries this
                // block, so an operator can read the real reason instead of
                // guessing which of four gates closed.
                $why = '';
                if (!$autoOn) {
                    $why = 'settings-off';
                } elseif ($smsTo === '') {
                    $why = 'no-phone';
                } else {
                    $why = 'gateway-manual';
                }
                $smsReport = ['attempted' => false, 'sent' => false, 'reason' => $why];
            }
        }
    } catch (Throwable $e) {
        // Cosmetic to the sale. A missing receipt text is never a reason to lose
        // or fail a checkout that has already been recorded and pushed.
        error_log('mobile sell invoice-sms: ' . $e->getMessage());
        $smsReport = ['attempted' => false, 'error' => 'sms-skipped'];
    }

    // The server's numbers, so the app can show what was actually recorded
    // rather than what it hoped for.
    echo json_encode([
        'success' => true,
        'sale_id' => $sale_id,
        'invoice' => $invoiceNumber,
        'subtotal' => $subtotal,
        'discount_type' => $discount_type,
        'discount_value' => $discount_value,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'vat_percent' => $vatPercent,
        'vat_amount' => $vatAmount,
        'total' => $total,
        'paid' => $paid,
        'change' => $changeAmount,
        'payment_method' => $payment_method,
        'payment_status' => $payment_status,
        'invoice_sms' => $smsReport,
        'message' => 'Sale recorded successfully',
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}