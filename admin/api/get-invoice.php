<?php
/**
 * POS System - Get Invoice API
 * Returns invoice details for viewing/printing
 */

header('Content-Type: application/json');

require_once'../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$saleId = (int) ($_GET['id'] ?? 0);

if (!$saleId) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
    exit;
}

$db = getDB();
$user = getCurrentUser();

try {
    // The id arrives from the page, and an invoice carries a customer's name and
    // phone, so it has to be checked against the shop that rang it: without this
    // gate any signed-in shop could read another's bill by changing the number.
    // A counter account (staff/cashier) is gated one step tighter: only the bill
    // it rang up itself, so no id typed into the URL opens a colleague's sale.
    $callerStoreId = $user['store_id'] ?? null;
    $isSuperAdmin = isSuperAdminUser($user);
    list($ownScope, $ownParams) = ownSalesScopeSql($user, 's');
    $params = [$saleId];
    $scope = '';
    if ($callerStoreId) {
        $scope = " AND " . saleStoreExpr('s', 'u') . " = ?";
        $params[] = $callerStoreId;
    } elseif (!$isSuperAdmin) {
        $scope = " AND " . saleOwnerScopeSql($user['owner_id'], 's', 'u');
        $params[] = $user['owner_id'];
        $params[] = $user['owner_id'];
    }
    $scope .= $ownScope;
    $params = array_merge($params, $ownParams);

    // Get sale details
    $stmt = $db->prepare("
        SELECT s.*, c.name as customer_name, c.phone as customer_phone, u.name as cashier_name,
               " . saleOwnerExpr('s', 'u') . " AS sale_owner_id
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ?" . $scope . "
    ");
    $stmt->execute($params);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Sale not found']);
        exit;
    }

    // Get sale items
    $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $items = $stmt->fetchAll();

    // Get settings (owner-scoped)
    // The SALE's owner, not the caller's: this renders someone else's till bill.
    $invoiceOwnerId = $sale['sale_owner_id'] !== null ? $sale['sale_owner_id'] : $sale['user_id'];
    $settings = [];
    $settingsStmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
    $settingsStmt->execute([$invoiceOwnerId]);
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // QR is on unless the shop explicitly turned it off. A missing row has to
    // mean "on" so this matches what the receipts were already doing before the
    // setting existed. Compared against all three off-spellings on purpose: the
    // column is text, so an unticked box arrives as '0', and a numeric 0 or an
    // empty value should mean off too.
    $qrOn = ($settings['invoice_show_qr'] ?? '1');
    $qrOn = !($qrOn === '0' || $qrOn === 0 || $qrOn === '');

    echo json_encode([
        'success' => true,
        'invoice' => [
            'id' => $sale['id'],
            'invoice_number' => $sale['invoice_number'],
            'date' => date('d M Y, h:i A', strtotime($sale['created_at'])),
            'customer_name' => $sale['customer_name'] ?? 'Walk-in Customer',
            'customer_phone' => $sale['customer_phone'] ?? '',
            'cashier' => $sale['cashier_name'],
            'items' => $items,
            'subtotal' => $sale['subtotal'],
            'discount_percent' => $sale['discount_percent'],
            'discount_amount' => $sale['discount_amount'],
            'vat_percent' => $sale['vat_percent'],
            'vat_amount' => $sale['vat_amount'],
            'total' => $sale['total'],
            'paid_amount' => $sale['paid_amount'],
            'change_amount' => $sale['change_amount'],
            'payment_method' => $sale['payment_method'],
            'payment_status' => $sale['payment_status'],
            'shop_name' => $settings['shop_name'] ?? 'POS System',
            'shop_address' => $settings['shop_address'] ?? '',
            'shop_phone' => $settings['shop_phone'] ?? '',
            'shop_website' => $settings['shop_website'] ?? 'www.vouser.com',
            'facebook_page' => $settings['facebook_page'] ?? 'https://www.facebook.com',
            'show_qr' => $qrOn,
            'receipt_footer' => $settings['receipt_footer'] ?? '',
            'voucher_terms' => $settings['voucher_terms'] ?? '',
            'return_qr_url' => $settings['return_qr_url'] ?? '',
            'coupon_status' => $settings['coupon_status'] ?? '0',
            'coupon_title' => $settings['coupon_title'] ?? 'SMART COLLECTION MONTHLY LUCKY COUPON',
            'coupon_subtitle' => $settings['coupon_subtitle'] ?? 'প্রতিটি কেনাকাটায় নিশ্চিত Lucky Entry Coupon!',
            'coupon_prize_1' => $settings['coupon_prize_1'] ?? '🥇 ৫,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_2' => $settings['coupon_prize_2'] ?? '🥈 ৩,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_3' => $settings['coupon_prize_3'] ?? '🥉 ২,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_4' => $settings['coupon_prize_4'] ?? '🎁 ৫০০ Shopping Voucher — ১০ জন',
            'coupon_prize_5' => $settings['coupon_prize_5'] ?? '👕 Premium T-Shirt — ১০ জন',
            'coupon_total_winners' => $settings['coupon_total_winners'] ?? 'মোট বিজয়ী: ২৩ জন',
            'coupon_announcement' => $settings['coupon_announcement'] ?? '📅 প্রতি মাসের ১ তারিখ রাত ৮:০০ টায় Smart Collection-এর অফিসিয়াল Facebook Live-এ বিজয়ী ঘোষণা করা হবে।'
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

?>