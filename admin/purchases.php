<?php
/**
 * POS System - Purchases
 *
 * The purchase register: every bill from a supplier, what was paid on it and what
 * is still owed. Money and stock are never edited from this list - a row links to
 * purchase-view.php, and only the domain layer (config/purchases.php) changes a
 * figure, so the totals here are always what the child rows add up to.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Purchases');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];
$branchStoreId = $user['store_id'] ? (int)$user['store_id'] : null;

purchaseEnsureTables($db);

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('purchases.php', ['purchases.php', 'purchase-view.php']);
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['purchase_id'] ?? 0);

    try {
        if ($action === 'delete') {
            purchaseDelete($db, [
                'owner_id' => $ownerId,
                'store_id' => $user['store_id'] ?? null,
                'user_id' => $user['id'],
                'name' => $user['name'],
            ], $id);
            setFlash('success', 'Purchase removed. The stock it added has been taken back out.');
        } else {
            setFlash('danger', 'Unknown action.');
        }
    } catch (PurchaseException $e) {
        setFlash('danger', $e->getMessage());
    }
    redirect('purchases.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

list($dateFrom, $dateTo, $prevFrom, $prevTo) = salesDateRange($_GET);

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$storeId = $branchStoreId ?: (int)($_GET['store_id'] ?? 0);

$where = ["p.owner_id = ?"];
$params = [$ownerId];
if ($dateFrom) { $where[] = "p.purchase_date >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "p.purchase_date <= ?"; $params[] = $dateTo; }
if ($storeId)  { $where[] = "p.store_id = ?"; $params[] = $storeId; }
if ($supplierId) { $where[] = "p.supplier_id = ?"; $params[] = $supplierId; }
if ($status === 'paid' || $status === 'partial' || $status === 'due') {
    $where[] = "p.status = ?";
    $params[] = $status;
}
if ($search !== '') {
    $where[] = "(p.invoice_no LIKE ? OR s.name LIKE ? OR p.note LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

$hasFilters = ($search !== '' || $status !== '' || $supplierId || (!$branchStoreId && $storeId)
    || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d'));

// ---------------------------------------------------------------------------
// KPI figures (the chosen window, and the equally long window before it)
// ---------------------------------------------------------------------------

$cur = purchaseSummary($db, $ownerId, $dateFrom, $dateTo, $branchStoreId ?: (int)($_GET['store_id'] ?? 0) ?: null);
$prev = purchaseSummary($db, $ownerId, $prevFrom, $prevTo, $branchStoreId ?: (int)($_GET['store_id'] ?? 0) ?: null);

$stmt = $db->prepare("SELECT COALESCE(SUM(due_amount),0) FROM purchases WHERE owner_id = ?"
    . ($storeId ? " AND store_id = " . (int)$storeId : ""));
$stmt->execute([$ownerId]);
$outstandingAll = (float)$stmt->fetchColumn();

// ---------------------------------------------------------------------------
// Page list
// ---------------------------------------------------------------------------

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT COUNT(*) FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE " . $whereSql);
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $db->prepare("SELECT p.*, s.name AS supplier_name, st.name AS store_name, u.name AS user_name,
                             (SELECT COUNT(*) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS line_count,
                             (SELECT COALESCE(SUM(pi.qty),0) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS qty_count,
                             (SELECT COALESCE(SUM(pi.returned_qty),0) FROM purchase_items pi WHERE pi.purchase_id = p.id) AS returned_count
                        FROM purchases p
                        JOIN suppliers s ON s.id = p.supplier_id
                        LEFT JOIN stores st ON st.id = p.store_id
                        LEFT JOIN users u ON u.id = p.user_id
                       WHERE " . $whereSql . "
                    ORDER BY p.purchase_date DESC, p.id DESC
                       LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$methods = purchasePaymentMethods($db, $ownerId);
$stores = purchaseStores($ownerId);
$suppliers = purchaseSupplierOptions($ownerId);

/** This page's URL with different query values. */
function purchasesQuery($overrides)
{
    return puQuery('purchases.php', $overrides);
}

include 'includes/header.php';
include 'includes/purchase-ui.php';
puStyles();
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<?php
puHero('fa-truck-ramp-box', 'Purchases', 'Supplier bills, payments and what is still owed',
    '<a class="btn btn-primary" href="purchase-add.php"><i class="fas fa-plus"></i> New Purchase</a>'
    . '<a class="btn btn-outline" href="supplier-dues.php"><i class="fas fa-scale-unbalanced"></i> Supplier Dues</a>'
    . '<a class="btn btn-outline" href="purchase-report.php"><i class="fas fa-chart-column"></i> Report</a>');

