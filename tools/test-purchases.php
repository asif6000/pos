<?php
/**
 * Test - Supplier Purchase and Due module (config/purchases.php)
 *
 * Run:  php tools/test-purchases.php
 *       C:\xampp\php\php.exe tools/test-purchases.php      (XAMPP on Windows)
 *
 * WHY EVERYTHING ROLLS BACK
 * -------------------------
 * This test moves stock and money, so it must never leave a trace: a shop's real
 * quantities are not a place to practise. Every module call runs inside one
 * transaction the script owns and rolls back at the end, so nothing is ever
 * committed no matter where a failure happens. That only works because the
 * domain layer will not commit a transaction it did not open
 * (purchaseTransactBegin), which is what makes the module's calls composable.
 *
 * Two things are deliberately outside that transaction, for one reason each:
 *   - purchaseEnsureTables(), because DDL commits a transaction implicitly - a
 *     CREATE inside it would quietly publish every test row;
 *   - the throwaway tenant's own store/product/supplier rows, which are inserted
 *     inside it and so disappear with it.
 *
 * The tenant is synthetic (owner id PU_TEST_OWNER, its own stores, products and
 * supplier), so no real shop's rows are read or written. The single borrowed
 * value is one existing user id: stock_history.user_id has a foreign key to
 * users, and that row is only ever referenced, never modified.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This is a command line tool.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/purchases.php';

// Opened here, before a single character is printed: startSecureSession() sets
// the session save path, and PHP refuses that once output has started, which
// would bury the run's result in warnings.
startSecureSession();

const PU_TEST_OWNER = 987654;
const PU_TEST_OTHER = 987655;

$db = getDB();

$pass = 0;
$fail = 0;

function check($name, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok    $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name" . ($detail ? "  ($detail)" : '') . "\n";
    }
}

/** Money compared the way the columns hold it: to the paisa, not to the bit. */
function isMoney($a, $b)
{
    return abs((float)$a - (float)$b) < 0.005;
}

function qtyAt($db, $storeId, $productId)
{
    $st = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
    $st->execute([$storeId, $productId]);
    return (int)$st->fetchColumn();
}

function stockColumn($db, $productId)
{
    $st = $db->prepare("SELECT stock FROM products WHERE id = ?");
    $st->execute([$productId]);
    return (int)$st->fetchColumn();
}

/** Sum of every recorded quantity change for one product. */
function historySum($db, $productId)
{
    $st = $db->prepare("SELECT COALESCE(SUM(quantity_change), 0) FROM stock_history WHERE product_id = ?");
    $st->execute([$productId]);
    return (int)$st->fetchColumn();
}

function cashbookOf($db, $sourceType, $sourceId)
{
    $st = $db->prepare("SELECT type, amount FROM cashbook_entries WHERE source_type = ? AND source_id = ?");
    $st->execute([$sourceType, $sourceId]);
    return $st->fetch();
}

/** Which book a payment's cashbook line names - null when it names none. */
function cashbookBookOf($db, $paymentId)
{
    $st = $db->prepare("SELECT cashbook_id FROM cashbook_entries WHERE source_type = 'purchase_pay' AND source_id = ?");
    $st->execute([$paymentId]);
    $row = $st->fetch();
    return $row ? $row['cashbook_id'] : 'no line';
}

