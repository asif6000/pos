<?php
/**
 * POS System - Supplier Dues
 *
 * What the shop owes its suppliers, bill by bill and supplier by supplier.
 *
 * The figure is built from the bills rather than stored beside them: each
 * purchase's due_amount is what its own payments and credit notes leave over, and
 * an on-account payment (money given without a bill behind it) is subtracted from
 * the supplier's total here. That is why an "as at" date gives a true historical
 * position - the same SQL simply ignores bills and payments after that day.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Supplier Dues');

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

$asAt = purchaseDateInput($_GET['as_at'] ?? date('Y-m-d'));
$search = trim($_GET['search'] ?? '');
$storeId = $actor['store_id'] ?: (int)($_GET['store_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('supplier-dues.php', ['supplier-dues.php']);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_payment') {
            $purchaseId = (int)($_POST['purchase_id'] ?? 0);
            $paymentId = purchasePaymentAdd($db, $actor, [
                'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
                'purchase_id' => $purchaseId ?: null,
                'invoice_no' => $_POST['invoice_label'] ?? '',
                'amount' => (float)($_POST['amount'] ?? 0),
                'payment_method' => $_POST['payment_method'] ?? '',
                'payment_date' => $_POST['payment_date'] ?? null,
                'reference' => $_POST['reference'] ?? '',
                'note' => $_POST['payment_note'] ?? '',
                'cashbook_id' => $_POST['cashbook_id'] ?? null,
            ]);
            setFlash('success', $purchaseId
                ? 'Payment recorded against that bill.'
                : 'On-account payment recorded. It reduces the supplier total due.');
            if (($_POST['after'] ?? '') === 'print' && $purchaseId) {
                redirect('purchase-print.php?id=' . $purchaseId . '&what=receipt&payment_id=' . (int)$paymentId . '&autoprint=1');
            }
        } elseif ($action === 'delete_advance') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            // Only an advance may come off this page - a payment that sits on a
            // bill is undone from that bill, where the recalculation is visible.
            // The row is also asked which shelf it came out of, so a cashier
            // pinned to one store cannot undo another store's payment.
            $stmtAdv = $db->prepare("SELECT id, store_id FROM supplier_payments
                                       WHERE id = ? AND owner_id = ? AND purchase_id IS NULL");
            $stmtAdv->execute([$paymentId, $ownerId]);
            $advance = $stmtAdv->fetch();
            if (!$advance || ($actor['store_id'] && (int)$advance['store_id'] !== (int)$actor['store_id'])) {
                setFlash('danger', 'That advance is not on this store\'s account.');
            } else {
                purchasePaymentDelete($db, $actor, $paymentId);
                setFlash('success', 'Advance deleted - the cashbook entry went with it.');
            }
        } else {
            setFlash('danger', 'Unknown action.');
        }
    } catch (PurchaseException $e) {
        setFlash('danger', $e->getMessage());
    }
    // Return to the same filtered view. The query string is rebuilt through
    // parse_str/http_build_query rather than pasted into the Location header, so
    // a hand-crafted `qs` cannot turn it into a redirect elsewhere.
    parse_str((string)($_POST['qs'] ?? ''), $backQs);
    unset($backQs['page']);
    $redirect = 'supplier-dues.php' . (empty($backQs) ? '' : '?' . http_build_query($backQs));
    redirect($redirect);
}

$rows = supplierDueList($db, $ownerId, $asAt, $storeId ?: null, $search);

$totalOutstanding = 0.0;
$totalPurchases = 0.0;
$withDue = 0;
$oldestDays = 0;
foreach ($rows as $r) {
    $totalOutstanding += (float)$r['outstanding'];
    $totalPurchases += (float)$r['total_purchase'];
    if ((float)$r['outstanding'] > 0.009) {
        $withDue++;
        if ($r['oldest_due_date']) {
            $days = (int)floor((strtotime($asAt) - strtotime($r['oldest_due_date'])) / 86400);
            $oldestDays = max($oldestDays, $days);
        }
    }
}

// Advances already sitting on the supplier's account (money out, no bill yet).
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payments
                        WHERE owner_id = ? AND purchase_id IS NULL AND payment_date <= ?");
$stmt->execute([$ownerId, $asAt]);
$advancesTotal = (float)$stmt->fetchColumn();

// Open bills per supplier, for the "apply to this bill" select in the payment box.
$stmt = $db->prepare("SELECT p.id, p.invoice_no, p.due_amount, p.supplier_id, s.name AS supplier_name
                        FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
                       WHERE p.owner_id = ? AND p.purchase_date <= ? AND p.due_amount > 0.009
                       ORDER BY p.purchase_date, p.id");
$stmt->execute([$ownerId, $asAt]);
$openBills = [];
foreach ($stmt->fetchAll() as $b) {
    $openBills[(int)$b['supplier_id']][] = $b;
}

$methods = purchasePaymentMethods($db, $ownerId);
$stores = purchaseStores($ownerId);
$suppliers = purchaseSupplierOptions($ownerId);

// The individual advances behind each supplier's "Advances" figure, so the one
// that was entered wrongly can be picked out and deleted.
$advances = [];
foreach (supplierAdvanceList($db, $ownerId, $asAt, $storeId) as $sid => $list) {
    foreach ($list as $a) {
        $advances[(int)$sid][] = [
            'id'     => (int)$a['id'],
            'date'   => $a['payment_date'] ? date('d M Y', strtotime($a['payment_date'])) : '-',
            'method' => isset($methods[$a['payment_method']]) ? $methods[$a['payment_method']] : $a['payment_method'],
            'amount' => purchaseMoney($a['amount']),
            'note'   => implode(' - ', array_filter([(string)$a['reference'], (string)$a['note']], function ($v) {
                return $v !== '';
            })),
        ];
    }
}

function duesQuery($overrides)
{
    return puQuery('supplier-dues.php', $overrides);
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
puHero('fa-scale-unbalanced', 'Supplier Dues',
    'Outstanding balance as at ' . date('d M Y', strtotime($asAt)),
    '<a class="btn btn-primary" href="purchase-add.php"><i class="fas fa-plus"></i> New Purchase</a>'
    . '<a class="btn btn-outline" href="purchases.php"><i class="fas fa-list"></i> Purchase Register</a>');

puKpiOpen();
puKpi([
    'tone' => 'red', 'icon' => 'fa-scale-unbalanced', 'label' => 'Total outstanding',
    'value' => puMoney($totalOutstanding), 'foot' => $withDue . ' supplier' . ($withDue === 1 ? '' : 's') . ' with a balance',
]);
puKpi([
    'icon' => 'fa-file-invoice-dollar', 'label' => 'Purchased to date',
    'value' => puMoney($totalPurchases), 'foot' => 'across the suppliers listed here',
]);
puKpi([
    'tone' => 'green', 'icon' => 'fa-money-bill-transfer', 'label' => 'Advances on account',
    'value' => puMoney($advancesTotal), 'foot' => 'paid without a bill behind them',
]);
puKpi([
    'tone' => 'amber', 'icon' => 'fa-hourglass-half', 'label' => 'Oldest unpaid bill',
    'value' => $oldestDays > 0 ? $oldestDays . ' days' : '-', 'foot' => 'measured to the as-at date',
]);
puKpiClose();
?>

<div class="card">
    <form method="GET" class="pu-form">
        <?php
        $filterHtml = '<div class="form-group"><label class="form-label">Dues as at</label>'
            . '<input type="date" class="form-control" name="as_at" value="' . puEsc($asAt) . '"></div>';
        if (!$actor['store_id']) {
            $filterHtml .= puSelectGroup('Store', 'store_id', $storeId ? $storeId : '',
                array_column($stores, 'name', 'id'), 'All Stores');
        }
        puFilterHead([
            'title' => 'Suppliers with a balance',
            'icon' => 'fa-scale-unbalanced',
            'count' => count($rows),
            'search' => ['name' => 'search', 'placeholder' => 'Supplier name, company or phone...', 'value' => $search],
            'filters' => $filterHtml,
            'hasFilters' => ($search !== '' || $asAt !== date('Y-m-d') || (!$actor['store_id'] && $storeId)),
            'clearUrl' => 'supplier-dues.php',
        ]);
        ?>
    </form>

    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th class="pu-num">Bills</th>
                        <th class="pu-num">Purchased</th>
                        <th class="pu-num">Paid</th>
                        <th class="pu-num">Credit notes</th>
                        <th class="pu-num">Advances</th>
                        <th class="pu-num">Outstanding</th>
                        <th>Oldest due</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted">
                            No supplier has purchases or advances on record.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $sid = (int)$r['supplier_id'];
                            $out = (float)$r['outstanding'];
                            $age = $r['oldest_due_date']
                                ? (int)floor((strtotime($asAt) - strtotime($r['oldest_due_date'])) / 86400)
                                : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="pu-inv-no"><?php echo puEsc($r['supplier_name']); ?></div>
                                    <?php if ($r['company']): ?>
                                        <div class="pu-mut" style="font-size:.72rem;"><?php echo puEsc($r['company']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($r['phone']): ?>
                                        <div class="pu-mut" style="font-size:.72rem;"><?php echo puEsc($r['phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="pu-num"><?php echo (int)$r['bills']; ?></td>
                                <td class="pu-num"><?php echo puMoney($r['total_purchase']); ?></td>
                                <td class="pu-num"><?php echo puMoney($r['paid']); ?></td>
                                <td class="pu-num"><?php echo (float)$r['credited'] > 0.009 ? puMoney($r['credited']) : '<span class="pu-mut">-</span>'; ?></td>
                                <td class="pu-num"><?php echo (float)$r['on_account'] > 0.009 ? puMoney($r['on_account']) : '<span class="pu-mut">-</span>'; ?></td>
                                <td class="pu-num">
                                    <span class="pu-amt <?php echo $out > 0.009 ? 'out' : 'in'; ?>"><?php echo puMoney($out); ?></span>
                                </td>
                                <td>
                                    <?php if ($out > 0.009 && $r['oldest_due_date']): ?>
                                        <span><?php echo date('d M Y', strtotime($r['oldest_due_date'])); ?></span>
                                        <div class="pu-mut" style="font-size:.72rem;"><?php echo $age; ?> days old</div>
                                    <?php else: ?>
                                        <span class="pu-pill" style="background:#e6f9f0; color:#047857;">
                                            <i class="fas fa-circle-check"></i> Clear
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-actions">
                                    <span class="pu-acts">
                                        <?php if ($out > 0.009): ?>
                                            <button type="button" class="pu-act" title="Record payment"
                                                onclick="puOpenPay(<?php echo $sid; ?>, '<?php echo puEsc($r['supplier_name']); ?>', <?php echo number_format($out, 2, '.', ''); ?>)">
                                                <i class="fas fa-money-bill-transfer"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a class="pu-act" title="Supplier ledger"
                                            href="supplier-ledger.php?supplier_id=<?php echo $sid; ?>&as_at=<?php echo puEsc($asAt); ?>">
                                            <i class="fas fa-book-open"></i>
                                        </a>
                                        <a class="pu-act" title="This supplier's bills"
                                            href="purchases.php?supplier_id=<?php echo $sid; ?>">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        <?php if (!empty($advances[$sid])): ?>
                                            <button type="button" class="pu-act"
                                                title="Advances on account (<?php echo count($advances[$sid]); ?>)"
                                                data-adv-name="<?php echo puEsc($r['supplier_name']); ?>"
                                                onclick="puOpenAdv(<?php echo $sid; ?>, this)">
                                                <i class="fas fa-hand-holding-dollar"></i>
                                            </button>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="pu-tbl-foot">
        <span>
            Dues are bills less payments less credit notes, with on-account payments subtracted from the supplier total.
            <?php echo $storeId ? '' : 'Balances shown cover every store.'; ?>
        </span>
        <span class="pu-cnt"><?php echo $withDue; ?> of <?php echo count($rows); ?> carrying a balance</span>
    </div>
</div>

<!-- Payment box: one form, filled by the row that opened it -->
<div class="modal-overlay" id="payModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-money-bill-transfer" style="color:#059669;"></i> Pay supplier</h3>
            <button class="modal-close" onclick="puClosePay()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="supplier-dues.php" id="duesPayForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="supplier_id" id="paySupplierId" value="">
                <input type="hidden" name="invoice_label" id="payInvoiceLabel" value="">
                <input type="hidden" name="qs" id="payQs" value="<?php echo puEsc((string)$_SERVER['QUERY_STRING']); ?>">

                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" class="form-control" id="paySupplierName" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Apply to a bill</label>
                    <select class="form-control" name="purchase_id" id="payBill" onchange="puPickBill()">
                        <option value="0">On account (no specific bill)</option>
                    </select>
                    <small class="text-muted" id="payBillHelp">An on-account payment reduces the supplier's total due.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <input type="number" class="form-control" name="amount" id="payAmount" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Method</label>
                    <select class="form-control" name="payment_method">
                        <?php foreach ($methods as $key => $label): ?>
                            <option value="<?php echo puEsc($key); ?>"><?php echo puEsc($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php echo purchaseCashbookField($db, $ownerId); ?>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Reference</label>
                    <input type="text" class="form-control" name="reference" maxlength="60">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Note</label>
                    <input type="text" class="form-control" name="payment_note" maxlength="255">
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" onclick="puClosePay()">Cancel</button>
            <button class="btn btn-outline" type="submit" form="duesPayForm" onclick="puAfterPay('print')">
                <i class="fas fa-receipt"></i> Save &amp; Receipt
            </button>
            <button class="btn btn-primary" type="submit" form="duesPayForm" onclick="puAfterPay('save')">
                <i class="fas fa-floppy-disk"></i> Save Payment
            </button>
        </div>
    </div>
</div>

<!-- Advances: the individual on-account payments behind a supplier's figure.
     Rendered per supplier and copied into the box by puOpenAdv(), because a
     delete is a form of its own with its own token. -->
<div id="advSets" hidden>
    <?php foreach ($rows as $r): ?>
        <?php
        // Walk the table's own suppliers, not the payment rows: an advance whose
        // supplier record is gone has no row here to open it from, so it gets no form.
        $sid = (int)$r['supplier_id'];
        if (empty($advances[$sid])) {
            continue;
        }
        $list = $advances[$sid];
        ?>
        <div class="adv-set" id="adv-set-<?php echo $sid; ?>">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference / note</th>
                            <th class="pu-num">Amount</th>
                            <th class="pu-num">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $a): ?>
                            <tr>
                                <td><?php echo puEsc($a['date']); ?></td>
                                <td><?php echo puEsc($a['method']); ?></td>
                                <td><?php echo $a['note'] !== '' ? puEsc($a['note']) : '<span class="pu-mut">-</span>'; ?></td>
                                <td class="pu-num"><?php echo puMoney($a['amount']); ?></td>
                                <td class="pu-num">
                                    <form method="POST" action="supplier-dues.php"
                                        onsubmit="return confirm('Delete this advance? The cashbook entry goes with it.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_advance">
                                        <input type="hidden" name="payment_id" value="<?php echo (int)$a['id']; ?>">
                                        <input type="hidden" name="qs" value="<?php echo puEsc((string)$_SERVER['QUERY_STRING']); ?>">
                                        <button class="pu-act" type="submit" title="Delete this advance">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="modal-overlay" id="advModal">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-hand-holding-dollar" style="color:#059669;"></i> Advances - <span id="advSupplier"></span></h3>
            <button class="modal-close" onclick="puCloseAdv()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="pu-mut" style="font-size:.8rem; margin:0 0 .6rem;">
                Money paid out with no bill behind it. Deleting one removes it from the supplier's balance and books the cash back.
            </p>
            <div id="advBody"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" type="button" onclick="puCloseAdv()">Close</button>
        </div>
    </div>
</div>

<script>
    const PU_OPEN_BILLS = <?php echo json_encode($openBills, JSON_UNESCAPED_UNICODE); ?>;

    function puOpenPay(supplierId, name, outstanding) {
        const modal = document.getElementById('payModal');
        document.getElementById('paySupplierId').value = supplierId;
        document.getElementById('paySupplierName').value = name;
        if (window.puPickBookForSupplier) puPickBookForSupplier(supplierId);
        document.getElementById('payAmount').value = outstanding.toFixed(2);
        document.getElementById('payAmount').max = outstanding.toFixed(2);
        const sel = document.getElementById('payBill');
        sel.innerHTML = '<option value="0">On account (no specific bill)</option>';
        (PU_OPEN_BILLS[supplierId] || []).forEach(function (b) {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.invoice_no + ' - due ' + Number(b.due_amount).toFixed(2);
            sel.appendChild(opt);
        });
        puPickBill();
        modal.classList.add('active');
    }

    function puPickBill() {
        const sel = document.getElementById('payBill');
        const help = document.getElementById('payBillHelp');
        const chosen = sel.options[sel.selectedIndex];
        const due = chosen && chosen.value !== '0'
            ? (PU_OPEN_BILLS[document.getElementById('paySupplierId').value] || [])
                .find(function (b) { return String(b.id) === chosen.value; })
            : null;
        if (due) {
            help.textContent = 'This bill still owes ' + Number(due.due_amount).toFixed(2) + '. A payment cannot exceed it.';
            document.getElementById('payAmount').max = Number(due.due_amount).toFixed(2);
            document.getElementById('payInvoiceLabel').value = due.invoice_no;
        } else {
            help.textContent = "An on-account payment reduces the supplier's total due.";
            document.getElementById('payAmount').max = '';
            document.getElementById('payInvoiceLabel').value = 'On account';
        }
    }

    function puClosePay() {
        document.getElementById('payModal').classList.remove('active');
    }

    function puAfterPay(what) {
        const f = document.getElementById('duesPayForm');
        let h = f.querySelector('input[name="after"]');
        if (!h) {
            h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'after';
            f.appendChild(h);
        }
        h.value = what;
    }

    function puOpenAdv(supplierId, btn) {
        const src = document.getElementById('adv-set-' + supplierId);
        if (!src) return;
        document.getElementById('advSupplier').textContent = btn.dataset.advName || '';
        document.getElementById('advBody').innerHTML = src.innerHTML;
        document.getElementById('advModal').classList.add('active');
    }

    function puCloseAdv() {
        document.getElementById('advModal').classList.remove('active');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        puClosePay();
        puCloseAdv();
    });
</script>

<?php puFilterScript(); ?>

<?php include 'includes/footer.php'; ?>
