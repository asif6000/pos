<?php
/**
 * POS System - Purchase Returns
 *
 * Goods sent back to a supplier. A credit note does three things at once, and
 * purchaseReturnAdd() does them in one transaction: the store's stock goes down,
 * the bill's outstanding due goes down by the value kept as credit, and any cash
 * the supplier hands back is recorded as a cashbook money-in.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Purchase Returns');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];
$actor = [
    'owner_id' => $ownerId,
    'store_id' => $user['store_id'] ? (int)$user['store_id'] : null,
    'user_id' => $user['id'],
    'name' => $user['name'],
];

purchaseEnsureTables($db);

$methods = purchasePaymentMethods($db, $ownerId);

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('purchase-returns.php', ['purchase-returns.php', 'purchase-view.php']);
    $action = $_POST['action'] ?? '';
    $back = (string)($_POST['back'] ?? 'purchase-returns.php');
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);

    try {
        if ($action === 'create') {
            $lines = [];
            $wanted = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [];
            foreach ($wanted as $iid => $row) {
                $lines[] = [
                    'purchase_item_id' => (int)$iid,
                    'qty' => is_array($row) ? (int)($row['qty'] ?? 0) : (int)$row,
                ];
            }
            $returnId = purchaseReturnAdd($db, $actor, [
                'purchase_id' => $purchaseId,
                'return_date' => $_POST['return_date'] ?? null,
                'note' => $_POST['note'] ?? '',
                'refund_amount' => (float)($_POST['refund_amount'] ?? 0),
                'refund_method' => $_POST['refund_method'] ?? '',
                'items' => $lines,
            ]);
            $stmt = $db->prepare("SELECT invoice_no FROM purchase_returns WHERE id = ?");
            $stmt->execute([$returnId]);
            setFlash('success', 'Credit note ' . $stmt->fetchColumn() . ' issued. Stock is reduced and the bill owes less.');
            redirect('purchase-view.php?id=' . $purchaseId);
        } elseif ($action === 'delete') {
            $returnId = (int)($_POST['return_id'] ?? 0);
            purchaseReturnDelete($db, $actor, $returnId);
            setFlash('success', 'Credit note cancelled - the goods are back in stock.');
        } else {
            setFlash('danger', 'Unknown action.');
        }
    } catch (PurchaseException $e) {
        setFlash('danger', $e->getMessage());
    }

    if ($back === 'purchase-view.php' && $purchaseId) {
        redirect('purchase-view.php?id=' . $purchaseId);
    }
    redirect('purchase-returns.php');
}

// ---------------------------------------------------------------------------
// The bill being returned against
// ---------------------------------------------------------------------------

$targetId = (int)($_GET['purchase_id'] ?? 0);
$target = $targetId ? purchaseFind($db, $targetId, $ownerId) : false;
$targetItems = $target ? purchaseItems($db, (int)$target['id']) : [];

// Bills to choose from when arriving without one.
$stmt = $db->prepare("SELECT p.id, p.invoice_no, p.purchase_date, p.grand_total, s.name AS supplier_name
                        FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
                       WHERE p.owner_id = ?
                       ORDER BY p.purchase_date DESC, p.id DESC LIMIT 400");
$stmt->execute([$ownerId]);
$billOptions = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

list($dateFrom, $dateTo) = salesDateRange($_GET);
$search = trim($_GET['search'] ?? '');
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$storeId = $actor['store_id'] ?: (int)($_GET['store_id'] ?? 0);

$where = ["r.owner_id = ?"];
$params = [$ownerId];
if ($dateFrom) { $where[] = "r.return_date >= ?"; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "r.return_date <= ?"; $params[] = $dateTo; }
if ($storeId)  { $where[] = "r.store_id = ?"; $params[] = $storeId; }
if ($supplierId) { $where[] = "r.supplier_id = ?"; $params[] = $supplierId; }
if ($search !== '') {
    $where[] = "(r.invoice_no LIKE ? OR p.invoice_no LIKE ? OR s.name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

$hasFilters = ($search !== '' || $supplierId || (!$actor['store_id'] && $storeId)
    || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d'));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT COUNT(*) FROM purchase_returns r
                        JOIN purchases p ON p.id = r.purchase_id
                        JOIN suppliers s ON s.id = r.supplier_id WHERE " . $whereSql);
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $db->prepare("SELECT r.*, p.invoice_no AS ref_invoice_no, s.name AS supplier_name,
                             st.name AS store_name,
                             (SELECT COUNT(*) FROM purchase_return_items x WHERE x.return_id = r.id) AS line_count,
                             (SELECT COALESCE(SUM(x.qty),0) FROM purchase_return_items x WHERE x.return_id = r.id) AS qty_count
                        FROM purchase_returns r
                        JOIN purchases p ON p.id = r.purchase_id
                        JOIN suppliers s ON s.id = r.supplier_id
                        LEFT JOIN stores st ON st.id = r.store_id
                       WHERE " . $whereSql . "
                    ORDER BY r.return_date DESC, r.id DESC
                       LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COALESCE(SUM(r.total),0) AS credited,
                             COALESCE(SUM(r.refund_amount),0) AS refunded, COUNT(*) AS cnt
                        FROM purchase_returns r
                        JOIN purchases p ON p.id = r.purchase_id
                        JOIN suppliers s ON s.id = r.supplier_id WHERE " . $whereSql);
$stmt->execute($params);
$totals = $stmt->fetch();

$suppliers = purchaseSupplierOptions($ownerId);
$stores = purchaseStores($ownerId);

function returnsQuery($overrides)
{
    return puQuery('purchase-returns.php', $overrides);
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
puHero('fa-rotate-left', 'Purchase Returns', 'Credit notes for goods sent back to a supplier',
    '<a class="btn btn-primary" href="purchases.php"><i class="fas fa-list"></i> Purchase Register</a>'
    . '<a class="btn btn-outline" href="supplier-dues.php"><i class="fas fa-scale-unbalanced"></i> Supplier Dues</a>');

puKpiOpen();
puKpi([
    'tone' => 'amber', 'icon' => 'fa-rotate-left', 'label' => 'Credit notes',
    'value' => (int)$totals['cnt'] . '', 'foot' => 'in this period',
]);
puKpi([
    'icon' => 'fa-file-invoice-dollar', 'label' => 'Value returned',
    'value' => puMoney($totals['credited']), 'foot' => 'goods handed back',
]);
puKpi([
    'tone' => 'green', 'icon' => 'fa-money-bill-transfer', 'label' => 'Cash taken back',
    'value' => puMoney($totals['refunded']), 'foot' => 'recorded as money-in',
]);
puKpi([
    'tone' => 'red', 'icon' => 'fa-minus', 'label' => 'Due reduced by credit',
    'value' => puMoney((float)$totals['credited'] - (float)$totals['refunded']),
    'foot' => 'kept against the bills',
]);
puKpiClose();
?>

<?php if ($target): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="pu-tbl-head">
            <h3><i class="fas fa-boxes-stacked" style="color:#b45309;"></i> Return goods from <?php echo puEsc($target['invoice_no']); ?></h3>
            <span class="pu-cnt"><?php echo puEsc($target['supplier_name']); ?></span>
            <span class="pu-spacer"></span>
            <a class="pu-lnk" style="font-size:.8rem;" href="purchase-view.php?id=<?php echo (int)$target['id']; ?>">
                <i class="fas fa-eye"></i> Open the bill
            </a>
        </div>
        <form method="POST" action="purchase-returns.php">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="purchase_id" value="<?php echo (int)$target['id']; ?>">
            <input type="hidden" name="back" value="purchase-returns.php">
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="pu-num">Bought</th>
                                <th class="pu-num">Already returned</th>
                                <th class="pu-num">Available</th>
                                <th class="pu-num">Unit Price</th>
                                <th style="width:120px;">Qty returning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($targetItems as $line): ?>
                                <?php
                                $remaining = (int)$line['qty'] - (int)$line['returned_qty'];
                                $iid = (int)$line['id'];
                                ?>
                                <tr>
                                    <td><?php echo puEsc($line['product_name']); ?></td>
                                    <td class="pu-num"><?php echo (int)$line['qty']; ?></td>
                                    <td class="pu-num"><?php echo (int)$line['returned_qty']; ?></td>
                                    <td class="pu-num">
                                        <span class="<?php echo $remaining > 0 ? '' : 'pu-mut'; ?>"><?php echo $remaining; ?></span>
                                    </td>
                                    <td class="pu-num"><?php echo puMoney($line['unit_price']); ?></td>
                                    <td>
                                        <?php if ($remaining > 0): ?>
                                            <input type="number" class="form-control pu-ret-qty" name="items[<?php echo $iid; ?>][qty]"
                                                min="0" max="<?php echo $remaining; ?>" value="0" data-price="<?php echo number_format((float)$line['unit_price'], 2, '.', ''); ?>"
                                                data-max="<?php echo $remaining; ?>">
                                        <?php else: ?>
                                            <span class="pu-mut">fully returned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="pu-card-body" style="border-top:1px solid var(--gray-100);">
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem;">
                    <div class="form-group">
                        <label class="form-label">Return date</label>
                        <input type="date" class="form-control" name="return_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cash taken back from the supplier</label>
                        <input type="number" class="form-control" name="refund_amount" id="refundAmount" step="0.01" min="0" value="0">
                        <small class="text-muted">Leave 0 if the value stays as credit on the bill.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Refunded through</label>
                        <select class="form-control" name="refund_method">
                            <?php foreach ($methods as $key => $label): ?>
                                <option value="<?php echo puEsc($key); ?>"><?php echo puEsc($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Note</label>
                        <input type="text" class="form-control" name="note" maxlength="255" placeholder="Damaged, expired, wrong item...">
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap; margin-top:.5rem;">
                    <span style="font-size:.85rem;">Return value: <strong id="retValue">0.00</strong>
                        &middot; bill due before this: <strong><?php echo puMoney($target['due_amount']); ?></strong></span>
                    <button type="submit" class="btn btn-primary" style="margin-left:auto;">
                        <i class="fas fa-floppy-disk"></i> Issue Credit Note
                    </button>
                    <a href="purchase-returns.php" class="btn btn-secondary">Clear</a>
                </div>
                <p class="text-muted" style="font-size:.74rem; margin:.75rem 0 0;">
                    Only quantities above zero are returned. The refund cannot be more than the return value, and a
                    refund taken in cash is added to the cashbook as money-in.
                </p>
            </div>
        </form>
    </div>
<?php elseif (!empty($billOptions)): ?>
    <div class="card" style="margin-bottom:1.25rem;">
        <div class="pu-tbl-head">
            <h3><i class="fas fa-plus" style="color:#2563eb;"></i> Return goods against a purchase</h3>
        </div>
        <div class="pu-card-body">
            <form method="GET" action="purchase-returns.php" style="display:flex; gap:.75rem; flex-wrap:wrap; align-items:end;">
                <div class="form-group" style="flex:1 1 320px; margin:0;">
                    <label class="form-label">Choose the bill</label>
                    <select class="form-control" name="purchase_id">
                        <option value="">Select a purchase...</option>
                        <?php foreach ($billOptions as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo puEsc($b['invoice_no']); ?> - <?php echo puEsc($b['supplier_name']); ?>
                                (<?php echo date('d M Y', strtotime($b['purchase_date'])); ?>,
                                <?php echo puMoney($b['grand_total']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-arrow-right"></i> Load items</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <form method="GET" class="pu-form">
        <?php
        $filterHtml = puDateGroup('From Date', 'date_from', $dateFrom)
            . puDateGroup('To Date', 'date_to', $dateTo)
            . puSelectGroup('Supplier', 'supplier_id', $supplierId ? $supplierId : '',
                array_column($suppliers, 'name', 'id'), 'All Suppliers');
        if (!$actor['store_id']) {
            $filterHtml .= puSelectGroup('Store', 'store_id', $storeId ? $storeId : '',
                array_column($stores, 'name', 'id'), 'All Stores');
        }
        puFilterHead([
            'title' => 'Credit Notes',
            'icon' => 'fa-rotate-left',
            'count' => $totalFiltered,
            'search' => ['name' => 'search', 'placeholder' => 'Return no, bill no or supplier...', 'value' => $search],
            'filters' => $filterHtml,
            'hasFilters' => $hasFilters,
            'clearUrl' => 'purchase-returns.php',
        ]);
        ?>
    </form>

    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Return</th>
                        <th>Against bill</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Store</th>
                        <th class="pu-num">Items</th>
                        <th class="pu-num">Value</th>
                        <th class="pu-num">Refund</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted">No purchase returns recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="pu-inv-no"><?php echo puEsc($r['invoice_no']); ?>
                                    <?php if ($r['note']): ?>
                                        <div class="pu-mut" style="font-size:.72rem;"><?php echo puEsc($r['note']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><a class="pu-lnk" href="purchase-view.php?id=<?php echo (int)$r['purchase_id']; ?>">
                                    <?php echo puEsc($r['ref_invoice_no']); ?></a></td>
                                <td><?php echo date('d M Y', strtotime($r['return_date'])); ?></td>
                                <td><?php echo puEsc($r['supplier_name']); ?></td>
                                <td><?php echo puEsc($r['store_name'] ?? '-'); ?></td>
                                <td class="pu-num"><?php echo (int)$r['qty_count']; ?>
                                    <div class="pu-mut" style="font-size:.72rem;"><?php echo (int)$r['line_count']; ?> lines</div></td>
                                <td class="pu-num"><span class="pu-amt out"><?php echo puMoney($r['total']); ?></span></td>
                                <td class="pu-num">
                                    <?php if ((float)$r['refund_amount'] > 0.009): ?>
                                        <span class="pu-amt in"><?php echo puMoney($r['refund_amount']); ?></span>
                                        <div><?php echo puMethodPill($r['refund_method'], $methods); ?></div>
                                    <?php else: ?>
                                        <span class="pu-mut">on the bill</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-actions">
                                    <span class="pu-acts">
                                        <a class="pu-act" title="Print credit note"
                                            href="purchase-print.php?id=<?php echo (int)$r['purchase_id']; ?>&what=return&return_id=<?php echo (int)$r['id']; ?>">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <form method="POST" style="display:inline;"
                                            onsubmit="return confirm('Cancel this credit note? The goods go back into stock and the bill owes what it owed before.');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="return_id" value="<?php echo (int)$r['id']; ?>">
                                            <input type="hidden" name="purchase_id" value="<?php echo (int)$r['purchase_id']; ?>">
                                            <input type="hidden" name="back" value="purchase-returns.php">
                                            <button class="pu-act" type="submit" title="Cancel credit note"><i class="fas fa-trash"></i></button>
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

    <?php puPager($page, $totalPages, $totalFiltered, $perPage, 'returnsQuery'); ?>
</div>

<script>
    function puRetTotal() {
        let total = 0;
        document.querySelectorAll('.pu-ret-qty').forEach(function (inp) {
            let qty = Number(inp.value) || 0;
            const max = Number(inp.dataset.max) || 0;
            if (qty > max) { qty = max; inp.value = max; }
            if (qty < 0) { qty = 0; inp.value = 0; }
            total += qty * Number(inp.dataset.price);
        });
        const el = document.getElementById('retValue');
        if (el) el.textContent = total.toFixed(2);
        const refund = document.getElementById('refundAmount');
        if (refund) refund.max = total.toFixed(2);
    }
    document.querySelectorAll('.pu-ret-qty').forEach(function (inp) {
        inp.addEventListener('input', puRetTotal);
    });
    puRetTotal();
</script>

<?php puFilterScript(); ?>

<?php include 'includes/footer.php'; ?>
