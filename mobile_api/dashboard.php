<?php
/**
 * Mobile API - Dashboard stats for whoever is signed in.
 *
 * An admin/owner sees the whole shop; every other role sees only the sales they
 * rang up themselves, which is what the cashier's own figures mean.
 */
require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();
$auth     = mobileAuth();
$data     = json_decode(file_get_contents('php://input'), true) ?: [];
$owner_id = $auth['owner_id'];
$user_id  = $auth['user_id'];

try {
    $period = $data['period'] ?? 'today';

    // Must be built per-alias: the sale_items subquery reads from `s2`, so
    // reusing a condition built for `s` silently binds to the outer query and
    // returns lifetime totals instead of the selected period.
    $dateCondition = function (string $alias) use ($period): string {
        if ($period === 'month') {
            return "MONTH($alias.created_at) = MONTH(CURDATE()) AND YEAR($alias.created_at) = YEAR(CURDATE())";
        }
        if ($period === 'year') {
            return "YEAR($alias.created_at) = YEAR(CURDATE())";
        }
        return "DATE($alias.created_at) = CURDATE()";
    };

    $dcMain = $dateCondition('s');
    $dcItems = $dateCondition('s2');

    $ownOnly  = !$auth['is_admin'];
    $scopeMain  = $ownOnly ? " AND s.user_id = ?" : "";
    $scopeItems = $ownOnly ? " AND s2.user_id = ?" : "";

    $sql = "
        SELECT
            COUNT(DISTINCT s.id) as total_orders,
            COALESCE(SUM(s.total), 0) as total_sales,
            COALESCE((SELECT SUM(quantity) FROM sale_items si WHERE si.sale_id IN (SELECT id FROM sales s2 WHERE s2.owner_id = ? $scopeItems AND $dcItems)), 0) as total_products
        FROM sales s
        WHERE s.owner_id = ? $scopeMain AND $dcMain
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($ownOnly ? [$owner_id, $user_id, $owner_id, $user_id] : [$owner_id, $owner_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_products = (int)$stats['total_products'];
    $commission_per_product = 5; // 5 Tk per product
    $commission = $total_products * $commission_per_product;

    // The same per-account KPI grants the web dashboard honours. Passed as a
    // plain user array: mobileAuth() has already closed the session, and
    // getCurrentUser() would take the file lock back. A figure whose card is not
    // ticked is answered as 0, so the phone cannot be used to read a KPI the
    // account has not been given.
    $kpiUser = ['id' => $user_id, 'role' => $auth['role']];
    $saleKpi = $period === 'month' ? 'kpi_monthly_sale'
        : ($period === 'year' ? 'kpi_yearly_sale' : 'kpi_today_sale');
    $canSales      = hasKpiPermission($saleKpi, $kpiUser);
    $canOrders     = hasKpiPermission('kpi_total_invoices', $kpiUser);
    $canProducts   = hasKpiPermission('kpi_total_products', $kpiUser);
    $canCommission = hasKpiPermission('kpi_commission', $kpiUser);

    echo json_encode([
        'success' => true, 
        'period' => $period,
        'orders' => $canOrders ? (int)$stats['total_orders'] : 0,
        'sales' => $canSales ? (float)$stats['total_sales'] : 0,
        'products' => $canProducts ? $total_products : 0,
        'commission' => $canCommission ? $commission : 0,
        'commission_per_product' => $commission_per_product
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
