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

if (!hasPermission('sales')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: Your plan does not include this feature.']);
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
        $db->exec("ALTER TABLE sales ADD COLUMN printed TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
    }
} catch (PDOException $e) {}

try {
    $stmt = $db->prepare("UPDATE sales SET printed = 1 WHERE id = ?");
    $stmt->execute([$saleId]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating sale']);
}
