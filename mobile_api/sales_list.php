<?php
/**
 * Mobile API - Sales list.
 *
 * Own sales for a cashier, the whole shop's for an admin/owner.
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth();
$owner_id = $auth['owner_id'];
$user_id  = $auth['user_id'];

try {
    $ownOnly = !$auth['is_admin'];
    $scope   = $ownOnly ? " AND s.user_id = ?" : "";

    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total, s.created_at, s.payment_status, s.payment_method, u.name as cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.owner_id = ? $scope
        ORDER BY s.id DESC LIMIT 50
    ");
    $stmt->execute($ownOnly ? [$owner_id, $user_id] : [$owner_id]);

    echo json_encode(['success' => true, 'sales' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
