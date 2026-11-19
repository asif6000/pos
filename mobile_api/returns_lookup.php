<?php
/**
 * Mobile API - Find a sale to return
 *
 * Accepts an invoice number or a product barcode and returns the sale's items
 * with the quantity still available to return (sold minus anything already
 * returned on earlier returns for the same sale).
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth();
$data     = json_decode(file_get_contents('php://input'), true) ?: [];
$owner_id = $auth['owner_id'];
$query    = trim($data['query'] ?? '');

if ($query === '') {
    echo json_encode(['success' => false, 'message' => 'Enter an invoice number or scan a barcode']);
    exit;
}

try {
    // Prefer an exact invoice match.
    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total, s.created_at, s.payment_method, s.payment_status,
               c.name AS customer_name, u.name AS cashier_name, " . saleStoreExpr('s', 'u') . " AS store_id, st.name AS store_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN stores st ON st.id = " . saleStoreExpr('s', 'u') . "
        WHERE s.invoice_number = ? AND s.owner_id = ?
        LIMIT 1
    ");
    $stmt->execute([$query, $owner_id]);
    $sale = $stmt->fetch();

    // Otherwise treat the input as a product barcode on one of this owner's sales.
    if (!$sale) {
        $stmt = $db->prepare("
            SELECT s.id, s.invoice_number, s.total, s.created_at, s.payment_method, s.payment_status,
                   c.name AS customer_name, u.name AS cashier_name, " . saleStoreExpr('s', 'u') . " AS store_id, st.name AS store_name
            FROM sales s
            JOIN sale_items si ON si.sale_id = s.id
            JOIN products p ON p.id = si.product_id
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN stores st ON st.id = " . saleStoreExpr('s', 'u') . "
            WHERE p.barcode = ? AND s.owner_id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$query, $owner_id]);
        $sale = $stmt->fetch();
    }

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'No sale found for "' . $query . '"']);
        exit;
    }

    // Items on this sale, net of what previous returns already took.
    $stmt = $db->prepare("
        SELECT si.id, si.product_id, si.product_name, si.quantity, si.unit_price, si.total_price,
               COALESCE((
                   SELECT SUM(ri.quantity)
                   FROM return_items ri
                   JOIN returns r ON r.id = ri.return_id
                   WHERE r.sale_id = ? AND ri.product_id = si.product_id
                     AND ri.product_name NOT LIKE '[EXCHANGE]%'
               ), 0) AS already_returned
        FROM sale_items si
        WHERE si.sale_id = ?
        ORDER BY si.id
    ");
    $stmt->execute([$sale['id'], $sale['id']]);

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        // The [EXCHANGE] rows that live in return_items are not sale items, so
        // this only ever reflects genuine returns of the same product.
        $sold    = (int)$row['quantity'];
        $retd    = (int)$row['already_returned'];
        $row['quantity']    = $sold;
        $row['sold']        = $sold;
        $row['returned_qty'] = $retd;
        $row['remaining']   = max(0, $sold - $retd);
        $items[] = $row;
    }

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'This sale has no items']);
        exit;
    }

    $fullyReturned = true;
    foreach ($items as $i) {
        if ($i['remaining'] > 0) { $fullyReturned = false; break; }
    }

    echo json_encode([
        'success' => true,
        'fully_returned' => $fullyReturned,
        'sale' => [
            'id'              => (int)$sale['id'],
            'invoice_number'  => $sale['invoice_number'],
            'date'            => date('d M Y, h:i A', strtotime($sale['created_at'])),
            'total'           => $sale['total'],
            'payment_method'  => $sale['payment_method'],
            'payment_status'  => $sale['payment_status'],
            'customer_name'   => $sale['customer_name'] ?: 'Walk-in',
            'cashier_name'    => $sale['cashier_name'] ?: 'Unknown',
            'store_name'      => $sale['store_name'] ?: '-',
        ],
        'items' => $items,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}