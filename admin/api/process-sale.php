<?php
/**
 * API - Process Sale (Create or Update)
 *
 * One till basket in, one sale row per store out.
 *
 * The basket used to become exactly one sale, filed against whatever store the
 * cashier's session pointed at. That stops holding when the platform Super Admin
 * sells: that account has no store of its own, and one basket can hold a shirt
 * from Store A and a pant from Store B. So the cart is now grouped by the store
 * each line came from - config/sale_split.php owns that decision and every
 * ownership rule around it - and each group is written as its own sale, its own
 * invoice, its own cashbook entry and its own stock movements, inside ONE
 * transaction, so a basket never half-saves across two shops.
 *
 * A single-store basket - every cashier POS, every normal shop - goes through the
 * same code and still becomes exactly one sale with the same columns as before.
 *
 * The money is recomputed from the lines and the shop's own VAT setting. What the
 * browser posted is only ever checked against that, never copied.
 *
 * @see config/sale_split.php  grouping, ownership checks, the money split
 */

// Buffer all output so any stray PHP warnings don't corrupt JSON
ob_start();

// Suppress all error output to browser — log only
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/sale_split.php';
startSecureSession();

/**
 * Every refusal in this file leaves through here, so the shape the POS reads is
 * the same whether the basket was empty, stale or someone else's.
 */
