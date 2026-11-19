<?php
/**
 * Mobile API - Get Products with Stock
 *
 * The shop this caller is signed into is taken from the session, never from the
 * body, so the request cannot name a different shop's catalogue.
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth('pos');
$owner_id = $auth['owner_id'];
$store_id = (int)($auth['store_id'] ?? 0);

try {
    // Get store_id if not provided
    if (!$store_id) {
        $st = $db->prepare("SELECT id FROM stores WHERE status='active' AND owner_id=? LIMIT 1");
        $st->execute([$owner_id]);
        $store_id = (int)($st->fetchColumn() ?: 1);
    }

    // Products with stock
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.barcode, p.sell_price, p.image, p.category_id,
               COALESCE(ss.quantity, 0) as stock,
               c.name as category_name
        FROM products p
        LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.owner_id = ? AND p.status = 'active' AND COALESCE(ss.quantity, 0) > 0
        ORDER BY p.name
    ");
    $stmt->execute([$store_id, $owner_id]);
    $products = $stmt->fetchAll();

    // The app fetches from a different origin than the pages that render these
    // images, so a project-relative path is useless to it - hand back a full URL.
    // getAppBaseUrl() already includes the install's base path, and the stored
    // value is project-relative, so joining them lands on the right file both on
    // XAMPP (/admin/...) and on the live docroot.
    $base = rtrim(getAppBaseUrl(), '/');
    foreach ($products as &$p) {
        $img = $p['image'] ?? null;
        $p['image_url'] = $img ? $base . '/' . ltrim($img, '/') : null;
    }
    unset($p);


    // Categories
    $cstmt = $db->prepare("SELECT id, name FROM categories WHERE status='active' AND owner_id=? ORDER BY name");
    $cstmt->execute([$owner_id]);
    $categories = $cstmt->fetchAll();

    // VAT
    $vstmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='vat_percent' AND owner_id=? LIMIT 1");
    $vstmt->execute([$owner_id]);
    $vat = (float)($vstmt->fetchColumn() ?: 0);

    echo json_encode([
        'success'    => true,
        'products'   => $products,
        'categories' => $categories,
        'vat_percent'=> $vat,
        'store_id'   => $store_id,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
