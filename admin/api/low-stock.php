<?php
/**
 * Realtime Low Stock / Out of Stock Count Endpoint
 *
 * Returns the current user's low-stock and out-of-stock product counts.
 * Polled by the dashboard every few seconds so counts update after a sale
 * without reloading the page.
 *
 * Security: read-only, scoped to the logged-in user / owner.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['authenticated' => false, 'low_stock' => 0, 'out_of_stock' => 0]);
    exit;
}

$user     = getCurrentUser();
$db       = getDB();
$store_id = isset($_GET['store_id']) && is_numeric($_GET['store_id']) && (int)$_GET['store_id'] > 0
            ? (int)$_GET['store_id']
            : ($user['store_id'] ?? null);
$owner_id = !empty($user['owner_id']) ? (int)$user['owner_id'] : (int)$user['id'];

try {
    if ($store_id) {
        // Per-store counts (based on that store's stock)
        $lowStock = $db->prepare("SELECT COUNT(*) FROM store_stocks ss
            JOIN products p ON ss.product_id = p.id
            WHERE ss.store_id = ? AND ss.quantity <= p.min_stock AND ss.quantity > 0 AND p.status = 'active'");
        $lowStock->execute([$store_id]);
        $lowStockCount = (int)$lowStock->fetchColumn();

        $outStock = $db->prepare("SELECT COUNT(*) FROM store_stocks ss
            JOIN products p ON ss.product_id = p.id
            WHERE ss.store_id = ? AND (ss.quantity = 0 OR ss.quantity IS NULL) AND p.status = 'active'");
        $outStock->execute([$store_id]);
        $outStockCount = (int)$outStock->fetchColumn();
    } else {
        // Global counts (based on master product stock)
        if (isSuperAdmin()) {
            $lowStockCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE status='active' AND stock <= min_stock AND stock > 0")->fetchColumn();
            $outStockCount = (int)$db->query("SELECT COUNT(*) FROM products WHERE status='active' AND (stock IS NULL OR stock = 0)")->fetchColumn();
        } else {
            $lowStock = $db->prepare("SELECT COUNT(*) FROM products WHERE status='active' AND stock <= min_stock AND stock > 0 AND owner_id = ?");
            $lowStock->execute([$owner_id]);
            $lowStockCount = (int)$lowStock->fetchColumn();

            $outStock = $db->prepare("SELECT COUNT(*) FROM products WHERE status='active' AND (stock IS NULL OR stock = 0) AND owner_id = ?");
            $outStock->execute([$owner_id]);
            $outStockCount = (int)$outStock->fetchColumn();
        }
    }

    echo json_encode(['authenticated' => true, 'low_stock' => $lowStockCount, 'out_of_stock' => $outStockCount]);
} catch (Exception $e) {
    echo json_encode(['authenticated' => true, 'low_stock' => 0, 'out_of_stock' => 0, 'error' => true]);
}