puKpiOpen();
puKpi([
    'icon' => 'fa-file-invoice-dollar', 'label' => 'Purchases (' . date('M Y', strtotime($dateTo)) . ')',
    'value' => puMoney($cur['total']), 'foot' => (int)$cur['bills'] . ' bill' . ((int)$cur['bills'] === 1 ? '' : 's'),
    'now' => $cur['total'], 'prev' => $prev['total'], 'prevLabel' => 'previous period',
]);
puKpi([
    'tone' => 'green', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Paid in this period',
    'value' => puMoney($cur['paid']), 'foot' => 'settled against these bills',
    'now' => $cur['paid'], 'prev' => $prev['paid'], 'prevLabel' => 'previous period',
]);
puKpi([
    'tone' => 'red', 'icon' => 'fa-scale-unbalanced', 'label' => 'Outstanding due',
    'value' => puMoney($outstandingAll), 'foot' => 'in this period: ' . puMoney($cur['due']),
    'href' => 'supplier-dues.php',
]);
puKpi([
    'tone' => 'amber', 'icon' => 'fa-rotate-left', 'label' => 'Purchase returns',
    'value' => puMoney($cur['returns']), 'foot' => 'credit notes issued',
    'href' => 'purchase-returns.php',
]);
puKpiClose();
?>

<div class="card">
    <form method="GET" class="pu-form">
        <?php
        $filterHtml = puDateGroup('From Date', 'date_from', $dateFrom)
            . puDateGroup('To Date', 'date_to', $dateTo)
            . puSelectGroup('Supplier', 'supplier_id', $supplierId ? $supplierId : '',
                array_column($suppliers, 'name', 'id'), 'All Suppliers')
            . puSelectGroup('Status', 'status', $status,
                ['paid' => 'Fully paid', 'partial' => 'Partly paid', 'due' => 'Unpaid'], 'Any Status');
        if (!$branchStoreId) {
            $filterHtml .= puSelectGroup('Store', 'store_id', $storeId ? $storeId : '',
                array_column($stores, 'name', 'id'), 'All Stores');
        }
        puFilterHead([
            'title' => 'Purchase Register',
            'icon' => 'fa-truck-ramp-box',
            'count' => $totalFiltered,
            'search' => ['name' => 'search', 'placeholder' => 'Invoice no, supplier or note...', 'value' => $search],
            'filters' => $filterHtml,
            'hasFilters' => $hasFilters,
            'clearUrl' => 'purchases.php',
        ]);
        ?>
    </form>

    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Store</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">
                                No purchases in this period.
                                <a class="pu-lnk" href="purchase-add.php">Add the first one</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $p): ?>
                            <?php
                            $pid = (int)$p['id'];
                            $returned = (int)$p['returned_count'];
                            ?>
                            <tr>
                                <td>
                                    <div class="pu-inv">
                                        <a class="pu-inv-no pu-lnk" href="purchase-view.php?id=<?php echo $pid; ?>">
                                            <?php echo puEsc($p['invoice_no']); ?>
                                        </a>
                                    </div>
                                    <?php if ($p['note'] !== null && $p['note'] !== ''): ?>
                                        <div class="pu-mut" style="font-size:.72rem;"><?php echo puEsc($p['note']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d M Y', strtotime($p['purchase_date'])); ?></td>
                                <td><?php echo puEsc($p['supplier_name']); ?></td>
                                <td><?php echo puEsc($p['store_name'] ?? '-'); ?></td>
                                <td>
                                    <span><?php echo (int)$p['qty_count']; ?> qty</span>
                                    <div class="pu-mut" style="font-size:.72rem;"><?php echo (int)$p['line_count']; ?> lines
                                        <?php echo $returned > 0 ? '&middot; ' . $returned . ' returned' : ''; ?></div>
                                </td>
                                <td class="pu-num"><?php echo puMoney($p['grand_total']); ?></td>
                                <td class="pu-num"><?php echo puMoney($p['paid_amount']); ?>
                                    <?php if ($p['payment_method']): ?>
                                        <div><?php echo puMethodPill($p['payment_method'], $methods); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="pu-num">
                                    <span class="pu-amt <?php echo (float)$p['due_amount'] > 0.009 ? 'out' : 'in'; ?>">
                                        <?php echo puMoney($p['due_amount']); ?>
                                    </span>
                                </td>
                                <td><?php echo puStatusPill($p['status']); ?></td>
                                <td class="cell-actions">
                                    <span class="pu-acts">
                                        <a class="pu-act" href="purchase-view.php?id=<?php echo $pid; ?>" title="Open">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a class="pu-act" href="purchase-print.php?id=<?php echo $pid; ?>&what=invoice" title="Print invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <form method="POST" style="display:inline;"
                                            onsubmit="return confirm('Delete this purchase? The stock it added is taken back out and its payments are removed.');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="purchase_id" value="<?php echo $pid; ?>">
                                            <input type="hidden" name="back" value="purchases.php">
                                            <button class="pu-act" type="submit" title="Delete purchase">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php puPager($page, $totalPages, $totalFiltered, $perPage, 'purchasesQuery'); ?>
</div>

<?php puFilterScript(); ?>

<?php include 'includes/footer.php'; ?>