function processSaleRefuse($message)
{
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isLoggedIn()) {
    processSaleRefuse('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    processSaleRefuse('Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (empty($input['items']) || !is_array($input['items'])) {
    if (!$input) {
        processSaleRefuse('Invalid JSON input');
    }
    processSaleRefuse('No items in cart');
}

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$ownerId = $currentUser['owner_id'];
$isSuper = saleIsSuperAdmin($currentUser);

$editSaleId = isset($input['edit_sale_id']) ? (int) $input['edit_sale_id'] : 0;

// sales.store_id and sales.idempotency_key carry the store a sale came out of and
// the token that stops a double tap writing it twice. Both are nullable and both
// are added on first use (config/migration_sale_store_scope.sql is the manual
// route), so a shop whose DB user cannot ALTER keeps selling the way it always
// did instead of erroring.
$storeReady = ensureSaleStoreColumns();

// The cashbook line this sale writes is an INSERT into cashbook_entries with
// source_type and source_id on it, and those tables and columns are created at
// runtime by DDL. DDL cannot wait until the transaction further down is open - it
// would commit the sale mid-flight - so the schema is settled here instead.
ensureCashbookWriteReady();

// VAT comes from the shop's OWN settings row, which is exactly where the till page
// read the number it showed the customer (admin/pos.php and cashier/pos.php both
// query owner_id only). The platform row is deliberately not consulted here: if the
// door priced a sale with a VAT the screen never displayed, every sale in that shop
// would be refused as a total mismatch.
$vatPercent = processSaleVatPercent($db, $ownerId);

// The store a line falls back to when the till did not name one. For a shop's own
// staff that is their session store, then the shop's first active store. The
// cross-shop "any active store" pick this used to fall back to is gone: it filed
// a sale against whichever store some other tenant happened to have opened, which
// is exactly the isolation the module has to keep. A Super Admin has no store of
// their own to fall back to, so on that path every line has to say its store.
$store_id = (int) ($_SESSION['store_id'] ?? 0);
if (!$isSuper && $store_id <= 0) {
    $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
    $stmtFallback->execute([$ownerId]);
    $store_id = (int) $stmtFallback->fetchColumn();
}

if ($editSaleId > 0) {
    $stmtSale = $db->prepare("SELECT id, user_id, owner_id, store_id, invoice_number, created_at FROM sales WHERE id = ?");
    $stmtSale->execute([$editSaleId]);
    $editSale = $stmtSale->fetch();
    if (!$editSale) {
        processSaleRefuse('Sale-ta pai jay ni (#' . $editSaleId . ').');
    }

    // Editing has never checked whose sale it was: any signed-in cashier could
    // post another shop's sale id and rewrite its money and its stock. A legacy
    // row with no owner_id is judged by the cashier who made it, since that is
    // where the reports put it anyway.
    //
    // The same lookup answers "which store" for a row written before the column
    // existed: the maker's own store, because that is the store the reports read
    // it under. Without this, editing an old sale would restore its stock into
    // whichever store the person editing happens to be standing in today.
    $saleOwner = (int) ($editSale['owner_id'] ?? 0);
    $saleStore = (int) ($editSale['store_id'] ?? 0);
    if ($saleOwner <= 0 || $saleStore <= 0) {
        $stmtMaker = $db->prepare("SELECT owner_id, store_id FROM users WHERE id = ?");
        $stmtMaker->execute([(int) $editSale['user_id']]);
        $maker = $stmtMaker->fetch();
        if ($saleOwner <= 0) {
            $saleOwner = (int) ($maker['owner_id'] ?? 0);
        }
        if ($saleStore <= 0) {
            $saleStore = (int) ($maker['store_id'] ?? 0);
        }
    }
    if (!$isSuper && $saleOwner !== (int) $ownerId) {
        processSaleRefuse('Apnar ei sale-te odhikar nai.');
    }

    // A counter account (staff/cashier) edits only the bill it rang up itself:
    // the sales list it opens the edit from already shows just those, so this
    // closes the same door against a hand-typed id.
    if (isOwnSalesOnlyUser($currentUser) && (int) $editSale['user_id'] !== (int) $userId) {
        processSaleRefuse('Ei sale apnar nijer bikri na - edit korte parben na.');
    }

    if ($saleStore > 0) {
        $store_id = $saleStore;
    }
}

if (!$isSuper && $store_id <= 0) {
    processSaleRefuse('No active store found. Please create a store first in Admin → Stores.');
}

// The lines, each with the store it names. In edit mode one invoice is one store,
// so the sale's own store wins over anything posted.
$lines = [];
foreach ($input['items'] as $item) {
    if (!is_array($item)) {
        $lines[] = $item;
        continue;
    }
    $line = $item;
    if ($editSaleId > 0) {
        $line['store_id'] = $store_id;
    }
    $lines[] = $line;
}

// Read the product and store rows the basket names, so the checks below are made
// against the database rather than against what the browser says it sold.
$productIds = [];
$storeIds = [];
foreach ($lines as $line) {
    if (!is_array($line)) {
        continue;
    }
    $pid = (int) ($line['product_id'] ?? 0);
    if ($pid > 0) {
        $productIds[$pid] = $pid;
    }
    $sid = (int) ($line['store_id'] ?? $store_id);
    if ($sid > 0) {
        $storeIds[$sid] = $sid;
    }
}
if ($store_id > 0) {
    $storeIds[$store_id] = $store_id;
}

$products = [];
$stores   = [];
if (!empty($productIds)) {
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmtP = $db->prepare("SELECT id, owner_id, name, sell_price FROM products WHERE id IN ($in)");
    $stmtP->execute(array_values($productIds));
    foreach ($stmtP->fetchAll() as $row) {
        $products[(int) $row['id']] = $row;
    }
}
if (!empty($storeIds)) {
    $in = implode(',', array_fill(0, count($storeIds), '?'));
    $stmtS = $db->prepare("SELECT id, name, owner_id, status FROM stores WHERE id IN ($in)");
    $stmtS->execute(array_values($storeIds));
    foreach ($stmtS->fetchAll() as $row) {
        $stores[(int) $row['id']] = $row;
    }
}

$checked = saleValidateCart($currentUser, $lines, $products, $stores, $store_id);
if (!$checked['ok']) {
    processSaleRefuse($checked['error']);
}

// Each shop charges the VAT its own Settings page holds, because the sale row that
// gets written is that shop's. For a shop selling from its own shelves this is the
// same number it has always been priced with; it only differs when one basket spans
// two shops, or when the platform Super Admin sells from a shop that is not theirs.
$basketGroups = [];
foreach ($checked['groups'] as $g) {
    $g['vat_percent'] = processSaleVatPercent($db, (int) $g['owner_id']);
    $basketGroups[] = $g;
}

// Several shops in one basket needs the store on the sale row; without the column
// the second shop's sale would be reported under the first one.
if (count($checked['groups']) > 1 && !$storeReady) {
    processSaleRefuse('Ekta basket-e duk store-r mal ache. Store-wise bill-er jonno '
        . 'config/migration_sale_store_scope.sql ta phpMyAdmin-e run korun.');
}

// The discount the cashier typed, from whichever pair the till sends: the newer
// pages send discount_type/discount_value, the figure that went on the screen.
$discountType = $input['discount_type'] ?? null;
$discountValue = $input['discount_value'] ?? null;
if ($discountType === null || $discountValue === null) {
    $postedPercent = (float) ($input['discount_percent'] ?? 0);
    $postedFixed   = (float) ($input['discount_amount'] ?? 0);
    $discountType  = $postedPercent > 0 ? 'percent' : 'fixed';
    $discountValue = $postedPercent > 0 ? $postedPercent : $postedFixed;
}

$basket = saleSplitBasket($basketGroups, $discountType, $discountValue, $vatPercent,
    (float) ($input['total'] ?? 0));
if (!$basket['ok']) {
    processSaleRefuse($basket['error']);
}
$groups = $basket['groups'];

// A customer row that carries no owner_id of its own has always been this shop's,
// so a single-store sale keeps attaching it. Across two shops that guess is not
// allowed, because it would print one shop's customer on the other shop's bill.
$customerAnyOwner = (count($groups) === 1 && $store_id > 0);

$storePayments = (isset($input['store_payments']) && is_array($input['store_payments']))
    ? $input['store_payments'] : null;
$paid = saleSplitPayments($groups, (float) ($input['paid_amount'] ?? 0), $storePayments);
if (!$paid['ok']) {
    processSaleRefuse($paid['error']);
}

// The customer, read once and judged per shop. A customer row belongs to one shop,
// so a basket spanning two shops can only name that customer on the sale of the
// shop the customer belongs to; the other shop's bill goes out without a name
// rather than borrowing one it does not own.
$customerIdRaw = (int) ($input['customer_id'] ?? 0);
$customer = null;
if ($customerIdRaw > 0) {
    $stmtCu = $db->prepare("SELECT id, name, phone, owner_id FROM customers WHERE id = ?");
    $stmtCu->execute([$customerIdRaw]);
    $cu = $stmtCu->fetch();
    if (!$cu) {
        processSaleRefuse('Customer-ta pai jay ni (#' . $customerIdRaw . ').');
    }
    $customer = $cu;
}

// A double tap, a refresh-and-resubmit or a network retry must not sell the same
// basket twice. The token is generated by the till per basket; if a sale already
// carries it, that sale is the answer and it is handed back instead of written
// again. Only new sales carry one: an edit is the same invoice, changed.
$idempotencyKey = '';
if ($storeReady && $editSaleId <= 0 && isset($input['idempotency_key'])) {
    $candidateKey = trim((string) $input['idempotency_key']);
    if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $candidateKey)) {
        $idempotencyKey = $candidateKey;
    }
}
if ($idempotencyKey !== '') {
    $stmtDup = $db->prepare("SELECT id FROM sales WHERE idempotency_key = ? LIMIT 1");
    $stmtDup->execute([$idempotencyKey]);
    $doneAlready = (int) $stmtDup->fetchColumn();
    if ($doneAlready > 0) {
        processSaleReplay($db, $doneAlready);
    }
}

