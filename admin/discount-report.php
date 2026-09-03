<?php
/**
 * POS System - Discount Report
 * Shows today, monthly, and all-time discount reports with filters
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Discount Report');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'];
$store_id = $user['store_id'] ?? null;

// Filter parameters
$filter = sanitize($_GET['filter'] ?? 'today');
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));
$filterMonth = sanitize($_GET['month'] ?? date('Y-m'));
$filterYear = sanitize($_GET['year'] ?? date('Y'));

// Determine date range based on filter
$today = date('Y-m-d');
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');
$yearStart = date('Y-01-01 00:00:00');
$yearEnd = date('Y-12-31 23:59:59');

switch ($filter) {
    case 'today':
        $queryFrom = $todayStart;
        $queryTo = $todayEnd;
        $periodLabel = 'Today (' . date('d M Y') . ')';
        break;
    case 'month':
        $y = substr($filterMonth, 0, 4);
        $m = substr($filterMonth, 5, 2);
        $queryFrom = date('Y-m-01 00:00:00', strtotime("$y-$m-01"));
        $queryTo = date('Y-m-t 23:59:59', strtotime("$y-$m-01"));
        $periodLabel = date('F Y', strtotime("$y-$m-01"));
        break;
    case 'year':
        $queryFrom = "$filterYear-01-01 00:00:00";
        $queryTo = "$filterYear-12-31 23:59:59";
        $periodLabel = "Year $filterYear";
        break;
    case 'custom':
        $queryFrom = $dateFrom . ' 00:00:00';
        $queryTo = $dateTo . ' 23:59:59';
        $periodLabel = date('d M Y', strtotime($dateFrom)) . ' - ' . date('d M Y', strtotime($dateTo));
        break;
    default:
        $queryFrom = $todayStart;
        $queryTo = $todayEnd;
        $periodLabel = 'Today';
}

// Scope helper
function buildWhereClause($ownerId, $store_id = null) {
    if ($store_id) {
        return ["JOIN users u ON s.user_id = u.id WHERE u.store_id = ?", [$store_id]];
    }
    return ["JOIN users u ON s.user_id = u.id WHERE u.owner_id = ?", [$ownerId]];
}

try {
    // ── Summary Stats ───────────────────────────────────────────────────────
    // Today's Discount
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal, COUNT(s.id) as sale_count FROM sales s $where AND s.created_at BETWEEN ? AND ?");
    $stmt->execute(array_merge($params, [$todayStart, $todayEnd]));
    $todayDiscount = $stmt->fetch();

    // Monthly Discount (current month)
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal, COUNT(s.id) as sale_count FROM sales s $where AND s.created_at BETWEEN ? AND ?");
    $stmt->execute(array_merge($params, [$monthStart, $monthEnd]));
    $monthlyDiscount = $stmt->fetch();

    // All-Time Discount
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal, COUNT(s.id) as sale_count FROM sales s $where");
    $stmt->execute($params);
    $allTimeDiscount = $stmt->fetch();

    // ── Filtered Period Summary ──────────────────────────────────────────────
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal, COALESCE(SUM(s.total), 0) as total, COUNT(s.id) as sale_count FROM sales s $where AND s.created_at BETWEEN ? AND ?");
    $stmt->execute(array_merge($params, [$queryFrom, $queryTo]));
    $periodSummary = $stmt->fetch();

    // ── Discount Breakdown by Payment Method (filtered period) ──────────────
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT s.payment_method, COUNT(s.id) as count, COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal FROM sales s $where AND s.created_at BETWEEN ? AND ? AND s.discount_amount > 0 GROUP BY s.payment_method ORDER BY total_discount DESC");
    $stmt->execute(array_merge($params, [$queryFrom, $queryTo]));
    $paymentDiscounts = $stmt->fetchAll();

    // ── Discount Breakdown by Store (filtered period) ──────────────────────
    if ($store_id) {
        $stmt = $db->prepare("SELECT u.store_id, st.name as store_name, COUNT(s.id) as count, COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal FROM sales s JOIN users u ON s.user_id = u.id LEFT JOIN stores st ON u.store_id = st.id WHERE u.store_id = ? AND s.created_at BETWEEN ? AND ? AND s.discount_amount > 0 GROUP BY u.store_id, st.name ORDER BY total_discount DESC");
        $stmt->execute([$store_id, $queryFrom, $queryTo]);
    } else {
        $stmt = $db->prepare("SELECT u.store_id, st.name as store_name, COUNT(s.id) as count, COALESCE(SUM(s.discount_amount), 0) as total_discount, COALESCE(SUM(s.subtotal), 0) as subtotal FROM sales s JOIN users u ON s.user_id = u.id LEFT JOIN stores st ON u.store_id = st.id WHERE u.owner_id = ? AND s.created_at BETWEEN ? AND ? AND s.discount_amount > 0 GROUP BY u.store_id, st.name ORDER BY total_discount DESC");
        $stmt->execute([$ownerId, $queryFrom, $queryTo]);
    }
    $storeDiscounts = $stmt->fetchAll();

    // ── Daily Discount Trend (filtered period) ──────────────────────────────
    list($where, $params) = buildWhereClause($ownerId, $store_id);
    $stmt = $db->prepare("SELECT DATE(s.created_at) as sale_date, COALESCE(SUM(s.discount_amount), 0) as daily_discount, COUNT(s.id) as count FROM sales s $where AND s.created_at BETWEEN ? AND ? AND s.discount_amount > 0 GROUP BY DATE(s.created_at) ORDER BY sale_date");
    $stmt->execute(array_merge($params, [$queryFrom, $queryTo]));
    $dailyTrend = $stmt->fetchAll();

    // ── Discount Detail List (filtered period) ──────────────────────────────
    $detailParams = [$ownerId, $queryFrom, $queryTo];
    $detailWhere = "u.owner_id = ?";
    if ($store_id) {
        $detailParams = [$store_id, $queryFrom, $queryTo];
        $detailWhere = "u.store_id = ?";
    }
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.invoice_number,
            s.subtotal,
            s.discount_amount,
            s.discount_percent,
            s.total,
            s.payment_method,
            s.created_at,
            c.name as customer_name,
            u.name as cashier_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        JOIN users u ON s.user_id = u.id
        WHERE $detailWhere
        AND s.created_at BETWEEN ? AND ?
        AND s.discount_amount > 0
        ORDER BY s.created_at DESC
    ");
    $stmt->execute($detailParams);
    $discountDetails = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Error loading discount data: ' . $e->getMessage();
}

include 'includes/header.php';
?>

<style>
    .discount-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
        border-radius: 1.25rem;
        padding: 2rem 2.25rem;
        margin-bottom: 1.75rem;
        color: #fff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 20px 40px -12px rgba(15, 23, 42, 0.55);
    }
    .discount-hero::before {
        content: '';
        position: absolute;
        top: -90px; right: -70px;
        width: 300px; height: 300px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(239,68,68,0.35) 0%, transparent 70%);
    }
    .discount-hero::after {
        content: '';
        position: absolute;
        bottom: -120px; left: -60px;
        width: 260px; height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(249,115,22,0.3) 0%, transparent 70%);
    }
    .discount-hero-content {
        position: relative;
        z-index: 1;
    }
    .discount-hero-title {
        font-size: 1.5rem;
        font-weight: 800;
        margin-bottom: 0.3rem;
    }
    .discount-hero-sub {
        font-size: 0.85rem;
        opacity: 0.75;
    }
    .discount-hero-kpis {
        margin-top: 1.5rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
    }
    .d-hero-kpi {
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.16);
        border-radius: 1rem;
        padding: 1.1rem 1.25rem;
        backdrop-filter: blur(4px);
    }
    .d-hero-kpi-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        opacity: 0.75;
        margin-bottom: 0.3rem;
    }
    .d-hero-kpi-value {
        font-size: 1.4rem;
        font-weight: 800;
    }
    .d-hero-kpi-sub {
        font-size: 0.75rem;
        opacity: 0.85;
        margin-top: 0.15rem;
    }
    .d-period {
        background: rgba(255,255,255,0.14);
        padding: 0.45rem 1.1rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
        margin-bottom: 1rem;
    }
    .d-stat {
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
    .d-stat:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
    }
    .d-icon {
        width: 52px; height: 52px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .d-icon.red     { background: #fef2f2; color: #dc2626; }
    .d-icon.amber   { background: #fffbeb; color: #d97706; }
    .d-icon.violet  { background: #f5f3ff; color: #7c3aed; }
    .d-icon.green   { background: #ecfdf5; color: #059669; }
    .d-icon.blue    { background: #eff6ff; color: #2563eb; }
    .d-icon.cyan    { background: #ecfeff; color: #0891b2; }
    .d-label {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.3rem;
    }
    .d-value {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
    }
    .d-sub {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: 0.25rem;
    }
    .filter-bar {
        background: #fff;
        border-radius: 1rem;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
    }
    .filter-form {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    .filter-pills {
        display: flex;
        gap: 0.4rem;
        flex-wrap: wrap;
    }
    .filter-pill {
        padding: 0.45rem 1rem;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 600;
        border: 2px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        cursor: pointer;
        text-decoration: none;
        transition: all .15s ease;
    }
    .filter-pill:hover {
        border-color: #4f46e5;
        color: #4f46e5;
    }
    .filter-pill.active {
        background: #4f46e5;
        border-color: #4f46e5;
        color: #fff;
    }
    .d-grid-3 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }
    .d-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }
    @media (max-width: 900px) {
        .d-grid-2 { grid-template-columns: 1fr; }
    }
    .d-panel {
        background: #fff;
        border-radius: 1.1rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        overflow: hidden;
    }
    .d-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .d-panel-head h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
    }
    .d-bar-track {
        height: 8px;
        border-radius: 999px;
        background: #f1f5f9;
        overflow: hidden;
    }
    .d-bar-fill {
        height: 100%;
        border-radius: 999px;
        transition: width .4s ease;
    }
    .d-mini { font-size: 0.75rem; color: #94a3b8; }
    .d-list-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .d-list-row:last-child { border-bottom: none; }
</style>

<!-- Hero Section -->
<div class="discount-hero">
    <div class="discount-hero-content">
        <div class="d-period"><i class="far fa-calendar-alt"></i> <?php echo $periodLabel; ?></div>
        <div class="discount-hero-title"><i class="fas fa-percent"></i> Discount Report</div>
        <div class="discount-hero-sub">
            Complete overview of all discounts given
            &middot; Welcome back, <?php echo sanitize($user['name']); ?>
        </div>
        <div class="discount-hero-kpis">
            <div class="d-hero-kpi">
                <div class="d-hero-kpi-label"><i class="fas fa-tags"></i> Today's Discount</div>
                <div class="d-hero-kpi-value" style="color:#f87171;"><?php echo formatCurrency($todayDiscount['total_discount']); ?></div>
                <div class="d-hero-kpi-sub">Subtotal: <?php echo formatCurrency($todayDiscount['subtotal']); ?></div>
            </div>
            <div class="d-hero-kpi">
                <div class="d-hero-kpi-label"><i class="fas fa-percentage"></i> Monthly Discount</div>
                <div class="d-hero-kpi-value" style="color:#fb923c;"><?php echo formatCurrency($monthlyDiscount['total_discount']); ?></div>
                <div class="d-hero-kpi-sub"><?php echo $monthlyDiscount['sale_count']; ?> discounted sales</div>
            </div>
            <div class="d-hero-kpi">
                <div class="d-hero-kpi-label"><i class="fas fa-history"></i> All-Time Discount</div>
                <div class="d-hero-kpi-value" style="color:#c084fc;"><?php echo formatCurrency($allTimeDiscount['total_discount']); ?></div>
                <div class="d-hero-kpi-sub"><?php echo $allTimeDiscount['sale_count']; ?> total discounted sales</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar">
    <form method="GET" class="filter-form">
        <div class="filter-pills">
            <a href="?filter=today" class="filter-pill <?php echo $filter === 'today' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-day"></i> Today
            </a>
            <a href="?filter=month&month=<?php echo date('Y-m'); ?>" class="filter-pill <?php echo $filter === 'month' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> This Month
            </a>
            <a href="?filter=year&year=<?php echo date('Y'); ?>" class="filter-pill <?php echo $filter === 'year' ? 'active' : ''; ?>">
                <i class="fas fa-calendar"></i> This Year
            </a>
            <a href="?filter=custom" class="filter-pill <?php echo $filter === 'custom' ? 'active' : ''; ?>">
                <i class="fas fa-sliders-h"></i> Custom Range
            </a>
        </div>

        <?php if ($filter === 'month'): ?>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Month</label>
            <input type="month" name="month" class="form-control" value="<?php echo $filterMonth; ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-sync"></i> Filter</button>
        <?php endif; ?>

        <?php if ($filter === 'year'): ?>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Year</label>
            <input type="number" name="year" class="form-control" value="<?php echo $filterYear; ?>" min="2020" max="<?php echo date('Y'); ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-sync"></i> Filter</button>
        <?php endif; ?>

        <?php if ($filter === 'custom'): ?>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">From</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">To</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-sync"></i> Filter</button>
        <?php endif; ?>
    </form>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo $error; ?></span>
    </div>
<?php endif; ?>

<!-- Period Summary Cards -->
<div class="d-grid-3">
    <div class="d-stat">
        <div class="d-icon red"><i class="fas fa-tags"></i></div>
        <div>
            <div class="d-label">Period Discount</div>
            <div class="d-value" style="color:#dc2626;"><?php echo formatCurrency($periodSummary['total_discount']); ?></div>
            <div class="d-sub"><?php echo $periodSummary['sale_count']; ?> discounted sales</div>
        </div>
    </div>
    <div class="d-stat">
        <div class="d-icon amber"><i class="fas fa-receipt"></i></div>
        <div>
            <div class="d-label">Period Subtotal</div>
            <div class="d-value" style="color:#d97706;"><?php echo formatCurrency($periodSummary['subtotal']); ?></div>
            <div class="d-sub">Before discounts</div>
        </div>
    </div>
    <div class="d-stat">
        <div class="d-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="d-label">Period Total</div>
            <div class="d-value" style="color:#059669;"><?php echo formatCurrency($periodSummary['total']); ?></div>
            <div class="d-sub">After discounts</div>
        </div>
    </div>
</div>

<!-- Discount by Payment Method + Store Breakdown -->
<div class="d-grid-2">
    <!-- Payment Method Breakdown -->
    <div class="d-panel">
        <div class="d-panel-head">
            <h3><i class="fas fa-credit-card" style="color:#2563eb; margin-right:0.5rem;"></i>Discount by Payment Method</h3>
        </div>
        <div style="padding:1.25rem;">
            <?php if (empty($paymentDiscounts)): ?>
                <p class="text-muted text-center">No discounts in this period</p>
            <?php else: ?>
                <?php
                $payMax = max(array_column($paymentDiscounts, 'total_discount')) ?: 1;
                $payColors = [
                    'cash'   => ['#10b981', '#ecfdf5'],
                    'bkash'  => ['#ec4899', '#fdf2f8'],
                    'nagad'  => ['#f59e0b', '#fffbeb'],
                    'rocket' => ['#8b5cf6', '#f5f3ff'],
                    'card'   => ['#3b82f6', '#eff6ff'],
                    'bank'   => ['#0ea5e9', '#ecfeff'],
                ];
                foreach ($paymentDiscounts as $pd):
                    $pc = $payColors[$pd['payment_method']] ?? ['#64748b', '#f1f5f9'];
                    $pct = round(($pd['total_discount'] / $payMax) * 100);
                ?>
                <div class="d-list-row">
                    <div style="display:flex; align-items:center; gap:0.75rem; flex:1; min-width:0;">
                        <div style="width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:<?php echo $pc[1]; ?>; color:<?php echo $pc[0]; ?>; font-size:1rem; flex-shrink:0;">
                            <i class="fas <?php echo $pd['payment_method'] === 'cash' ? 'fa-money-bill-wave' : ($pd['payment_method'] === 'bkash' || $pd['payment_method'] === 'nagad' || $pd['payment_method'] === 'rocket' ? 'fa-mobile-alt' : 'fa-credit-card'); ?>"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; font-size:0.82rem;">
                                <strong style="text-transform:capitalize;"><?php echo $pd['payment_method']; ?></strong>
                                <span class="d-mini"><?php echo $pd['count']; ?> sales</span>
                            </div>
                            <div class="d-bar-track">
                                <div class="d-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $pc[0]; ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <strong style="color:#dc2626; white-space:nowrap;">-<?php echo formatCurrency($pd['total_discount']); ?></strong>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Store Breakdown -->
    <div class="d-panel">
        <div class="d-panel-head">
            <h3><i class="fas fa-store" style="color:#f59e0b; margin-right:0.5rem;"></i>Discount by Store</h3>
        </div>
        <div style="padding:1.25rem;">
            <?php if (empty($storeDiscounts)): ?>
                <p class="text-muted text-center">No discounts in this period</p>
            <?php else: ?>
                <?php
                $storeMax = max(array_column($storeDiscounts, 'total_discount')) ?: 1;
                $storeColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#64748b'];
                $si = 0;
                foreach ($storeDiscounts as $sd):
                    $sc = $storeColors[$si % count($storeColors)];
                    $pct = round(($sd['total_discount'] / $storeMax) * 100);
                    $si++;
                ?>
                <div class="d-list-row">
                    <div style="display:flex; align-items:center; gap:0.75rem; flex:1; min-width:0;">
                        <span style="width:12px; height:12px; border-radius:50%; background:<?php echo $sc; ?>; flex-shrink:0;"></span>
                        <div style="flex:1;">
                            <div style="display:flex; justify-content:space-between; font-size:0.82rem;">
                                <strong><?php echo sanitize($sd['store_name']); ?></strong>
                                <span class="d-mini"><?php echo $sd['count']; ?> sales</span>
                            </div>
                            <div class="d-bar-track">
                                <div class="d-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $sc; ?>;"></div>
                            </div>
                        </div>
                    </div>
                    <strong style="color:#dc2626; white-space:nowrap;">-<?php echo formatCurrency($sd['total_discount']); ?></strong>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Daily Discount Trend -->
<?php if (!empty($dailyTrend)): ?>
<div class="d-panel" style="margin-bottom:1.75rem;">
    <div class="d-panel-head">
        <h3><i class="fas fa-chart-bar" style="color:#4f46e5; margin-right:0.5rem;"></i>Daily Discount Trend</h3>
        <span class="badge badge-primary"><?php echo count($dailyTrend); ?> days with discounts</span>
    </div>
    <div style="padding:1.25rem;">
        <?php
        $trendMax = max(array_column($dailyTrend, 'daily_discount')) ?: 1;
        ?>
        <div style="display:flex; flex-direction:column; gap:0.5rem;">
            <?php foreach ($dailyTrend as $day): ?>
                <?php
                $h = max(3, round(($day['daily_discount'] / $trendMax) * 100));
                ?>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <span style="width:80px; font-size:0.75rem;"><?php echo date('d M', strtotime($day['sale_date'])); ?></span>
                    <div style="flex:1; background:#f1f5f9; border-radius:4px; height:22px;">
                        <div style="background:linear-gradient(90deg, #ef4444, #f87171); border-radius:4px; height:100%; width:<?php echo $h; ?>%;"></div>
                    </div>
                    <span style="width:90px; font-size:0.75rem; text-align:right; color:#dc2626; font-weight:700;">-<?php echo formatCurrency($day['daily_discount']); ?></span>
                    <span class="d-mini" style="width:50px; text-align:right;"><?php echo $day['count']; ?> txns</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Discount Detail Table -->
<div class="d-panel" style="margin-bottom:1.75rem;">
    <div class="d-panel-head">
        <h3><i class="fas fa-list" style="color:#059669; margin-right:0.5rem;"></i>Discount Transaction Details</h3>
        <span class="badge badge-success"><?php echo count($discountDetails); ?> transactions</span>
    </div>
    <div style="padding:0;">
        <?php if (empty($discountDetails)): ?>
            <p class="text-muted text-center" style="padding:2rem;">No discount transactions found for this period</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Cashier</th>
                            <th>Subtotal</th>
                            <th>Discount %</th>
                            <th>Discount Amount</th>
                            <th>Final Total</th>
                            <th>Payment</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountDetails as $i => $sale): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><strong><?php echo sanitize($sale['invoice_number']); ?></strong></td>
                            <td><?php echo sanitize($sale['customer_name'] ?? 'Walk-in'); ?></td>
                            <td><?php echo sanitize($sale['cashier_name']); ?></td>
                            <td><?php echo formatCurrency($sale['subtotal']); ?></td>
                            <td>
                                <?php if ($sale['discount_percent'] > 0): ?>
                                    <span class="badge badge-warning"><?php echo number_format($sale['discount_percent'], 1); ?>%</span>
                                <?php else: ?>
                                    <span class="d-mini">Fixed</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#dc2626; font-weight:700;">-<?php echo formatCurrency($sale['discount_amount']); ?></td>
                            <td><strong><?php echo formatCurrency($sale['total']); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $sale['payment_method'] === 'cash' ? 'success' : 'primary'; ?>">
                                    <?php echo ucfirst($sale['payment_method']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d M Y, h:i A', strtotime($sale['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:800; background:#f8fafc;">
                            <td colspan="4" style="text-align:right;">Total:</td>
                            <td><?php echo formatCurrency($periodSummary['subtotal']); ?></td>
                            <td></td>
                            <td style="color:#dc2626;">-<?php echo formatCurrency($periodSummary['total_discount']); ?></td>
                            <td><?php echo formatCurrency($periodSummary['total']); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
