<?php
/**
 * POS System - Supplier Ledger
 *
 * The supplier's account statement: every bill a debit, every payment and credit
 * note a credit, oldest first, with a running balance.
 *
 * There is no ledger table. The statement is derived from purchases,
 * supplier_payments and purchase_returns each time it is opened, which means it
 * can never fall out of step with the bills - the closing balance is the same
 * arithmetic the due list does from the other direction.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Supplier Ledger');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];

purchaseEnsureTables($db);

$suppliers = purchaseSupplierOptions($ownerId);
$supplierId = (int)($_GET['supplier_id'] ?? 0);
if (!$supplierId && !empty($suppliers)) {
    $supplierId = (int)$suppliers[0]['id'];
}

list($dateFrom, $dateTo) = salesDateRange($_GET);
// The dues page links in with an as-at day; that is the end of the window.
if (isset($_GET['as_at']) && trim((string)$_GET['as_at']) !== '') {
    $dateTo = purchaseDateInput($_GET['as_at']);
}

$ledger = null;
$supplier = null;
$allTimeDue = null;
if ($supplierId) {
    foreach ($suppliers as $s) {
        if ((int)$s['id'] === $supplierId) {
            $supplier = $s;
        }
    }
    if ($supplier) {
        $ledger = supplierLedger($db, $ownerId, $supplierId, $dateFrom, $dateTo);
        $allTimeDue = supplierDueTotal($db, $ownerId, $supplierId);
    }
}

include 'includes/header.php';
include 'includes/purchase-ui.php';
puStyles();
?>

<style>
    @media print {
        .sidebar, .header, .pu-hero-actions, .no-print { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; }
        body { background: #fff; }
        .card { border: none !important; box-shadow: none !important; }
        html[data-theme="dark"] body { color: #000; }
    }
</style>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<?php
puHero('fa-book-open', 'Supplier Ledger',
    $supplier ? $supplier['name'] . ' - account statement' : 'Pick a supplier to see its account',
    '<a class="btn btn-outline no-print" href="supplier-dues.php"><i class="fas fa-scale-unbalanced"></i> Supplier Dues</a>'
    . '<button class="btn btn-primary no-print" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>');
?>

<div class="card">
    <form method="GET" class="pu-form">
        <?php
        $filterHtml = puSelectGroup('Supplier', 'supplier_id', $supplierId ? $supplierId : '',
            array_column($suppliers, 'name', 'id'), null)
            . puDateGroup('From Date', 'date_from', $dateFrom)
            . puDateGroup('To Date', 'date_to', $dateTo);
        puFilterHead([
            'title' => 'Period',
            'icon' => 'fa-filter',
            'count' => $ledger ? count($ledger['rows']) : 0,
            'filters' => $filterHtml,
            'hasFilters' => true,
            'clearUrl' => 'supplier-ledger.php?supplier_id=' . (int)$supplierId,
        ]);
        ?>
    </form>

    <?php if (!$supplier): ?>
        <div class="pu-card-body">
            <div class="pu-note">
                <i class="fas fa-circle-info"></i>
                <div>This shop has no suppliers yet. Add one on the
                    <a class="pu-lnk" href="suppliers.php">Suppliers</a> page and its account will appear here.</div>
            </div>
        </div>
    <?php else: ?>
        <?php
        $debits = 0.0;
        $credits = 0.0;
        foreach ($ledger['rows'] as $row) {
            $debits += (float)$row['debit'];
            $credits += (float)$row['credit'];
        }
        ?>
        <div class="pu-card-body" style="padding-top:0;">
            <div class="pu-meta">
                <div><span>Supplier</span><strong><?php echo puEsc($supplier['name']); ?></strong></div>
                <div><span>Company</span><strong><?php echo $supplier['company'] ? puEsc($supplier['company']) : '-'; ?></strong></div>
                <div><span>Phone</span><strong><?php echo $supplier['phone'] ? puEsc($supplier['phone']) : '-'; ?></strong></div>
                <div><span>Window</span><strong><?php echo date('d M Y', strtotime($dateFrom)) . ' - ' . date('d M Y', strtotime($dateTo)); ?></strong></div>
            </div>
        </div>

        <?php
        puKpiOpen();
        puKpi(['icon' => 'fa-arrow-right-to-bracket', 'label' => 'Opening balance',
            'value' => puMoney($ledger['opening']), 'foot' => 'before ' . date('d M Y', strtotime($dateFrom))]);
        puKpi(['tone' => 'red', 'icon' => 'fa-file-invoice', 'label' => 'Bills in the period',
            'value' => puMoney($debits), 'foot' => 'debited to the account']);
        puKpi(['tone' => 'green', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Payments and credits',
            'value' => puMoney($credits), 'foot' => 'credited to the account']);
        puKpi(['tone' => 'amber', 'icon' => 'fa-scale-unbalanced', 'label' => 'Closing balance',
            'value' => puMoney($ledger['closing']),
            'foot' => 'all-time outstanding on the bills: ' . puMoney($allTimeDue)]);
        puKpiClose();
        ?>

        <div class="card-body" style="padding:0;">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:110px;">Date</th>
                            <th>Particulars</th>
                            <th>Ref</th>
                            <th class="pu-num">Debit</th>
                            <th class="pu-num">Credit</th>
                            <th class="pu-num">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($dateFrom)); ?></td>
                            <td><strong>Opening balance</strong></td>
                            <td><span class="pu-mut">-</span></td>
                            <td class="pu-num"><span class="pu-mut">-</span></td>
                            <td class="pu-num"><span class="pu-mut">-</span></td>
                            <td class="pu-num"><strong><?php echo puMoney($ledger['opening']); ?></strong></td>
                        </tr>
                        <?php if (empty($ledger['rows'])): ?>
                            <tr><td colspan="6" class="text-center text-muted">
                                No purchases, payments or credit notes in this period.
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($ledger['rows'] as $row): ?>
                                <?php
                                $kind = $row['kind'];
                                $tone = $kind === 'Purchase' ? ['#fdecec', '#be123c', 'fa-file-invoice']
                                    : ($kind === 'Payment' ? ['#e6f9f0', '#047857', 'fa-hand-holding-dollar']
                                        : ['#fff4e0', '#b45309', 'fa-rotate-left']);
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($row['dt'])); ?></td>
                                    <td>
                                        <span class="pu-pill" style="background:<?php echo $tone[0]; ?>; color:<?php echo $tone[1]; ?>;">
                                            <i class="fas <?php echo $tone[2]; ?>"></i> <?php echo puEsc($kind); ?>
                                        </span>
                                        <?php if ($row['note']): ?>
                                            <div class="pu-mut" style="font-size:.74rem;"><?php echo puEsc($row['note']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['ref_id'])): ?>
                                            <a class="pu-lnk" href="purchase-view.php?id=<?php echo (int)$row['ref_id']; ?>">
                                                <?php echo puEsc($row['ref']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo puEsc($row['ref']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pu-num"><?php echo (float)$row['debit'] > 0 ? puMoney($row['debit']) : '<span class="pu-mut">-</span>'; ?></td>
                                    <td class="pu-num"><?php echo (float)$row['credit'] > 0 ? puMoney($row['credit']) : '<span class="pu-mut">-</span>'; ?></td>
                                    <td class="pu-num"><strong><?php echo puMoney($row['balance']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($dateTo)); ?></td>
                            <td><strong>Closing balance</strong></td>
                            <td><span class="pu-mut">-</span></td>
                            <td class="pu-num"><span class="pu-mut">-</span></td>
                            <td class="pu-num"><span class="pu-mut">-</span></td>
                            <td class="pu-num"><strong><?php echo puMoney($ledger['closing']); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pu-tbl-foot">
            <span>Debit raises what the shop owes; credit lowers it. Balance runs forward from the opening figure.</span>
            <span class="pu-cnt"><?php echo count($ledger['rows']); ?> entries</span>
        </div>
    <?php endif; ?>
</div>

<?php puFilterScript(); ?>

<?php include 'includes/footer.php'; ?>
