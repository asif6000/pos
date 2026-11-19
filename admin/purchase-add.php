<?php
/**
 * POS System - New Purchase
 *
 * One form, one bill: goods in, money settled, credit taken. Nothing here does
 * arithmetic on the ledgers itself - the entries are handed to purchaseCreate(),
 * which validates them, writes the bill, puts the stock in the store exactly once
 * and works out what is still owed in a single transaction.
 */

require_once '../config/db.php';
require_once '../config/purchases.php';
require_once '../config/csrf.php';
startSecureSession();

requirePermission('suppliers');

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'New Purchase');

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
$stores = purchaseStores($ownerId);
$suppliers = purchaseSupplierOptions($ownerId);
$branchStoreId = $actor['store_id'];

// The store the goods arrive at: the branch user's own, or whatever the owner picked.
$storeId = $branchStoreId ?: (int)($_POST['store_id'] ?? $_GET['store_id'] ?? 0);
if (!$storeId && !empty($stores)) {
    $storeId = (int)$stores[0]['id'];
}

$branchStoreName = '';
foreach ($stores as $st) {
    if ((int)$st['id'] === $storeId) {
        $branchStoreName = $st['name'];
    }
}
if ($branchStoreName === '' && $storeId) {
    $branchStoreName = '#' . $storeId;
}

$draft = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$draftError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('purchase-add.php', ['purchase-add.php']);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $items = [];
        foreach ((isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'product_id' => (int)($row['product_id'] ?? 0),
                'qty' => (int)($row['qty'] ?? 0),
                'unit_price' => (float)($row['unit_price'] ?? 0),
                'discount' => (float)($row['discount'] ?? 0),
            ];
        }

        try {
            $created = purchaseCreate($db, $actor, [
                'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
                'store_id' => $storeId,
                'purchase_date' => $_POST['purchase_date'] ?? null,
                'note' => $_POST['note'] ?? '',
                'discount' => (float)($_POST['discount'] ?? 0),
                'update_buy_price' => !empty($_POST['update_buy_price']),
                'items' => $items,
                'payment' => [
                    'amount' => (float)($_POST['paid_amount'] ?? 0),
                    'method' => $_POST['payment_method'] ?? 'cash',
                    'date' => $_POST['payment_date'] ?? null,
                    'reference' => $_POST['reference'] ?? '',
                    'note' => $_POST['payment_note'] ?? '',
                    'cashbook_id' => $_POST['cashbook_id'] ?? null,
                ],
            ]);
            setFlash('success', 'Purchase ' . $created['invoice_no'] . ' saved.');
            if (($_POST['after'] ?? '') === 'print') {
                redirect('purchase-print.php?id=' . (int)$created['id'] . '&what=invoice&autoprint=1');
            }
            redirect('purchase-view.php?id=' . (int)$created['id']);
        } catch (PurchaseException $e) {
            // Stay on the form and keep the lines: re-typing a whole bill because
            // one field was wrong is what makes people stop using a purchase page.
            setFlash('danger', $e->getMessage());
            $draftError = true;
        }
    }
}

$purchaseDate = purchaseDateInput($draft['purchase_date'] ?? date('Y-m-d'));
$paymentDate = purchaseDateInput($draft['payment_date'] ?? date('Y-m-d'));
$supplierId = (int)($draft['supplier_id'] ?? 0);
$billDiscount = (float)($draft['discount'] ?? 0);
$paidAmount = (float)($draft['paid_amount'] ?? 0);
$paymentMethod = $draft['payment_method'] ?? 'cash';
$note = $draft['note'] ?? '';
$reference = $draft['reference'] ?? '';
$updateBuyPrice = !empty($draft['update_buy_price']);

// Lines to redraw after a rejected POST.
$draftLines = [];
if ($draftError && isset($draft['items']) && is_array($draft['items'])) {
    foreach ($draft['items'] as $row) {
        if (is_array($row) && (int)($row['product_id'] ?? 0) > 0) {
            $draftLines[] = $row;
        }
    }
}

