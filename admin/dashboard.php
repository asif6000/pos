<?php
/**
 * POS System - Admin Dashboard
 * Modern dashboard with complete business overview and reports
 */

require_once '../config/db.php';
startSecureSession();

// Check authentication
if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$user = getCurrentUser();
$store_id = $user['store_id'] ?? null;
$owner_id = $user['owner_id'] ?? $user['id'];
// If admin and no store assigned, they see global data.
// If specific store admin, they see only their store data.

define('PAGE_TITLE', $store_id ? 'Store Dashboard' : 'Global Dashboard');

$db = getDB();

// Get today's date range
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

// Scope helper: applies store filter to a query
function scopeSql($sql, $store_id, $owner_id) {
    if ($store_id) {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE u.store_id = ?";
        return [$sql, [$store_id]];
    }
    $sql .= " JOIN users u ON s.user_id = u.id WHERE u.owner_id = ?";
    return [$sql, [$owner_id]];
}

try {
    // ── Today's Sales ────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = $todayStart; $params[] = $todayEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $todaySales = $stmt->fetch();

    // ── Monthly Sales ────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = $monthStart; $params[] = $monthEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $monthlySales = $stmt->fetch();

    // ── All-time Sales ───────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $allTimeSales = $stmt->fetch();

    // ── Commission (৳5 per unit sold) ────────────────────────────────────────
    $commissionPerUnit = 5.00;
    $commScope = "FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN users u ON s.user_id = u.id WHERE";
    if ($store_id) {
        $commWhere = " u.store_id = ?";
        $commParams = [$store_id];
    } else {
        $commWhere = " u.owner_id = ?";
        $commParams = [$owner_id];
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0) * $commissionPerUnit as commission
        $commScope $commWhere AND s.created_at BETWEEN ? AND ?");
    $stmt->execute(array_merge($commParams, [$todayStart, $todayEnd]));
    $commissionToday = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0) * $commissionPerUnit as commission
        $commScope $commWhere");
    $stmt->execute($commParams);
    $commissionAllTime = (float)$stmt->fetchColumn();

    // ── Yesterday's Sales (for comparison) ───────────────────────────────────
    $yStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $yEnd = date('Y-m-d 23:59:59', strtotime('-1 day'));
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total FROM sales s", $store_id, $owner_id);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = $yStart; $params[] = $yEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $yesterdayTotal = (float)$stmt->fetchColumn();

    // ── Sales by Store (Today) ───────────────────────────────────────────────
    $isSuper = ($user['role'] === 'admin' && empty($user['owner_id']));
    $params = [];
    $sql = "SELECT st.id as store_id, st.name as store_name, COUNT(s.id) as sale_count, COALESCE(SUM(s.total), 0) as total
            FROM stores st
            LEFT JOIN users u ON u.store_id = st.id
            LEFT JOIN sales s ON s.user_id = u.id
            WHERE 1=1";
    if (!$isSuper) { $sql .= " AND st.owner_id = ?"; $params[] = $owner_id; }
    if ($store_id) { $sql .= " AND st.id = ?"; $params[] = $store_id; }
    $sql .= " AND s.created_at BETWEEN ? AND ? GROUP BY st.id, st.name ORDER BY total DESC";
    $params[] = $todayStart; $params[] = $todayEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSales = $stmt->fetchAll();

    // ── Sales by Store (This Month + All-time) for complete store-wise report ─
    $params = [];
    $sql = "SELECT st.id as store_id, st.name as store_name,
            COUNT(s.id) as sale_count, COALESCE(SUM(s.total), 0) as total
            FROM stores st
            LEFT JOIN users u ON u.store_id = st.id
            LEFT JOIN sales s ON s.user_id = u.id
            WHERE 1=1";
    if (!$isSuper) { $sql .= " AND st.owner_id = ?"; $params[] = $owner_id; }
    if ($store_id) { $sql .= " AND st.id = ?"; $params[] = $store_id; }
    $sql .= " AND s.created_at BETWEEN ? AND ? GROUP BY st.id, st.name ORDER BY total DESC";
    $params[] = $monthStart; $params[] = $monthEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSalesMonth = $stmt->fetchAll();

    $params = [];
    $sql = "SELECT st.id as store_id, st.name as store_name,
            COUNT(s.id) as sale_count, COALESCE(SUM(s.total), 0) as total
            FROM stores st
            LEFT JOIN users u ON u.store_id = st.id
            LEFT JOIN sales s ON s.user_id = u.id
            WHERE 1=1";
    if (!$isSuper) { $sql .= " AND st.owner_id = ?"; $params[] = $owner_id; }
    if ($store_id) { $sql .= " AND st.id = ?"; $params[] = $store_id; }
    $sql .= " GROUP BY st.id, st.name ORDER BY total DESC";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSalesAll = $stmt->fetchAll();

    // ── Total Products ───────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM products p JOIN store_stocks ss ON p.id = ss.product_id JOIN stores st ON ss.store_id = st.id WHERE st.owner_id = ? AND p.status = 'active'");
    $stmt->execute([$owner_id]);
    $totalProducts = $stmt->fetch()['count'];

    // ── Total Categories ─────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM categories WHERE status = 'active' AND owner_id = ?");
    $stmt->execute([$owner_id]);
    $totalCategories = $stmt->fetch()['count'];

    // ── Total Customers ──────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM customers WHERE owner_id = ?");
    $stmt->execute([$owner_id]);
    $totalCustomers = $stmt->fetch()['count'];

    // ── Total Stores ─────────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM stores WHERE status = 'active' AND owner_id = ?");
    $stmt->execute([$owner_id]);
    $totalStores = $stmt->fetch()['count'];

    // ── Total Staff & Salary Due ─────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM staff WHERE owner_id = ? AND status = 'active'");
    $stmt->execute([$owner_id]);
    $totalStaff = $stmt->fetch()['count'];

    // ── Low Stock Products ───────────────────────────────────────────────────
    if ($store_id) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM store_stocks ss JOIN products p ON ss.product_id = p.id WHERE ss.store_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active'");
        $stmt->execute([$store_id]);
        $lowStockCount = $stmt->fetch()['count'];
        $stmt = $db->prepare("SELECT p.id, p.name, ss.quantity as stock, p.min_stock FROM store_stocks ss JOIN products p ON ss.product_id = p.id WHERE ss.store_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active' ORDER BY ss.quantity ASC LIMIT 6");
        $stmt->execute([$store_id]);
        $lowStockProducts = $stmt->fetchAll();
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM store_stocks ss JOIN products p ON ss.product_id = p.id JOIN stores st ON ss.store_id = st.id WHERE st.owner_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active'");
        $stmt->execute([$owner_id]);
        $lowStockCount = $stmt->fetch()['count'];
        $stmt = $db->prepare("SELECT p.id, p.name, SUM(ss.quantity) as stock, p.min_stock
                                FROM store_stocks ss
                                JOIN products p ON ss.product_id = p.id
                                JOIN stores st ON ss.store_id = st.id
                                WHERE st.owner_id = ? AND p.status = 'active'
                                GROUP BY p.id
                                HAVING stock <= p.min_stock
                                ORDER BY stock ASC LIMIT 6");
        $stmt->execute([$owner_id]);
        $lowStockProducts = $stmt->fetchAll();
    }

    // ── Recent Sales ─────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id", $store_id, $owner_id);
    $sql .= " ORDER BY s.created_at DESC LIMIT 10";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $recentSales = $stmt->fetchAll();

    // ── Top Selling Products (This Month) ────────────────────────────────────
    $sql = "SELECT p.name, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        JOIN sales s ON si.sale_id = s.id";
    $params = [];
    if ($store_id) {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE u.store_id = ? AND s.created_at BETWEEN ? AND ?";
        $params[] = $store_id;
    } else {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE u.owner_id = ? AND s.created_at BETWEEN ? AND ?";
        $params[] = $owner_id;
    }
    $params[] = $monthStart; $params[] = $monthEnd;
    $sql .= " GROUP BY p.id ORDER BY total_qty DESC LIMIT 6";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $topProducts = $stmt->fetchAll();

    // ── Payment method breakdown (This Month) ────────────────────────────────
    list($sql, $params) = scopeSql("SELECT s.payment_method, COUNT(s.id) as count, COALESCE(SUM(s.total), 0) as total FROM sales s", $store_id, $owner_id);
    $sql .= " AND s.created_at BETWEEN ? AND ? GROUP BY s.payment_method ORDER BY total DESC";
    $params[] = $monthStart; $params[] = $monthEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $paymentStats = $stmt->fetchAll();

    // ── Last 30 days sales (chart) ───────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT DATE(s.created_at) as sale_date, COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id);
    $sql .= " AND s.created_at >= ? GROUP BY DATE(s.created_at) ORDER BY sale_date";
    $params[] = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $chartRows = $stmt->fetchAll();
    $chartData = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $chartData[$d] = ['total' => 0, 'count' => 0];
    }
    foreach ($chartRows as $row) {
        $chartData[$row['sale_date']] = ['total' => (float)$row['total'], 'count' => (int)$row['count']];
    }

    // ── Cashbook summary ─────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out
        FROM cashbook_entries ce WHERE ce.owner_id = ?");
    $stmt->execute([$owner_id]);
    $cashTotals = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out
        FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.created_at BETWEEN ? AND ?");
    $stmt->execute([$owner_id, $todayStart, $todayEnd]);
    $cashToday = $stmt->fetch();

    // ── Recent cashbook entries ──────────────────────────────────────────────
    $stmt = $db->prepare("SELECT ce.*, u.name as user_name FROM cashbook_entries ce LEFT JOIN users u ON ce.user_id = u.id WHERE ce.owner_id = ? ORDER BY ce.created_at DESC, ce.id DESC LIMIT 6");
    $stmt->execute([$owner_id]);
    $recentCash = $stmt->fetchAll();

    // ── Staff salary overview ────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT s.name, s.salary, s.salary_type,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id), 0) as total_paid
        FROM staff s WHERE s.owner_id = ? AND s.status = 'active' ORDER BY s.created_at DESC LIMIT 5");
    $stmt->execute([$monthStart, $monthEnd, $owner_id]);
    $staffOverview = $stmt->fetchAll();

    // ── Salary balance (total due for the period across all active staff) ────
    if ($isSuper) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(salary), 0) FROM staff WHERE status = 'active'");
        $stmt->execute();
        $salaryTotals = ['total_salary' => (float)$stmt->fetchColumn(), 'paid_period' => 0];

        $stmt = $db->prepare("SELECT COALESCE(SUM(sp.amount), 0)
            FROM staff_payments sp JOIN staff s ON sp.staff_id = s.id
            WHERE sp.payment_date BETWEEN ? AND ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $salaryTotals['paid_period'] = (float)$stmt->fetchColumn();
    } else {
        $stmt = $db->prepare("SELECT COALESCE(SUM(salary), 0) as total_salary
            FROM staff WHERE owner_id = ? AND status = 'active'");
        $stmt->execute([$owner_id]);
        $salaryTotals = ['total_salary' => (float)$stmt->fetchColumn(), 'paid_period' => 0];

        $stmt = $db->prepare("SELECT COALESCE(SUM(sp.amount), 0)
            FROM staff_payments sp JOIN staff s ON sp.staff_id = s.id
            WHERE s.owner_id = ? AND sp.payment_date BETWEEN ? AND ?");
        $stmt->execute([$owner_id, $monthStart, $monthEnd]);
        $salaryTotals['paid_period'] = (float)$stmt->fetchColumn();
    }
    $salaryBalance = max(0, $salaryTotals['total_salary'] - $salaryTotals['paid_period']);

    $netCash = $allTimeSales['total'] - $cashTotals['cash_out'];

} catch (PDOException $e) {
    $error = 'Error loading dashboard data: ' . $e->getMessage();
}

// Comparisons
$todayTotal = (float)$todaySales['total'];
$todayDiff = $yesterdayTotal > 0 ? round(($todayTotal - $yesterdayTotal) / $yesterdayTotal * 100) : 0;

include 'includes/header.php';
?>

<style>
    .dash-hero {
        position: relative;
        border-radius: 1.25rem;
        padding: 2rem 2.25rem;
        margin-bottom: 1.75rem;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
        color: #fff;
        overflow: hidden;
        box-shadow: 0 20px 40px -12px rgba(15, 23, 42, 0.55);
    }
    .dash-hero::before {
        content: '';
        position: absolute;
        top: -90px; right: -70px;
        width: 300px; height: 300px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(59,130,246,0.35) 0%, transparent 70%);
    }
    .dash-hero::after {
        content: '';
        position: absolute;
        bottom: -120px; left: -60px;
        width: 260px; height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(16,185,129,0.3) 0%, transparent 70%);
    }
    .dash-hero-top {
        display: flex;
        align-items: center;
        gap: 1rem;
        position: relative;
        z-index: 1;
        flex-wrap: wrap;
    }
    .dash-hero-title {
        font-size: 1.5rem;
        font-weight: 800;
    }
    .dash-hero-sub {
        font-size: 0.85rem;
        opacity: 0.75;
        margin-top: 0.2rem;
    }
    .dash-hero-date {
        margin-left: auto;
        background: rgba(255,255,255,0.14);
        padding: 0.45rem 1.1rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        position: relative;
        z-index: 1;
    }
    .dash-hero-bottom {
        margin-top: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        position: relative;
        z-index: 1;
    }
    .hero-kpi {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.16);
        border-radius: 1rem;
        padding: 1.1rem 1.25rem;
        backdrop-filter: blur(4px);
    }
    .hero-kpi-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        opacity: 0.75;
        margin-bottom: 0.3rem;
    }
    .hero-kpi-value {
        font-size: 1.4rem;
        font-weight: 800;
    }
    .hero-kpi-sub {
        font-size: 0.75rem;
        opacity: 0.85;
        margin-top: 0.15rem;
    }
    .hero-kpi-sub .up { color: #34d399; font-weight: 700; }
    .hero-kpi-sub .down { color: #f87171; font-weight: 700; }

    .m-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }
    .m-stat {
        background: #fff;
        border-radius: 1.1rem;
        padding: 1.4rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .m-stat:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
    }
    .m-icon {
        width: 52px; height: 52px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .m-icon.green  { background: #ecfdf5; color: #059669; }
    .m-icon.blue   { background: #eff6ff; color: #2563eb; }
    .m-icon.indigo { background: #eef2ff; color: #4f46e5; }
    .m-icon.amber  { background: #fffbeb; color: #d97706; }
    .m-icon.rose   { background: #fef2f2; color: #dc2626; }
    .m-icon.gold   { background: #fefce8; color: #b45309; }
    .m-icon.violet { background: #f5f3ff; color: #7c3aed; }
    .m-icon.cyan   { background: #ecfeff; color: #0891b2; }
    .m-label {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.3rem;
    }
    .m-value {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
    }
    .m-sub {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: 0.25rem;
    }

    .grid-2col { display: grid; grid-template-columns: 1.6fr 1fr; gap: 1.25rem; margin-bottom: 1.75rem; }
    .grid-2col-eq { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.75rem; }
    @media (max-width: 900px) {
        .grid-2col, .grid-2col-eq { grid-template-columns: 1fr; }
    }

    .panel {
        background: #fff;
        border-radius: 1.1rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        overflow: hidden;
    }
    .panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .panel-head h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
    }
    .panel-body { padding: 1.5rem; }
    .panel-body.no-pad { padding: 0; }

    /* Chart */
    .chart-bars {
        display: flex;
        align-items: flex-end;
        gap: 6px;
        height: 160px;
        margin-top: 1rem;
    }
    .chart-col {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        height: 100%;
        justify-content: flex-end;
        min-width: 0;
    }
    .chart-bar {
        width: 100%;
        border-radius: 6px 6px 2px 2px;
        background: linear-gradient(180deg, #4f46e5, #818cf8);
        min-height: 3px;
        transition: height .5s ease;
    }
    .chart-col:hover .chart-bar { background: linear-gradient(180deg, #0f172a, #334155); }
    .chart-col.today .chart-bar { background: linear-gradient(180deg, #10b981, #34d399); }
    .chart-val { font-size: 0.62rem; font-weight: 700; color: #64748b; }
    .chart-label { font-size: 0.66rem; color: #94a3b8; }

    /* Payment method pills */
    .pay-stat {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .pay-stat:last-child { border-bottom: none; }
    .pay-icon {
        width: 40px; height: 40px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .pay-bar { flex: 1; }
    .pay-bar-track {
        height: 8px;
        border-radius: 999px;
        background: #f1f5f9;
        overflow: hidden;
        margin-top: 4px;
    }
    .pay-bar-fill { height: 100%; border-radius: 999px; }

    .list-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .list-row:last-child { border-bottom: none; }
    .list-row .lr-main { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
    .list-row .lr-amt { font-weight: 700; white-space: nowrap; }

    .mini-num { font-size: 0.75rem; color: #94a3b8; }

    .badge-pay { font-size: 0.72rem; padding: 0.25rem 0.6rem; border-radius: 999px; font-weight: 700; }
</style>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo $error; ?></span>
    </div>
<?php endif; ?>

<!-- Hero -->
<div class="dash-hero">
    <div class="dash-hero-top">
        <div>
            <div class="dash-hero-title">
                <?php echo $store_id ? 'Store Dashboard' : 'Business Overview'; ?>
            </div>
            <div class="dash-hero-sub">
                <i class="fas fa-store"></i>
                <?php echo $store_id ? 'Viewing a single store' : 'Viewing all your stores'; ?>
                &nbsp;&middot;&nbsp; Welcome back, <?php echo sanitize($user['name']); ?>
            </div>
        </div>
        <span class="dash-hero-date">
            <i class="far fa-calendar-alt"></i> <?php echo date('l, d M Y'); ?>
        </span>
    </div>
    <div class="dash-hero-bottom">
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-shopping-bag"></i> Today's Sales</div>
            <div class="hero-kpi-value" style="color:#4ade80;"><?php echo formatCurrency($todaySales['total']); ?></div>
            <div class="hero-kpi-sub">
                <?php if ($todayDiff >= 0): ?>
                    <span class="up"><i class="fas fa-arrow-up"></i> <?php echo $todayDiff; ?>%</span> vs yesterday
                <?php else: ?>
                    <span class="down"><i class="fas fa-arrow-down"></i> <?php echo abs($todayDiff); ?>%</span> vs yesterday
                <?php endif; ?>
                &middot; <?php echo $todaySales['count']; ?> transactions
            </div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-chart-line"></i> Monthly Sales</div>
            <div class="hero-kpi-value"><?php echo formatCurrency($monthlySales['total']); ?></div>
            <div class="hero-kpi-sub"><?php echo date('F Y'); ?> &middot; <?php echo $monthlySales['count']; ?> transactions</div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-history"></i> All-Time Sales</div>
            <div class="hero-kpi-value"><?php echo formatCurrency($allTimeSales['total']); ?></div>
            <div class="hero-kpi-sub"><?php echo $allTimeSales['count']; ?> total transactions</div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-hand-holding-usd"></i> Net (Sales - Expenses)</div>
            <div class="hero-kpi-value" style="<?php echo $netCash < 0 ? 'color:#f87171;' : ''; ?>"><?php echo formatCurrency($netCash); ?></div>
            <div class="hero-kpi-sub">All-time sales minus expenses</div>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="m-stats">
    <div class="m-stat">
        <div class="m-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="m-label">Today's Sales</div>
            <div class="m-value" style="color:#059669;"><?php echo formatCurrency($todaySales['total']); ?></div>
            <div class="m-sub"><?php echo $todaySales['count']; ?> transactions</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon indigo"><i class="fas fa-chart-line"></i></div>
        <div>
            <div class="m-label">Monthly Sales</div>
            <div class="m-value" style="color:#111827;"><?php echo formatCurrency($monthlySales['total']); ?></div>
            <div class="m-sub"><?php echo $monthlySales['count']; ?> transactions this month</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon red"><i class="fas fa-arrow-up"></i></div>
        <div>
            <div class="m-label">Expenses</div>
            <div class="m-value" style="color:#dc2626;"><?php echo formatCurrency($cashToday['cash_out']); ?></div>
            <div class="m-sub"><?php echo formatCurrency($cashTotals['cash_out']); ?> total</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon <?php echo $netCash >= 0 ? 'green' : 'red'; ?>"><i class="fas fa-hand-holding-usd"></i></div>
        <div>
            <div class="m-label">Net Balance</div>
            <div class="m-value" style="color:<?php echo $netCash >= 0 ? '' : '#dc2626'; ?>;"><?php echo formatCurrency($netCash); ?></div>
            <div class="m-sub">Sales - Expenses</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon gold"><i class="fas fa-coins"></i></div>
        <div>
            <div class="m-label">Commission</div>
            <div class="m-value" style="color:#b45309;"><?php echo formatCurrency($commissionToday); ?></div>
            <div class="m-sub">৳5/pcs · <?php echo formatCurrency($commissionAllTime); ?> total</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon blue"><i class="fas fa-box"></i></div>
        <div>
            <div class="m-label">Active Products</div>
            <div class="m-value"><?php echo $totalProducts; ?></div>
            <div class="m-sub"><?php echo $totalCategories; ?> categories</div>
        </div>
    </div>
    
    <div class="m-stat">
        <div class="m-icon cyan"><i class="fas fa-users"></i></div>
        <div>
            <div class="m-label">Customers</div>
            <div class="m-value"><?php echo $totalCustomers; ?></div>
            <div class="m-sub"><?php echo $totalStores; ?> active stores</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon amber"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="m-label">Staff Members</div>
            <div class="m-value"><?php echo $totalStaff; ?></div>
            <div class="m-sub">Active staff</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon violet"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="m-label">Salary Balance</div>
            <div class="m-value" style="color:#7c3aed;"><?php echo formatCurrency($salaryBalance); ?></div>
            <div class="m-sub"><?php echo formatCurrency($salaryTotals['paid_period']); ?> paid this month</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon rose"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
            <div class="m-label">Low Stock Alerts</div>
            <div class="m-value"><?php echo $lowStockCount; ?></div>
            <div class="m-sub">Products need restock</div>
        </div>
    </div>
</div>

<!-- Sales chart + Payment methods -->
<div class="grid-2col">
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-chart-area" style="color:#4f46e5; margin-right:0.5rem;"></i>Sales — Last 30 Days</h3>
            <span class="badge badge-primary"><?php echo formatCurrency(array_sum(array_column($chartData, 'total'))); ?></span>
        </div>
        <div class="panel-body">
            <?php
            $chartMax = max(array_column($chartData, 'total')) ?: 1;
            $todayD = date('Y-m-d');
            ?>
            <div class="chart-bars">
                <?php foreach ($chartData as $d => $cd): ?>
                    <?php
                    $h = max(3, round(((float)$cd['total'] / $chartMax) * 100));
                    $label = date('d', strtotime($d));
                    ?>
                    <div class="chart-col <?php echo $d === $todayD ? 'today' : ''; ?>" title="<?php echo date('d M', strtotime($d)) . ': ' . formatCurrency($cd['total']); ?>">
                        <span class="chart-val"><?php echo (float)$cd['total'] > 0 ? number_format((float)$cd['total'] / 1000, 1) . 'k' : ''; ?></span>
                        <div class="chart-bar" style="height: <?php echo $h; ?>%;"></div>
                        <span class="chart-label"><?php echo $label; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-credit-card" style="color:#059669; margin-right:0.5rem;"></i>Payment Methods</h3>
            <span class="badge badge-success"><?php echo date('M'); ?></span>
        </div>
        <div class="panel-body">
            <?php
            $payMax = 0;
            foreach ($paymentStats as $ps) { $payMax = max($payMax, (float)$ps['total']); }
            $payColors = [
                'cash'   => ['#10b981', '#ecfdf5'],
                'bkash'  => ['#ec4899', '#fdf2f8'],
                'nagad'  => ['#f59e0b', '#fffbeb'],
                'rocket' => ['#8b5cf6', '#f5f3ff'],
                'card'   => ['#3b82f6', '#eff6ff'],
                'bank'   => ['#0ea5e9', '#ecfeff'],
            ];
            if (empty($paymentStats)): ?>
                <p class="text-muted text-center">No sales this month yet.</p>
            <?php else: ?>
                <?php foreach ($paymentStats as $ps): ?>
                    <?php
                    $pc = $payColors[$ps['payment_method']] ?? ['#64748b', '#f1f5f9'];
                    $pct = $payMax > 0 ? round(((float)$ps['total'] / $payMax) * 100) : 0;
                    ?>
                    <div class="pay-stat">
                        <div class="pay-icon" style="background: <?php echo $pc[1]; ?>; color: <?php echo $pc[0]; ?>;">
                            <i class="fas <?php echo $ps['payment_method'] === 'cash' ? 'fa-money-bill-wave' : ($ps['payment_method'] === 'bkash' || $ps['payment_method'] === 'nagad' || $ps['payment_method'] === 'rocket' ? 'fa-mobile-alt' : 'fa-credit-card'); ?>"></i>
                        </div>
                        <div class="pay-bar">
                            <div style="display:flex; justify-content:space-between; font-size:0.82rem;">
                                <strong style="text-transform:capitalize;"><?php echo $ps['payment_method']; ?></strong>
                                <span class="mini-num"><?php echo $ps['count']; ?> txns</span>
                            </div>
                            <div class="pay-bar-track">
                                <div class="pay-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $pc[0]; ?>;"></div>
                            </div>
                        </div>
                        <div class="lr-amt"><?php echo formatCurrency($ps['total']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sales by Store + Daily report -->
<?php
$grandTotal = array_sum(array_column($storeSales, 'total'));
$grandCount = array_sum(array_column($storeSales, 'sale_count'));
$storeMonthTotal = array_sum(array_column($storeSalesMonth, 'total'));
$storeAllTotal = array_sum(array_column($storeSalesAll, 'total'));
$allStoreNames = [];
foreach (array_merge($storeSales, $storeSalesMonth, $storeSalesAll) as $ss) {
    $allStoreNames[$ss['store_id']] = $ss['store_name'];
}
$avatarColors = [
    'linear-gradient(135deg, #3b82f6, #1d4ed8)',
    'linear-gradient(135deg, #10b981, #047857)',
    'linear-gradient(135deg, #f59e0b, #d97706)',
    'linear-gradient(135deg, #ef4444, #b91c1c)',
    'linear-gradient(135deg, #8b5cf6, #6d28d9)',
    'linear-gradient(135deg, #06b6d4, #0e7490)',
    'linear-gradient(135deg, #ec4899, #be185d)',
    'linear-gradient(135deg, #64748b, #334155)'
];

// Merge today/month/all into a single per-store row
function storeRow($id, $name, $today, $month, $all) {
    $t = null; foreach ($today as $r) { if ($r['store_id'] == $id) $t = $r; }
    $m = null; foreach ($month as $r) { if ($r['store_id'] == $id) $m = $r; }
    $a = null; foreach ($all as $r) { if ($r['store_id'] == $id) $a = $r; }
    return [
        'store_id' => $id,
        'store_name' => $name,
        'today'  => $t ? (float)$t['total'] : 0,
        'today_count'  => $t ? (int)$t['sale_count'] : 0,
        'month'  => $m ? (float)$m['total'] : 0,
        'month_count'  => $m ? (int)$m['sale_count'] : 0,
        'all'    => $a ? (float)$a['total'] : 0,
        'all_count'    => $a ? (int)$a['sale_count'] : 0,
    ];
}
$storeRows = [];
foreach ($allStoreNames as $sid => $sname) {
    $storeRows[] = storeRow($sid, $sname, $storeSales, $storeSalesMonth, $storeSalesAll);
}
usort($storeRows, function ($x, $y) { return $y['all'] <=> $x['all']; });
?>
<div class="panel" style="margin-bottom:1.75rem;">
    <div class="panel-head">
        <h3><i class="fas fa-store" style="color:#f59e0b; margin-right:0.5rem;"></i>Store-Wise Sales</h3>
        <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
            <span class="badge badge-warning">Today: <?php echo formatCurrency($grandTotal); ?></span>
            <span class="badge badge-primary">This Month: <?php echo formatCurrency($storeMonthTotal); ?></span>
            <span class="badge badge-success">All-Time: <?php echo formatCurrency($storeAllTotal); ?></span>
        </div>
    </div>
    <div class="panel-body no-pad">
        <?php if (empty($storeRows)): ?>
            <p class="text-muted text-center" style="padding:1.5rem;">
                No stores yet. <a href="stores.php">Create a store</a> to start tracking store-wise sales.
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th>Today</th>
                            <th>This Month</th>
                            <th>All-Time</th>
                            <th>Share (All-Time)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storeRows as $i => $ss): ?>
                            <?php
                            $pct = $storeAllTotal > 0 ? round(($ss['all'] / $storeAllTotal) * 100, 1) : 0;
                            $color = $avatarColors[$i % count($avatarColors)];
                            ?>
                            <tr>
                                <td>
                                    <div class="lr-main">
                                        <span style="width:10px; height:10px; border-radius:50%; background:<?php echo $color; ?>; flex-shrink:0;"></span>
                                        <strong><?php echo sanitize($ss['store_name']); ?></strong>
                                        <?php if ($i === 0): ?><i class="fas fa-crown" style="color:#f59e0b;" title="Top Store"></i><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($ss['today']); ?></strong>
                                    <div class="mini-num"><?php echo $ss['today_count']; ?> txns</div>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($ss['month']); ?></strong>
                                    <div class="mini-num"><?php echo $ss['month_count']; ?> txns</div>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($ss['all']); ?></strong>
                                    <div class="mini-num"><?php echo $ss['all_count']; ?> txns</div>
                                </td>
                                <td style="width:160px;">
                                    <div class="pay-bar-track">
                                        <div class="pay-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>;"></div>
                                    </div>
                                    <div class="mini-num" style="margin-top:3px;"><?php echo $pct; ?>% of total</div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Top Products -->
<div class="panel" style="margin-bottom:1.75rem;">
    <div class="panel-head">
        <h3><i class="fas fa-star" style="color:#f59e0b; margin-right:0.5rem;"></i>Top Products</h3>
        <span class="badge badge-warning"><?php echo date('M Y'); ?></span>
    </div>
    <div class="panel-body">
        <?php if (empty($topProducts)): ?>
            <p class="text-muted text-center">No sales data yet.</p>
        <?php else: ?>
            <?php
            $topMax = max(array_column($topProducts, 'total_revenue')) ?: 1;
            ?>
            <div class="grid-2col-eq" style="margin-bottom:0;">
                <div>
                    <?php foreach ($topProducts as $index => $product): ?>
                        <?php if ($index >= 3) break; ?>
                        <div class="list-row">
                            <div class="lr-main">
                                <span class="badge badge-primary" style="width:26px; text-align:center;"><?php echo $index + 1; ?></span>
                                <span>
                                    <strong style="font-size:0.88rem;"><?php echo sanitize($product['name']); ?></strong>
                                    <div class="mini-num"><?php echo $product['total_qty']; ?> sold</div>
                                </span>
                            </div>
                            <div class="lr-amt"><?php echo formatCurrency($product['total_revenue']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <?php foreach ($topProducts as $index => $product): ?>
                        <?php if ($index < 3) continue; ?>
                        <?php if ($index >= 6) break; ?>
                        <div class="list-row">
                            <div class="lr-main">
                                <span class="badge badge-primary" style="width:26px; text-align:center;"><?php echo $index + 1; ?></span>
                                <span>
                                    <strong style="font-size:0.88rem;"><?php echo sanitize($product['name']); ?></strong>
                                    <div class="mini-num"><?php echo $product['total_qty']; ?> sold</div>
                                </span>
                            </div>
                            <div class="lr-amt"><?php echo formatCurrency($product['total_revenue']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Sales + Low Stock + Cashbook -->
<div class="grid-2col">
    <div class="panel">
        <div class="panel-head">
            <h3><i class="fas fa-receipt" style="color:#3b82f6; margin-right:0.5rem;"></i>Recent Sales</h3>
            <a href="sales.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <div class="panel-body no-pad">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSales)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No sales yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td><strong><?php echo sanitize($sale['invoice_number']); ?></strong></td>
                                    <td><?php echo sanitize($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td><?php echo formatCurrency($sale['total']); ?></td>
                                    <td>
                                        <span class="badge-pay" style="background:<?php echo ($payColors[$sale['payment_method']] ?? ['#64748b','#f1f5f9'])[1]; ?>; color:<?php echo ($payColors[$sale['payment_method']] ?? ['#64748b','#f1f5f9'])[0]; ?>;">
                                            <?php echo ucfirst($sale['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d M, h:i A', strtotime($sale['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="display:flex; flex-direction:column; gap:1.25rem;">
        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444; margin-right:0.5rem;"></i>Low Stock</h3>
                <span class="badge badge-danger"><?php echo $lowStockCount; ?></span>
            </div>
            <div class="panel-body">
                <?php if (empty($lowStockProducts)): ?>
                    <p class="text-muted text-center">All products have sufficient stock</p>
                <?php else: ?>
                    <?php foreach ($lowStockProducts as $product): ?>
                        <div class="list-row">
                            <div class="lr-main">
                                <span class="mini-num"><i class="fas fa-box"></i></span>
                                <strong style="font-size:0.88rem;"><?php echo sanitize($product['name']); ?></strong>
                            </div>
                            <span class="badge badge-danger"><?php echo $product['stock']; ?> left</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h3><i class="fas fa-book" style="color:#0891b2; margin-right:0.5rem;"></i>Expense</h3>
                <a href="expense.php" class="btn btn-sm btn-outline">Open</a>
            </div>
            <div class="panel-body">
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0.75rem; margin-bottom:1rem;">
                    <div style="background:#ecfdf5; border-radius:0.75rem; padding:0.7rem; text-align:center;">
                        <div class="mini-num">Sales</div>
                        <strong style="color:#059669;"><?php echo formatCurrency($allTimeSales['total']); ?></strong>
                    </div>
                    <div style="background:#fef2f2; border-radius:0.75rem; padding:0.7rem; text-align:center;">
                        <div class="mini-num">Expenses</div>
                        <strong style="color:#dc2626;"><?php echo formatCurrency($cashTotals['cash_out']); ?></strong>
                    </div>
                    <div style="background:#eff6ff; border-radius:0.75rem; padding:0.7rem; text-align:center;">
                        <div class="mini-num">Balance</div>
                        <strong style="color:#2563eb;"><?php echo formatCurrency($netCash); ?></strong>
                    </div>
                </div>
                <?php if (!empty($recentCash)): ?>
                    <?php foreach ($recentCash as $ce): ?>
                        <div class="list-row">
                            <div class="lr-main">
                                <span class="badge badge-<?php echo $ce['type'] === 'cash_in' ? 'success' : 'danger'; ?>">
                                    <i class="fas fa-<?php echo $ce['type'] === 'cash_in' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                </span>
                                <span>
                                    <strong style="font-size:0.85rem;"><?php echo $ce['type'] === 'cash_in' ? 'Cash In' : sanitize($ce['note'] ?: 'Expense'); ?></strong>
                                    <div class="mini-num"><?php echo date('d M, h:i A', strtotime($ce['created_at'])); ?></div>
                                </span>
                            </div>
                            <div class="lr-amt" style="color:<?php echo $ce['type'] === 'cash_in' ? '#059669' : '#dc2626'; ?>;">
                                <?php echo $ce['type'] === 'cash_in' ? '+' : '-'; ?> <?php echo formatCurrency($ce['amount']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center">No cashbook entries yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Staff Salary Overview -->
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-head">
        <h3><i class="fas fa-user-tie" style="color:#7c3aed; margin-right:0.5rem;"></i>Staff Salary Overview</h3>
        <a href="staff.php" class="btn btn-sm btn-outline">Manage Staff</a>
    </div>
    <div class="panel-body no-pad">
        <?php if (empty($staffOverview)): ?>
            <p class="text-muted text-center" style="padding:1.5rem;">No active staff members yet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th>Salary</th>
                            <th>Type</th>
                            <th>Paid (Month)</th>
                            <th>Total Paid</th>
                            <th>Due (Month)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffOverview as $s): ?>
                            <?php $due = max(0, (float)$s['salary'] - (float)$s['paid_month']); ?>
                            <tr>
                                <td><strong><?php echo sanitize($s['name']); ?></strong></td>
                                <td><?php echo formatCurrency($s['salary']); ?></td>
                                <td><span class="badge badge-<?php echo $s['salary_type'] === 'weekly' ? 'warning' : 'primary'; ?>"><?php echo ucfirst($s['salary_type']); ?></span></td>
                                <td class="text-success"><?php echo formatCurrency($s['paid_month']); ?></td>
                                <td><?php echo formatCurrency($s['total_paid']); ?></td>
                                <td><?php if ($due > 0): ?><strong class="text-danger"><?php echo formatCurrency($due); ?></strong><?php else: ?><span class="badge badge-success">Paid</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>