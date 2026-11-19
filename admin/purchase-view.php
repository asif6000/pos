<?php
/**
 * POS System - Purchase Detail
 *
 * One bill, everything about it: the lines, every payment made against it, every
 * credit note raised, and the actions that change them. The money figures shown
 * here are read from the purchase row, which purchaseRecalc() rebuilds from those
 * child rows after every change, so the page cannot disagree with the ledger.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Purchase');

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

$id = (int)($_POST['purchase_id'] ?? $_GET['id'] ?? 0);
$purchase = purchaseFind($db, $id, $ownerId);

if (!$purchase) {
    setFlash('danger', 'No such purchase in this shop.');
    redirect('purchases.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('purchase-view.php', ['purchase-view.php']);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_payment') {
            $paymentId = purchasePaymentAdd($db, $actor, [
                'supplier_id' => (int)$purchase['supplier_id'],
                'purchase_id' => (int)$purchase['id'],
                'invoice_no' => $purchase['invoice_no'],
                'amount' => (float)($_POST['amount'] ?? 0),
                'payment_method' => $_POST['payment_method'] ?? '',
                'payment_date' => $_POST['payment_date'] ?? null,
                'reference' => $_POST['reference'] ?? '',
                'note' => $_POST['payment_note'] ?? '',
                'cashbook_id' => $_POST['cashbook_id'] ?? null,
            ]);
            setFlash('success', 'Payment recorded. It is in the cashbook as a money-out entry.');
            if (($_POST['after'] ?? '') === 'print') {
                redirect('purchase-print.php?id=' . (int)$purchase['id'] . '&what=receipt&payment_id=' . (int)$paymentId . '&autoprint=1');
            }
        } elseif ($action === 'delete_payment') {
            purchasePaymentDelete($db, $actor, (int)($_POST['payment_id'] ?? 0));
            setFlash('success', 'Payment removed, and its cashbook entry with it.');
        } elseif ($action === 'delete_return') {
            purchaseReturnDelete($db, $actor, (int)($_POST['return_id'] ?? 0));
            setFlash('success', 'Credit note cancelled. The goods are back in stock and the bill owes what it owed before.');
        } elseif ($action === 'delete') {
            purchaseDelete($db, $actor, (int)$purchase['id']);
            setFlash('success', 'Purchase removed and its stock reversed.');
            redirect('purchases.php');
        } else {
            setFlash('danger', 'Unknown action.');
        }
    } catch (PurchaseException $e) {
        setFlash('danger', $e->getMessage());
    }
    redirect('purchase-view.php?id=' . (int)$purchase['id']);
}

$items = purchaseItems($db, (int)$purchase['id']);
$payments = purchasePayments($db, (int)$purchase['id']);
$returns = purchaseReturns($db, (int)$purchase['id']);
$methods = purchasePaymentMethods($db, $ownerId);

$lineDisc = 0.0;
$subtotal = 0.0;
foreach ($items as $line) {
    $subtotal += (float)$line['qty'] * (float)$line['unit_price'];
    $lineDisc += (float)$line['discount'];
}
$returnedValue = 0.0;
foreach ($returns as $r) {
    $returnedValue += (float)$r['total'];
}

$due = (float)$purchase['due_amount'];
$canPay = $due > 0.009;

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
puHero('fa-file-invoice-dollar', 'Purchase ' . $purchase['invoice_no'],
    date('d M Y', strtotime($purchase['purchase_date'])) . ' from ' . $purchase['supplier_name'],
    '<a class="btn btn-primary" href="purchase-print.php?id=' . (int)$purchase['id'] . '&what=invoice">'
    . '<i class="fas fa-print"></i> Print Invoice</a>'
    . '<a class="btn btn-outline" href="supplier-ledger.php?supplier_id=' . (int)$purchase['supplier_id'] . '">'
    . '<i class="fas fa-book-open"></i> Supplier Ledger</a>'
    . '<a class="btn btn-outline" href="purchases.php"><i class="fas fa-arrow-left"></i> Back</a>');
?>

<div class="pu-cols">
    <div>
        <div class="card">
            <div class="pu-tbl-head">
                <h3><i class="fas fa-boxes-stacked" style="color:#2563eb;"></i> Items</h3>
                <span class="pu-cnt"><?php echo count($items); ?> line<?php echo count($items) === 1 ? '' : 's'; ?></span>
                <span class="pu-spacer"></span>
                <span style="font-size:.78rem; color:var(--gray-500);">
                    <i class="fas fa-warehouse"></i> Stock added to this bill was
                    <?php echo (int)$purchase['stock_applied'] === 1 ? 'received' : 'not received'; ?>
                </span>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="pu-num">Qty</th>
                                <th class="pu-num">Unit Price</th>
                                <th class="pu-num">Discount</th>
                                <th class="pu-num">Line Total</th>
                                <th class="pu-num">Returned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $line): ?>
                                <tr>
                                    <td><?php echo puEsc($line['product_name']); ?></td>
                                    <td class="pu-num"><?php echo (int)$line['qty']; ?></td>
                                    <td class="pu-num"><?php echo puMoney($line['unit_price']); ?></td>
                                    <td class="pu-num"><?php echo (float)$line['discount'] > 0 ? puMoney($line['discount']) : '<span class="pu-mut">-</span>'; ?></td>
                                    <td class="pu-num"><strong><?php echo puMoney($line['line_total']); ?></strong></td>
                                    <td class="pu-num">
                                        <?php echo (int)$line['returned_qty'] > 0
                                            ? '<span class="pu-amt out">' . (int)$line['returned_qty'] . '</span>'
                                            : '<span class="pu-mut">-</span>'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="pu-tbl-foot">
                <span>
                    Subtotal <?php echo puMoney($subtotal); ?>
                    &middot; line discounts <?php echo puMoney($lineDisc); ?>
                    &middot; bill discount <?php echo puMoney($purchase['discount']); ?>
                    <?php echo $returnedValue > 0 ? '&middot; returned value ' . puMoney($returnedValue) : ''; ?>
                </span>
                <a class="pu-lnk" style="font-size:.8rem;" href="purchase-returns.php?purchase_id=<?php echo (int)$purchase['id']; ?>">
                    <i class="fas fa-rotate-left"></i> Return goods against this bill
                </a>
            </div>
        </div>

        <div class="card" style="margin-top:1.25rem;">
            <div class="pu-tbl-head">
                <h3><i class="fas fa-hand-holding-dollar" style="color:#059669;"></i> Payments on this bill</h3>
                <span class="pu-cnt"><?php echo count($payments); ?></span>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Note</th>
                                <th class="pu-num">Amount</th>
                                <th style="width:44px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Nothing paid against this bill yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $pay): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($pay['payment_date'])); ?></td>
                                        <td><?php echo puMethodPill($pay['payment_method'], $methods); ?></td>
                                        <td><?php echo $pay['reference'] ? puEsc($pay['reference']) : '<span class="pu-mut">-</span>'; ?></td>
                                        <td class="cell-wide"><?php echo $pay['note'] ? puEsc($pay['note']) : '<span class="pu-mut">-</span>'; ?></td>
                                        <td class="pu-num"><span class="pu-amt in"><?php echo puMoney($pay['amount']); ?></span></td>
                                        <td class="cell-actions">
                                            <span class="pu-acts">
                                                <a class="pu-act" title="Print receipt"
                                                    href="purchase-print.php?id=<?php echo (int)$purchase['id']; ?>&what=receipt&payment_id=<?php echo (int)$pay['id']; ?>">
                                                    <i class="fas fa-receipt"></i>
                                                </a>
                                                <form method="POST" style="display:inline;"
                                                    onsubmit="return confirm('Remove this payment? The cashbook entry is deleted with it.');">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete_payment">
                                                    <input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['id']; ?>">
                                                    <input type="hidden" name="payment_id" value="<?php echo (int)$pay['id']; ?>">
                                                    <input type="hidden" name="back" value="purchase-view.php">
                                                    <button class="pu-act" type="submit" title="Delete payment"><i class="fas fa-trash"></i></button>
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
        </div>

        <?php if (!empty($returns)): ?>
            <div class="card" style="margin-top:1.25rem;">
                <div class="pu-tbl-head">
                    <h3><i class="fas fa-rotate-left" style="color:#b45309;"></i> Credit notes</h3>
                    <span class="pu-cnt"><?php echo count($returns); ?></span>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Return</th>
                                    <th>Date</th>
                                    <th>Refund taken</th>
                                    <th class="pu-num">Credit</th>
                                    <th style="width:44px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returns as $r): ?>
                                    <tr>
                                        <td><a class="pu-lnk" href="purchase-returns.php?search=<?php echo urlencode($r['invoice_no']); ?>">
                                                <?php echo puEsc($r['invoice_no']); ?></a></td>
                                        <td><?php echo date('d M Y', strtotime($r['return_date'])); ?></td>
                                        <td>
                                            <?php echo (float)$r['refund_amount'] > 0
                                                ? puMethodPill($r['refund_method'], $methods) . ' ' . puMoney($r['refund_amount'])
                                                : '<span class="pu-mut">adjusted on the bill</span>'; ?>
                                        </td>
                                        <td class="pu-num"><span class="pu-amt out"><?php echo puMoney($r['total']); ?></span></td>
                                        <td class="cell-actions">
                                            <form method="POST" action="purchase-view.php" style="display:inline;"
                                                onsubmit="return confirm('Cancel this credit note? The goods go back into stock and the bill owes what it owed before.');">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_return">
                                                <input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['id']; ?>">
                                                <input type="hidden" name="return_id" value="<?php echo (int)$r['id']; ?>">
                                                <input type="hidden" name="back" value="purchase-view.php">
                                                <button class="pu-act" type="submit" title="Cancel credit note"><i class="fas fa-rotate-left"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="card pu-sticky">
            <div class="pu-tbl-head">
                <h3><i class="fas fa-receipt" style="color:#2563eb;"></i> Bill Summary</h3>
                <span class="pu-spacer"></span>
                <?php echo puStatusPill($purchase['status']); ?>
            </div>
            <div class="pu-card-body">
                <div class="pu-sum">
                    <div class="pu-sum-row"><span>Grand total</span><strong><?php echo puMoney($purchase['grand_total']); ?></strong></div>
                    <div class="pu-sum-row"><span>Paid</span><strong><?php echo puMoney($purchase['paid_amount']); ?></strong></div>
                    <?php if ((float)$purchase['credit_amount'] > 0.009): ?>
                        <div class="pu-sum-row"><span>Settled by credit notes</span><strong><?php echo puMoney($purchase['credit_amount']); ?></strong></div>
                    <?php endif; ?>
                    <div class="pu-sum-row pu-sum-total"><span>Still owed</span>
                        <strong class="pu-sum-due <?php echo $due <= 0.009 ? 'clear' : ''; ?>"><?php echo puMoney($due); ?></strong></div>
                </div>
            </div>

            <div class="pu-tbl-head" style="border-top:1px solid var(--gray-100);">
                <h3 style="font-size:.92rem;"><i class="fas fa-circle-dollar-to-slot" style="color:#059669;"></i> Record a payment</h3>
            </div>
            <div class="pu-card-body">
                <?php if (!$canPay): ?>
                    <div class="pu-note">
                        <i class="fas fa-circle-check"></i>
                        <div>This bill is fully settled. Extra money for this supplier goes on
                            <a class="pu-lnk" href="supplier-dues.php">Supplier Dues</a> as an on-account payment.</div>
                    </div>
                <?php else: ?>
                    <form method="POST" action="purchase-view.php" id="payForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['id']; ?>">
                        <input type="hidden" name="back" value="purchase-view.php">
                        <div class="form-group">
                            <label class="form-label">Amount (up to <?php echo puMoney($due); ?>)</label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0.01" max="<?php echo number_format($due, 2, '.', ''); ?>"
                                value="<?php echo number_format($due, 2, '.', ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Method</label>
                            <select class="form-control" name="payment_method">
                                <?php foreach ($methods as $key => $label): ?>
                                    <option value="<?php echo puEsc($key); ?>" <?php echo $key === 'cash' ? 'selected' : ''; ?>><?php echo puEsc($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php echo purchaseCashbookField($db, $ownerId, (int)$purchase['supplier_id']); ?>
                        <div class="form-group">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reference</label>
                            <input type="text" class="form-control" name="reference" maxlength="60" placeholder="bKash trx id, cheque no...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Note</label>
                            <input type="text" class="form-control" name="payment_note" maxlength="255"
                                value="Payment for <?php echo puEsc($purchase['invoice_no']); ?>">
                        </div>
                        <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary" style="flex:1;" onclick="puAfter('save')">
                                <i class="fas fa-floppy-disk"></i> Record
                            </button>
                            <button type="submit" class="btn btn-outline" onclick="puAfter('print')">
                                <i class="fas fa-receipt"></i> Record &amp; Receipt
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="pu-tbl-head" style="border-top:1px solid var(--gray-100);">
                <h3 style="font-size:.92rem;"><i class="fas fa-circle-info" style="color:#2563eb;"></i> Details</h3>
            </div>
            <div class="pu-card-body">
                <div class="pu-meta">
                    <div><span>Supplier</span><strong><a class="pu-lnk" href="suppliers.php?edit=<?php echo (int)$purchase['supplier_id']; ?>"><?php echo puEsc($purchase['supplier_name']); ?></a></strong></div>
                    <div><span>Store</span><strong><?php echo puEsc($purchase['store_name'] ?? '-'); ?></strong></div>
                    <div><span>Purchase date</span><strong><?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?></strong></div>
                    <div><span>Entered by</span><strong><?php echo puEsc($purchase['user_name'] ?? '-'); ?></strong></div>
                    <div><span>Created</span><strong><?php echo date('d M Y, h:i A', strtotime($purchase['created_at'])); ?></strong></div>
                    <div><span>Note</span><strong><?php echo $purchase['note'] ? puEsc($purchase['note']) : '-'; ?></strong></div>
                </div>
            </div>

            <div class="pu-card-body" style="padding-top:0;">
                <form method="POST" action="purchase-view.php"
                    onsubmit="return confirm('Delete this purchase? The stock it added is taken back out and its payments are removed.');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="purchase_id" value="<?php echo (int)$purchase['id']; ?>">
                    <input type="hidden" name="back" value="purchase-view.php">
                    <button type="submit" class="btn btn-outline" style="width:100%; color:#be123c; border-color:#fbd5d5;">
                        <i class="fas fa-trash"></i> Delete this purchase
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function puAfter(what) {
        const form = document.getElementById('payForm');
        if (!form) return;
        let f = form.querySelector('input[name="after"]');
        if (!f) {
            f = document.createElement('input');
            f.type = 'hidden';
            f.name = 'after';
            form.appendChild(f);
        }
        f.value = what;
    }
</script>

<?php include 'includes/footer.php'; ?>
