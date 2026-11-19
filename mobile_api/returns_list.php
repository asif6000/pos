<?php
/**
 * Mobile API - Return history for the signed-in staff member
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth();
$owner_id = $auth['owner_id'];
$user_id  = $auth['user_id'];

try {
    // Own returns for a cashier; an admin/owner reads the shop's whole history,
    // which is what the Returns screen is for when somebody else rang the sale.
    $ownOnly = !$auth['is_admin'];
    $scope   = $ownOnly ? " AND r.user_id = ?" : "";

    $stmt = $db->prepare("
        SELECT r.id, r.return_number, r.total_amount, r.refund_method, r.reason, r.created_at, r.status,
               s.invoice_number AS sale_invoice,
               (SELECT COALESCE(SUM(ri.total_price), 0) FROM return_items ri
                 WHERE ri.return_id = r.id AND ri.product_name NOT LIKE '[EXCHANGE]%') AS returned_value,
               (SELECT COALESCE(SUM(ri.total_price), 0) FROM return_items ri
                 WHERE ri.return_id = r.id AND ri.product_name LIKE '[EXCHANGE]%') AS exchanged_value
        FROM returns r
        JOIN sales s ON s.id = r.sale_id
        WHERE r.owner_id = ? $scope
        ORDER BY r.id DESC
        LIMIT 50
    ");
    $stmt->execute($ownOnly ? [$owner_id, $user_id] : [$owner_id]);

    echo json_encode(['success' => true, 'returns' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}