$paymentMethod = sanitize($input['payment_method'] ?? 'cash');

try {
    $db->beginTransaction();

    if ($editSaleId > 0) {
        // --- EDIT MODE: one sale, one store, restocked and rewritten ---

        // 1. Restore stock from old items (subtracting already-returned quantities)
        $stmtReturned = $db->prepare("
            SELECT ri.product_id, SUM(ri.quantity) as returned_qty
            FROM return_items ri
            JOIN returns r ON ri.return_id = r.id
            WHERE r.sale_id = ?
            GROUP BY ri.product_id");
        $stmtReturned->execute([$editSaleId]);
        $returnedQtys = [];
        while ($row = $stmtReturned->fetch()) {
            $returnedQtys[$row['product_id']] = (int) $row['returned_qty'];
        }

        $stmtOldItems = $db->prepare("SELECT product_id, quantity FROM sale_items WHERE sale_id = ?");
        $stmtOldItems->execute([$editSaleId]);
        $oldItems = $stmtOldItems->fetchAll();

        // Prepared once for the whole restore - the loop used to buy the same
        // statements again for every line it put back.
        $stmtRestoreUpd = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
        $stmtRestoreIns = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmtRestoreLog = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'adjustment', ?, 'Sale Edit - Restored (Store $store_id)', ?)");

        foreach ($oldItems as $item) {
            // Subtract already-returned quantity to avoid double restoration
            $restoreQty = $item['quantity'];
            if (isset($returnedQtys[$item['product_id']])) {
                $restoreQty -= $returnedQtys[$item['product_id']];
            }

            if ($restoreQty <= 0) continue;

            // Restore to the store the sale came out of
            $stmtRestoreUpd->execute([$restoreQty, $store_id, $item['product_id']]);
            if ($stmtRestoreUpd->rowCount() === 0) {
                $stmtRestoreIns->execute([$store_id, $item['product_id'], $restoreQty]);
            }

            // Log restoration
            $stmtRestoreLog->execute([$item['product_id'], $restoreQty, $editSaleId, $userId]);
        }

        // 2. Delete old items
        $db->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$editSaleId]);

        $g = $groups[0];
        $payRow = $paid['payments'][0];
        $stmtUpdate = $db->prepare("UPDATE sales SET  
            customer_id = ?, 
            subtotal = ?, 
            discount_percent = ?, 
            discount_amount = ?, 
            vat_percent = ?, 
            vat_amount = ?, 
            total = ?, 
            paid_amount = ?, 
            change_amount = ?, 
            payment_method = ?, 
            payment_status = ?,
            user_id = ?,
            updated_at = NOW() 
            WHERE id = ?");

        $stmtUpdate->execute([
            // Judged against the sale's own shop, not the store row's: this invoice
            // already belongs to that tenant and its customer was picked from there.
            processSaleCustomerForOwner($customer, $saleOwner, true),
            $g['subtotal'],
            $g['discount_percent'],
            $g['discount'],
            $g['vat_percent'],
            $g['vat'],
            $g['total'],
            $payRow['paid'],
            $payRow['change'],
            $paymentMethod,
            $payRow['status'],
            $userId,
            $editSaleId
        ]);

        $groups[0]['sale_id'] = $editSaleId;
        $groups[0]['invoice_number'] = $editSale['invoice_number'];
        $groups[0]['date'] = date('d M Y, h:i A', strtotime($editSale['created_at']));
        $groups[0]['is_new'] = false;

    } else {
        // --- NEW SALE MODE: one sale per store, in one transaction ---

        foreach ($groups as $gi => $g) {
            $payRow = $paid['payments'][$gi];
            $invoiceNumber = processSaleNextInvoiceNumber($db);

            // The token only exists as a column, so it is only written when the
            // migration has run; a UNIQUE clash on it means a concurrent double
            // tap got here first, which is a replay rather than a second sale.
            if ($storeReady) {
                $stmtInsert = $db->prepare("INSERT INTO sales (
                    invoice_number, customer_id, user_id, store_id,
                    subtotal, discount_percent, discount_amount,
                    vat_percent, vat_amount, total,
                    paid_amount, change_amount,
                    payment_method, payment_status, owner_id, idempotency_key)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            } else {
                $stmtInsert = $db->prepare("INSERT INTO sales (
                    invoice_number, customer_id, user_id,
                    subtotal, discount_percent, discount_amount,
                    vat_percent, vat_amount, total,
                    paid_amount, change_amount,
                    payment_method, payment_status, owner_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            }

            $bind = [
                $invoiceNumber,
                processSaleCustomerForOwner($customer, $g['owner_id'], $customerAnyOwner),
                $userId,
            ];
            if ($storeReady) {
                $bind[] = $g['store_id'];
            }
            $bind = array_merge($bind, [
                $g['subtotal'],
                $g['discount_percent'],
                $g['discount'],
                $g['vat_percent'],
                $g['vat'],
                $g['total'],
                $payRow['paid'],
                $payRow['change'],
                $paymentMethod,
                $payRow['status'],
                $g['owner_id'],
            ]);
            if ($storeReady) {
                // One token per basket, stamped on the first bill only, because the
                // column is UNIQUE and a split basket is several rows. That is still
                // the whole protection: a second post of the same basket is stopped
                // at its first row and can never reach the second one.
                $bind[] = ($gi === 0 && $idempotencyKey !== '') ? $idempotencyKey : null;
            }

            $written = false;
            for ($attempt = 0; $attempt < 5 && !$written; $attempt++) {
                try {
                    $stmtInsert->execute($bind);
                    $written = true;
                } catch (PDOException $e) {
                    $msg = $e->getMessage();
                    if (strpos($msg, 'uq_sales_idempotency') !== false) {
                        // Same basket posted twice at the same moment: the other
                        // request owns the sale now. Show that one, do not write a
                        // second one.
                        $db->rollBack();
                        $stmtOther = $db->prepare("SELECT id FROM sales WHERE idempotency_key = ? LIMIT 1");
                        $stmtOther->execute([$idempotencyKey]);
                        processSaleReplay($db, (int) $stmtOther->fetchColumn());
                    }
                    // invoice_number is UNIQUE, and it comes from MAX(id), so two
                    // baskets saved together can pick the same one. Take the next
                    // number instead of failing the sale.
                    if (strpos($msg, 'invoice_number') !== false && $attempt < 4) {
                        $invoiceNumber = processSaleNextInvoiceNumber($db);
                        $bind[0] = $invoiceNumber;
                        continue;
                    }
                    throw $e;
                }
            }

            $groups[$gi]['sale_id'] = (int) $db->lastInsertId();
            $groups[$gi]['invoice_number'] = $invoiceNumber;
            $groups[$gi]['date'] = date('d M Y, h:i A');
            $groups[$gi]['is_new'] = true;
        }
    }

    // Insert Sale Items and Deduct Stock (Common for both New and Edit)
    //
    // Every statement is prepared once, here, outside the line loop - a prepare
    // is a round trip of its own, and a basket of ten items used to pay for the
    // same four statements ten times over.
    $stmtItem = $db->prepare("INSERT INTO sale_items (
        sale_id, product_id, product_name, 
        quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");

    $stmtHistory = $db->prepare("INSERT INTO stock_history (
        product_id, quantity_change, type, 
        reference_id, note, user_id) VALUES (?, ?, 'sale', ?, ?, ?)");

    $stmtStockDeduct = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
    $stmtStockSeed   = $db->prepare("INSERT IGNORE INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");

    foreach ($groups as $g) {
        foreach ($g['lines'] as $line) {
            $stmtItem->execute([
                $g['sale_id'],
                $line['product_id'],
                $line['product_name'],
                $line['quantity'],
                $line['unit_price'],
                $line['total_price']
            ]);

            // One UPDATE does what a SELECT ... FOR UPDATE followed by a second
            // UPDATE used to do. The deduction itself takes the row's exclusive
            // lock, so two tills selling the last piece still wait for each
            // other - the lock the SELECT was read for was the same one the
            // UPDATE then took again. The stock is still allowed to go
            // negative, exactly as before, because a shop that sells from a
            // till while its stock book is behind must not be blocked
            // mid-customer. Zero rows matched means this product had no
            // store_stocks row yet: seed it at 0, then deduct as usual.
            $stmtStockDeduct->execute([$line['quantity'], $g['store_id'], $line['product_id']]);
            if ($stmtStockDeduct->rowCount() === 0) {
                $stmtStockSeed->execute([$g['store_id'], $line['product_id']]);
                $stmtStockDeduct->execute([$line['quantity'], $g['store_id'], $line['product_id']]);
            }

            // Stock history
            $stmtHistory->execute([
                $line['product_id'],
                -$line['quantity'],
                $g['sale_id'],
                "Sale Transaction (Store {$g['store_id']})",
                $userId
            ]);
        }
    }

    // Auto Cash In entry for each store's bill, on that store's own shop - the
    // till's session is not the right owner when the goods came from elsewhere.
    // The method and invoice number ride along in the scope, which is what lets
    // the cashbook write without reading back the sale row it was just handed.
    foreach ($groups as $gi => $g) {
        $scope = [
            'owner_id' => $g['owner_id'],
            'store_id' => $g['store_id'],
            'user_id'  => $userId,
            'payment_method' => $paymentMethod,
            'invoice_no'     => $g['invoice_number'],
        ];
        if ($g['is_new']) {
            addAutoCashbookEntry('cash_in', $g['total'], 'Sale' . $g['invoice_number'], 'sale', $g['sale_id'], $scope);
        } else {
            updateAutoCashbookEntry('sale', $g['sale_id'], $g['total'], $scope);
        }
        $groups[$gi]['paid']   = $paid['payments'][$gi]['paid'];
        $groups[$gi]['change'] = $paid['payments'][$gi]['change'];
        $groups[$gi]['status'] = $paid['payments'][$gi]['status'];
    }

    $db->commit();

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    processSaleRefuse('Error: ' . $e->getMessage());
}

ob_end_clean();

// --- Response: one invoice per store, and the old single-invoice keys intact ---
//
// cashier/pos.php and the receipt screen both read result.invoice and result.sale_id,
// so those stay the first (and for a normal sale, the only) bill. The new keys are
// additive: result.invoices is the list, and split says whether the till has to show
// more than one receipt for the basket it just sent.
$invoices = [];
foreach ($groups as $gi => $g) {
    // The same rule that chose the customer_id written on this row, so the receipt
    // can never show a name the sale does not actually carry.
    $groupCustomer = processSaleCustomerForOwner(
        $customer,
        $editSaleId > 0 ? $saleOwner : $g['owner_id'],
        $editSaleId > 0 ? true : $customerAnyOwner
    );
    $row = null;
    if ($groupCustomer !== null) {
        $row = $customer;
    }

    $invoices[] = processSaleInvoice([
        'id'               => $g['sale_id'],
        'is_new'           => $g['is_new'],
        'invoice_number'   => $g['invoice_number'],
        'date'             => $g['date'],
        'store_id'         => $g['store_id'],
        'store_name'       => $g['store_name'],
        'subtotal'         => $g['subtotal'],
        'discount_percent' => $g['discount_percent'],
        'discount_amount'  => $g['discount'],
        'vat_percent'      => $g['vat_percent'],
        'vat_amount'       => $g['vat'],
        'total'            => $g['total'],
        'paid_amount'      => $g['paid'],
        'change_amount'    => $g['change'],
        'payment_method'   => $paymentMethod,
        'payment_status'   => $g['status'],
    ], $g['lines'], processSaleSettings($db, $g['owner_id']), $row);
}

$first = $invoices[0];

echo json_encode([
    'success'   => true,
    'message'   => count($invoices) > 1
        ? 'Sale processed successfully (' . count($invoices) . ' store bills)'
        : 'Sale processed successfully',
    'invoice'   => $first,
    'sale_id'   => $first['id'],
    'invoices'  => $invoices,
    'sale_ids'  => array_column($invoices, 'id'),
    'split'     => count($invoices) > 1 ? 1 : 0,
    // Kept at the root as the first bill's settings, which is what a single-store
    // sale has always meant by it. Each invoice also carries its own notify, so a
    // two-shop receipt can be delivered under each shop's own choices.
    'notify'    => $first['notify'],
]);
exit;


/**
 * One shop's VAT rate, from its OWN settings row.
 *
 * Owner-scoped only, with no platform fallback, because that is exactly how the two
 * till pages read the number they show the customer (admin/pos.php and
 * cashier/pos.php). If the door consulted a platform row the till never looked at,
 * every sale in that shop would be refused as a total mismatch.
 */
function processSaleVatPercent($db, $ownerId)
{
    static $cache = [];
    $key = (string) $ownerId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $vat = 0.0;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE owner_id = ? AND setting_key = 'vat_percent' LIMIT 1");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetchColumn();
        if ($row !== false && $row !== null && $row !== '') {
            $vat = (float) $row;
        }
    } catch (Throwable $e) {
        error_log('process-sale vat read: ' . $e->getMessage());
    }

    $cache[$key] = $vat;
    return $vat;
}

