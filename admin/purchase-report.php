<?php
/**
 * POS System - Purchase Report
 *
 * What the shop bought in a period, who it was bought from, how it was paid for,
 * and a reconciliation proving the cashbook agrees with the payment rows.
 *
 * Every figure is read from the purchase tables; nothing here writes. The
 * reconciliation is the reason this page exists: a payment and its cashbook
 * movement are inserted in the same transaction, so if the two sides ever differ
 * the reader sees it in rupees instead of discovering it in a month-end panic.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Purchase Report');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];
$branchStoreId = $user['store_id'] ? (int)$user['store_id'] : null;

purchaseEnsureTables($db);

// ---------------------------------------------------------------------------
// Period and scope
// ---------------------------------------------------------------------------

list($dateFrom, $dateTo, $prevFrom, $prevTo) = salesDateRange($_GET);

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$storeId = $branchStoreId ?: (int)($_GET['store_id'] ?? 0);
$scopeStore = $storeId ? (int)$storeId : null;

$hasFilters = ($supplierId || (!$branchStoreId && $storeId)
    || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d'));

$cur = purchaseSummary($db, $ownerId, $dateFrom, $dateTo, $scopeStore);
$prev = purchaseSummary($db, $ownerId, $prevFrom, $prevTo, $scopeStore);

$stmt = $db->prepare("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE owner_id = ?"
    . ($scopeStore ? " AND store_id = " . $scopeStore : ""));
$stmt->execute([$ownerId]);
$outstandingAll = (float)$stmt->fetchColumn();

// ---------------------------------------------------------------------------
// Supplier-wise
// ---------------------------------------------------------------------------

$where = ["p.owner_id = ?", "p.purchase_date BETWEEN ? AND ?"];
$params = [$ownerId, $dateFrom, $dateTo];
if ($scopeStore) {
    $where[] = "p.store_id = " . $scopeStore;
}
if ($supplierId) {
    $where[] = "p.supplier_id = " . $supplierId;
}

$stmt = $db->prepare("SELECT p.supplier_id, s.name AS supplier_name, s.company,
                             COUNT(*) AS bills,
                             COALESCE(SUM(p.subtotal), 0) AS gross,
                             COALESCE(SUM(p.discount), 0) AS discount,
                             COALESCE(SUM(p.grand_total), 0) AS net,
                             COALESCE(SUM(p.paid_amount), 0) AS paid,
                             COALESCE(SUM(p.credit_amount), 0) AS credited,
                             COALESCE(SUM(p.due_amount), 0) AS due
                        FROM purchases p
                        JOIN suppliers s ON s.id = p.supplier_id
                       WHERE " . implode(' AND ', $where) . "
                    GROUP BY p.supplier_id, s.name, s.company
                    ORDER BY net DESC");
$stmt->execute($params);
$rows = [];
foreach ($stmt->fetchAll() as $r) {
    $rows[(int)$r['supplier_id']] = [
        'name' => $r['supplier_name'],
        'company' => $r['company'],
        'bills' => (int)$r['bills'],
        'gross' => (float)$r['gross'],
        'discount' => (float)$r['discount'],
        'net' => (float)$r['net'],
        'paid' => (float)$r['paid'],
        'due' => (float)$r['due'],
        'credited' => 0.0,
        'returns' => 0.0,
        'advances' => 0.0,
    ];
}

// Returns are dated on the return, not the original bill, so a supplier can owe
// here without a bill in this window - hence the isset() fallbacks.
$stmt = $db->prepare("SELECT r.supplier_id, s.name AS supplier_name, s.company,
                             COUNT(*) AS rets,
                             COALESCE(SUM(r.total), 0) AS credited,
                             COALESCE(SUM(r.refund_amount), 0) AS refunded
                        FROM purchase_returns r
                        JOIN suppliers s ON s.id = r.supplier_id
                       WHERE r.owner_id = ? AND r.return_date BETWEEN ? AND ?"
    . ($scopeStore ? " AND r.store_id = " . $scopeStore : "")
    . ($supplierId ? " AND r.supplier_id = " . $supplierId : "") . "
     GROUP BY r.supplier_id, s.name, s.company");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
foreach ($stmt->fetchAll() as $r) {
    $sid = (int)$r['supplier_id'];
    if (!isset($rows[$sid])) {
        $rows[$sid] = ['name' => $r['supplier_name'], 'company' => $r['company'],
            'bills' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0,
            'paid' => 0.0, 'due' => 0.0, 'credited' => 0.0, 'returns' => 0.0, 'advances' => 0.0];
    }
    $rows[$sid]['credited'] += (float)$r['credited'];
    $rows[$sid]['returns'] += (int)$r['rets'];
}

// Advances sit on no bill, so they are the one payment figure purchases.paid_amount
// does not already contain; shown as its own column rather than folded into paid.
$stmt = $db->prepare("SELECT sp.supplier_id, s.name AS supplier_name, s.company,
                             COALESCE(SUM(sp.amount), 0) AS advances
                        FROM supplier_payments sp
                        JOIN suppliers s ON s.id = sp.supplier_id
                        LEFT JOIN purchases p ON p.id = sp.purchase_id
                       WHERE sp.owner_id = ? AND sp.purchase_id IS NULL
                         AND sp.payment_date BETWEEN ? AND ?"
    . ($scopeStore ? " AND COALESCE(p.store_id, sp.store_id) = " . $scopeStore : "")
    . ($supplierId ? " AND sp.supplier_id = " . $supplierId : "") . "
     GROUP BY sp.supplier_id, s.name, s.company");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
foreach ($stmt->fetchAll() as $r) {
    $sid = (int)$r['supplier_id'];
    if (!isset($rows[$sid])) {
        $rows[$sid] = ['name' => $r['supplier_name'], 'company' => $r['company'],
            'bills' => 0, 'gross' => 0.0, 'discount' => 0.0, 'net' => 0.0,
            'paid' => 0.0, 'due' => 0.0, 'credited' => 0.0, 'returns' => 0.0, 'advances' => 0.0];
    }
    $rows[$sid]['advances'] += (float)$r['advances'];
}

uasort($rows, function ($a, $b) {
    if ($a['net'] == $b['net']) {
        return strcmp($a['name'], $b['name']);
    }
    return $a['net'] < $b['net'] ? 1 : -1;
});

$colTotals = ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'paid' => 0.0,
    'credited' => 0.0, 'advances' => 0.0, 'due' => 0.0, 'bills' => 0];
foreach ($rows as $r) {
    foreach ($colTotals as $k => $v) {
        $colTotals[$k] += $r[$k];
    }
}

// ---------------------------------------------------------------------------
// Method-wise
// ---------------------------------------------------------------------------

$methods = purchasePaymentMethods($db, $ownerId);

$stmt = $db->prepare("SELECT sp.payment_method AS method, COUNT(*) AS cnt,
                             COALESCE(SUM(sp.amount), 0) AS amount
                        FROM supplier_payments sp
                        LEFT JOIN purchases p ON p.id = sp.purchase_id
                       WHERE sp.owner_id = ? AND sp.payment_date BETWEEN ? AND ?"
    . ($scopeStore ? " AND COALESCE(p.store_id, sp.store_id) = " . $scopeStore : "")
    . ($supplierId ? " AND sp.supplier_id = " . $supplierId : "") . "
     GROUP BY sp.payment_method");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$byMethod = [];
foreach ($stmt->fetchAll() as $r) {
    $m = (string)$r['method'];
    $byMethod[$m] = ['paid' => (float)$r['amount'], 'paid_cnt' => (int)$r['cnt'],
        'refunded' => 0.0, 'refunded_cnt' => 0];
}

$stmt = $db->prepare("SELECT r.refund_method AS method, COUNT(*) AS cnt,
                             COALESCE(SUM(r.refund_amount), 0) AS amount
                        FROM purchase_returns r
                       WHERE r.owner_id = ? AND r.return_date BETWEEN ? AND ?
                         AND r.refund_amount > 0 AND r.refund_method IS NOT NULL"
    . ($scopeStore ? " AND r.store_id = " . $scopeStore : "")
    . ($supplierId ? " AND r.supplier_id = " . $supplierId : "") . "
     GROUP BY r.refund_method");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
foreach ($stmt->fetchAll() as $r) {
    $m = (string)$r['method'];
    if (!isset($byMethod[$m])) {
        $byMethod[$m] = ['paid' => 0.0, 'paid_cnt' => 0, 'refunded' => 0.0, 'refunded_cnt' => 0];
    }
    $byMethod[$m]['refunded'] += (float)$r['amount'];
    $byMethod[$m]['refunded_cnt'] += (int)$r['cnt'];
}
uasort($byMethod, function ($a, $b) {
    $cmp = strcmp($b['paid'], $a['paid']);
    return $cmp !== 0 ? $cmp : strcmp($a['refunded'], $b['refunded']);
});

$paidTotal = 0.0;
$refundedTotal = 0.0;
foreach ($byMethod as $m => $v) {
    $paidTotal += $v['paid'];
    $refundedTotal += $v['refunded'];
}

// ---------------------------------------------------------------------------
// Cashbook reconciliation
//
// Both sides cover the same window: a payment's cashbook row is written with the
// payment date, so money out here should equal the payment rows above to the
// paisa. A difference means a row was changed outside the module.
// ---------------------------------------------------------------------------

$stmt = $db->prepare("SELECT type, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount
                        FROM cashbook_entries
                       WHERE owner_id = ?
                         AND source_type IN ('purchase_pay', 'purchase_refund')
                         AND created_at BETWEEN ? AND ?
                    GROUP BY type");
$stmt->execute([$ownerId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$cashbook = ['cash_out' => ['cnt' => 0, 'amount' => 0.0], 'cash_in' => ['cnt' => 0, 'amount' => 0.0]];
foreach ($stmt->fetchAll() as $r) {
    $t = (string)$r['type'];
    if (isset($cashbook[$t])) {
        $cashbook[$t] = ['cnt' => (int)$r['cnt'], 'amount' => (float)$r['amount']];
    }
}

$outDiff = purchaseMoney($cashbook['cash_out']['amount'] - $paidTotal);
$inDiff = purchaseMoney($cashbook['cash_in']['amount'] - $refundedTotal);
$balanced = abs($outDiff) < 0.005 && abs($inDiff) < 0.005;

$stores = $branchStoreId ? [] : purchaseStores($ownerId);
$suppliers = purchaseSupplierOptions($ownerId);

/** This page's URL with different query values. */
function reportQuery($overrides)
{
    return puQuery('purchase-report.php', $overrides);
}