function rowsOf($db, $sql, $params = [])
{
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** @return string the message a rejected call gave, or '' when it did not throw. */
function rejection(callable $fn)
{
    try {
        $fn();
    } catch (PurchaseException $e) {
        return $e->getMessage();
    }
    return '';
}

purchaseEnsureTables($db);

// A caller that only borrows the transaction needs the cashbook columns to already
// exist: purchaseCashbookWrite makes them with ALTER TABLE when they do not, and
// that would commit this run's work half way through.
foreach ([
    'cashbook_entries' => ['source_type', 'cashbook_id'],
    'cash_books' => ['supplier_id'],
] as $needTable => $needCols) {
    foreach ($needCols as $needCol) {
        if (!$db->query("SHOW COLUMNS FROM $needTable LIKE '$needCol'")->fetch()) {
            echo "$needTable has no $needCol column yet.\n";
            echo "Open the Cashbook page once (it adds them), then re-run this.\n";
            exit(1);
        }
    }
}

// Read before the transaction so the rollback proof can compare against it.
$snapshot = [
    'purchases' => (int)$db->query("SELECT COUNT(*) FROM purchases")->fetchColumn(),
    'stocks' => (int)$db->query("SELECT COALESCE(SUM(quantity), 0) FROM store_stocks")->fetchColumn(),
    'cashbook' => (int)$db->query("SELECT COUNT(*) FROM cashbook_entries")->fetchColumn(),
];

$st = $db->prepare("SELECT id FROM users ORDER BY id LIMIT 1");
$st->execute();
$anchorUserId = (int)$st->fetchColumn();
if ($anchorUserId <= 0) {
    echo "No user row to point stock_history at - this database has no accounts.\n";
    exit(1);
}

$db->beginTransaction();

try {
    // ── Fixture ────────────────────────────────────────────────────────────
    $insStore = $db->prepare("INSERT INTO stores (name, owner_id, status) VALUES (?, ?, 'active')");
    $insStore->execute(['P Test Main', PU_TEST_OWNER]);
    $storeA = (int)$db->lastInsertId();
    $insStore->execute(['P Test Branch', PU_TEST_OWNER]);
    $storeB = (int)$db->lastInsertId();

    $insSup = $db->prepare("INSERT INTO suppliers (name, company, status, owner_id)
                            VALUES ('P Test Supplier', 'P Test Co', 'active', ?)");
    $insSup->execute([PU_TEST_OWNER]);
    $supplierId = (int)$db->lastInsertId();

    $insProd = $db->prepare("INSERT INTO products (name, buy_price, sell_price, stock, min_stock, unit, status, owner_id)
                             VALUES (?, ?, ?, 0, 5, 'piece', 'active', ?)");
    $prod = [];
    foreach ([['P Test Widget A', 40, 60], ['P Test Widget B', 80, 120], ['P Test Widget C', 20, 35]] as $p) {
        $insProd->execute([$p[0], $p[1], $p[2], PU_TEST_OWNER]);
        $prod[] = (int)$db->lastInsertId();
    }
    list($prodA, $prodB, $prodC) = $prod;

    $actor = ['owner_id' => PU_TEST_OWNER, 'store_id' => null, 'user_id' => $anchorUserId, 'name' => 'P Test Actor'];
    $branch = ['owner_id' => PU_TEST_OWNER, 'store_id' => $storeB, 'user_id' => $anchorUserId, 'name' => 'P Test Branch User'];
    $today = date('Y-m-d');

    // ── 1. A fully paid bill puts the goods on the shelf once ──────────────
    echo "\n1. Full payment, stock and paper trail\n";

    $p1 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId,
        'store_id' => $storeA,
        'purchase_date' => $today,
        'discount' => 100,
        'note' => 'first bill',
        'items' => [
            ['product_id' => $prodA, 'qty' => 10, 'unit_price' => 50, 'discount' => 0],
            ['product_id' => $prodB, 'qty' => 4, 'unit_price' => 100, 'discount' => 0],
        ],
        'payment' => ['amount' => 800, 'method' => 'cash', 'date' => $today],
    ]);

    check('bill total is the lines minus the bill discount', isMoney($p1['grand_total'], 800), $p1['grand_total']);
    check('paid in full, nothing owed', isMoney($p1['paid_amount'], 800) && isMoney($p1['due_amount'], 0));
    check('status is paid', $p1['status'] === 'paid', $p1['status']);
    check('the bill is marked stock-applied', (int)$p1['stock_applied'] === 1);
    check('stock arrived: 10 of A', qtyAt($db, $storeA, $prodA) === 10, qtyAt($db, $storeA, $prodA));
    check('stock arrived: 4 of B', qtyAt($db, $storeA, $prodB) === 4);
    check('legacy products.stock mirrors store stock', stockColumn($db, $prodA) === 10 && stockColumn($db, $prodB) === 4);
    check('stock_history agrees with the shelf',
        historySum($db, $prodA) === qtyAt($db, $storeA, $prodA));
    check('one history row per line, not two',
        count(rowsOf($db, "SELECT id FROM stock_history WHERE product_id = ?", [$prodA])) === 1);

    $pay1 = rowsOf($db, "SELECT * FROM supplier_payments WHERE purchase_id = ?", [$p1['id']]);
    check('exactly one payment row on the bill', count($pay1) === 1);
    $cb1 = cashbookOf($db, 'purchase_pay', (int)$pay1[0]['id']);
    check('the payment wrote one cash_out of 800', $cb1 && $cb1['type'] === 'cash_out' && isMoney($cb1['amount'], 800));
    check('one purchase wrote exactly one cashbook movement',
        count(rowsOf($db, "SELECT id FROM cashbook_entries WHERE source_type = 'purchase_pay' AND owner_id = ?",
            [PU_TEST_OWNER])) === 1);
    check('creating the bill was audited',
        count(rowsOf($db, "SELECT id FROM purchase_audit_log WHERE action = 'purchase.create' AND entity_id = ?",
            [$p1['id']])) === 1);

    // ── 2. Part payment, then the rest ─────────────────────────────────────
    echo "\n2. Partial payment and due\n";

    $p2 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId,
        'store_id' => $storeA,
        'purchase_date' => $today,
        'items' => [['product_id' => $prodA, 'qty' => 10, 'unit_price' => 100, 'discount' => 0]],
        'payment' => ['amount' => 400, 'method' => 'bank', 'date' => $today],
    ]);
    check('partly paid bill is partial', $p2['status'] === 'partial', $p2['status']);
    check('due is 600 after 400 of 1000', isMoney($p2['due_amount'], 600), $p2['due_amount']);

    $payId = purchasePaymentAdd($db, $actor, [
        'supplier_id' => $supplierId, 'purchase_id' => $p2['id'],
        'amount' => 300, 'payment_method' => 'bkash', 'payment_date' => $today,
    ]);
    $p2 = purchaseFind($db, (int)$p2['id'], PU_TEST_OWNER);
    check('after 300 more the due is 300', isMoney($p2['due_amount'], 300), $p2['due_amount']);
    check('still partial', $p2['status'] === 'partial');
    check('the extra payment wrote its own cash_out',
        isMoney(cashbookOf($db, 'purchase_pay', $payId)['amount'], 300));

    $msg = rejection(function () use ($db, $actor, $p2, $supplierId, $today) {
        purchasePaymentAdd($db, $actor, [
            'supplier_id' => $supplierId, 'purchase_id' => $p2['id'],
            'amount' => 400, 'payment_method' => 'cash', 'payment_date' => $today,
        ]);
    });
    check('paying more than the due is refused', $msg !== '' && stripos($msg, 'remaining due') !== false, $msg);

    purchasePaymentDelete($db, $actor, $payId);
    $p2 = purchaseFind($db, (int)$p2['id'], PU_TEST_OWNER);
    check('deleting a payment puts the due back', isMoney($p2['due_amount'], 600), $p2['due_amount']);
    check('deleting a payment removes its cashbook row', cashbookOf($db, 'purchase_pay', $payId) === false);
    check('and the payment row with it',
        count(rowsOf($db, "SELECT id FROM supplier_payments WHERE id = ?", [$payId])) === 0);

    // ── 3. A credit bill, returned goods and the cash the supplier handed back
    echo "\n3. Credit purchase and purchase returns\n";

    $p3 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId,
        'store_id' => $storeA,
        'purchase_date' => $today,
        'items' => [['product_id' => $prodA, 'qty' => 10, 'unit_price' => 50, 'discount' => 0]],
        'payment' => ['amount' => 0, 'method' => '', 'date' => $today],
    ]);
    check('unpaid bill is due', $p3['status'] === 'due' && isMoney($p3['due_amount'], 500));
    check('an unpaid bill moves no cash',
        count(rowsOf($db, "SELECT id FROM supplier_payments WHERE purchase_id = ?", [$p3['id']])) === 0);

    $line3 = purchaseItems($db, (int)$p3['id']);
    $itemA = (int)$line3[0]['id'];
    $stockBefore = qtyAt($db, $storeA, $prodA);

    $retId = purchaseReturnAdd($db, $actor, [
        'purchase_id' => $p3['id'], 'return_date' => $today,
        'items' => [['purchase_item_id' => $itemA, 'qty' => 4]],
        'refund_amount' => 0, 'refund_method' => '',
    ]);
    $p3 = purchaseFind($db, (int)$p3['id'], PU_TEST_OWNER);
    check('returning goods puts value back on the shelf', qtyAt($db, $storeA, $prodA) === $stockBefore - 4);
    check('credit note settles the bill', isMoney($p3['credit_amount'], 200) && isMoney($p3['due_amount'], 300),
        $p3['due_amount']);
    check('the line records what went back',
        (int)purchaseItems($db, (int)$p3['id'])[0]['returned_qty'] === 4);
    check('credit note number is issued', strpos(purchaseReturns($db, (int)$p3['id'])[0]['invoice_no'], 'PUR-RTN-') === 0);
    check('a return of no cash takes nothing out of the till',
        count(rowsOf($db, "SELECT id FROM cashbook_entries WHERE source_type = 'purchase_refund' AND owner_id = ?",
            [PU_TEST_OWNER])) === 0);

    $stockBefore2 = qtyAt($db, $storeA, $prodA);
    $retId2 = purchaseReturnAdd($db, $actor, [
        'purchase_id' => $p3['id'], 'return_date' => $today,
        'items' => [['purchase_item_id' => $itemA, 'qty' => 2]],
        'refund_amount' => 100, 'refund_method' => 'cash',
    ]);
    $p3 = purchaseFind($db, (int)$p3['id'], PU_TEST_OWNER);
    check('refunded part of a return is not credit', isMoney($p3['credit_amount'], 200) && isMoney($p3['due_amount'], 300),
        $p3['credit_amount'] . '/' . $p3['due_amount']);
    check('cash taken back is a cash_in',
        isMoney(cashbookOf($db, 'purchase_refund', $retId2)['amount'], 100)
        && cashbookOf($db, 'purchase_refund', $retId2)['type'] === 'cash_in');
    check('the goods really left', qtyAt($db, $storeA, $prodA) === $stockBefore2 - 2);

    purchaseReturnDelete($db, $actor, $retId2);
    $p3 = purchaseFind($db, (int)$p3['id'], PU_TEST_OWNER);
    check('cancelling a credit note restores the stock', qtyAt($db, $storeA, $prodA) === $stockBefore2);
    check('cancelling it drops the refund from the cashbook',
        cashbookOf($db, 'purchase_refund', $retId2) === false);
    check('cancelling a cashless credit note leaves the due alone',
        isMoney($p3['due_amount'], 300), $p3['due_amount']);

    $msg = rejection(function () use ($db, $actor, $p3, $itemA, $today) {
        purchaseReturnAdd($db, $actor, [
            'purchase_id' => $p3['id'], 'return_date' => $today,
            'items' => [['purchase_item_id' => $itemA, 'qty' => 10]],
        ]);
    });
    check('returning more than arrived is refused', $msg !== '' && stripos($msg, 'can still be returned') !== false, $msg);

    $msg = rejection(function () use ($db, $actor, $p3, $itemA, $today) {
        purchaseReturnAdd($db, $actor, [
            'purchase_id' => $p3['id'], 'return_date' => $today,
            'items' => [['purchase_item_id' => $itemA, 'qty' => 1]],
            'refund_amount' => 900, 'refund_method' => 'cash',
        ]);
    });
    check('a refund bigger than the return is refused', $msg !== '' && stripos($msg, 'more than the return value') !== false, $msg);

    $msg = rejection(function () use ($db, $actor, $p3, $itemA, $today) {
        purchaseReturnAdd($db, $actor, [
            'purchase_id' => $p3['id'], 'return_date' => $today,
            'items' => [['purchase_item_id' => $itemA, 'qty' => 1]],
            'refund_amount' => 50, 'refund_method' => 'bitcoin',
        ]);
    });
    check('a refund in a method the shop does not use is refused', $msg !== '', $msg);

    // ── 4. Deleting a bill takes the goods back out ────────────────────────
    echo "\n4. Deleting a purchase\n";

    $msg = rejection(function () use ($db, $actor, $p3) {
        purchaseDelete($db, $actor, (int)$p3['id']);
    });
    check('a bill with credit notes cannot be deleted',
        $msg !== '' && stripos($msg, 'purchase returns first') !== false, $msg);

    $p4 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId,
        'store_id' => $storeA,
        'purchase_date' => $today,
        'items' => [['product_id' => $prodC, 'qty' => 5, 'unit_price' => 30, 'discount' => 0]],
        'payment' => ['amount' => 100, 'method' => 'bank', 'date' => $today],
        'update_buy_price' => true,
    ]);
    check('buy price updated from the bill when asked',
        isMoney(rowsOf($db, "SELECT buy_price FROM products WHERE id = ?", [$prodC])[0]['buy_price'], 30));
    check('the bill owes 50 of its 150', isMoney($p4['due_amount'], 50));

    $payId4 = (int)rowsOf($db, "SELECT id FROM supplier_payments WHERE purchase_id = ?", [$p4['id']])[0]['id'];
    purchaseDelete($db, $actor, (int)$p4['id']);
    check('deleting reverses the stock it added', qtyAt($db, $storeA, $prodC) === 0, qtyAt($db, $storeA, $prodC));
    check('deleting removes the bill', purchaseFind($db, (int)$p4['id'], PU_TEST_OWNER) === false);
    check('deleting removes its payments',
        count(rowsOf($db, "SELECT id FROM supplier_payments WHERE purchase_id = ?", [$p4['id']])) === 0);
    check('deleting removes their cashbook rows', cashbookOf($db, 'purchase_pay', $payId4) === false);
    check('deleting keeps the audit line',
        count(rowsOf($db, "SELECT id FROM purchase_audit_log WHERE action = 'purchase.delete' AND entity_id = ?",
            [$p4['id']])) === 1);

    // ── 5. A branch account can only ever buy for its own branch ───────────
    echo "\n5. Store scope\n";

    $p5 = purchaseCreate($db, $branch, [
        'supplier_id' => $supplierId,
        'store_id' => $storeA,
        'purchase_date' => $today,
        'items' => [['product_id' => $prodB, 'qty' => 3, 'unit_price' => 100, 'discount' => 0]],
    ]);
    check('goods land in the branch, not the store named in the form',
        (int)$p5['store_id'] === $storeB && qtyAt($db, $storeB, $prodB) === 3);

    // ── 6. Dues, advances and the ledger agree ────────────────────────────
    echo "\n6. Supplier dues and ledger\n";

    $expectedDue = (float)rowsOf($db,
        "SELECT COALESCE(SUM(due_amount), 0) d FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['d'];
    $dueList = supplierDueList($db, PU_TEST_OWNER, null, null, 'P Test');
    check('the dues list has our one supplier', count($dueList) === 1, count($dueList));
    check('before advances the list owes the sum of the bills',
        isMoney($dueList[0]['outstanding'], $expectedDue), $dueList[0]['outstanding'] . ' vs ' . $expectedDue);

    purchasePaymentAdd($db, $actor, [
        'supplier_id' => $supplierId, 'purchase_id' => null,
        'amount' => 100, 'payment_method' => 'cash', 'payment_date' => $today,
        'note' => 'on account',
    ]);
    $dueList = supplierDueList($db, PU_TEST_OWNER, null, null, 'P Test');
    $afterAdvance = supplierDueTotal($db, PU_TEST_OWNER, $supplierId);
    check('an advance on account lowers what is owed',
        isMoney($afterAdvance, $expectedDue - 100), $afterAdvance . ' vs ' . ($expectedDue - 100));
    check('the dues list shows the advance', isMoney($dueList[0]['on_account'], 100));
    check('the list and the supplier total agree', isMoney($dueList[0]['outstanding'], $afterAdvance));

    $ledger = supplierLedger($db, PU_TEST_OWNER, $supplierId);
    check('the ledger closes on what the supplier is owed',
        isMoney($ledger['closing'], $afterAdvance), $ledger['closing'] . ' vs ' . $afterAdvance);
    $running = 0.0;
    foreach ($ledger['rows'] as $row) {
        $running += (float)$row['debit'] - (float)$row['credit'];
    }
    check('the ledger rows add up to its closing balance', isMoney($running + $ledger['opening'], $ledger['closing']));
    $debited = 0.0;
    foreach ($ledger['rows'] as $row) {
        $debited += (float)$row['debit'];
    }
    check('the ledger debits are exactly what was billed',
        isMoney($debited, (float)rowsOf($db,
            "SELECT COALESCE(SUM(grand_total),0) t FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['t']));
    check('the ledger starts with a bill, not a payment', $ledger['rows'][0]['kind'] === 'Purchase');

    $window = supplierLedger($db, PU_TEST_OWNER, $supplierId, $today, $today);
    check('a dated window still closes on the same balance',
        isMoney($window['closing'], $ledger['closing']));
    $future = supplierLedger($db, PU_TEST_OWNER, $supplierId, date('Y-m-d', strtotime('+1 day')), null);
    check('a window with nothing in it opens at the whole balance',
        isMoney($future['opening'], $ledger['closing']) && count($future['rows']) === 0, $future['opening']);

    $summary = purchaseSummary($db, PU_TEST_OWNER, $today, $today, null);
    check('the report totals count the bills', (int)$summary['bills'] === 4, $summary['bills']);
    check('the report total is the sum of the bill totals',
        isMoney($summary['total'], (float)rowsOf($db,
            "SELECT COALESCE(SUM(grand_total),0) t FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['t']));
    check('the report keeps advances separate from bill payments',
        isMoney($summary['advances'], 100), $summary['advances']);

    // ── 7. Entries the shop should not be able to save ────────────────────
    echo "\n7. Validation\n";

    $good = ['supplier_id' => $supplierId, 'store_id' => $storeA, 'purchase_date' => $today,
        'items' => [['product_id' => $prodA, 'qty' => 2, 'unit_price' => 50, 'discount' => 0]]];
    $msg = rejection(function () use ($db, $actor, $today, $prodA, $storeA) {
        purchaseCreate($db, $actor, ['supplier_id' => 999999, 'store_id' => $storeA,
            'purchase_date' => $today,
            'items' => [['product_id' => $prodA, 'qty' => 1, 'unit_price' => 10]]]);
    });
    check('a supplier from another shop is refused', $msg !== '', $msg);

    $msg = rejection(function () use ($db, $actor, $supplierId, $today) {
        purchaseCreate($db, $actor, ['supplier_id' => $supplierId, 'store_id' => 999999,
            'purchase_date' => $today,
            'items' => [['product_id' => 1, 'qty' => 1, 'unit_price' => 10]]]);
    });
    check('a store the shop does not have is refused', $msg !== '', $msg);

    $msg = rejection(function () use ($db, $actor, $supplierId, $storeA, $today) {
        purchaseCreate($db, $actor, ['supplier_id' => $supplierId, 'store_id' => $storeA,
            'purchase_date' => $today,
            'items' => [['product_id' => 1, 'qty' => 1, 'unit_price' => 10]]]);
    });
    check('a product of another shop is refused', $msg !== '', $msg);

    $msg = rejection(function () use ($db, $actor, $good) {
        purchaseCreate($db, $actor, array_merge($good, ['items' => []]));
    });
    check('a bill with no lines is refused', $msg !== '', $msg);

    $msg = rejection(function () use ($db, $actor, $good, $prodA) {
        purchaseCreate($db, $actor, ['items' => [
            ['product_id' => $prodA, 'qty' => 1, 'unit_price' => 10],
            ['product_id' => $prodA, 'qty' => 2, 'unit_price' => 10],
        ], 'supplier_id' => $good['supplier_id'], 'store_id' => $good['store_id'],
            'purchase_date' => $good['purchase_date']]);
    });
    check('the same product twice is refused instead of silently merged', $msg !== '', $msg);

    $msg = rejection(function () use ($db, $actor, $good) {
        purchaseCreate($db, $actor, array_merge($good, ['discount' => 10000]));
    });
    check('a discount larger than the bill is refused',
        $msg !== '' && stripos($msg, 'larger than the purchase value') !== false, $msg);

    $msg = rejection(function () use ($db, $actor, $good) {
        purchaseCreate($db, $actor, array_merge($good,
            ['payment' => ['amount' => 99999, 'method' => 'cash', 'date' => date('Y-m-d')]]));
    });
    check('paying more than the grand total is refused',
        $msg !== '' && stripos($msg, 'more than the grand total') !== false, $msg);

    $msg = rejection(function () use ($db, $actor, $good) {
        purchaseCreate($db, $actor, array_merge($good,
            ['payment' => ['amount' => 10, 'method' => 'bitcoin', 'date' => date('Y-m-d')]]));
    });
    check('an unknown payment method is refused when money moves', $msg !== '', $msg);

    $before = (int)rowsOf($db, "SELECT COUNT(*) c FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['c'];
    $p6 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId, 'store_id' => $storeA, 'purchase_date' => 'not a date',
        'items' => [['product_id' => $prodA, 'qty' => 1, 'unit_price' => 10, 'discount' => 0],
                     ['product_id' => $prodB, 'qty' => 0, 'unit_price' => 10, 'discount' => 0]],
    ]);
    check('a junk date falls back to today instead of failing', $p6['purchase_date'] === date('Y-m-d'), $p6['purchase_date']);
    check('a zero-quantity line is dropped, not saved',
        count(purchaseItems($db, (int)$p6['id'])) === 1);
    check('the rejected bills above really did not save',
        (int)rowsOf($db, "SELECT COUNT(*) c FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['c'] === $before + 1);

    // ── 8. One shop cannot see another's purchases ────────────────────────
    echo "\n8. Tenant isolation\n";

    $other = ['owner_id' => PU_TEST_OTHER, 'store_id' => null, 'user_id' => $anchorUserId, 'name' => 'P Other Actor'];
    $someId = (int)$p1['id'];

    check('another shop cannot read the bill', purchaseFind($db, $someId, PU_TEST_OTHER) === false);
    $msg = rejection(function () use ($db, $other, $someId) {
        purchaseDelete($db, $other, $someId);
    });
    check('another shop cannot delete it', $msg !== '', $msg);
    $msg = rejection(function () use ($db, $other, $supplierId, $someId) {
        purchasePaymentAdd($db, $other, ['supplier_id' => $supplierId, 'purchase_id' => $someId,
            'amount' => 10, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]);
    });
    check('another shop cannot pay against it', $msg !== '', $msg);
    $msg = rejection(function () use ($db, $other, $supplierId) {
        purchasePaymentAdd($db, $other, ['supplier_id' => $supplierId,
            'amount' => 10, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]);
    });
    check('another shop cannot pay this supplier at all', $msg !== '', $msg);
    check('the dues list of another shop is empty',
        count(supplierDueList($db, PU_TEST_OTHER, null, null, 'P Test')) === 0);
    check('its ledger is empty', count(supplierLedger($db, PU_TEST_OTHER, $supplierId)['rows']) === 0);
    check('its product picker does not list these products',
        count(purchaseFormProducts(PU_TEST_OTHER, null)) === 0);

    // ── 9. Numbers and money ──────────────────────────────────────────────
    echo "\n9. Numbering, money and dates\n";

    check('bill numbers are PUR- and rising',
        strpos($p1['invoice_no'], 'PUR-') === 0 && strcmp($p6['invoice_no'], $p1['invoice_no']) > 0,
        $p1['invoice_no'] . ' then ' . $p6['invoice_no']);
    check('bill numbers stay unique across shops',
        count(rowsOf($db, "SELECT invoice_no FROM purchases GROUP BY invoice_no HAVING COUNT(*) > 1")) === 0);
    check('credit note numbers are PUR-RTN-',
        strpos(purchaseReturns($db, (int)$p3['id'])[0]['invoice_no'], 'PUR-RTN-') === 0);
    check('money is rounded to the paisa', isMoney(purchaseMoney(123.456), 123.46));
    check('a line total is stored to the paisa',
        isMoney(purchaseCreate($db, $actor, [
            'supplier_id' => $supplierId, 'store_id' => $storeA,
            'items' => [['product_id' => $prodC, 'qty' => 7, 'unit_price' => 1.43, 'discount' => 0]],
        ])['grand_total'], 10.01));
    check('an unusable date becomes today', purchaseDateInput('2026-13-45') === date('Y-m-d'));
    check('a good date is kept', purchaseDateInput('2026-03-05') === '2026-03-05');
    check('payment methods are the sales methods the shop allows',
        count(purchasePaymentMethods($db, PU_TEST_OTHER)) >= 1
        && isset(purchasePaymentMethods($db, PU_TEST_OTHER)['cash']));

    // The shop narrows its own list on Settings -> Supplier Payment Methods, and
    // the module reads it per shop: what one shop switches off must not quietly
    // remove an option from another shop's purchase form.
    $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id)
                  VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['purchase_payment_methods', 'cash,bank,not-a-method', PU_TEST_OWNER]);
    $shopMethods = purchasePaymentMethods($db, PU_TEST_OWNER);
    check('the shop is left with the methods it allows',
        implode(',', array_keys($shopMethods)) === 'cash,bank', implode(',', array_keys($shopMethods)));
    check('a key the sales enum does not know is dropped, not trusted',
        !isset($shopMethods['not-a-method']));
    check('another shop keeps its own full list',
        count(purchasePaymentMethods($db, PU_TEST_OTHER)) === 6);
    $msg = rejection(function () use ($db, $actor, $supplierId) {
        purchasePaymentInsert($db, $actor, [
            'supplier_id' => $supplierId, 'amount' => 5, 'payment_method' => 'bkash',
        ]);
    });
    check('a payment cannot use a method this shop switched off',
        strpos($msg, 'valid payment method') !== false, $msg);
    $msg = rejection(function () use ($db, $actor, $supplierId, $prodA, $storeA) {
        purchaseCreate($db, $actor, [
            'supplier_id' => $supplierId, 'store_id' => $storeA,
            'items' => [['product_id' => $prodA, 'qty' => 1, 'unit_price' => 10, 'discount' => 0]],
            'payment' => ['amount' => 5, 'method' => 'bkash'],
        ]);
    });
    check('nor can a purchase entry', $msg !== '', $msg);
    check('a method the shop kept is still accepted',
        purchasePaymentInsert($db, $actor, [
            'supplier_id' => $supplierId, 'amount' => 5, 'payment_method' => 'bank',
        ]) > 0);

    // ── 10. Which cashbook a payment leaves ────────────────────────────────
    echo "\n10. The book a supplier payment leaves\n";

    $insBook = $db->prepare("INSERT INTO cash_books
            (owner_id, store_id, name, type, supplier_id, opening_balance, opening_date, is_default, status)
            VALUES (?, NULL, ?, 'bank', ?, 0, ?, 0, 'active')");
    $insBook->execute([PU_TEST_OWNER, 'P Test Supplier Bank', $supplierId, $today]);
    $supBook = (int)$db->lastInsertId();
    $insBook->execute([PU_TEST_OWNER, 'P Test Till', null, $today]);
    $tillBook = (int)$db->lastInsertId();
    // Another shop's book, to prove a posted id cannot reach it.
    $insBook->execute([PU_TEST_OTHER, 'P Test Foreign Book', null, $today]);
    $foreignBook = (int)$db->lastInsertId();

    $insSup2 = $db->prepare("INSERT INTO suppliers (name, company, status, owner_id)
                             VALUES ('P Test Unassigned Supplier', 'P Test Co', 'active', ?)");
    $insSup2->execute([PU_TEST_OWNER]);
    $unassignedSup = (int)$db->lastInsertId();

    $p10 = purchaseCreate($db, $actor, [
        'supplier_id' => $supplierId, 'store_id' => $storeA, 'purchase_date' => $today,
        'items' => [['product_id' => $prodA, 'qty' => 5, 'unit_price' => 200, 'discount' => 0]],
        'payment' => ['amount' => 0, 'method' => 'cash', 'date' => $today],
    ]);
    check('a credit bill writes no cashbook line',
        count(rowsOf($db, "SELECT id FROM cashbook_entries WHERE source_type = 'purchase_pay' AND source_id IN
                 (SELECT id FROM supplier_payments WHERE purchase_id = ?)", [$p10['id']])) === 0);

    $payNamed = purchasePaymentAdd($db, $actor, [
        'supplier_id' => $supplierId, 'purchase_id' => $p10['id'],
        'amount' => 100, 'payment_method' => 'bank', 'payment_date' => $today,
        'cashbook_id' => $tillBook,
    ]);
    check('the book the form named is the one the money left',
        (int)cashbookBookOf($db, $payNamed) === $tillBook, cashbookBookOf($db, $payNamed));

    $payAuto = purchasePaymentAdd($db, $actor, [
        'supplier_id' => $supplierId, 'purchase_id' => $p10['id'],
        'amount' => 100, 'payment_method' => 'bank', 'payment_date' => $today,
    ]);
    check('with no choice on the form, the supplier\'s own book takes it',
        (int)cashbookBookOf($db, $payAuto) === $supBook, cashbookBookOf($db, $payAuto));

    $payNone = purchasePaymentAdd($db, $actor, [
        'supplier_id' => $unassignedSup, 'amount' => 50,
        'payment_method' => 'cash', 'payment_date' => $today,
    ]);
    check('a supplier no book claims leaves the line as it always was',
        cashbookBookOf($db, $payNone) === null, var_export(cashbookBookOf($db, $payNone), true));

    $msg = rejection(function () use ($db, $actor, $supplierId, $p10, $foreignBook, $today) {
        purchasePaymentAdd($db, $actor, [
            'supplier_id' => $supplierId, 'purchase_id' => $p10['id'],
            'amount' => 10, 'payment_method' => 'cash', 'payment_date' => $today,
            'cashbook_id' => $foreignBook,
        ]);
    });
    check("another shop's book is refused", $msg !== '' && stripos($msg, 'cashbook') !== false, $msg);

    $msg = rejection(function () use ($db, $actor, $supplierId, $p10, $today) {
        purchasePaymentAdd($db, $actor, [
            'supplier_id' => $supplierId, 'purchase_id' => $p10['id'],
            'amount' => 10, 'payment_method' => 'cash', 'payment_date' => $today,
            'cashbook_id' => 999999999,
        ]);
    });
    check('a book that does not exist is refused', $msg !== '', $msg);
    check('and a refused payment wrote no cashbook line',
        count(rowsOf($db, "SELECT id FROM cashbook_entries WHERE source_type = 'purchase_pay' AND source_id
                 IN (SELECT id FROM supplier_payments WHERE amount = 10)")) === 0);

    // The field the payment forms render: only this shop's active books, and the
    // supplier's own one already picked.
    $field = purchaseCashbookField($db, PU_TEST_OWNER, $supplierId);
    check('the paid-from field offers both of the shop\'s books',
        substr_count($field, '<option') === 3, substr_count($field, '<option'));
    check('and none of the other shop\'s', strpos($field, 'P Test Foreign Book') === false);
    check('with this supplier\'s book pre-picked',
        strpos($field, 'value="' . $supBook . '" data-supplier="' . $supplierId . '" selected') !== false);
    $fieldTill = purchaseCashbookField($db, PU_TEST_OWNER, $unassignedSup);
    check('a supplier with no book of its own gets no pre-pick',
        strpos($fieldTill, ' selected') === false);
    check('the field still lets the choice be changed back to the default',
        strpos($field, '<option value="">Default book for the method</option>') !== false);

    // ── 11. CSRF guards a posted form ──────────────────────────────────────
    echo "\n11. CSRF\n";

    $token = csrfToken();
    check('a token is issued once per session', strlen($token) === 64 && csrfToken() === $token);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    check('a POST with no token is rejected', csrfCheck() === false);
    $_POST = ['csrf_token' => 'wrong'];
    check('a POST with a forged token is rejected', csrfCheck() === false);
    $_POST = ['csrf_token' => $token];
    check('a POST with the session token passes', csrfCheck() === true);
    $_POST = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    check('the fetch header is accepted', csrfCheck() === true);
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    check('a GET is never blocked', csrfCheck('GET') === true);
    check('csrfField() carries the token', strpos(csrfField(), $token) !== false);

    // Nothing of this run stays behind, its session file included.
    $_SESSION = [];
    session_destroy();

    echo "\nRolling back the " . rowsOf($db,
        "SELECT COUNT(*) c FROM purchases WHERE owner_id = ?", [PU_TEST_OWNER])[0]['c']
        . " purchases this run wrote...\n";
} catch (Throwable $e) {
    $fail++;
    echo "\n  FAIL  the test itself broke: " . $e->getMessage() . "\n";
    echo "        at " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Nothing above may survive. The rollback is the whole safety argument, so it is
// checked rather than assumed.
if ($db->inTransaction()) {
    $db->rollBack();
}

$left = [
    'purchases' => (int)rowsOf($db, "SELECT COUNT(*) c FROM purchases WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
    'stores' => (int)rowsOf($db, "SELECT COUNT(*) c FROM stores WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
    'products' => (int)rowsOf($db, "SELECT COUNT(*) c FROM products WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
    'suppliers' => (int)rowsOf($db, "SELECT COUNT(*) c FROM suppliers WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
    'cashbooks' => (int)rowsOf($db, "SELECT COUNT(*) c FROM cash_books WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
    'audit' => (int)rowsOf($db, "SELECT COUNT(*) c FROM purchase_audit_log WHERE owner_id IN (?, ?)",
        [PU_TEST_OWNER, PU_TEST_OTHER])[0]['c'],
];
$after = [
    'purchases' => (int)$db->query("SELECT COUNT(*) FROM purchases")->fetchColumn(),
    'stocks' => (int)$db->query("SELECT COALESCE(SUM(quantity), 0) FROM store_stocks")->fetchColumn(),
    'cashbook' => (int)$db->query("SELECT COUNT(*) FROM cashbook_entries")->fetchColumn(),
];

echo "\nClean-up\n";
foreach ($left as $table => $n) {
    check("no $table rows left behind", $n === 0, $n);
}
check('the shop has exactly the purchases it had before', $after['purchases'] === $snapshot['purchases'],
    $snapshot['purchases'] . ' -> ' . $after['purchases']);
check('no quantity on any shelf changed', $after['stocks'] === $snapshot['stocks'],
    $snapshot['stocks'] . ' -> ' . $after['stocks']);
check('the cashbook has the same rows as before', $after['cashbook'] === $snapshot['cashbook'],
    $snapshot['cashbook'] . ' -> ' . $after['cashbook']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