/**
 * The shop's settings: its own rows first, then platform rows (owner_id IS NULL)
 * only for keys the shop has never set - which is how the printed receipt reads
 * them, so the sale and its receipt cannot disagree.
 *
 * Read per owner because one basket can now span two shops: each bill has to carry
 * its own shop's name, footer and coupon, not the ones belonging to whoever typed.
 */
function processSaleSettings($db, $ownerId)
{
    static $cache = [];
    $key = (string) $ownerId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $settings = [];
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    try {
        $stmtGlobal = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id IS NULL");
        $stmtGlobal->execute();
        while ($row = $stmtGlobal->fetch()) {
            if (!array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        // A sale still completes with only the shop's own settings.
        error_log('process-sale settings fallback: ' . $e->getMessage());
    }

    $cache[$key] = $settings;
    return $settings;
}

/**
 * The customer_id to write on one store's sale, or null.
 *
 * A customer belongs to one shop, so only the sale of that shop may name them.
 * $allowAny is the legacy single-store case, where a customer row that carries no
 * owner_id of its own is as much this shop's as it ever was.
 */
function processSaleCustomerForOwner($customer, $ownerId, $allowAny)
{
    if (!is_array($customer)) {
        return null;
    }
    $customerOwner = (int) ($customer['owner_id'] ?? 0);
    if ($customerOwner === (int) $ownerId) {
        return (int) $customer['id'];
    }
    if ($customerOwner <= 0 && $allowAny) {
        return (int) $customer['id'];
    }
    return null;
}

/**
 * The next free invoice number.
 *
 * INV-<MAX(id)+1> is the numbering every receipt in this installation already
 * shows, so it is kept; what was missing is that two baskets saved in the same
 * second could pick the same number and one of them died on the UNIQUE. Asking
 * for the first one that is not taken removes that, and the timestamp form at the
 * end only appears if the next 25 numbers are all somehow used.
 */
function processSaleNextInvoiceNumber($db)
{
    $lastId = (int) $db->query("SELECT MAX(id) FROM sales")->fetchColumn();
    for ($i = 1; $i <= 25; $i++) {
        $candidate = 'INV-' . str_pad($lastId + $i, 6, '0', STR_PAD_LEFT);
        $st = $db->prepare("SELECT 1 FROM sales WHERE invoice_number = ? LIMIT 1");
        $st->execute([$candidate]);
        if (!$st->fetch()) {
            return $candidate;
        }
    }
    return 'INV-' . date('ymdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
}

/**
 * One receipt, in the shape the POS print screen reads.
 */
function processSaleInvoice(array $sale, array $items, array $settings, $customer)
{
    $flag = function ($key, $default = '1') use ($settings) {
        $v = $settings[$key] ?? $default;
        return ($v === '0' || $v === 0 || $v === '') ? 0 : 1;
    };

    // Edits do not re-send. A corrected sale putting a second copy of the same
    // receipt in the customer's chat is confusing, and an SMS costs money every
    // time it happens.
    $isEdit = empty($sale['is_new']);

    return [
        // The id belongs to the invoice object, not beside it. The SMS button on
        // the receipt posts this back as sale_id, and gets it from here - so an
        // invoice without it is one the cashier cannot send.
        'id'               => $sale['id'],
        'invoice_number'   => $sale['invoice_number'],
        'date'             => $sale['date'],
        'store_id'         => $sale['store_id'],
        'store_name'       => $sale['store_name'],
        'customer_name'    => is_array($customer) ? $customer['name'] : '',
        'customer_phone'   => is_array($customer) ? (string) ($customer['phone'] ?? '') : '',
        'cashier'          => $_SESSION['user_name'] ?? 'Admin',
        'items'            => $items,
        'subtotal'         => $sale['subtotal'],
        'discount_percent' => $sale['discount_percent'],
        'discount_amount'  => $sale['discount_amount'],
        'vat_percent'      => $sale['vat_percent'],
        'vat_amount'       => $sale['vat_amount'],
        'total'            => $sale['total'],
        'paid_amount'      => $sale['paid_amount'],
        'change_amount'    => $sale['change_amount'],
        'payment_method'   => $sale['payment_method'],
        'payment_status'   => $sale['payment_status'],
        'coupon_status'    => $settings['coupon_status'] ?? '0',
        'coupon_title'     => $settings['coupon_title'] ?? 'SMART COLLECTION MONTHLY LUCKY COUPON',
        'coupon_subtitle'  => $settings['coupon_subtitle'] ?? 'প্রতিটি কেনাকাটায় নিশ্চিত Lucky Entry Coupon!',
        'coupon_prize_1'   => $settings['coupon_prize_1'] ?? '🥇 ৫,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_2'   => $settings['coupon_prize_2'] ?? '🥈 ৩,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_3'   => $settings['coupon_prize_3'] ?? '🥉 ২,০০০ Shopping Voucher — ১ জন',
        'coupon_prize_4'   => $settings['coupon_prize_4'] ?? '🎁 ৫০০ Shopping Voucher — ১০ জন',
        'coupon_prize_5'   => $settings['coupon_prize_5'] ?? '👕 Premium T-Shirt — ১০ জন',
        'coupon_total_winners'  => $settings['coupon_total_winners'] ?? 'মোট বিজয়ী: ২৩ জন',
        'coupon_announcement'   => $settings['coupon_announcement'] ?? '📅 প্রতি মাসের ১ তারিখ রাত ৮:০০ টায় Smart Collection-এর অফিসিয়াল Facebook Live-এ বিজয়ী ঘোষণা করা হবে।',
        'voucher_terms'    => $settings['voucher_terms'] ?? '',
        'return_qr_url'    => $settings['return_qr_url'] ?? '',
        // The shop's own letterhead, sent with the bill instead of the till page
        // filling it in from the session. One basket can span two shops, and the
        // second bill cannot be printed under the first shop's name.
        'shop_name'        => $settings['shop_name'] ?? 'POS System',
        'shop_address'     => $settings['shop_address'] ?? '',
        'shop_phone'       => $settings['shop_phone'] ?? '',
        'receipt_footer'   => $settings['receipt_footer'] ?? 'Thank you for shopping!',
        'facebook_url'     => $settings['facebook_page'] ?? 'https://www.facebook.com',
        'show_qr'          => $flag('invoice_show_qr'),
        'notify' => [
            // What the POS should do about this receipt on its own. Settings >
            // Invoice Delivery owns these four checkboxes and they are resolved
            // here, next to the sale, from the same shop's settings the cashier
            // saved. A missing row means on, except for the restrictions the shop
            // has to ask for.
            'auto'          => $flag('auto_send_invoice'),
            'sms'           => $flag('invoice_send_sms'),
            'is_edit'       => $isEdit ? 1 : 0,
        ],
    ];
}

/**
 * The basket was already saved by an earlier post carrying this token - or, on the
 * replay after a race, by the request that won. Show that sale instead of making
 * another one: same receipt, same invoice number, no second set of stock movements.
 */
function processSaleReplay($db, $saleId)
{
    if ($saleId <= 0) {
        processSaleRefuse('Ei sale-ta age-i hoyeche, kintu row-ta pai jay ni.');
    }
    $stmt = $db->prepare("SELECT id, invoice_number, customer_id, store_id, owner_id, subtotal, discount_percent,
                                 discount_amount, vat_percent, vat_amount, total, paid_amount,
                                 change_amount, payment_method, payment_status, created_at
                          FROM sales WHERE id = ?");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) {
        processSaleRefuse('Ei sale-ta age-i hoyeche, kintu row-ta pai jay ni.');
    }

    $stmtItems = $db->prepare("SELECT product_id, product_name, quantity, unit_price, total_price
                               FROM sale_items WHERE sale_id = ? ORDER BY id");
    $stmtItems->execute([$saleId]);
    $items = $stmtItems->fetchAll();

    $storeId = (int) ($sale['store_id'] ?? 0);
    $storeName = '';
    if ($storeId > 0) {
        $st = $db->prepare("SELECT name FROM stores WHERE id = ?");
        $st->execute([$storeId]);
        $storeName = (string) $st->fetchColumn();
    }

    $customer = null;
    $stmtCu = $db->prepare("SELECT id, owner_id, name, phone FROM customers WHERE id = ?");
    $stmtCu->execute([(int) $sale['customer_id']]);
    $cu = $stmtCu->fetch();
    if ($cu && (int) $cu['owner_id'] === (int) $sale['owner_id']) {
        $customer = $cu;
    }

    $invoice = processSaleInvoice([
        'id'               => (int) $sale['id'],
        'invoice_number'   => $sale['invoice_number'],
        'date'             => date('d M Y, h:i A', strtotime($sale['created_at'])),
        'store_id'         => $storeId,
        'store_name'       => $storeName,
        'subtotal'         => (float) $sale['subtotal'],
        'discount_percent' => (float) $sale['discount_percent'],
        'discount_amount'  => (float) $sale['discount_amount'],
        'vat_percent'      => (float) $sale['vat_percent'],
        'vat_amount'       => (float) $sale['vat_amount'],
        'total'            => (float) $sale['total'],
        'paid_amount'      => (float) $sale['paid_amount'],
        'change_amount'    => (float) $sale['change_amount'],
        'payment_method'   => $sale['payment_method'],
        'payment_status'   => $sale['payment_status'],
        'is_new'           => false,
    ], $items, processSaleSettings($db, (int) $sale['owner_id']), $customer);

    ob_end_clean();
    echo json_encode([
        'success'      => true,
        'message'      => 'Sale already processed',
        'replayed'     => 1,
        'invoice'      => $invoice,
        'sale_id'      => $invoice['id'],
        'invoices'     => [$invoice],
        'sale_ids'     => [$invoice['id']],
        'split'        => 0,
        'notify'       => $invoice['notify'],
    ]);
    exit;
}
