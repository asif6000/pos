<?php
/**
 * POS System - Get Invoice API
 * Returns invoice details for viewing/printing
 */

header('Content-Type: application/json');

require_once '../../config/db.php';
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

try {
    // Get sale details
    $stmt = $db->prepare("
        SELECT s.*, c.name as customer_name, c.phone as customer_phone, u.name as cashier_name 
        FROM sales s 
        LEFT JOIN customers c ON s.customer_id = c.id 
        LEFT JOIN users u ON s.user_id = u.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Sale not found']);
        exit;
    }

    // Get sale items
    $stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
    $stmt->execute([$saleId]);
    $items = $stmt->fetchAll();

    // Get settings
    $settings = [];
    $settingsStmt = $db->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

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
            'receipt_footer' => $settings['receipt_footer'] ?? '',
            'voucher_terms' => $settings['voucher_terms'] ?? '',
            'return_qr_url' => $settings['return_qr_url'] ?? '',
            'coupon_status' => $settings['coupon_status'] ?? '0',
            'coupon_title' => $settings['coupon_title'] ?? 'SMART COLLECTION MONTHLY LUCKY COUPON',
            'coupon_subtitle' => $settings['coupon_subtitle'] ?? 'প্রতিটি কেনাকাটায় নিশ্চিত Lucky Entry Coupon!',
            'coupon_prize_1' => $settings['coupon_prize_1'] ?? '🥇 ৳৫,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_2' => $settings['coupon_prize_2'] ?? '🥈 ৳৩,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_3' => $settings['coupon_prize_3'] ?? '🥉 ৳২,০০০ Shopping Voucher — ১ জন',
            'coupon_prize_4' => $settings['coupon_prize_4'] ?? '🎁 ৳৫০০ Shopping Voucher — ১০ জন',
            'coupon_prize_5' => $settings['coupon_prize_5'] ?? '👕 Premium T-Shirt — ১০ জন',
            'coupon_total_winners' => $settings['coupon_total_winners'] ?? 'মোট বিজয়ী: ২৩ জন',
            'coupon_announcement' => $settings['coupon_announcement'] ?? '📅 প্রতি মাসের ১ তারিখ রাত ৮:০০ টায় Smart Collection-এর অফিসিয়াল Facebook Live-এ বিজয়ী ঘোষণা করা হবে।'
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>