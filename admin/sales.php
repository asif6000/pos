<?php
/**
 * POS System - Sales List
 * View and manage all sales transactions
 */

require_once '../config/db.php';
require_once 'includes/sales-scope.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

// Permission check: only users with 'sales' permission can view this page
if (!hasPermission('sales')) {
    setFlash('danger', 'Access denied. You do not have permission to view Sales.');
    redirect('dashboard.php');
}

$user = getCurrentUser();
$store_id = $user['store_id'] ?? null;

$db = getDB();

// Add printed column if missing (safe migration, same as cashier/sales.php)
try {
    $cols = $db->query("SHOW COLUMNS FROM sales LIKE 'printed'")->fetchAll();
    if (empty($cols)) {
        appSafeDdl($db, "ALTER TABLE sales ADD COLUMN printed TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
    }
} catch (PDOException $e) {
    // Column may already exist
}

// Filters and scope (shared with api/export-sales.php)
$filter = salesFilterSql($user, $_GET);
$from = $filter['from'];
$where = $filter['where'];
$params = $filter['params'];
$dateFrom = $filter['filters']['date_from'];
$dateTo = $filter['filters']['date_to'];
$paymentMethod = $filter['filters']['payment'];

// Lottery Sales is this same list with the filter pinned, so its page title
// says what is on screen rather than sending the reader back to "Sales".
define('PAGE_TITLE', $paymentMethod === 'lottery' ? 'Lottery Sales' : 'Sales');

$search = $filter['filters']['search'];
$filterStore = $filter['filters']['store'];
list($unusedFrom, $unusedTo, $prevFrom, $prevTo, $rangeDays) = salesDateRange($_GET);
$rangeLabel = $rangeDays . ' day' . ($rangeDays === 1 ? '' : 's');

/**
 * Percentage change against the previous window. null means there is nothing to
 * compare with yet (the earlier window had no sales), which the card shows as
 * "New" rather than a made-up 100%.
 */
function salesPctChange($now, $prev)
{
    $prev = (float) $prev;
    if ($prev <= 0) {
        return (float) $now > 0 ? null : 0.0;
    }
    return round(((float) $now - $prev) / $prev * 100, 1);
}

/**
 * Fold a day-keyed map into at most $buckets windows, so a sparkline stays
 * readable whether the range covers a week or a year.
 */
function salesBuckets(array $map, $from, $to, $buckets = 20)
{
    $days = [];
    $cursor = strtotime($from);
    $last = strtotime($to);
    while ($cursor !== false && $last !== false && $cursor <= $last) {
        $days[] = date('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
    }
    if (!$days) {
        return [];
    }
    $size = (int) ceil(count($days) / $buckets);
    $out = [];
    foreach (array_chunk($days, $size) as $chunk) {
        $sum = 0;
        foreach ($chunk as $d) {
            $sum += isset($map[$d]) ? (float) $map[$d] : 0;
        }
        $out[] = $sum;
    }
    return $out;
}

$aggSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(s.total), 0) AS total,
           COALESCE(SUM(s.discount_amount), 0) AS discount $from$where";

$stmt = $db->prepare($aggSql);
$stmt->execute($params);
$kpi = $stmt->fetch();

// The equally long window right before the selected one. The date placeholders
// are always the first two parameters, so only those are swapped.
$stmt = $db->prepare($aggSql);
$stmt->execute(array_merge([$prevFrom, $prevTo], array_slice($params, 2)));
$prevKpi = $stmt->fetch();

$stmt = $db->prepare("SELECT DATE(s.created_at) AS d, COUNT(*) AS cnt,
        COALESCE(SUM(s.total), 0) AS total, COALESCE(SUM(s.discount_amount), 0) AS discount
        $from$where GROUP BY DATE(s.created_at)");
$stmt->execute($params);
$byDay = ['cnt' => [], 'total' => [], 'discount' => []];
foreach ($stmt->fetchAll() as $row) {
    $byDay['cnt'][$row['d']] = (int) $row['cnt'];
    $byDay['total'][$row['d']] = (float) $row['total'];
    $byDay['discount'][$row['d']] = (float) $row['discount'];
}

$totalSales = (float) $kpi['total'];
$totalDiscount = (float) $kpi['discount'];
$totalTransactions = (int) $kpi['cnt'];
$avgOrder = $totalTransactions > 0 ? $totalSales / $totalTransactions : 0;

$sparkTotal = salesBuckets($byDay['total'], $dateFrom, $dateTo);
$sparkDiscount = salesBuckets($byDay['discount'], $dateFrom, $dateTo);
$sparkCount = salesBuckets($byDay['cnt'], $dateFrom, $dateTo);
$sparkAvg = [];
foreach ($sparkTotal as $i => $t) {
    $sparkAvg[] = ($sparkCount[$i] ?? 0) > 0 ? $t / $sparkCount[$i] : 0;
}

// One page of the list, plus the total the footer reports.
$perPage = 20;
$stmt = $db->prepare("SELECT COUNT(*) $from$where");
$stmt->execute($params);
$totalFiltered = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT s.*, c.name as customer_name, u.name as cashier_name, st.name as store_name
        $from$where
        ORDER BY s.created_at DESC, s.id DESC
        LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Items and product pictures for this page only - one query each, instead of
// two per row while the table renders.
$itemsBySale = [];
$imagesById = [];
if ($sales) {
    $saleIds = array_map('intval', array_column($sales, 'id'));
    $ph = implode(',', array_fill(0, count($saleIds), '?'));

    $stmt = $db->prepare("SELECT sale_id, product_id, product_name, quantity
                          FROM sale_items WHERE sale_id IN ($ph) ORDER BY sale_id, id");
    $stmt->execute($saleIds);
    $productIds = [];
    foreach ($stmt->fetchAll() as $item) {
        $itemsBySale[(int) $item['sale_id']][] = $item;
        $productIds[(int) $item['product_id']] = true;
    }

    if ($productIds) {
        $pph = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $db->prepare("SELECT id, image FROM products WHERE id IN ($pph)");
        $stmt->execute(array_keys($productIds));
        foreach ($stmt->fetchAll() as $p) {
            $imagesById[(int) $p['id']] = $p['image'];
        }
    }
}

// Stores for filter dropdown
$stores = [];
if (!$store_id) {
    // The platform Super Admin sells off every shop's shelf, so the filter that
    // narrows their list has to name every shop - and a sale they made is filed
    // under the shop whose goods went, not under them.
    $isSuperAdmin = isSuperAdminUser($user);
    if ($isSuperAdmin) {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
        $stmt->execute([$user['owner_id']]);
    }
    $stores = $stmt->fetchAll();
}

/** Rebuild a sales.php URL with the current filters plus these overrides. */
function salesQuery($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) { unset($q[$k]); }
    }
    return 'sales.php' . ($q ? '?' . http_build_query($q) : '');
}

$payMethods = salesPaymentLabels();
$payColors = [
    'cash'    => ['#e8f1ff', '#1d4ed8'],
    'bkash'   => ['#ffeaf1', '#be185d'],
    'nagad'   => ['#fff4e0', '#b45309'],
    'rocket'  => ['#f1ecff', '#6d28d9'],
    'card'    => ['#e4f8fb', '#0e7490'],
    'bank'    => ['#eef2f7', '#475569'],
    'lottery' => ['#fef9c3', '#a16207'],
];
$statusColors = [
    'paid'    => ['#e6f9f0', '#047857'],
    'partial' => ['#fff4e0', '#b45309'],
    'unpaid'  => ['#fdeaea', '#b91c1c'],
];

$sparkline = function (array $vals, $color) {
    $n = count($vals);
    if ($n < 2) {
        return '';
    }
    $W = 100; $H = 32; $pad = 3;
    $max = max($vals); $min = min($vals);
    $span = ($max - $min) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $i * ($W / ($n - 1));
        $y = $H - $pad - (($v - $min) / $span) * ($H - $pad * 2);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $line = implode(' ', $pts);
    return '<svg class="kpi-spark" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon points="0,' . $H . ' ' . $line . ' ' . $W . ',' . $H . '" fill="' . $color . '" opacity=".12"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>';
};

/** The "vs previous N days" pill; null percent means there is no baseline yet. */
$kpiDelta = function ($now, $prev) use ($rangeDays) {
    $pct = salesPctChange($now, $prev);
    if ($pct === null) {
        return '<span class="delta flat">New</span>';
    }
    if ($pct == 0.0) {
        return '<span class="delta flat">Flat</span>';
    }
    $up = $pct > 0;
    return '<span class="delta ' . ($up ? 'up' : 'down') . '"><i class="fas fa-arrow-' . ($up ? 'up' : 'down') . '"></i> '
        . abs($pct) . '%</span>';
};

$canDeleteSales = ($user['role'] === 'admin' || hasPermission('sales_delete'));

include 'includes/header.php';
?>

<style>
    /* ── Page hero ────────────────────────────────────────────────────────── */
    .pg-hero {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        background: linear-gradient(115deg, #dbeafe 0%, #eef4ff 45%, #f7fbff 100%);
        border: 1px solid #e0eaff; border-radius: 18px;
        padding: 1.1rem 1.4rem; margin-bottom: 1.25rem;
    }
    .pg-hero-ico {
        width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 1.15rem;
    }
    .pg-hero-txt { flex: 1 1 220px; min-width: 0; }
    .pg-hero-txt h2 { margin: 0; font-size: 1.3rem; font-weight: 800; color: #0f172a; }
    .pg-hero-txt p { margin: .15rem 0 0; font-size: .82rem; color: #475569; }
    .pg-hero-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    html[data-theme="dark"] .pg-hero { background: linear-gradient(115deg, #16233f 0%, #111a2e 100%); border-color: #1e2a44; }
    html[data-theme="dark"] .pg-hero-txt h2 { color: var(--gray-900); }
    html[data-theme="dark"] .pg-hero-txt p { color: var(--gray-500); }

    /* ── KPI cards ────────────────────────────────────────────────────────── */
    .kpi-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 1rem; margin-bottom: 1.25rem;
    }
    .kpi-card {
        display: flex; align-items: flex-start; gap: .85rem;
        background: var(--surface); border: 1px solid var(--gray-200);
        border-radius: 16px; padding: 1.05rem 1.15rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(15, 23, 42, .1); }
    .kpi-body { flex: 1 1 auto; min-width: 0; }
    .kpi-label { font-size: .78rem; font-weight: 600; color: var(--gray-500); }
    .kpi-value { font-size: 1.4rem; font-weight: 800; color: var(--gray-900); line-height: 1.25; margin-top: .2rem; }
    .kpi-foot { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; font-size: .72rem; color: var(--gray-400); margin-top: .4rem; }
    .kpi-spark { width: 76px; height: 34px; flex-shrink: 0; }
    .kpi-ico {
        width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    .kpi-ico.blue { background: #e8f1ff; color: #2563eb; }
    .kpi-ico.green { background: #e6f9f0; color: #059669; }
    .kpi-ico.violet { background: #f1ecff; color: #7c3aed; }
    .kpi-ico.rose { background: #ffeaf1; color: #db2777; }
    html[data-theme="dark"] .kpi-ico { background: rgba(255, 255, 255, .07); }
    .delta { font-size: .72rem; font-weight: 700; padding: .2rem .5rem; border-radius: 999px; white-space: nowrap; }
    .delta.up { background: #e6f9f0; color: #047857; }
    .delta.down { background: #fdeaea; color: #b91c1c; }
    .delta.flat { background: var(--gray-100); color: var(--gray-500); }
    html[data-theme="dark"] .delta.up { background: #0b2e22; color: #6ee7b7; }
    html[data-theme="dark"] .delta.down { background: #3a1212; color: #fca5a5; }

    /* ── Filter card ──────────────────────────────────────────────────────── */
    .flt-card {
        background: var(--surface); border: 1px solid var(--gray-200);
        border-radius: 16px; padding: 1.15rem 1.3rem; margin-bottom: 1.25rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
    }
    .flt-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr minmax(200px, 1.6fr) auto; gap: .9rem; align-items: end; }
    .flt-actions { display: flex; gap: .6rem; }
    .search-wrap { position: relative; display: block; }
    .search-wrap > i { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); font-size: .78rem; color: var(--gray-400); pointer-events: none; }
    .search-wrap input { padding-left: 2.2rem; }
    @media (max-width: 1200px) { .flt-grid { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 820px) { .flt-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 520px) { .flt-grid { grid-template-columns: 1fr; } .flt-actions .btn { flex: 1; } }

    /* ── Table panel ──────────────────────────────────────────────────────── */
    .tbl-head {
        display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100);
    }
    .tbl-head h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
    .cnt { font-size: .72rem; font-weight: 700; background: #e8f1ff; color: #1d4ed8; padding: .2rem .6rem; border-radius: 999px; }
    .tbl-head .spacer { flex: 1; }

    .th-check, .sel-check { width: 34px; text-align: center; }
    .inv { display: flex; align-items: center; gap: .4rem; white-space: nowrap; }
    .inv-no { font-weight: 700; color: var(--gray-800); }
    .copy-btn { border: none; background: none; color: var(--gray-400); cursor: pointer; padding: 2px; font-size: .72rem; }
    .copy-btn:hover { color: #2563eb; }
    .who { display: flex; align-items: center; gap: .5rem; min-width: 0; }
    .avatar {
        width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: #e8f1ff; color: #1d4ed8; font-size: .72rem; font-weight: 800;
    }
    .prods { display: flex; align-items: center; gap: .3rem; }
    .th-wrap { position: relative; display: inline-flex; }
    .th-img, .th-ph { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--gray-200); }
    .th-img { object-fit: cover; }
    .th-ph { display: flex; align-items: center; justify-content: center; background: var(--gray-100); color: var(--gray-400); font-size: .7rem; }
    .th-qty {
        position: absolute; right: -5px; bottom: -5px; background: #2563eb; color: #fff;
        font-size: .6rem; font-weight: 700; line-height: 1; padding: 2px 4px; border-radius: 999px;
    }
    .th-more { font-size: .7rem; font-weight: 700; color: var(--gray-500); background: var(--gray-100); border-radius: 999px; padding: 2px 6px; }
    .disc { color: #dc2626; font-weight: 700; }
    .disc small { display: block; font-weight: 600; font-size: .68rem; color: var(--gray-400); }
    .pill {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .72rem; font-weight: 700; padding: .25rem .6rem;
        border-radius: 999px; white-space: nowrap;
    }
    .pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .pill.pill-store { background: #e8f1ff; color: #1d4ed8; }
    .pill.pill-store i { font-size: .62rem; }
    .row-acts { display: flex; align-items: center; gap: .35rem; }
    .act {
        width: 32px; height: 32px; border: none; border-radius: 9px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; font-size: .78rem;
        background: var(--gray-50); border: 1px solid var(--gray-200); color: var(--gray-600);
        text-decoration: none;
        transition: transform .15s ease, filter .15s ease;
    }
    .act:hover { transform: translateY(-1px); filter: brightness(.97); color: #2563eb; text-decoration: none; }
    .act.danger:hover { color: #dc2626; }

    /* Kebab menu: the two actions nobody needs in the row itself. */
    .menu { position: relative; }
    .menu-list {
        display: none; position: absolute; right: 0; top: 34px; z-index: 40;
        min-width: 140px; padding: .3rem; background: var(--surface);
        border: 1px solid var(--gray-200); border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, .16);
    }
    .menu.open .menu-list { display: block; }
    /* The table scrolls, so a menu on the last rows would be clipped by the
       wrapper's bottom edge - those open upwards instead. */
    .menu.up .menu-list { top: auto; bottom: 34px; }
    .menu-list a, .menu-list button {
        display: flex; align-items: center; gap: .5rem; width: 100%;
        padding: .45rem .6rem; border: none; background: none; cursor: pointer;
        border-radius: 7px; font-size: .8rem; font-weight: 600; text-align: left;
        color: var(--gray-700); text-decoration: none;
    }
    .menu-list a:hover, .menu-list button:hover { background: var(--gray-100); text-decoration: none; }
    .menu-list .danger { color: #dc2626; }

    /* ── Footer + pager ───────────────────────────────────────────────────── */
    .tbl-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        flex-wrap: wrap; padding: .85rem 1.25rem;
        border-top: 1px solid var(--gray-100);
        font-size: .8rem; color: var(--gray-500);
    }
    .pager { display: flex; align-items: center; gap: .35rem; }
    .pg {
        min-width: 30px; height: 30px; padding: 0 .45rem; border-radius: 8px;
        border: 1px solid var(--gray-200); background: var(--surface); color: var(--gray-600);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .76rem; font-weight: 700; text-decoration: none;
    }
    .pg:hover { border-color: #bfdbfe; color: #2563eb; text-decoration: none; }
    .pg.cur { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pg.off { opacity: .4; pointer-events: none; }
    .pg-dots { color: var(--gray-400); font-size: .8rem; padding: 0 .1rem; }

    /* The checkbox column is a desktop affordance; a phone card has nothing to
       bulk-select with. */
    @media screen and (max-width: 768px) {
        .table thead th.th-check, .table tbody tr td.sel-check { display: none; }
    }

    /* Printed / not-printed row states.
       Not printed = red so unprinted bills stand out in a long list; printed
       = green. Applied by class so markAsPrinted() can flip a row in place.
       The .table tbody tr:hover rule in style.css is (0,2,2), so these are
       scoped through .table tbody to win on specificity - otherwise hovering a
       row would knock the colour back to grey. */
    .table tbody tr.sale-unprinted { background: #fef2f2; }
    .table tbody tr.sale-unprinted:hover { background: #fee2e2; }
    .table tbody tr.sale-printed { background: #f0fdf4; }
    .table tbody tr.sale-printed:hover { background: #dcfce7; }
    .print-dot { font-size: 0.5rem; vertical-align: middle; }
    .table tbody tr.sale-unprinted .print-dot { color: #ef4444; }
    .table tbody tr.sale-printed .print-dot { color: #22c55e; }
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

<!-- Page Header -->
<div class="pg-hero">
    <span class="pg-hero-ico"><i class="fas <?php echo $paymentMethod === 'lottery' ? 'fa-ticket' : 'fa-chart-bar'; ?>"></i></span>
    <div class="pg-hero-txt">
        <h2><?php echo $paymentMethod === 'lottery' ? 'Lottery Sales' : 'Sales'; ?></h2>
        <p><?php echo $paymentMethod === 'lottery' ? 'Sales completed with the Lottery Sale payment method' : 'View and manage all sales transactions'; ?></p>
    </div>
    <div class="pg-hero-actions">
        <a href="pos.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Sale
        </a>
    </div>
</div>

<!-- Summary Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <span class="kpi-ico blue"><i class="fas fa-money-bill-wave"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Total Sales</div>
            <div class="kpi-value"><?php echo formatCurrency($totalSales); ?></div>
            <div class="kpi-foot">
                <?php echo $kpiDelta($totalSales, $prevKpi['total']); ?>
                vs previous <?php echo $rangeLabel; ?>
            </div>
        </div>
        <?php echo $sparkline($sparkTotal, '#2563eb'); ?>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico green"><i class="fas fa-tags"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Discount Given</div>
            <div class="kpi-value"><?php echo formatCurrency($totalDiscount); ?></div>
            <div class="kpi-foot">
                <?php echo $kpiDelta($totalDiscount, $prevKpi['discount']); ?>
                vs previous <?php echo $rangeLabel; ?>
            </div>
        </div>
        <?php echo $sparkline($sparkDiscount, '#059669'); ?>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico violet"><i class="fas fa-receipt"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Transactions</div>
            <div class="kpi-value"><?php echo number_format($totalTransactions); ?></div>
            <div class="kpi-foot">
                <?php echo $kpiDelta($totalTransactions, $prevKpi['cnt']); ?>
                vs previous <?php echo $rangeLabel; ?>
            </div>
        </div>
        <?php echo $sparkline($sparkCount, '#7c3aed'); ?>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico rose"><i class="fas fa-wallet"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Avg. Order Value</div>
            <div class="kpi-value"><?php echo formatCurrency($avgOrder); ?></div>
            <div class="kpi-foot">
                <?php echo $kpiDelta($avgOrder, $prevKpi['cnt'] > 0 ? (float)$prevKpi['total'] / (int)$prevKpi['cnt'] : 0); ?>
                vs previous <?php echo $rangeLabel; ?> &middot; per bill
            </div>
        </div>
        <?php echo $sparkline($sparkAvg, '#db2777'); ?>
    </div>
</div>

<!-- Filters -->
<div class="flt-card">
    <form method="GET" class="flt-grid">
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">From Date</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">To Date</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
        </div>
        <?php if (!$store_id): ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Store</label>
            <select name="store" class="form-control">
                <option value="">All Stores</option>
                <?php foreach ($stores as $st): ?>
                    <option value="<?php echo $st['id']; ?>" <?php echo $filterStore == $st['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($st['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="store" value="">
        <?php endif; ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Payment Method</label>
            <select name="payment" class="form-control">
                <option value="">All Methods</option>
                <?php foreach ($payMethods as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $paymentMethod === $key ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Search</label>
            <span class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control"
                    placeholder="Search by invoice, customer or cashier..." value="<?php echo $search; ?>">
            </span>
        </div>
        <div class="flt-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="sales.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="tbl-head">
        <h3>
            <i class="fas <?php echo $paymentMethod === 'lottery' ? 'fa-ticket' : 'fa-chart-bar'; ?>" style="color:#2563eb;"></i>
            <?php echo $paymentMethod === 'lottery' ? 'Lottery Sales' : 'Sales Transactions'; ?>
        </h3>
        <span class="cnt"><?php echo number_format($totalFiltered); ?> Records</span>
        <span class="spacer"></span>
        <div class="menu" id="exportMenu">
            <button class="btn btn-outline btn-sm" type="button" onclick="toggleMenu('exportMenu')">
                <i class="fas fa-download"></i> Export
            </button>
            <div class="menu-list">
                <button type="button" onclick="exportSales('xls')"><i class="fas fa-file-excel"></i> Excel (XLS)</button>
                <button type="button" onclick="exportSales('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
            </div>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th class="th-check">
                            <input type="checkbox" id="checkAll" onclick="toggleAllRows(this)" aria-label="Select all sales">
                        </th>
                        <th>Invoice No.</th>
                        <th>Date &amp; Time</th>
                        <th>Store</th>
                        <th>Customer</th>
                        <th>Cashier</th>
                        <th>Products</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">No sales found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php
                            $items = $itemsBySale[(int) $sale['id']] ?? [];
                            $itemQty = array_sum(array_column($items, 'quantity'));
                            $pay = $payColors[$sale['payment_method']] ?? ['#eef2f7', '#475569'];
                            $st = $statusColors[$sale['payment_status']] ?? $statusColors['unpaid'];
                            $cashier = $sale['cashier_name'] ?? '';
                            ?>
                            <tr data-sale-id="<?php echo (int)$sale['id']; ?>" class="<?php echo $sale['printed'] ? 'sale-printed' : 'sale-unprinted'; ?>">
                                <td class="sel-check">
                                    <input type="checkbox" class="row-check" value="<?php echo (int)$sale['id']; ?>"
                                        onclick="syncCheckAll()" aria-label="Select <?php echo sanitize($sale['invoice_number']); ?>">
                                </td>
                                <td>
                                    <div class="inv">
                                        <span class="inv-no"><?php echo sanitize($sale['invoice_number']); ?></span>
                                        <button class="copy-btn" type="button" title="Copy invoice number"
                                            onclick="copyInvoice(this, <?php echo htmlspecialchars(json_encode($sale['invoice_number']), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="far fa-copy"></i>
                                        </button>
                                        <i class="fas fa-circle print-dot" title="<?php echo $sale['printed'] ? 'Printed' : 'Not printed'; ?>"></i>
                                    </div>
                                </td>
                                <td><?php echo date('d M Y, h:i A', strtotime($sale['created_at'])); ?></td>
                                <td>
                                    <?php if (!empty($sale['store_name'])): ?>
                                        <span class="pill pill-store">
                                            <i class="fas fa-store"></i> <?php echo sanitize($sale['store_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($sale['customer_name'])): ?>
                                        <?php echo sanitize($sale['customer_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Walk-in</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="who">
                                        <span class="avatar"><?php echo $cashier !== '' ? sanitize(strtoupper(mb_substr($cashier, 0, 1))) : '-'; ?></span>
                                        <span><?php echo $cashier !== '' ? sanitize($cashier) : '-'; ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if (empty($items)): ?>
                                        <span class="text-muted"><?php echo (int)$itemQty; ?> items</span>
                                    <?php else: ?>
                                        <div class="prods">
                                            <?php foreach (array_slice($items, 0, 2) as $it): ?>
                                                <?php $img = $imagesById[(int)$it['product_id']] ?? ''; ?>
                                                <span class="th-wrap" title="<?php echo sanitize($it['product_name']); ?>">
                                                    <?php if ($img): ?>
                                                        <img class="th-img" src="<?php echo assetUrl($img); ?>" alt="">
                                                    <?php else: ?>
                                                        <span class="th-ph"><i class="fas fa-cube"></i></span>
                                                    <?php endif; ?>
                                                    <em class="th-qty"><?php echo (int)$it['quantity']; ?></em>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($items) > 2): ?>
                                                <span class="th-more">+<?php echo count($items) - 2; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $discAmt = (float)$sale['discount_amount'];
                                    $discPct = (float)($sale['discount_percent'] ?? 0);
                                    ?>
                                    <?php if ($discAmt > 0): ?>
                                        <span class="disc">
                                            - <?php echo formatCurrency($discAmt); ?>
                                            <?php if ($discPct > 0): ?>
                                                <small><?php echo rtrim(rtrim(number_format($discPct, 2), '0'), '.') ?>%</small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo formatCurrency($sale['total']); ?></strong></td>
                                <td>
                                    <span class="pill" style="background:<?php echo $pay[0]; ?>; color:<?php echo $pay[1]; ?>;">
                                        <?php echo $payMethods[$sale['payment_method']] ?? ucfirst($sale['payment_method']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="pill" style="background:<?php echo $st[0]; ?>; color:<?php echo $st[1]; ?>;">
                                        <i class="dot"></i> <?php echo ucfirst($sale['payment_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="row-acts">
                                        <button class="act" type="button" onclick="viewInvoice(<?php echo (int)$sale['id']; ?>)" title="View Invoice">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a class="act" href="pos.php?edit_sale_id=<?php echo (int)$sale['id']; ?>" title="Edit Sale">
                                            <i class="fas fa-pen"></i>
                                        </a>
                                        <button class="act" type="button" onclick="printInvoice(<?php echo (int)$sale['id']; ?>)" title="Print">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <div class="menu">
                                            <button class="act" type="button" title="More"
                                                onclick="toggleMenu(this.parentNode)">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <div class="menu-list">
                                                <a href="returns.php?invoice=<?php echo urlencode($sale['invoice_number']); ?>">
                                                    <i class="fas fa-undo"></i> Return
                                                </a>
                                                <?php if ($canDeleteSales): ?>
                                                <button type="button" class="danger" onclick="deleteSale(<?php echo (int)$sale['id']; ?>)">
                                                    <i class="fas fa-trash"></i> Delete Sale
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="tbl-foot">
        <span>
            <?php if ($totalFiltered === 0): ?>
                No sales match these filters
            <?php else: ?>
                Showing <?php echo $offset + 1; ?> to <?php echo min($page * $perPage, $totalFiltered); ?>
                of <?php echo number_format($totalFiltered); ?> sales
            <?php endif; ?>
        </span>
        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <a class="pg <?php echo $page <= 1 ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(salesQuery(['page' => max(1, $page - 1)])); ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                // A window of pages around the current one, never more than 7 links.
                $from2 = max(1, min($page - 3, $totalPages - 6));
                $to2 = min($totalPages, max($page + 3, 7));
                ?>
                <?php if ($from2 > 1): ?>
                    <a class="pg" href="<?php echo htmlspecialchars(salesQuery(['page' => 1])); ?>">1</a>
                    <?php if ($from2 > 2): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $from2; $i <= $to2; $i++): ?>
                    <a class="pg <?php echo $i === $page ? 'cur' : ''; ?>"
                        href="<?php echo htmlspecialchars(salesQuery(['page' => $i])); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($to2 < $totalPages): ?>
                    <?php if ($to2 < $totalPages - 1): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                    <a class="pg" href="<?php echo htmlspecialchars(salesQuery(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                <a class="pg <?php echo $page >= $totalPages ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(salesQuery(['page' => min($totalPages, $page + 1)])); ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal-overlay" id="invoiceModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Invoice Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="invoiceContent">
            <div class="text-center">
                <div class="spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="printBtn">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('assets/js/qrcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
    /* ── Row selection + export ─────────────────────────────────────────────
       The checkboxes narrow the export down to what is ticked; with nothing
       ticked the export covers the whole filtered list. */
    function toggleAllRows(master) {
        document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = master.checked; });
    }

    function syncCheckAll() {
        const boxes = document.querySelectorAll('.row-check');
        const checked = document.querySelectorAll('.row-check:checked');
        const master = document.getElementById('checkAll');
        if (!master) return;
        master.checked = boxes.length > 0 && checked.length === boxes.length;
        master.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    function selectedSaleIds() {
        return [].slice.call(document.querySelectorAll('.row-check:checked')).map(function (cb) { return cb.value; });
    }

    function exportSales(format) {
        const p = new URLSearchParams(location.search);
        p.set('format', format);
        const ids = selectedSaleIds();
        if (ids.length) p.set('ids', ids.join(','));
        location.href = 'api/export-sales.php?' + p.toString();
    }

    /* One dropdown component: any element with class "menu" toggles its own
       list, and a click anywhere else closes them all. */
    function toggleMenu(menu) {
        const el = typeof menu === 'string' ? document.getElementById(menu) : menu;
        if (!el) return;
        const wasOpen = el.classList.contains('open');
        document.querySelectorAll('.menu.open').forEach(function (m) { m.classList.remove('open'); });
        if (wasOpen) return;

        const box = (el.querySelector('.act, .btn') || el).getBoundingClientRect();
        // Only the scrolling table clips its children; a menu outside it (the
        // Export button) can always hang downwards.
        const clip = el.closest('.table-wrapper');
        el.classList.toggle('up', !!clip && clip.getBoundingClientRect().bottom - box.bottom < 90);
        el.classList.add('open');
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.menu')) {
            document.querySelectorAll('.menu.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });

    function copyInvoice(btn, invoiceNumber) {
        const done = function () {
            const icon = btn.querySelector('i');
            if (!icon) return;
            icon.className = 'fas fa-check';
            setTimeout(function () { icon.className = 'far fa-copy'; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(invoiceNumber).then(done).catch(function () { });
        } else {
            const tmp = document.createElement('textarea');
            tmp.value = invoiceNumber;
            document.body.appendChild(tmp);
            tmp.select();
            try { document.execCommand('copy'); done(); } catch (e) { }
            document.body.removeChild(tmp);
        }
    }

    async function viewInvoice(saleId) {
        document.getElementById('invoiceModal').classList.add('active');

        try {
            const response = await fetch(`api/get-invoice.php?id=${saleId}`);
            const data = await response.json();

            if (data.success) {
                displayInvoice(data.invoice);
            } else {
                document.getElementById('invoiceContent').innerHTML = '<p class="text-danger">Error loading invoice</p>';
            }
        } catch (error) {
            document.getElementById('invoiceContent').innerHTML = '<p class="text-danger">Error loading invoice</p>';
        }
    }

    /**
     * The coupon QR, drawn in the page as an inline SVG.
     *
     * It used to be an <img> pointing at api.qrserver.com, which left an empty
     * box wherever that request did not finish in time: a print preview that
     * snapshots the page straight away, or a shop PC with no internet at all.
     * Drawing it here means the preview, the printout and the slip all carry
     * the same code and none of them need the network.
     */
    function couponQrSvg(url, size) {
        if (!url || typeof qrcode === 'undefined') return '';
        try {
            const qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            return qr.createSvgTag({ cellSize: 4, margin: 1, scalable: true })
                .replace('<svg ', '<svg style="width:' + size + 'px;height:' + size + 'px;" ');
        } catch (e) {
            return '';
        }
    }

    function displayInvoice(invoice) {
        const currency = '<?php echo CURRENCY; ?>';
        const facebookUrl = invoice.facebook_page || 'https://www.facebook.com';
        // Controlled from Settings > Invoice. Defaults to on when absent, which
        // is how the receipt behaved before the setting existed.
        const showQr = invoice.show_qr !== false;

        let couponSlips = [];
        if (invoice.coupon_status == '1') {
            let slipIdx = 1;
            invoice.items.forEach(item => {
                let qty = parseInt(item.quantity) || 1;
                for (let i = 0; i < qty; i++) {
                    couponSlips.push({
                        productName: item.product_name,
                        number: `SC-${invoice.invoice_number}-${slipIdx++}`
                    });
                }
            });
        }

        document.getElementById('invoiceContent').innerHTML = `
        <style>
            #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
        </style>
        <div id="printableInvoice" style="font-family: 'Hind Siliguri', monospace; font-size: 12px; width: 100%; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 1rem;">
                <h3 style="margin: 0; font-size: 16px;">${invoice.shop_name || ''}</h3>
                <p style="margin: 0.25rem 0; font-size: 11px;">${invoice.shop_address || ''}</p>
                <p style="margin: 0.25rem 0; font-size: 11px;">${invoice.shop_phone || ''}</p>
                <div style="margin-top: 8px;">
                    <svg id="invoiceReturnBarcode" data-barcode="${invoice.invoice_number}"></svg>
                </div>
            </div>
            <hr style="border-style: dashed;">
            <p style="margin: 2px 0;"><strong>Invoice:</strong> ${invoice.invoice_number}</p>
            <p style="margin: 2px 0;"><strong>Date:</strong> ${invoice.date}</p>
            <p style="margin: 2px 0;"><strong>Customer:</strong> ${invoice.customer_name}</p>
            <p style="margin: 2px 0;"><strong>Cashier:</strong> ${invoice.cashier}</p>
            <hr style="border-style: dashed;">
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; border-bottom: 1px dashed #000; padding-bottom: 4px;">Item</th>
                        <th style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 4px;">Qty</th>
                        <th style="text-align: right; border-bottom: 1px dashed #000; padding-bottom: 4px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${invoice.items.map(item => `
                        <tr>
                            <td style="padding: 4px 0;">${item.product_name}</td>
                            <td style="text-align: center; padding: 4px 0;">${item.quantity}</td>
                            <td style="text-align: right; padding: 4px 0;">${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <hr style="border-style: dashed;">
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 2px 0;">Subtotal</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.subtotal || 0).toFixed(2)}</td>
                </tr>
                ${parseFloat(invoice.discount_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Discount (${invoice.discount_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">- ${currency} ${parseFloat(invoice.discount_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                ${parseFloat(invoice.vat_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">VAT (${invoice.vat_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.vat_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                <tr style="font-size: 14px; border-top: 1px dashed #000; border-bottom: 1px dashed #000;">
                    <td style="padding: 4px 0;">TOTAL</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.total || 0).toFixed(2)}</td>
                </tr>
                <tr>
                    <td style="padding: 2px 0;">Paid (${(invoice.payment_method || 'Cash').toUpperCase()})</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.paid_amount || 0).toFixed(2)}</td>
                </tr>
                ${parseFloat(invoice.change_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Change</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.change_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
            </table>
            <hr style="border-style: dashed;">
            <p style="text-align: center; font-size: 11px; margin-top: 10px;">${invoice.receipt_footer || 'Thank you for shopping!'}</p>
            ${invoice.voucher_terms ? `
            <div style="margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px;">
                <p style="margin: 0 0 4px 0; font-size: 11px; font-weight: bold;">Terms & Conditions</p>
                <p style="margin: 0; font-size: 10px; white-space: pre-line;">${invoice.voucher_terms}</p>
            </div>
            ` : ''}

            

            
            ${invoice.coupon_status == '1' ? `
            <div style="margin-top: 20px; border-top: 1px dashed #000; padding-top: 20px; font-family: monospace;">
                <!-- Coupon Header (Single) -->
                <div style="border: 2px solid #000; padding: 10px; border-radius: 8px; margin-bottom: 12px;">
                    <h3 style="text-align: center; margin: 0 0 4px 0; font-size: 14px; text-transform: uppercase;">${invoice.coupon_title}</h3>
                    <p style="text-align: center; margin: 0 0 8px 0; font-size: 11px;">${invoice.coupon_subtitle}</p>
                    <div style="text-align: center; margin-bottom: 8px;">
                        ${showQr ? `<div style="display: flex; justify-content: center;">${couponQrSvg(facebookUrl, 60)}</div>
                        <p style="margin: 2px 0 0 0; font-size: 9px;">Scan for Facebook</p>` : ''}
                    </div>
                    <div style="text-align: center; font-size: 10px; border: 1px dashed #000; padding: 4px; border-radius: 4px; margin-bottom: 6px;">
                        ${invoice.coupon_prize_1} <br/> ${invoice.coupon_prize_2} <br/> ${invoice.coupon_prize_3} <br/> ${invoice.coupon_prize_4} <br/> ${invoice.coupon_prize_5}
                    </div>
                    <div style="text-align: center; font-size: 10px; margin-bottom: 4px;">${invoice.coupon_total_winners}</div>
                    <div style="font-size: 9px; text-align: center;">
                        ${invoice.coupon_announcement}
                    </div>
                </div>

                <!-- Individual Coupon Slips (Multiple) -->
                ${couponSlips.map(slip => `
                <div style="border: 1px dashed #000; padding: 10px; border-radius: 8px; margin-bottom: 12px; font-size: 11px;">
                    <div style="text-align: center; margin-bottom: 8px; border: 1px solid #000; padding: 6px; font-size: 14px; font-weight: bold;">
                        ${slip.number}
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Item:</span> <span>${slip.productName}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Name:</span> <span>${invoice.customer_name}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Mobile:</span> <span>${invoice.customer_phone || ''}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                        <span>Date:</span> <span>${invoice.date || ''}</span>
                    </div>
                </div>
                `).join('')}
            </div>
            ` : ''}
        </div>
    `;
        renderInvoiceBarcode();
    }

    function renderInvoiceBarcode() {
        const svg = document.getElementById('invoiceReturnBarcode');
        if (svg && typeof JsBarcode !== 'undefined' && svg.dataset.barcode) {
            try {
                JsBarcode(svg, svg.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                });
            } catch (e) {}
        }
    }

    // Which sale the open invoice belongs to, so printing can flag that exact row.
    let currentPrintSaleId = null;

    // Called once a print has actually been sent, so the row can flip from the
    // unprinted (red) state to printed (green) without a page reload.
    function markAsPrinted() {
        if (!currentPrintSaleId) return;

        const saleId = currentPrintSaleId;
        currentPrintSaleId = null;

        fetch('api/mark-printed.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: saleId })
        }).then(res => res.json()).then(data => {
            if (!data || !data.success) return;

            const row = document.querySelector(`tr[data-sale-id="${saleId}"]`);
            if (!row) return;

            row.classList.remove('sale-unprinted');
            row.classList.add('sale-printed');

            const dot = row.querySelector('.print-dot');
            if (dot) dot.title = 'Printed';
        }).catch(() => {
            // Leave the row red if the update could not be saved; printing
            // still worked, so this must not interrupt the user.
        });
    }

    function printInvoice(saleId) {
        if (saleId) {
            currentPrintSaleId = saleId;
            viewInvoice(saleId).then(() => {
                setTimeout(() => doPrint(), 500);
            });
        } else {
            doPrint();
        }
    }

    document.getElementById('printBtn').addEventListener('click', () => doPrint());

    async function doPrint() {
        const printContentEl = document.getElementById('printableInvoice');
        if (!printContentEl) return;

        let printHTML = printContentEl.innerHTML;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
        <head>
            <title>Invoice</title>
            <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
            <style>
                body { font-family: 'Hind Siliguri', monospace; font-size: 12px; margin: 0; padding: 10px; }
                #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
                #invoiceReturnBarcode { height: 50px; }
                hr { border-style: dashed; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 2px 0; }
            </style>
            <script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"><\/script>
        </head>
        <body>${printHTML}</body>
        </html>
    `);
        printWindow.document.close();
        printWindow.onload = function() {
            const doPrint = function() {
                // The print dialog is about to open, so treat the bill as
                // printed and flip its row green.
                markAsPrinted();

                const barcode = printWindow.document.getElementById('invoiceReturnBarcode');
                if (barcode && typeof printWindow.JsBarcode !== 'undefined' && barcode.dataset.barcode) {
                    try {
                        printWindow.JsBarcode(barcode, barcode.dataset.barcode, {
                            format: 'CODE128',
                            width: 1.5,
                            height: 50,
                            displayValue: false
                        });
                    } catch (e) {}
                }
                const images = printWindow.document.querySelectorAll('img');
                let loaded = 0;
                const total = images.length;
                if (total === 0) { printWindow.print(); printWindow.close(); return; }
                images.forEach(img => {
                    if (img.complete) {
                        loaded++;
                        if (loaded === total) { printWindow.print(); printWindow.close(); }
                    } else {
                        img.onload = img.onerror = function() {
                            loaded++;
                            if (loaded === total) { printWindow.print(); printWindow.close(); }
                        };
                    }
                });
                setTimeout(() => { printWindow.print(); printWindow.close(); }, 5000);
            };
            const fontsReady = (printWindow.document.fonts && printWindow.document.fonts.ready) ? printWindow.document.fonts.ready : Promise.resolve();
            fontsReady.then(doPrint);
        };
    }

    async function deleteSale(saleId) {
        if (!confirm('Are you sure you want to delete this sale? This will restore stock.')) {
            return;
        }

        try {
            const response = await fetch('api/delete-sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: saleId })
            });

            const result = await response.json();

            if (result.success) {
                alert('Sale deleted successfully');
                window.location.reload();
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Error deleting sale');
            console.error(error);
        }
    }

    function closeModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    document.getElementById('invoiceModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>
