<?php
/**
 * POS System - Purchase print documents
 *
 * The supplier invoice, a payment receipt and a purchase-return credit note, each
 * on its own plain page: no sidebar, no theme, nothing that would print in grey.
 * This file only renders what is already on the books - a document is a view of a
 * stored row, never a place where a figure can be edited.
 *
 * ?id=<purchase>&what=invoice|receipt|return   (payment_id / return_id narrow it)
 * &autoprint=1 opens the browser print dialog once the page has loaded.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];

purchaseEnsureTables($db);

$what = (string)($_GET['what'] ?? 'invoice');
if (!in_array($what, ['invoice', 'receipt', 'return'], true)) {
    $what = 'invoice';
}

$purchase = purchaseFind($db, (int)($_GET['id'] ?? 0), $ownerId);
if (!$purchase) {
    setFlash('danger', 'No such purchase in this shop.');
    redirect('purchases.php');
}

$items = purchaseItems($db, (int)$purchase['id']);
$payments = purchasePayments($db, (int)$purchase['id']);
$returns = purchaseReturns($db, (int)$purchase['id']);
$methods = purchasePaymentMethods($db, $ownerId);

$payment = null;
if ($what === 'receipt') {
    $wanted = (int)($_GET['payment_id'] ?? 0);
    foreach ($payments as $p) {
        if ($wanted === 0 || (int)$p['id'] === $wanted) {
            $payment = $p;
        }
    }
    if (!$payment) {
        setFlash('danger', 'This bill has no payment to print a receipt for.');
        redirect('purchase-view.php?id=' . (int)$purchase['id']);
    }
}

$ret = null;
if ($what === 'return') {
    $wanted = (int)($_GET['return_id'] ?? 0);
    foreach ($returns as $r) {
        if ($wanted === 0 || (int)$r['id'] === $wanted) {
            $ret = $r;
        }
    }
    if (!$ret) {
        setFlash('danger', 'No such purchase return.');
        redirect('purchase-view.php?id=' . (int)$purchase['id']);
    }
    $retLines = purchaseReturnItems($db, (int)$ret['id']);
}

$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $settings = [];
}
$shopName = $settings['shop_name'] ?? 'POS System';
$shopAddress = $settings['shop_address'] ?? '';
$shopPhone = $settings['shop_phone'] ?? '';

$e = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$m = function ($v) {
    return number_format((float)$v, 2, '.', ',');
};

$docTitle = [
    'invoice' => 'Purchase Invoice ' . $purchase['invoice_no'],
    'receipt' => 'Payment Receipt',
    'return'  => 'Credit Note ' . ($ret ? $ret['invoice_no'] : ''),
][$what];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($docTitle); ?></title>
<link rel="stylesheet" href="<?php echo $e(assetUrl('assets/css/hind-siliguri.css')); ?>">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Hind Siliguri', 'Segoe UI', sans-serif;
        background: #f1f5f9; color: #0f172a; margin: 0; padding: 18px 12px 40px;
        font-size: 13px; line-height: 1.45;
    }
    .sheet {
        max-width: 820px; margin: 0 auto; background: #fff; padding: 26px 30px 30px;
        border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
    }
    .bar { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .shop h1 { margin: 0; font-size: 1.25rem; font-weight: 800; }
    .shop p { margin: .1rem 0 0; font-size: .8rem; color: #475569; }
    .doc { text-align: right; }
    .doc .kind {
        display: inline-block; font-size: .72rem; font-weight: 800; letter-spacing: .12em;
        text-transform: uppercase; color: #1d4ed8; background: #e8f1ff;
        border: 1px solid #bfdbfe; border-radius: 999px; padding: .25rem .8rem;
    }
    .doc .no { margin-top: .45rem; font-size: 1.05rem; font-weight: 800; }
    .doc .when { font-size: .78rem; color: #475569; }
    .rule { border: none; border-top: 2px solid #1d4ed8; margin: 1rem 0; }
    .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .parties .lbl { font-size: .68rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #64748b; }
    .parties .val { font-weight: 700; margin-top: .15rem; }
    .parties .sub { font-size: .78rem; color: #475569; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    table.lines th {
        text-align: left; font-size: .7rem; letter-spacing: .06em; text-transform: uppercase;
        color: #1e40af; background: #eef4ff; padding: .45rem .5rem; border-bottom: 1px solid #dbeafe;
    }
    table.lines td { padding: .45rem .5rem; border-bottom: 1px solid #eef2f7; }
    table.lines .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    table.lines tfoot td { border: none; padding: .2rem .5rem; }
    .totals { width: 100%; max-width: 320px; margin-left: auto; margin-top: .8rem; font-size: .85rem; }
    .totals .row { display: flex; justify-content: space-between; gap: 1rem; padding: .2rem 0; }
    .totals .grand {
        border-top: 1px dashed #94a3b8; margin-top: .35rem; padding-top: .45rem;
        font-size: 1rem; font-weight: 800;
    }
    .totals .due { color: #b91c1c; font-weight: 800; }
    .totals .settled { color: #047857; font-weight: 800; }
    .note { margin-top: 1rem; font-size: .8rem; color: #475569; }
    .note b { color: #0f172a; }
    .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; margin-top: 2.5rem; }
    .sign div { border-top: 1px solid #94a3b8; padding-top: .35rem; font-size: .75rem; color: #475569; text-align: center; }
    .foot { margin-top: 1.25rem; text-align: center; font-size: .72rem; color: #64748b; }
    .toolbar {
        max-width: 820px; margin: 0 auto .8rem; display: flex; gap: .5rem; flex-wrap: wrap;
        align-items: center; font-size: .82rem;
    }
    .toolbar a, .toolbar button {
        display: inline-flex; align-items: center; gap: .4rem; text-decoration: none;
        background: #1d4ed8; color: #fff; border: none; border-radius: 9px;
        padding: .5rem .9rem; font: inherit; font-weight: 700; cursor: pointer;
    }
    .toolbar a.ghost { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
    @media print {
        body { background: #fff; padding: 0; font-size: 12px; }
        .sheet { border: none; box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
        .toolbar { display: none !important; }
        @page { margin: 12mm; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button type="button" onclick="window.print();">Print</button>
    <a class="ghost" href="purchase-view.php?id=<?php echo (int)$purchase['id']; ?>">Back to the bill</a>
    <span style="margin-left:auto; color:#64748b;"><?php echo $e($shopName); ?></span>
</div>

<div class="sheet">
    <div class="bar">
        <div class="shop">
            <h1><?php echo $e($shopName); ?></h1>
            <?php if ($shopAddress !== ''): ?><p><?php echo $e($shopAddress); ?></p><?php endif; ?>
            <?php if ($shopPhone !== ''): ?><p>Phone: <?php echo $e($shopPhone); ?></p><?php endif; ?>
        </div>
        <div class="doc">
            <span class="kind">
                <?php echo $what === 'invoice' ? 'Purchase Invoice' : ($what === 'receipt' ? 'Payment Receipt' : 'Credit Note'); ?>
            </span>
            <div class="no"><?php
                if ($what === 'receipt') {
                    echo 'REC-' . str_pad((string)$payment['id'], 6, '0', STR_PAD_LEFT);
                } elseif ($what === 'return') {
                    echo $e($ret['invoice_no']);
                } else {
                    echo $e($purchase['invoice_no']);
                }
            ?></div>
            <div class="when"><?php
                $whenDate = $what === 'receipt' ? $payment['payment_date'] : ($what === 'return' ? $ret['return_date'] : $purchase['purchase_date']);
                echo date('d M Y', strtotime($whenDate));
            ?></div>
        </div>
    </div>

    <hr class="rule">

    <?php if ($what === 'invoice'): ?>
        <div class="parties">
            <div>
                <div class="lbl">Supplier</div>
                <div class="val"><?php echo $e($purchase['supplier_name']); ?></div>
                <?php if (!empty($purchase['supplier_company'])): ?>
                    <div class="sub"><?php echo $e($purchase['supplier_company']); ?></div>
                <?php endif; ?>
                <?php if (!empty($purchase['supplier_phone'])): ?>
                    <div class="sub">Phone: <?php echo $e($purchase['supplier_phone']); ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="lbl">Received at</div>
                <div class="val"><?php echo $e($purchase['store_name'] ?? '-'); ?></div>
                <div class="sub">Entered by <?php echo $e($user['name']); ?></div>
            </div>
        </div>

        <table class="lines">
            <thead>
                <tr>
                    <th style="width:24px;">#</th>
                    <th>Product</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Discount</th>
                    <th class="num">Line Total</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; foreach ($items as $line): $n++; ?>
                    <tr>
                        <td><?php echo $n; ?></td>
                        <td><?php echo $e($line['product_name']); ?></td>
                        <td class="num"><?php echo (int)$line['qty']; ?></td>
                        <td class="num"><?php echo $m($line['unit_price']); ?></td>
                        <td class="num"><?php echo (float)$line['discount'] > 0 ? $m($line['discount']) : '-'; ?></td>
                        <td class="num"><?php echo $m($line['line_total']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="row"><span>Subtotal</span><span><?php echo $m($purchase['subtotal']); ?></span></div>
            <div class="row"><span>Bill discount</span><span><?php echo $m($purchase['discount']); ?></span></div>
            <?php if ((float)$purchase['credit_amount'] > 0.009): ?>
                <div class="row"><span>Credit notes</span><span><?php echo $m($purchase['credit_amount']); ?></span></div>
            <?php endif; ?>
            <div class="row grand"><span>Grand Total</span><span><?php echo $m($purchase['grand_total']); ?></span></div>
            <div class="row"><span>Paid</span><span><?php echo $m($purchase['paid_amount']); ?></span></div>
            <div class="row"><span>Outstanding</span>
                <span class="<?php echo (float)$purchase['due_amount'] > 0.009 ? 'due' : 'settled'; ?>">
                    <?php echo $m($purchase['due_amount']); ?>
                </span>
            </div>
        </div>

        <?php if (!empty($purchase['note'])): ?>
            <div class="note"><b>Note:</b> <?php echo $e($purchase['note']); ?></div>
        <?php endif; ?>
        <div class="note">Goods are received into stock at the moment this bill is saved. Any return against it
            is issued as a separate credit note.</div>

    <?php elseif ($what === 'receipt'): ?>
        <div class="parties">
            <div>
                <div class="lbl">Paid to</div>
                <div class="val"><?php echo $e($purchase['supplier_name']); ?></div>
                <?php if (!empty($purchase['supplier_phone'])): ?>
                    <div class="sub">Phone: <?php echo $e($purchase['supplier_phone']); ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="lbl">Against</div>
                <div class="val">
                    <a href="purchase-view.php?id=<?php echo (int)$purchase['id']; ?>" style="color:#0f172a; text-decoration:none;">
                        <?php echo $e($purchase['invoice_no']); ?>
                    </a>
                </div>
                <div class="sub"><?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?></div>
            </div>
        </div>

        <div class="totals" style="max-width:360px; margin-top:1.5rem;">
            <div class="row grand"><span>Amount received</span><span><?php echo $m($payment['amount']); ?></span></div>
            <div class="row"><span>Payment method</span>
                <span><?php echo $e($methods[$payment['payment_method']] ?? ucfirst($payment['payment_method'])); ?></span></div>
            <div class="row"><span>Date</span><span><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></span></div>
            <?php if (!empty($payment['reference'])): ?>
                <div class="row"><span>Reference</span><span><?php echo $e($payment['reference']); ?></span></div>
            <?php endif; ?>
            <div class="row"><span>Bill total</span><span><?php echo $m($purchase['grand_total']); ?></span></div>
            <div class="row"><span>Total paid on this bill</span><span><?php echo $m($purchase['paid_amount']); ?></span></div>
            <div class="row"><span>Still outstanding</span>
                <span class="<?php echo (float)$purchase['due_amount'] > 0.009 ? 'due' : 'settled'; ?>">
                    <?php echo $m($purchase['due_amount']); ?>
                </span>
            </div>
        </div>

        <?php if (!empty($payment['note'])): ?>
            <div class="note"><b>Note:</b> <?php echo $e($payment['note']); ?></div>
        <?php endif; ?>

    <?php else: ?>
        <div class="parties">
            <div>
                <div class="lbl">Supplier</div>
                <div class="val"><?php echo $e($purchase['supplier_name']); ?></div>
            </div>
            <div>
                <div class="lbl">Against invoice</div>
                <div class="val"><?php echo $e($purchase['invoice_no']); ?></div>
                <div class="sub"><?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?></div>
            </div>
        </div>

        <table class="lines">
            <thead>
                <tr>
                    <th style="width:24px;">#</th>
                    <th>Product returned</th>
                    <th class="num">Qty</th>
                    <th class="num">Unit Price</th>
                    <th class="num">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 0; foreach ($retLines as $line): $n++; ?>
                    <tr>
                        <td><?php echo $n; ?></td>
                        <td><?php echo $e($line['product_name'] !== null && $line['product_name'] !== '' ? $line['product_name'] : 'Item ' . $line['purchase_item_id']); ?></td>
                        <td class="num"><?php echo (int)$line['qty']; ?></td>
                        <td class="num"><?php echo $m($line['unit_price']); ?></td>
                        <td class="num"><?php echo $m($line['line_total']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="row grand"><span>Credit to the bill</span><span><?php echo $m($ret['total']); ?></span></div>
            <div class="row"><span>Cash taken back</span><span><?php echo $m($ret['refund_amount']); ?></span></div>
            <?php if ((float)$ret['refund_amount'] > 0.009): ?>
                <div class="row"><span>Refunded through</span>
                    <span><?php echo $e($methods[$ret['refund_method']] ?? ucfirst((string)$ret['refund_method'])); ?></span></div>
            <?php endif; ?>
            <div class="row"><span>Reduces the due by</span>
                <span class="settled"><?php echo $m((float)$ret['total'] - (float)$ret['refund_amount']); ?></span></div>
        </div>

        <?php if (!empty($ret['note'])): ?>
            <div class="note"><b>Note:</b> <?php echo $e($ret['note']); ?></div>
        <?php endif; ?>
        <div class="note">These goods have been removed from the store's stock count.</div>
    <?php endif; ?>

    <div class="sign">
        <div>Supplier signature</div>
        <div>Authorised signature</div>
    </div>

    <div class="foot">
        <?php echo $e($settings['receipt_footer'] ?? 'Computer generated document, no signature required for record keeping.'); ?>
        &middot; Printed <?php echo date('d M Y, h:i A'); ?> by <?php echo $e($user['name']); ?>
    </div>
</div>

<?php if ((string)($_GET['autoprint'] ?? '') === '1'): ?>
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
<?php endif; ?>

</body>
</html>