$products = purchaseFormProducts($ownerId, $storeId);

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
if (empty($suppliers)) {
    puHero('fa-cart-plus', 'New Purchase', 'Add goods bought from a supplier');
    echo '<div class="card"><div class="pu-card-body"><div class="pu-note">'
        . '<i class="fas fa-circle-info"></i><div>No supplier is registered for this shop yet. '
        . 'Add one on the <a class="pu-lnk" href="suppliers.php">Suppliers</a> page first - every purchase '
        . 'is filed against a supplier so its due can be tracked.</div></div></div></div>';
    include 'includes/footer.php';
    exit;
}

puHero('fa-cart-plus', 'New Purchase', 'Goods received, payment settled or taken on credit');
?>

<form method="POST" action="purchase-add.php" id="purForm" autocomplete="off">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="after" value="" id="afterField">

    <div class="pu-cols">
        <div>
            <div class="card">
                <div class="pu-tbl-head">
                    <h3><i class="fas fa-file-circle-plus" style="color:#2563eb;"></i> Bill Details</h3>
                </div>
                <div class="pu-card-body">
                    <div class="form-row" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem;">
                        <div class="form-group">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-control" name="supplier_id" required
                                onchange="window.puPickBookForSupplier && puPickBookForSupplier(this.value)">
                                <option value="">Choose supplier...</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>"
                                        <?php echo $supplierId === (int)$s['id'] ? 'selected' : ''; ?>>
                                        <?php echo puEsc($s['name']); ?>
                                        <?php echo $s['company'] ? ' (' . puEsc($s['company']) . ')' : ''; ?>
                                        <?php echo $s['status'] !== 'active' ? ' - inactive' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" class="form-control" name="purchase_date" value="<?php echo puEsc($purchaseDate); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Store (goods arrive here)</label>
                            <?php if ($branchStoreId): ?>
                                <input type="text" class="form-control" readonly value="<?php echo puEsc($branchStoreName); ?>">
                                <small class="text-muted">Your account only buys for its own branch.</small>
                            <?php else: ?>
                                <select class="form-control" name="store_id" id="storeSelect">
                                    <?php foreach ($stores as $st): ?>
                                        <option value="<?php echo (int)$st['id']; ?>" <?php echo $storeId === (int)$st['id'] ? 'selected' : ''; ?>>
                                            <?php echo puEsc($st['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:.25rem;">
                        <label class="form-label">Note (printed on the invoice)</label>
                        <input type="text" class="form-control" name="note" maxlength="255"
                            placeholder="Invoice no, vehicle, remarks..." value="<?php echo puEsc($note); ?>">
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top:1.25rem;">
                <div class="pu-tbl-head">
                    <h3><i class="fas fa-boxes-stacked" style="color:#2563eb;"></i> Products</h3>
                    <span class="pu-spacer"></span>
                    <span class="pu-cnt" id="lineCount">0 lines</span>
                </div>

                <div class="pu-card-body" style="padding-bottom:0;">
                    <div class="pu-pick">
                        <label class="form-label">Search products by name or barcode</label>
                        <span class="pu-search">
                            <i class="fas fa-search"></i>
                            <input type="text" class="form-control" id="prodSearch"
                                placeholder="Type a product name or scan a barcode, Enter to add">
                        </span>
                        <div class="pu-pick-list" id="prodList"></div>
                    </div>
                </div>

                <div class="card-body" style="padding: 1rem 1.25rem 0;">
                    <div class="table-wrapper">
                        <table class="table pu-lines">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="width:90px;">Qty</th>
                                    <th style="width:120px;">Unit Price</th>
                                    <th style="width:110px;">Discount</th>
                                    <th style="width:120px;" class="pu-num">Line Total</th>
                                    <th style="width:44px;"></th>
                                </tr>
                            </thead>
                            <tbody id="lineBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="pu-tbl-foot">
                    <span>Stock counts below each line are what the store holds <em>before</em> this bill is saved.</span>
                    <label class="pu-pill" style="background:#eef4ff; color:#1d4ed8; cursor:pointer;">
                        <input type="checkbox" name="update_buy_price" value="1" style="margin:0;"
                            <?php echo $updateBuyPrice ? 'checked' : ''; ?>>
                        Set product cost price to these rates
                    </label>
                </div>
            </div>
        </div>

        <div>
            <div class="card pu-sticky">
                <div class="pu-tbl-head">
                    <h3><i class="fas fa-receipt" style="color:#2563eb;"></i> Summary</h3>
                </div>
                <div class="pu-card-body">
                    <div class="pu-sum">
                        <div class="pu-sum-row"><span>Subtotal</span><strong id="sumSubtotal">0.00</strong></div>
                        <div class="pu-sum-row"><span>Line discounts</span><strong id="sumLineDisc">0.00</strong></div>
                        <div class="form-group" style="margin:.35rem 0 0;">
                            <label class="form-label">Extra bill discount</label>
                            <input type="number" class="form-control" name="discount" id="billDiscount" step="0.01" min="0"
                                value="<?php echo $billDiscount > 0 ? number_format($billDiscount, 2, '.', '') : ''; ?>">
                        </div>
                        <div class="pu-sum-row pu-sum-total"><span>Grand Total</span><strong id="sumGrand">0.00</strong></div>
                        <div class="pu-sum-row"><span>Outstanding after payment</span>
                            <strong class="pu-sum-due" id="sumDue">0.00</strong></div>
                    </div>
                </div>

                <div class="pu-tbl-head" style="border-top:1px solid var(--gray-100);">
                    <h3 style="font-size:.92rem;"><i class="fas fa-hand-holding-dollar" style="color:#059669;"></i> Payment</h3>
                </div>
                <div class="pu-card-body">
                    <div class="form-group">
                        <label class="form-label">Amount paid now</label>
                        <input type="number" class="form-control" name="paid_amount" id="paidAmount" step="0.01" min="0"
                            value="<?php echo $paidAmount > 0 ? number_format($paidAmount, 2, '.', '') : ''; ?>">
                        <small class="text-muted">Leave 0 for a full credit purchase.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Method</label>
                        <select class="form-control" name="payment_method">
                            <?php foreach ($methods as $key => $label): ?>
                                <option value="<?php echo puEsc($key); ?>" <?php echo $paymentMethod === $key ? 'selected' : ''; ?>>
                                    <?php echo puEsc($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php echo purchaseCashbookField($db, $ownerId, $supplierId, $_POST['cashbook_id'] ?? null); ?>
                    <div class="form-group">
                        <label class="form-label">Payment date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo puEsc($paymentDate); ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Reference (transaction / cheque no)</label>
                        <input type="text" class="form-control" name="reference" maxlength="60" value="<?php echo puEsc($reference); ?>">
                    </div>
                    <div style="display:flex; gap:.6rem; flex-wrap:wrap; margin-top:1.1rem;">
                        <button type="submit" class="btn btn-primary" style="flex:1;" onclick="puAfter('save')">
                            <i class="fas fa-floppy-disk"></i> Save Purchase
                        </button>
                        <button type="submit" class="btn btn-outline" onclick="puAfter('print')">
                            <i class="fas fa-print"></i> Save &amp; Print
                        </button>
                    </div>
                    <a href="purchases.php" class="btn btn-secondary" style="width:100%; margin-top:.6rem;">
                        <i class="fas fa-xmark"></i> Cancel
                    </a>
                    <p class="text-muted" style="font-size:.74rem; margin:.85rem 0 0;">
                        Saving puts the goods into stock once, records the payment in the cashbook when cash
                        leaves, and files the remaining amount as supplier due.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    const PU_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
    const PU_DRAFT = <?php echo json_encode($draftLines); ?>;
    const PU_STORE_KEY = 'purchase-draft-lines';
    const money = (v) => (Math.round((Number(v) || 0) * 100) / 100).toFixed(2);

    let puIndex = 0;

    function puAfter(what) {
        document.getElementById('afterField').value = what;
    }

    function puProduct(id) {
        for (const p of PU_PRODUCTS) {
            if (String(p.id) === String(id)) return p;
        }
        return null;
    }

    function puAddLine(product, qty, price, discount) {
        const body = document.getElementById('lineBody');
        const tr = document.createElement('tr');
        tr.dataset.product = product.id;
        const stock = (product.stock === null || product.stock === undefined) ? '-' : product.stock;
        tr.innerHTML =
            '<td>' +
            '<div class="pu-inv-no">' + puHtml(product.name) + '</div>' +
            '<div class="pu-mut" style="font-size:.72rem;">' +
            (product.barcode ? puHtml(product.barcode) + ' &middot; ' : '') + 'stock ' + puHtml(stock) +
            (product.unit ? ' ' + puHtml(product.unit) : '') +
            ' &middot; cost ' + money(product.buy_price) + '</div>' +
            '<input type="hidden" name="items[' + puIndex + '][product_id]" value="' + product.id + '">' +
            '</td>' +
            '<td><input type="number" class="form-control pu-qty" name="items[' + puIndex + '][qty]" min="1" step="1" value="' + (qty || 1) + '"></td>' +
            '<td><input type="number" class="form-control pu-price" name="items[' + puIndex + '][unit_price]" min="0" step="0.01" value="' + money(price) + '"></td>' +
            '<td><input type="number" class="form-control pu-disc" name="items[' + puIndex + '][discount]" min="0" step="0.01" value="' + money(discount || 0) + '"></td>' +
            '<td class="pu-num pu-total">0.00</td>' +
            '<td class="cell-check"><button type="button" class="pu-act" title="Remove line">' +
            '<i class="fas fa-trash"></i></button></td>';
        body.appendChild(tr);
        puIndex++;
        tr.querySelector('.pu-act').addEventListener('click', () => { tr.remove(); puRecalc(); });
        tr.querySelectorAll('input').forEach((inp) => inp.addEventListener('input', puRecalc));
        puRecalc();
    }

    function puHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function puRecalc() {
        let subtotal = 0, lineDisc = 0;
        document.querySelectorAll('#lineBody tr').forEach((tr) => {
            const qty = Number(tr.querySelector('.pu-qty').value) || 0;
            const price = Number(tr.querySelector('.pu-price').value) || 0;
            const disc = Number(tr.querySelector('.pu-disc').value) || 0;
            const gross = qty * price;
            subtotal += gross;
            lineDisc += disc;
            tr.querySelector('.pu-total').textContent = money(Math.max(0, gross - disc));
        });
        const grand = Math.max(0, subtotal - lineDisc - (Number(document.getElementById('billDiscount').value) || 0));
        const paid = Math.max(0, Math.min(Number(document.getElementById('paidAmount').value) || 0, grand));
        document.getElementById('sumSubtotal').textContent = money(subtotal);
        document.getElementById('sumLineDisc').textContent = money(lineDisc);
        document.getElementById('sumGrand').textContent = money(grand);
        const due = document.getElementById('sumDue');
        due.textContent = money(grand - paid);
        due.classList.toggle('clear', grand - paid <= 0.009);
        document.getElementById('lineCount').textContent =
            document.querySelectorAll('#lineBody tr').length + ' line' + (document.querySelectorAll('#lineBody tr').length === 1 ? '' : 's');
    }

    // --- product picker -------------------------------------------------------
    const search = document.getElementById('prodSearch');
    const list = document.getElementById('prodList');
    let puHits = [], puHl = -1;

    function puRender(q) {
        const needle = q.trim().toLowerCase();
        puHits = !needle ? [] : PU_PRODUCTS.filter((p) =>
            p.name.toLowerCase().includes(needle) || (p.barcode || '').toLowerCase().includes(needle)
        ).slice(0, 30);
        puHl = -1;
        if (!puHits.length) {
            list.classList.remove('open');
            list.innerHTML = '';
            return;
        }
        list.innerHTML = puHits.map((p, i) =>
            '<button type="button" class="pu-pick-row" data-i="' + i + '">' +
            '<strong>' + puHtml(p.name) + '</strong>' +
            '<span class="pu-pick-meta">' + (p.barcode ? puHtml(p.barcode) + ' &middot; ' : '') +
            'cost ' + money(p.buy_price) + ' &middot; stock ' + puHtml(p.stock) + '</span>' +
            '</button>'
        ).join('');
        list.classList.add('open');
        list.querySelectorAll('.pu-pick-row').forEach((btn) => {
            btn.addEventListener('mousedown', (e) => { e.preventDefault(); puPick(puHits[Number(btn.dataset.i)]); });
        });
    }

    function puPick(product) {
        if (!product) return;
        const existing = document.querySelector('#lineBody tr[data-product="' + product.id + '"]');
        if (existing) {
            const qty = existing.querySelector('.pu-qty');
            qty.value = (Number(qty.value) || 0) + 1;
            puRecalc();
        } else {
            puAddLine(product, 1, product.buy_price, 0);
        }
        search.value = '';
        list.classList.remove('open');
        list.innerHTML = '';
        search.focus();
    }

    search.addEventListener('input', () => puRender(search.value));
    search.addEventListener('focus', () => puRender(search.value));
    search.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!puHits.length) return;
            e.preventDefault();
            puHl = (puHl + (e.key === 'ArrowDown' ? 1 : -1) + puHits.length) % puHits.length;
            list.querySelectorAll('.pu-pick-row').forEach((b, i) => b.classList.toggle('hl', i === puHl));
            return;
        }
        if (e.key === 'Escape') { list.classList.remove('open'); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            const exact = PU_PRODUCTS.find((p) => (p.barcode || '') === search.value.trim());
            puPick(exact || puHits[puHl >= 0 ? puHl : 0]);
        }
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.pu-pick')) list.classList.remove('open');
    });

    document.getElementById('billDiscount').addEventListener('input', puRecalc);
    document.getElementById('paidAmount').addEventListener('input', puRecalc);

    // --- keep the lines when the store switch reloads the page -----------------
    function puSaveDraft() {
        const lines = [];
        document.querySelectorAll('#lineBody tr').forEach((tr) => {
            lines.push({
                product_id: Number(tr.querySelector('[name$="[product_id]"]').value),
                qty: Number(tr.querySelector('.pu-qty').value),
                unit_price: Number(tr.querySelector('.pu-price').value),
                discount: Number(tr.querySelector('.pu-disc').value)
            });
        });
        sessionStorage.setItem(PU_STORE_KEY, JSON.stringify(lines));
    }

    function puLoadDraft() {
        try {
            const raw = sessionStorage.getItem(PU_STORE_KEY);
            if (!raw) return [];
            const lines = JSON.parse(raw);
            sessionStorage.removeItem(PU_STORE_KEY);
            return Array.isArray(lines) ? lines : [];
        } catch (err) {
            return [];
        }
    }

    const storeSelect = document.getElementById('storeSelect');
    if (storeSelect) {
        storeSelect.addEventListener('change', () => {
            puSaveDraft();
            // Reload so the stock and cost figures shown per product belong to
            // the store the goods are going into.
            window.location = 'purchase-add.php?store_id=' + encodeURIComponent(storeSelect.value);
        });
    }

    const carried = puLoadDraft();
    if (PU_DRAFT.length) {
        PU_DRAFT.forEach((l) => {
            const p = puProduct(l.product_id);
            if (p) puAddLine(p, Number(l.qty) || 1, Number(l.unit_price) || p.buy_price, Number(l.discount) || 0);
        });
    } else if (carried.length) {
        carried.forEach((l) => {
            const p = puProduct(l.product_id);
            if (p) puAddLine(p, l.qty || 1, l.unit_price, l.discount || 0);
        });
    }
    document.getElementById('purForm').addEventListener('submit', () => sessionStorage.removeItem(PU_STORE_KEY));
    puRecalc();
</script>

<?php include 'includes/footer.php'; ?>