include 'includes/header.php';
include 'includes/purchase-ui.php';
puStyles();
?>

<style>
    /* A report is meant for paper: the admin chrome and the filter form stay on
       screen, the figures print. */
    @media print {
        .sidebar, .header, .pu-hero-actions, .no-print { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
        body { background: #fff; }
        .card { border: none !important; box-shadow: none !important; }
        .pu-flt { display: none !important; }
        html[data-theme="dark"] body { color: #000; }
    }
    .rep-two { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1.25rem; align-items: start; }
    @media (max-width: 1100px) { .rep-two { grid-template-columns: 1fr; } }
    .rep-note { font-size: .78rem; color: var(--gray-500); padding: .85rem 1.25rem; border-top: 1px solid var(--gray-100); }
    .rep-tot { background: #f6faff; font-weight: 800; }
    html[data-theme="dark"] .rep-tot { background: #131a24; }
    .rep-check { display: flex; align-items: center; gap: .55rem; font-size: .82rem; font-weight: 700; }
    .rep-check i { font-size: .95rem; }
    .rep-check.ok { color: #047857; }
    .rep-check.bad { color: #be123c; }
</style>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<?php
puHero('fa-chart-column', 'Purchase Report',
    'Purchases, suppliers, payment methods and the cashbook check for '
        . date('d M Y', strtotime($dateFrom)) . ' - ' . date('d M Y', strtotime($dateTo)),
    '<button class="btn btn-primary no-print" type="button" onclick="window.print();"><i class="fas fa-print"></i> Print</button>'
    . '<a class="btn btn-outline" href="purchase-add.php"><i class="fas fa-plus"></i> New Purchase</a>'
    . '<a class="btn btn-outline" href="supplier-dues.php"><i class="fas fa-scale-unbalanced"></i> Supplier Dues</a>');

puKpiOpen();
puKpi([
    'icon' => 'fa-file-invoice-dollar', 'label' => 'Purchased',
    'value' => puMoney($cur['total']), 'foot' => (int)$cur['bills'] . ' bill' . ((int)$cur['bills'] === 1 ? '' : 's'),
    'now' => $cur['total'], 'prev' => $prev['total'], 'prevLabel' => 'previous period',
]);
puKpi([
    'tone' => 'green', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Paid to suppliers',
    'value' => puMoney($paidTotal), 'foot' => 'all methods, this period',
    'now' => $paidTotal, 'prev' => $prev['paid'], 'prevLabel' => 'previous period',
]);
puKpi([
    'tone' => 'amber', 'icon' => 'fa-rotate-left', 'label' => 'Credit notes',
    'value' => puMoney($cur['returns']), 'foot' => 'returned to suppliers',
    'now' => $cur['returns'], 'prev' => $prev['returns'], 'prevLabel' => 'previous period',
    'href' => 'purchase-returns.php',
]);
puKpi([
    'tone' => 'red', 'icon' => 'fa-scale-unbalanced', 'label' => 'Outstanding due',
    'value' => puMoney($outstandingAll),
    'foot' => $cur['due'] > 0 ? 'from this period: ' . puMoney($cur['due']) : 'nothing new owed this period',
    'href' => 'supplier-dues.php',
]);
puKpiClose();
?>

<div class="card no-print">
    <form method="GET" class="pu-form">
        <?php
        $filterHtml = puDateGroup('From Date', 'date_from', $dateFrom)
            . puDateGroup('To Date', 'date_to', $dateTo)
            . puSelectGroup('Supplier', 'supplier_id', $supplierId ? $supplierId : '',
                array_column($suppliers, 'name', 'id'), 'All Suppliers');
        if (!$branchStoreId) {
            $filterHtml .= puSelectGroup('Store', 'store_id', $storeId ? $storeId : '',
                array_column($stores, 'name', 'id'), 'All Stores');
        }
        puFilterHead([
            'title' => 'Choose the period',
            'icon' => 'fa-sliders',
            'count' => $colTotals['bills'],
            'filters' => $filterHtml,
            'hasFilters' => true,
            'cols3' => false,
            'clearUrl' => 'purchase-report.php',
        ]);
        ?>
    </form>
    <div class="rep-note">
        Quick periods:
        <a class="pu-lnk" href="<?php echo puEsc(reportQuery(['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')])); ?>">Today</a>
        &middot;
        <a class="pu-lnk" href="<?php echo puEsc(reportQuery(['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')])); ?>">This month</a>
        &middot;
        <a class="pu-lnk" href="<?php echo puEsc(reportQuery(['date_from' => date('Y-m-01', strtotime('first day of last month')), 'date_to' => date('Y-m-t', strtotime('last day of last month'))])); ?>">Last month</a>
        &middot;
        <a class="pu-lnk" href="<?php echo puEsc(reportQuery(['date_from' => date('Y-01-01'), 'date_to' => date('Y-m-d')])); ?>">This year</a>
    </div>
</div>

<div class="rep-two">
    <div class="card">
        <div class="pu-tbl-head">
            <h3><i class="fas fa-users-rectangle" style="color:#2563eb;"></i> Supplier-wise</h3>
            <span class="pu-cnt"><?php echo count($rows); ?> Supplier<?php echo count($rows) === 1 ? '' : 's'; ?></span>
            <span class="pu-spacer"></span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Bills</th>
                            <th>Purchased</th>
                            <th>Discount</th>
                            <th>Credit notes</th>
                            <th>Paid</th>
                            <th>Advances</th>
                            <th>Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">
                                    No purchases in this period.
                                    <a class="pu-lnk" href="purchase-add.php">Add the first one</a>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $sid => $r): ?>
                                <tr>
                                    <td>
                                        <a class="pu-lnk" href="supplier-ledger.php?supplier_id=<?php echo (int)$sid; ?>">
                                            <?php echo puEsc($r['name']); ?>
                                        </a>
                                        <?php if ($r['company']): ?>
                                            <div class="pu-mut" style="font-size:.76rem;"><?php echo puEsc($r['company']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pu-num"><?php echo (int)$r['bills']; ?></td>
                                    <td class="pu-num"><?php echo puMoney($r['net']); ?></td>
                                    <td class="pu-num"><?php echo $r['discount'] > 0 ? puMoney($r['discount']) : '<span class="pu-mut">&mdash;</span>'; ?></td>
                                    <td class="pu-num"><?php echo $r['credited'] > 0 ? puMoney($r['credited']) : '<span class="pu-mut">&mdash;</span>'; ?></td>
                                    <td class="pu-num"><?php echo puMoney($r['paid']); ?></td>
                                    <td class="pu-num"><?php echo $r['advances'] > 0 ? puMoney($r['advances']) : '<span class="pu-mut">&mdash;</span>'; ?></td>
                                    <td class="pu-num">
                                        <span class="pu-amt <?php echo $r['due'] > 0 ? 'out' : 'in'; ?>"><?php echo puMoney($r['due']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="rep-tot">
                                <td>Total</td>
                                <td class="pu-num"><?php echo (int)$colTotals['bills']; ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['net']); ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['discount']); ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['credited']); ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['paid']); ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['advances']); ?></td>
                                <td class="pu-num"><?php echo puMoney($colTotals['due']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="rep-note">
            Purchased is the bill total after its own discount. Credit notes reduce what is owed
            but are dated on the return, so a supplier can appear here with no bill in this period.
            Outstanding is what is still owed in full, not only from these bills.
        </div>
    </div>

    <div class="card">
        <div class="pu-tbl-head">
            <h3><i class="fas fa-wallet" style="color:#2563eb;"></i> Payment method-wise</h3>
            <span class="pu-spacer"></span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Paid</th>
                            <th>Refunded</th>
                            <th>Net out</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byMethod)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">
                                    No supplier payments in this period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($byMethod as $m => $v): ?>
                                <tr>
                                    <td><?php echo puMethodPill($m, $methods); ?></td>
                                    <td class="pu-num">
                                        <?php echo puMoney($v['paid']); ?>
                                        <div class="pu-mut" style="font-size:.74rem;"><?php echo (int)$v['paid_cnt']; ?> payment<?php echo (int)$v['paid_cnt'] === 1 ? '' : 's'; ?></div>
                                    </td>
                                    <td class="pu-num"><?php echo $v['refunded'] > 0 ? puMoney($v['refunded']) : '<span class="pu-mut">&mdash;</span>'; ?></td>
                                    <td class="pu-num">
                                        <span class="pu-amt <?php echo ($v['paid'] - $v['refunded']) > 0 ? 'out' : 'in'; ?>">
                                            <?php echo puMoney($v['paid'] - $v['refunded']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="rep-tot">
                                <td>Total</td>
                                <td class="pu-num"><?php echo puMoney($paidTotal); ?></td>
                                <td class="pu-num"><?php echo puMoney($refundedTotal); ?></td>
                                <td class="pu-num"><?php echo puMoney($paidTotal - $refundedTotal); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="rep-note">
            Methods are the ones switched on for purchases. A supplier can be paid from more than one
            method across the bills of a period.
        </div>
    </div>
</div>

<div class="card">
    <div class="pu-tbl-head">
        <h3><i class="fas fa-scale-balanced" style="color:#2563eb;"></i> Cashbook reconciliation</h3>
        <span class="pu-spacer"></span>
        <span class="rep-check <?php echo $balanced ? 'ok' : 'bad'; ?>">
            <i class="fas <?php echo $balanced ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <?php echo $balanced ? 'The till and the purchase records agree' : 'Difference of ' . puMoney(abs($outDiff) + abs($inDiff)); ?>
        </span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Entries</th>
                        <th>Purchase records</th>
                        <th>Cashbook</th>
                        <th>Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Money out &mdash; paid to suppliers</td>
                        <td class="pu-num"><?php echo (int)$cashbook['cash_out']['cnt']; ?></td>
                        <td class="pu-num"><?php echo puMoney($paidTotal); ?></td>
                        <td class="pu-num"><?php echo puMoney($cashbook['cash_out']['amount']); ?></td>
                        <td class="pu-num">
                            <span class="pu-amt <?php echo $outDiff > 0 ? 'out' : ($outDiff < 0 ? 'in' : ''); ?>">
                                <?php echo $outDiff == 0.0 ? '<span class="pu-mut">&mdash;</span>' : puMoney($outDiff); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td>Money in &mdash; refunds received</td>
                        <td class="pu-num"><?php echo (int)$cashbook['cash_in']['cnt']; ?></td>
                        <td class="pu-num"><?php echo puMoney($refundedTotal); ?></td>
                        <td class="pu-num"><?php echo puMoney($cashbook['cash_in']['amount']); ?></td>
                        <td class="pu-num">
                            <span class="pu-amt <?php echo $inDiff > 0 ? 'in' : ($inDiff < 0 ? 'out' : ''); ?>">
                                <?php echo $inDiff == 0.0 ? '<span class="pu-mut">&mdash;</span>' : puMoney($inDiff); ?>
                            </span>
                        </td>
                    </tr>
                    <tr class="rep-tot">
                        <td>Net effect on the till</td>
                        <td class="pu-num">&mdash;</td>
                        <td class="pu-num"><?php echo puMoney($refundedTotal - $paidTotal); ?></td>
                        <td class="pu-num"><?php echo puMoney($cashbook['cash_in']['amount'] - $cashbook['cash_out']['amount']); ?></td>
                        <td class="pu-num">
                            <?php
                            $netDiff = purchaseMoney(($cashbook['cash_in']['amount'] - $cashbook['cash_out']['amount'])
                                - ($refundedTotal - $paidTotal));
                            echo $netDiff == 0.0 ? '<span class="pu-mut">&mdash;</span>' : puMoney($netDiff);
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="rep-note">
        Every payment and every refund writes one cashbook line in the same transaction, so these two
        columns should match to the paisa. A difference means a purchase row or a cashbook entry was
        changed outside this module - the Expense page and the Supplier Dues list are where to look.
        Unpaid bills are not here: a bill on credit is money owed, not money moved.
        <?php if ($scopeStore): ?>
            <br>The cashbook line carries the store of the person who recorded the payment, so this
            check is shop-wide even when the tables above are narrowed to one store.
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
