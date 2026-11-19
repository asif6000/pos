<?php
/**
 * API - Mark Invoice as Printed
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$saleId = (int)($input['id'] ?? 0);

if ($saleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'];

// Ensure printed column exists
try {
    $cols = $db->query("SHOW COLUMNS FROM sales LIKE 'printed'")->fetchAll();
    if (empty($cols)) {
        appSafeDdl($db, "ALTER TABLE sales ADD COLUMN printed TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
    }
} catch (PDOException $e) {}

try {
    // The bill is marked printed only inside the caller's scope: the shop that
    // rang it, and for a counter account (staff/cashier) only the bill it rang
    // up itself. An id typed into a request can no longer touch anyone else's.
    $saleScope = " JOIN users u ON s.user_id = u.id WHERE s.id = ?" .
        (isSuperAdminUser($user) ? '' : " AND " . saleOwnerScopeSql($ownerId, 's', 'u'));
    $saleParams = [$saleId];
    if (!isSuperAdminUser($user)) {
        $saleParams[] = $ownerId;
        $saleParams[] = $ownerId;
    }
    list($ownScope, $ownParams) = ownSalesScopeSql($user, 's');
    $saleScope .= $ownScope;
    $saleParams = array_merge($saleParams, $ownParams);

    $stmt = $db->prepare("SELECT s.id FROM sales s" . $saleScope);
    $stmt->execute($saleParams);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Sale not found in your scope']);
        exit;
    }

    $stmt = $db->prepare("UPDATE sales SET printed = 1 WHERE id = ?");
    $stmt->execute([$saleId]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating sale']);
}
