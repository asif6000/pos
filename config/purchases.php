<?php
/**
 * Supplier purchases, payments, returns, dues and the supplier ledger.
 *
 * Every money- or stock-touching rule of the purchase module lives here once, so
 * the admin pages, the CLI acceptance test and any future mobile endpoint share
 * one implementation. Two invariants hold it together:
 *
 *   1. The money columns on a purchase (paid/credit/due/status) are never edited
 *      directly - purchaseRecalc() rebuilds them from the child rows every time
 *      a payment or return is added, deleted or changed, so a bill cannot drift
 *      out of step with what was actually paid.
 *   2. Stock moves in exactly one place, purchaseApplyStock(), and only while a
 *      purchase's own stock_applied flag flips 0 -> 1. Confirming therefore adds
 *      quantity once and once only, and deleting a purchase reverses precisely
 *      what it added.
 *
 * Nothing in this file reads $_SESSION: callers pass an $actor
 * (owner_id / store_id / user_id), which is what lets the same functions run
 * from a page request and from the command line.
 *
 * Cash really leaving the shop is recorded in the shared `cashbook_entries`
 * table (type cash_out, source_type 'purchase_pay'), so the Expense page, the
 * dashboard balance and every existing report pick it up without change. The
 * unpaid part of a purchase is a liability, not a cash movement, so it writes no
 * cashbook row - that is what the supplier due list is for.
 */

require_once __DIR__ . '/db.php';
// The book a payment leaves from, and the supplier a book belongs to, are the
// cashbook module's answers - asked here so every payment door gets the same one.
require_once __DIR__ . '/cashbook.php';
// salesPaymentMethods() owns the list of keys the payment enums accept; the
// configurable purchase methods are a subset of it, so they must agree.
require_once __DIR__ . '/../admin/includes/sales-scope.php';

/** Thrown for anything the user can fix by editing the form. */
class PurchaseException extends Exception {}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

/**
 * The module's tables, in create order. Kept as data so both the self-installing
 * page load (purchaseEnsureTables) and config/migration_purchases.sql describe
 * the same thing.
 *
 * @return array  table name => DDL
 */
function purchaseSchema()
{
    $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        'purchases' => "CREATE TABLE IF NOT EXISTS purchases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(20) NOT NULL,
            owner_id INT NOT NULL,
            store_id INT NOT NULL,
            user_id INT NOT NULL,
            supplier_id INT NOT NULL,
            purchase_date DATE NOT NULL,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            due_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(20) DEFAULT NULL,
            status ENUM('paid','partial','due') NOT NULL DEFAULT 'due',
            stock_applied TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_pur_invoice (invoice_no),
            KEY idx_pur_owner (owner_id),
            KEY idx_pur_store (store_id),
            KEY idx_pur_supplier (supplier_id),
            KEY idx_pur_date (purchase_date),
            KEY idx_pur_status (status),
            CONSTRAINT fk_pur_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT,
            CONSTRAINT fk_pur_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE RESTRICT
        )" . $engine,

        'purchase_items' => "CREATE TABLE IF NOT EXISTS purchase_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            qty INT NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            returned_qty INT NOT NULL DEFAULT 0,
            KEY idx_pi_purchase (purchase_id),
            KEY idx_pi_product (product_id),
            CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
            CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
        )" . $engine,

        'supplier_payments' => "CREATE TABLE IF NOT EXISTS supplier_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            store_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            supplier_id INT NOT NULL,
            purchase_id INT DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
            payment_date DATE NOT NULL,
            reference VARCHAR(60) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sp_owner (owner_id),
            KEY idx_sp_supplier (supplier_id),
            KEY idx_sp_purchase (purchase_id),
            KEY idx_sp_date (payment_date),
            CONSTRAINT fk_sp_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE
        )" . $engine,

        'purchase_returns' => "CREATE TABLE IF NOT EXISTS purchase_returns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(24) NOT NULL,
            owner_id INT NOT NULL,
            store_id INT NOT NULL,
            user_id INT NOT NULL,
            supplier_id INT NOT NULL,
            purchase_id INT NOT NULL,
            return_date DATE NOT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            refund_method VARCHAR(20) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_pr_invoice (invoice_no),
            KEY idx_pr_owner (owner_id),
            KEY idx_pr_supplier (supplier_id),
            KEY idx_pr_purchase (purchase_id),
            KEY idx_pr_date (return_date),
            CONSTRAINT fk_pr_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE
        )" . $engine,

        'purchase_return_items' => "CREATE TABLE IF NOT EXISTS purchase_return_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            purchase_item_id INT NOT NULL,
            product_id INT NOT NULL,
            qty INT NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            KEY idx_pri_return (return_id),
            KEY idx_pri_product (product_id),
            CONSTRAINT fk_pri_return FOREIGN KEY (return_id) REFERENCES purchase_returns (id) ON DELETE CASCADE,
            CONSTRAINT fk_pri_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (id) ON DELETE RESTRICT
        )" . $engine,

        // The project has no general activity log, so the module keeps its own
        // paper trail rather than inventing a shared one under foot.
        'purchase_audit_log' => "CREATE TABLE IF NOT EXISTS purchase_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            user_name VARCHAR(150) DEFAULT NULL,
            action VARCHAR(40) NOT NULL,
            entity VARCHAR(30) NOT NULL,
            entity_id INT DEFAULT NULL,
            invoice_no VARCHAR(24) DEFAULT NULL,
            amount DECIMAL(12,2) DEFAULT NULL,
            meta TEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pal_owner (owner_id),
            KEY idx_pal_entity (entity, entity_id),
            KEY idx_pal_created (created_at)
        )" . $engine,
    ];
}

/**
 * Create what is missing, then stop with the "run the migration" page if the
 * grant on this host does not allow CREATE TABLE. Same pairing suppliers.php uses.
 */
function purchaseEnsureTables($db)
{
    foreach (purchaseSchema() as $name => $ddl) {
        appSafeDdl($db, $ddl);
    }
    appRequireTables(array_keys(purchaseSchema()), $db);
}

// ---------------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------------

/**
 * Payment methods a purchase may use.
 *
 * The shop's own list, when it has set one (settings.purchase_payment_methods,
 * a CSV of the keys salesPaymentMethods() knows, edited on the Settings page);
 * otherwise every method the sales enum accepts. Unknown stored keys are dropped
 * rather than trusted, and a shop that ticks nothing gets the full list back
 * rather than a form that cannot be saved.
 *
 * Scope is per shop, not platform-wide: two suppliers can be paid quite
 * differently, and a shop must not be able to trim another shop's choices.
 */
function purchasePaymentMethods($db = null, $ownerId = null)
{
    $all = function_exists('salesPaymentMethods') ? salesPaymentMethods() : ['cash' => 'Cash'];
    $chosen = ['cash', 'bkash', 'nagad', 'rocket', 'card', 'bank'];
    if ($ownerId !== null && (int)$ownerId > 0) {
        try {
            $db = $db ?: getDB();
            $stmt = $db->prepare("SELECT setting_value FROM settings
                                   WHERE owner_id = ? AND setting_key = 'purchase_payment_methods'");
            $stmt->execute([(int)$ownerId]);
            $raw = (string)($stmt->fetchColumn() ?: '');
            if ($raw !== '') {
                $list = array_filter(array_map('trim', explode(',', $raw)));
                $known = array_intersect($list, array_keys($all));
                if (!empty($known)) {
                    $chosen = array_values($known);
                }
            }
        } catch (PDOException $e) {
            // No settings row (or no table yet) - the default list stands.
        }
    }
    $out = [];
    foreach ($chosen as $key) {
        if (isset($all[$key])) {
            $out[$key] = $all[$key];
        }
    }
    return empty($out) ? $all : $out;
}

/** Store the purchase may draw from: the shop's own stores only. */
function purchaseStores($ownerId)
{
    $stmt = getDB()->prepare("SELECT id, name FROM stores WHERE owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll();
}

/** Suppliers of this shop, active first - the picker on the entry form. */
function purchaseSupplierOptions($ownerId)
{
    $stmt = getDB()->prepare("SELECT id, name, company, phone, status FROM suppliers
                                WHERE owner_id = ? ORDER BY (status <> 'active'), name");
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll();
}

/**
 * Products of this shop for the line-entry search, with the last price paid and
 * the current stock in the store being bought into.
 */
function purchaseProductOptions($ownerId, $storeId, $q = '')
{
    $sql = "SELECT p.id, p.name, p.barcode, p.unit, p.buy_price, p.sell_price,
                   COALESCE(ss.quantity, 0) AS stock,
                   (SELECT MAX(pi.unit_price) FROM purchase_items pi
                     JOIN purchases pu ON pu.id = pi.purchase_id
                    WHERE pi.product_id = p.id AND pu.owner_id = ?) AS last_price
              FROM products p
              LEFT JOIN store_stocks ss ON ss.product_id = p.id AND ss.store_id = ?
              WHERE p.owner_id = ? AND p.status = 'active'";
    $params = [$ownerId, $storeId, $ownerId];
    if ($q !== '') {
        $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY p.name LIMIT 60";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Every active product of the shop, for the entry form's picker. The list is
 * inlined into the page as JSON and filtered in the browser, which is what the
 * POS screen already does - a 60-row cap would just hide half the catalogue.
 */
function purchaseFormProducts($ownerId, $storeId = null)
{
    $sql = "SELECT p.id, p.name, p.barcode, p.unit, p.buy_price, p.sell_price,
                   COALESCE(ss.quantity, 0) AS stock
              FROM products p
              LEFT JOIN store_stocks ss ON ss.product_id = p.id AND ss.store_id = ?
             WHERE p.owner_id = ? AND p.status = 'active'
             ORDER BY p.name";
    $stmt = getDB()->prepare($sql);
    $stmt->execute([$storeId ?: 0, $ownerId]);
    return $stmt->fetchAll();
}

/** PUR-000001 style number, same shape as the POS invoice numbers. */
function purchaseNextInvoiceNo($db, $prefix = 'PUR-')
{
    $stmt = $db->query("SELECT MAX(id) FROM purchases");
    $last = (int)($stmt->fetchColumn() ?: 0);
    return $prefix . str_pad((string)($last + 1), 6, '0', STR_PAD_LEFT);
}

/** PUR-RTN-000001 for a credit note. */
function purchaseNextReturnNo($db)
{
    $stmt = $db->query("SELECT MAX(id) FROM purchase_returns");
    $last = (int)($stmt->fetchColumn() ?: 0);
    return 'PUR-RTN-' . str_pad((string)($last + 1), 5, '0', STR_PAD_LEFT);
}

/** @return float rounded to the 2dp the money columns hold. */
function purchaseMoney($v)
{
    return round((float)$v, 2);
}

/** A 'Y-m-d' the caller can trust, or today when nothing usable was sent. */
function purchaseDateInput($raw)
{
    $raw = trim((string)$raw);
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : date('Y-m-d');
}

/** One purchase, scoped to the shop. Returns false when it is not theirs. */
function purchaseFind($db, $purchaseId, $ownerId)
{
    $stmt = $db->prepare("SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone,
                                 s.company AS supplier_company, st.name AS store_name
                            FROM purchases p
                            JOIN suppliers s ON s.id = p.supplier_id
                            LEFT JOIN stores st ON st.id = p.store_id
                            WHERE p.id = ? AND p.owner_id = ?");
    $stmt->execute([$purchaseId, $ownerId]);
    $row = $stmt->fetch();
    return $row ?: false;
}

function purchaseItems($db, $purchaseId)
{
    $stmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id = ? ORDER BY id");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function purchasePayments($db, $purchaseId)
{
    $stmt = $db->prepare("SELECT * FROM supplier_payments WHERE purchase_id = ? ORDER BY payment_date, id");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function purchaseReturns($db, $purchaseId)
{
    $stmt = $db->prepare("SELECT * FROM purchase_returns WHERE purchase_id = ? ORDER BY return_date, id");
    $stmt->execute([$purchaseId]);
    return $stmt->fetchAll();
}

function purchaseReturnItems($db, $returnId)
{
    $stmt = $db->prepare("SELECT pri.*, p.name AS product_name
                            FROM purchase_return_items pri
                            LEFT JOIN products p ON p.id = pri.product_id
                            WHERE pri.return_id = ? ORDER BY pri.id");
    $stmt->execute([$returnId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------

/**
 * Append-only trail of what the module did. Never part of a business decision -
 * a failed write must not roll back a purchase, so it is tried and discarded.
 */
function purchaseAudit($db, $actor, $action, $entity, $entityId, $invoiceNo = null, $amount = null, $meta = null)
{
    try {
        $stmt = $db->prepare("INSERT INTO purchase_audit_log
                (owner_id, user_id, user_name, action, entity, entity_id, invoice_no, amount, meta, ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $actor['owner_id'] ?? null,
            $actor['user_id'] ?? null,
            $actor['name'] ?? null,
            $action,
            $entity,
            $entityId,
            $invoiceNo,
            $amount === null ? null : purchaseMoney($amount),
            $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    } catch (PDOException $e) {
        error_log('purchaseAudit failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

/**
 * Open a transaction unless the caller already owns one, and say who owns it.
 *
 * Every write in this module goes through this trio so a caller can group
 * several module calls into one unit: the CLI acceptance test runs a whole
 * scenario and rolls it back, so nothing it creates is ever committed, and a
 * future flow can confirm a purchase and send its invoice in one transaction.
 *
 * A call that finds itself inside someone else's transaction opens a savepoint
 * instead, so its own failure cannot leave half-written rows in the caller's
 * work - the alternative is a bill with no lines on it. The returned handle is
 * what purchaseTransactCommit() and ...Rollback() act on.
 *
 * @return string 'own', or the name of this call's savepoint
 */
function purchaseTransactBegin($db)
{
    if (!$db->inTransaction()) {
        // A purchase books its money side in the cashbook inside this unit, and
        // that table's runtime migration is DDL - which MySQL would commit along
        // with the half-written bill. So the schema is settled before it opens.
        ensureCashbookWriteReady();
        $db->beginTransaction();
        return 'own';
    }
    static $seq = 0;
    $name = 'pu_sp_' . (++$seq);
    $db->exec('SAVEPOINT ' . $name);
    return $name;
}

function purchaseTransactCommit($db, $owned)
{
    if ($owned === 'own') {
        if ($db->inTransaction()) {
            $db->commit();
        }
        return;
    }
    $db->exec('RELEASE SAVEPOINT ' . $owned);
}

function purchaseTransactRollback($db, $owned)
{
    if ($owned === 'own') {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return;
    }
    $db->exec('ROLLBACK TO SAVEPOINT ' . $owned);
    $db->exec('RELEASE SAVEPOINT ' . $owned);
}

// ---------------------------------------------------------------------------
// Cashbook
// ---------------------------------------------------------------------------

/**
 * Cash leaving or entering the till for a purchase reason, in the shared
 * cashbook_entries the Expense page and the reports already read.
 *
 * addAutoCashbookEntry() is not used because it takes the owner and store from
 * the web session; this module also runs from the CLI test.
 */
function purchaseCashbookWrite($db, $actor, $type, $amount, $note, $sourceType, $sourceId, $date = null, $cashbookId = null)
{
    if (purchaseMoney($amount) <= 0) {
        return;
    }
    ensureCashbookSourceColumns();
    $cols = "owner_id, store_id, user_id, type, amount, note, category_id, source_type, source_id, created_at";
    $vals = "?, ?, ?, ?, ?, ?, NULL, ?, ?, ?";
    $params = [
        $actor['owner_id'],
        $actor['store_id'] ?? null,
        $actor['user_id'] ?? 0,
        $type,
        purchaseMoney($amount),
        $note,
        $sourceType,
        $sourceId,
        purchaseDateInput($date) . ' ' . date('H:i:s'),
    ];
    // Naming the book here is what keeps a supplier payment in the drawer it was
    // actually paid from. Left out, the line carries no book and the cashbook's
    // own backfill files it under the shop's default one - which is a guess about
    // where the money came from, and wrong for a shop that pays Rahim from his
    // bKash and the rent from the drawer.
    if (purchaseCashbookEntryBookColumn($db)) {
        $cols .= ", cashbook_id";
        $vals .= ", ?";
        $params[] = $cashbookId !== null && (int)$cashbookId > 0 ? (int)$cashbookId : null;
    }
    $stmt = $db->prepare("INSERT INTO cashbook_entries ($cols) VALUES ($vals)");
    $stmt->execute($params);
}

/**
 * Does cashbook_entries carry the book column yet?
 *
 * The cashbook module adds it on first use. An install whose DB user cannot ALTER
 * and has not run the migration has none, and its payments must still be recorded
 * - they just go on without saying which book, exactly as they always did.
 */
function purchaseCashbookEntryBookColumn($db)
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = !empty($db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'cashbook_id'")->fetchAll());
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

/**
 * The "Paid from" select shared by every payment door.
 *
 * Empty value means "the book the app would have picked anyway", which is what
 * keeps a shop that never assigned a supplier to a book seeing the same figures
 * it saw before the field existed. When the supplier does have one, that book
 * arrives already chosen and the person at the counter can still change it.
 *
 * @return string  markup, or '' when the shop has no book to pay out of
 */
function purchaseCashbookField($db, $ownerId, $supplierId = 0, $selected = null)
{
    $books = cashbookPaymentBooks($db, $ownerId);
    if (!$books) {
        return '';
    }
    $current = ($selected === null || $selected === '')
        ? cashbookIdAssignedToSupplier($db, $ownerId, $supplierId)
        : (int)$selected;

    $html = '<div class="form-group">'
          . '<label class="form-label">Paid from</label>'
          . '<select class="form-control" name="cashbook_id" id="payCashbook">'
          . '<option value="">Default book for the method</option>';
    foreach ($books as $id => $book) {
        $html .= '<option value="' . (int)$id . '"'
               . ' data-supplier="' . (int)$book['supplier_id'] . '"'
               . ((int)$current === (int)$id ? ' selected' : '') . '>'
               . htmlspecialchars($book['name'], ENT_QUOTES, 'UTF-8')
               . '</option>';
    }
    $html .= '</select>'
           . '<small class="text-muted">Which cashbook this money leaves. The supplier\'s own book is picked for you.</small>'
           . '</div>';
    // One definition per page: a payment form whose supplier is chosen on the page
    // calls this so the book follows the supplier, and a page that renders the
    // field twice does not redefine it.
    $html .= '<script>window.puPickBookForSupplier || (window.puPickBookForSupplier = function (supplierId) {'
           . 'var sel = document.getElementById("payCashbook"); if (!sel) return;'
           . 'var opt = supplierId ? sel.querySelector(\'option[data-supplier="\' + supplierId + \'"]\') : null;'
           . 'sel.value = opt ? opt.value : \'\';'
           . '});</script>';
    return $html;
}

function purchaseCashbookDrop($db, $sourceType, $sourceId)
{
    ensureCashbookSourceColumns();
    $stmt = $db->prepare("DELETE FROM cashbook_entries WHERE source_type = ? AND source_id = ?");
    $stmt->execute([$sourceType, $sourceId]);
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------

/**
 * Move the store's stock for a purchase, in one direction.
 *
 * $sign  1 when the goods arrive, -1 to take exactly that back out. Every write
 * also lands in stock_history, which is how the rest of the app explains a
 * quantity change, and mirrors the legacy products.stock column the way
 * stock.php does so both readings agree.
 */
function purchaseApplyStock($db, $purchase, $items, $sign)
{
    $ensure = $db->prepare("SELECT id FROM store_stocks WHERE store_id = ? AND product_id = ?");
    $insert = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
    $change = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
    $mirror = $db->prepare("UPDATE products SET stock = COALESCE((SELECT SUM(quantity) FROM store_stocks WHERE product_id = ?), 0) WHERE id = ?");
    $history = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id)
                                VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($items as $item) {
        $qty = (int)$item['qty'] * ($sign > 0 ? 1 : -1);
        if ($qty === 0) {
            continue;
        }
        $ensure->execute([$purchase['store_id'], $item['product_id']]);
        if (!$ensure->fetchColumn()) {
            $insert->execute([$purchase['store_id'], $item['product_id']]);
        }
        $change->execute([$qty, $purchase['store_id'], $item['product_id']]);
        $mirror->execute([$item['product_id'], $item['product_id']]);
        $history->execute([
            $item['product_id'],
            $qty,
            $sign > 0 ? 'purchase' : 'return',
            (int)$purchase['id'],
            ($sign > 0 ? 'Purchase ' : 'Purchase reversed ') . $purchase['invoice_no'],
            $purchase['user_id'],
        ]);
    }
}

/**
 * Put goods back on the shelf after a purchase return: the store loses quantity
 * and stock_history records it as a return against the credit note.
 */
function purchaseApplyReturnStock($db, $ret, $lines)
{
    $change = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
    $mirror = $db->prepare("UPDATE products SET stock = COALESCE((SELECT SUM(quantity) FROM store_stocks WHERE product_id = ?), 0) WHERE id = ?");
    $history = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id)
                                VALUES (?, ?, 'return', ?, ?, ?)");
    foreach ($lines as $line) {
        $qty = (int)$line['qty'];
        if ($qty <= 0) {
            continue;
        }
        $change->execute([$qty, $ret['store_id'], $line['product_id']]);
        $mirror->execute([$line['product_id'], $line['product_id']]);
        $history->execute([
            $line['product_id'],
            -$qty,
            (int)$ret['id'],
            'Purchase return ' . $ret['invoice_no'] . ' (' . $ret['ref_invoice_no'] . ')',
            $ret['user_id'],
        ]);
    }
}

// ---------------------------------------------------------------------------
// The one recalculation
// ---------------------------------------------------------------------------

/**
 * Rebuild paid/credit/due/status from the child rows.
 *
 * Called inside every transaction that can change them, so the columns are a
 * cache of the truth rather than a second copy of it.
 *
 * The discount is split on purpose: `purchase_items.discount` holds what came off
 * a single line and `purchases.discount` only what came off the bill as a whole,
 * so re-deriving the total never has to unpick a merged figure.
 */
function purchaseRecalc($db, $purchaseId)
{
    $stmt = $db->prepare("SELECT * FROM purchases WHERE id = ?");
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        return null;
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(qty * unit_price), 0) gross,
                                 COALESCE(SUM(discount), 0) line_disc
                            FROM purchase_items WHERE purchase_id = ?");
    $stmt->execute([$purchaseId]);
    $sum = $stmt->fetch();
    $subtotal = purchaseMoney($sum['gross']);
    $lineDisc = purchaseMoney($sum['line_disc']);
    $billDisc = purchaseMoney($purchase['discount']);
    $grand = purchaseMoney($subtotal - $lineDisc - $billDisc);

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) a, MAX(payment_method) m
                            FROM supplier_payments WHERE purchase_id = ?");
    $stmt->execute([$purchaseId]);
    $paid = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(total), 0) t, COALESCE(SUM(refund_amount), 0) r
                            FROM purchase_returns WHERE purchase_id = ?");
    $stmt->execute([$purchaseId]);
    $cr = $stmt->fetch();

    // A credit note gives back value on the goods; the part the supplier refunded
    // in cash has already left the ledger, so only the rest settles the bill.
    $credit = purchaseMoney($cr['t'] - $cr['r']);
    $paidAmount = purchaseMoney($paid['a']);
    $due = purchaseMoney($grand - $paidAmount - $credit);

    $status = $due <= 0.009 ? ($paidAmount > 0 || $credit > 0 ? 'paid' : 'due')
                            : ($paidAmount > 0 ? 'partial' : 'due');

    $stmt = $db->prepare("UPDATE purchases
                             SET subtotal = ?, grand_total = ?, paid_amount = ?,
                                 credit_amount = ?, due_amount = ?, status = ?, payment_method = ?
                           WHERE id = ?");
    $stmt->execute([
        $subtotal,
        $grand,
        $paidAmount,
        $credit,
        $due,
        $status,
        $paidAmount > 0 ? $paid['m'] : null,
        $purchaseId,
    ]);

    return $due;
}

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

/**
 * Validate a purchase entry, write it, take it onto the books and put the stock
 * in the store - all in one transaction.
 *
 * @param array $actor  owner_id, store_id, user_id, name
 * @param array $data   supplier_id, store_id, purchase_date, note, update_buy_price,
 *                      discount, items[] {product_id, qty, unit_price, discount},
 *                      payment {amount, method, date, reference, note} or empty
 * @return array the stored purchase row
 * @throws PurchaseException with a message safe to show the user
 */
function purchaseCreate($db, $actor, $data)
{
    $ownerId = (int)$actor['owner_id'];

    $supplierId = (int)($data['supplier_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE id = ? AND owner_id = ?");
    $stmt->execute([$supplierId, $ownerId]);
    $supplier = $stmt->fetch();
    if (!$supplier) {
        throw new PurchaseException('Choose a supplier that belongs to this shop.');
    }

    $storeId = (int)($data['store_id'] ?? $actor['store_id'] ?? 0);
    if ($actor['store_id']) {
        // A branch user may only ever buy into their own branch.
        $storeId = (int)$actor['store_id'];
    }
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE id = ? AND owner_id = ?");
    $stmt->execute([$storeId, $ownerId]);
    $store = $stmt->fetch();
    if (!$store) {
        throw new PurchaseException('Choose the store the goods are arriving at.');
    }

    $rawItems = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
    $items = [];
    $seen = [];
    foreach ($rawItems as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = (int)($row['qty'] ?? 0);
        $price = (float)($row['unit_price'] ?? 0);
        $disc = (float)($row['discount'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        if (isset($seen[$pid])) {
            throw new PurchaseException('The same product is listed twice - merge the two lines first.');
        }
        if ($price < 0 || $disc < 0) {
            throw new PurchaseException('Price and discount cannot be negative.');
        }
        $stmt = $db->prepare("SELECT id, name, buy_price FROM products WHERE id = ? AND owner_id = ? AND status = 'active'");
        $stmt->execute([$pid, $ownerId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new PurchaseException('One of the products is not part of this shop.');
        }
        $line = purchaseMoney($qty * $price - $disc);
        if ($line < 0) {
            throw new PurchaseException('A line discount is larger than that line\'s value.');
        }
        $seen[$pid] = true;
        $items[] = [
            'product_id' => $pid,
            'product_name' => $product['name'],
            'qty' => $qty,
            'unit_price' => purchaseMoney($price),
            'discount' => purchaseMoney($disc),
            'line_total' => $line,
        ];
    }
    if (empty($items)) {
        throw new PurchaseException('Add at least one product with a quantity.');
    }

    $subtotal = purchaseMoney(array_sum(array_map(function ($i) { return $i['qty'] * $i['unit_price']; }, $items)));
    $lineDisc = purchaseMoney(array_sum(array_column($items, 'discount')));
    $billDisc = purchaseMoney($data['discount'] ?? 0);
    if ($billDisc < 0) {
        throw new PurchaseException('Discount cannot be negative.');
    }
    $grand = purchaseMoney($subtotal - $lineDisc - $billDisc);
    if ($grand < 0) {
        throw new PurchaseException('The discount is larger than the purchase value.');
    }

    $payAmount = purchaseMoney($data['payment']['amount'] ?? 0);
    $payMethod = trim((string)($data['payment']['method'] ?? 'cash'));
    if ($payAmount < 0) {
        throw new PurchaseException('Paid amount cannot be negative.');
    }
    if ($payAmount > $grand) {
        throw new PurchaseException('The paid amount is more than the grand total.');
    }
    if ($payAmount > 0 && !array_key_exists($payMethod, purchasePaymentMethods($db, $actor['owner_id']))) {
        throw new PurchaseException('Choose a valid payment method for the amount paid.');
    }
    $payDate = purchaseDateInput($data['payment']['date'] ?? null);
    $purchaseDate = purchaseDateInput($data['purchase_date'] ?? null);

    $updateBuyPrice = !empty($data['update_buy_price']);

    $ownTx = purchaseTransactBegin($db);
    try {
        $stmt = $db->prepare("INSERT INTO purchases
                (invoice_no, owner_id, store_id, user_id, supplier_id, purchase_date,
                 subtotal, discount, grand_total, paid_amount, credit_amount, due_amount,
                 status, stock_applied, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'due', 0, ?)");
        $invoiceNo = purchaseNextInvoiceNo($db);
        $stmt->execute([
            $invoiceNo, $ownerId, $storeId, (int)$actor['user_id'], $supplierId, $purchaseDate,
            $subtotal, $billDisc, $grand, $grand,
            mb_substr(trim((string)($data['note'] ?? '')), 0, 255),
        ]);
        $purchaseId = (int)$db->lastInsertId();

        $item = $db->prepare("INSERT INTO purchase_items
                (purchase_id, product_id, product_name, qty, unit_price, discount, line_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $line) {
            $item->execute([$purchaseId, $line['product_id'], $line['product_name'],
                $line['qty'], $line['unit_price'], $line['discount'], $line['line_total']]);
        }

        $purchase = purchaseFind($db, $purchaseId, $ownerId);
        $items = purchaseItems($db, $purchaseId);
        purchaseApplyStock($db, $purchase, $items, 1);
        $db->prepare("UPDATE purchases SET stock_applied = 1 WHERE id = ?")->execute([$purchaseId]);

        if ($updateBuyPrice) {
            $buy = $db->prepare("UPDATE products SET buy_price = ? WHERE id = ? AND owner_id = ?");
            foreach ($items as $line) {
                $buy->execute([purchaseMoney($line['unit_price']), $line['product_id'], $ownerId]);
            }
        }

        if ($payAmount > 0) {
            purchasePaymentInsert($db, $actor, [
                'supplier_id' => $supplierId,
                'store_id' => $storeId,
                'purchase_id' => $purchaseId,
                'amount' => $payAmount,
                'payment_method' => $payMethod,
                'payment_date' => $payDate,
                'reference' => $data['payment']['reference'] ?? null,
                'note' => $data['payment']['note'] ?? ('Payment for ' . $invoiceNo),
                'cashbook_id' => $data['payment']['cashbook_id'] ?? null,
            ]);
        }

        purchaseRecalc($db, $purchaseId);
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        if ($e instanceof PurchaseException) {
            throw $e;
        }
        error_log('purchaseCreate failed: ' . $e->getMessage());
        throw new PurchaseException('The purchase could not be saved. Check the entries and try again.');
    }

    purchaseAudit($db, $actor, 'purchase.create', 'purchase', $purchaseId, $invoiceNo, $grand, [
        'supplier' => $supplier['name'],
        'store' => $store['name'],
        'lines' => count($items),
        'paid' => $payAmount,
        'due' => purchaseMoney($grand - $payAmount),
    ]);

    return purchaseFind($db, $purchaseId, $ownerId);
}

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

/**
 * Insert a payment row and its cashbook movement. Caller owns the transaction.
 *
 * @return int payment id
 */
function purchasePaymentInsert($db, $actor, $data)
{
    $amount = purchaseMoney($data['amount'] ?? 0);
    if ($amount <= 0) {
        throw new PurchaseException('Enter the amount paid.');
    }
    $methods = purchasePaymentMethods($db, $actor['owner_id']);
    $method = (string)($data['payment_method'] ?? '');
    if (!isset($methods[$method])) {
        throw new PurchaseException('Choose a valid payment method.');
    }
    $stmt = $db->prepare("INSERT INTO supplier_payments
            (owner_id, store_id, user_id, supplier_id, purchase_id, amount, payment_method,
             payment_date, reference, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $actor['owner_id'],
        $actor['store_id'] ?? ($data['store_id'] ?? null),
        $actor['user_id'],
        (int)$data['supplier_id'],
        !empty($data['purchase_id']) ? (int)$data['purchase_id'] : null,
        $amount,
        $method,
        purchaseDateInput($data['payment_date'] ?? null),
        mb_substr(trim((string)($data['reference'] ?? '')), 0, 60),
        mb_substr(trim((string)($data['note'] ?? '')), 0, 255),
    ]);
    $id = (int)$db->lastInsertId();

    // Which book the money left. The form's choice wins; a door that carries no
    // choice (an older page, the CLI test) still lands on the book the shop
    // assigned to this supplier, and on nothing at all when none is assigned.
    $bookId = null;
    if (isset($data['cashbook_id']) && $data['cashbook_id'] !== '') {
        $bookId = (int)$data['cashbook_id'];
        if (!cashbookIsPayableBook($db, $actor['owner_id'], $bookId)) {
            throw new PurchaseException('Choose a cashbook of this shop to pay from.');
        }
    } else {
        $bookId = cashbookIdAssignedToSupplier($db, $actor['owner_id'], (int)$data['supplier_id']);
    }

    $label = !empty($data['purchase_id'])
        ? 'Purchase payment ' . ($data['invoice_no'] ?? ('#' . (int)$data['purchase_id']))
        : 'Supplier advance payment';
    purchaseCashbookWrite($db, $actor, 'cash_out', $amount,
        $label . ' - ' . $methods[$method], 'purchase_pay', $id, $data['payment_date'] ?? null, $bookId);

    return $id;
}

/**
 * Record that the shop paid a supplier, against one bill or on account.
 *
 * A bill payment can never exceed what is still owed on it, which is what keeps
 * due_amount from going negative through the payment form.
 */
function purchasePaymentAdd($db, $actor, $data)
{
    $ownerId = (int)$actor['owner_id'];
    $supplierId = (int)($data['supplier_id'] ?? 0);
    $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE id = ? AND owner_id = ?");
    $stmt->execute([$supplierId, $ownerId]);
    $supplier = $stmt->fetch();
    if (!$supplier) {
        throw new PurchaseException('Choose a supplier of this shop.');
    }

    $purchase = null;
    if (!empty($data['purchase_id'])) {
        $purchase = purchaseFind($db, (int)$data['purchase_id'], $ownerId);
        if (!$purchase || (int)$purchase['supplier_id'] !== $supplierId) {
            throw new PurchaseException('That bill does not belong to the chosen supplier.');
        }
        $amount = purchaseMoney($data['amount']);
        if ($amount > purchaseMoney($purchase['due_amount']) + 0.009) {
            throw new PurchaseException('The amount is more than the remaining due ('
                . number_format((float)$purchase['due_amount'], 2) . ').');
        }
    }

    $ownTx = purchaseTransactBegin($db);
    try {
        $data['supplier_id'] = $supplierId;
        $paymentId = purchasePaymentInsert($db, $actor, $data);
        if ($purchase) {
            purchaseRecalc($db, (int)$purchase['id']);
        }
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        if ($e instanceof PurchaseException) {
            throw $e;
        }
        error_log('purchasePaymentAdd failed: ' . $e->getMessage());
        throw new PurchaseException('The payment could not be recorded.');
    }

    purchaseAudit($db, $actor, 'payment.add', 'supplier_payment', $paymentId,
        $purchase ? $purchase['invoice_no'] : null, purchaseMoney($data['amount']),
        ['supplier' => $supplier['name'], 'method' => $data['payment_method'] ?? null]);

    return $paymentId;
}

/** Undo a payment: the cashbook row goes with it, and the bill is recalculated. */
function purchasePaymentDelete($db, $actor, $paymentId)
{
    $ownerId = (int)$actor['owner_id'];
    $stmt = $db->prepare("SELECT * FROM supplier_payments WHERE id = ? AND owner_id = ?");
    $stmt->execute([$paymentId, $ownerId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        throw new PurchaseException('That payment no longer exists.');
    }

    $ownTx = purchaseTransactBegin($db);
    try {
        purchaseCashbookDrop($db, 'purchase_pay', (int)$payment['id']);
        $db->prepare("DELETE FROM supplier_payments WHERE id = ?")->execute([$paymentId]);
        if ($payment['purchase_id']) {
            purchaseRecalc($db, (int)$payment['purchase_id']);
        }
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        throw new PurchaseException('The payment could not be deleted.');
    }

    purchaseAudit($db, $actor, 'payment.delete', 'supplier_payment', (int)$paymentId,
        null, purchaseMoney($payment['amount']), ['reason' => 'removed by user']);
    return true;
}

// ---------------------------------------------------------------------------
// Returns
// ---------------------------------------------------------------------------

/**
 * Send goods back to the supplier: stock leaves the store, a credit note reduces
 * what is owed, and any cash the supplier hands back is a cash_in.
 *
 * @param array $data purchase_id, return_date, note, refund_amount, refund_method,
 *                    items[] {purchase_item_id, qty}
 */
function purchaseReturnAdd($db, $actor, $data)
{
    $ownerId = (int)$actor['owner_id'];
    $purchase = purchaseFind($db, (int)($data['purchase_id'] ?? 0), $ownerId);
    if (!$purchase) {
        throw new PurchaseException('Choose the purchase to return against.');
    }

    $items = purchaseItems($db, (int)$purchase['id']);
    $byId = [];
    foreach ($items as $line) {
        $byId[(int)$line['id']] = $line;
    }

    $lines = [];
    $total = 0.0;
    $wanted = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
    foreach ($wanted as $row) {
        $iid = (int)($row['purchase_item_id'] ?? 0);
        $qty = (int)($row['qty'] ?? 0);
        if ($qty <= 0 || !isset($byId[$iid])) {
            continue;
        }
        $line = $byId[$iid];
        $already = (int)$line['returned_qty'];
        if ($already + $qty > (int)$line['qty']) {
            throw new PurchaseException('Only ' . ((int)$line['qty'] - $already)
                . ' of "' . $line['product_name'] . '" can still be returned.');
        }
        $value = purchaseMoney($qty * (float)$line['unit_price']);
        $total += $value;
        $lines[] = [
            'purchase_item_id' => $iid,
            'product_id' => (int)$line['product_id'],
            'product_name' => $line['product_name'],
            'qty' => $qty,
            'unit_price' => purchaseMoney($line['unit_price']),
            'line_total' => $value,
        ];
    }
    if (empty($lines)) {
        throw new PurchaseException('Say how many of each item are going back.');
    }
    $total = purchaseMoney($total);

    $refund = purchaseMoney($data['refund_amount'] ?? 0);
    $refundMethod = trim((string)($data['refund_method'] ?? ''));
    if ($refund < 0 || $refund > $total) {
        throw new PurchaseException('The refund taken back cannot be more than the return value.');
    }
    if ($refund > 0 && !array_key_exists($refundMethod, purchasePaymentMethods($db, $actor['owner_id']))) {
        throw new PurchaseException('Choose how the supplier refunded the cash.');
    }

    $ownTx = purchaseTransactBegin($db);
    try {
        $invoiceNo = purchaseNextReturnNo($db);
        $stmt = $db->prepare("INSERT INTO purchase_returns
                (invoice_no, owner_id, store_id, user_id, supplier_id, purchase_id,
                 return_date, total, refund_amount, refund_method, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $invoiceNo, $ownerId, (int)$purchase['store_id'], (int)$actor['user_id'],
            (int)$purchase['supplier_id'], (int)$purchase['id'],
            purchaseDateInput($data['return_date'] ?? null), $total, $refund,
            $refund > 0 ? $refundMethod : null,
            mb_substr(trim((string)($data['note'] ?? '')), 0, 255),
        ]);
        $returnId = (int)$db->lastInsertId();

        $ins = $db->prepare("INSERT INTO purchase_return_items
                (return_id, purchase_item_id, product_id, qty, unit_price, line_total)
                VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($lines as $line) {
            $ins->execute([$returnId, $line['purchase_item_id'], $line['product_id'],
                $line['qty'], $line['unit_price'], $line['line_total']]);
        }
        $add = $db->prepare("UPDATE purchase_items SET returned_qty = returned_qty + ? WHERE id = ?");
        foreach ($lines as $line) {
            $add->execute([$line['qty'], $line['purchase_item_id']]);
        }

        $ret = [
            'id' => $returnId,
            'store_id' => (int)$purchase['store_id'],
            'user_id' => (int)$actor['user_id'],
            'invoice_no' => $invoiceNo,
            'ref_invoice_no' => $purchase['invoice_no'],
        ];
        purchaseApplyReturnStock($db, $ret, $lines);

        if ($refund > 0) {
            purchaseCashbookWrite($db, $actor, 'cash_in', $refund,
                'Purchase return refund ' . $invoiceNo, 'purchase_refund', $returnId,
                $data['return_date'] ?? null);
        }

        purchaseRecalc($db, (int)$purchase['id']);
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        if ($e instanceof PurchaseException) {
            throw $e;
        }
        error_log('purchaseReturnAdd failed: ' . $e->getMessage());
        throw new PurchaseException('The return could not be saved.');
    }

    purchaseAudit($db, $actor, 'return.add', 'purchase_return', $returnId, $invoiceNo, $total,
        ['purchase' => $purchase['invoice_no'], 'refund' => $refund, 'lines' => count($lines)]);

    return $returnId;
}

/** Cancel a credit note: stock goes back in, the bill owes what it owed before. */
function purchaseReturnDelete($db, $actor, $returnId)
{
    $ownerId = (int)$actor['owner_id'];
    $stmt = $db->prepare("SELECT r.*, p.invoice_no AS ref_invoice_no FROM purchase_returns r
                            JOIN purchases p ON p.id = r.purchase_id
                            WHERE r.id = ? AND r.owner_id = ?");
    $stmt->execute([$returnId, $ownerId]);
    $ret = $stmt->fetch();
    if (!$ret) {
        throw new PurchaseException('That return no longer exists.');
    }
    $lines = purchaseReturnItems($db, (int)$ret['id']);

    $ownTx = purchaseTransactBegin($db);
    try {
        $restore = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
        $mirror = $db->prepare("UPDATE products SET stock = COALESCE((SELECT SUM(quantity) FROM store_stocks WHERE product_id = ?), 0) WHERE id = ?");
        $history = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id)
                                    VALUES (?, ?, 'purchase', ?, ?, ?)");
        foreach ($lines as $line) {
            $qty = (int)$line['qty'];
            if ($qty <= 0) {
                continue;
            }
            $restore->execute([$qty, (int)$ret['store_id'], (int)$line['product_id']]);
            $mirror->execute([(int)$line['product_id'], (int)$line['product_id']]);
            $history->execute([(int)$line['product_id'], $qty, (int)$ret['id'],
                'Return cancelled ' . $ret['invoice_no'], (int)$actor['user_id']]);
        }
        $sub = $db->prepare("UPDATE purchase_items SET returned_qty = GREATEST(returned_qty - ?, 0) WHERE id = ?");
        foreach ($lines as $line) {
            $sub->execute([(int)$line['qty'], (int)$line['purchase_item_id']]);
        }
        purchaseCashbookDrop($db, 'purchase_refund', (int)$ret['id']);
        $db->prepare("DELETE FROM purchase_return_items WHERE return_id = ?")->execute([$returnId]);
        $db->prepare("DELETE FROM purchase_returns WHERE id = ?")->execute([$returnId]);
        purchaseRecalc($db, (int)$ret['purchase_id']);
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        throw new PurchaseException('The return could not be cancelled.');
    }

    purchaseAudit($db, $actor, 'return.delete', 'purchase_return', (int)$returnId,
        $ret['invoice_no'], purchaseMoney($ret['total']), ['purchase' => $ret['ref_invoice_no']]);
    return true;
}

// ---------------------------------------------------------------------------
// Delete a purchase
// ---------------------------------------------------------------------------

/**
 * Take a purchase off the books: stock reversed, its payments and their cashbook
 * rows removed, the audit line kept.
 *
 * Blocked while a credit note references it, because the return already moved
 * stock that this purchase put in.
 */
function purchaseDelete($db, $actor, $purchaseId)
{
    $ownerId = (int)$actor['owner_id'];
    $purchase = purchaseFind($db, $purchaseId, $ownerId);
    if (!$purchase) {
        throw new PurchaseException('That purchase no longer exists.');
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM purchase_returns WHERE purchase_id = ?");
    $stmt->execute([$purchaseId]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new PurchaseException('Cancel its purchase returns first - they moved stock of their own.');
    }

    $items = purchaseItems($db, $purchaseId);

    $ownTx = purchaseTransactBegin($db);
    try {
        if ((int)$purchase['stock_applied'] === 1) {
            purchaseApplyStock($db, $purchase, $items, -1);
            $db->prepare("UPDATE purchases SET stock_applied = 0 WHERE id = ?")->execute([$purchaseId]);
        }
        $stmt = $db->prepare("SELECT id FROM supplier_payments WHERE purchase_id = ?");
        $stmt->execute([$purchaseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
            purchaseCashbookDrop($db, 'purchase_pay', (int)$paymentId);
        }
        $db->prepare("DELETE FROM supplier_payments WHERE purchase_id = ?")->execute([$purchaseId]);
        $db->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchaseId]);
        $db->prepare("DELETE FROM purchases WHERE id = ?")->execute([$purchaseId]);
        purchaseTransactCommit($db, $ownTx);
    } catch (Throwable $e) {
        purchaseTransactRollback($db, $ownTx);
        error_log('purchaseDelete failed: ' . $e->getMessage());
        throw new PurchaseException('The purchase could not be deleted.');
    }

    purchaseAudit($db, $actor, 'purchase.delete', 'purchase', (int)$purchaseId,
        $purchase['invoice_no'], purchaseMoney($purchase['grand_total']),
        ['supplier' => $purchase['supplier_name'], 'stock_reversed' => (int)$purchase['stock_applied']]);
    return true;
}

// ---------------------------------------------------------------------------
// Dues and the ledger
// ---------------------------------------------------------------------------

/**
 * Everything that hangs off a supplier, so the owner can see it before it goes.
 *
 * On-account payments are listed apart from the bills because they belong to the
 * supplier rather than to a purchase, so deleting the bills would never reach them.
 */
function supplierDeletePlan($db, $supplierId, $ownerId)
{
    $stmt = $db->prepare("SELECT p.id, p.invoice_no, p.purchase_date, p.grand_total, p.due_amount,
                                 p.stock_applied, st.name AS store_name
                            FROM purchases p
                            LEFT JOIN stores st ON st.id = p.store_id
                           WHERE p.supplier_id = ? AND p.owner_id = ?
                           ORDER BY p.purchase_date, p.id");
    $stmt->execute([$supplierId, $ownerId]);
    $bills = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT id, amount, payment_date FROM supplier_payments
                           WHERE supplier_id = ? AND owner_id = ? AND purchase_id IS NULL
                           ORDER BY payment_date, id");
    $stmt->execute([$supplierId, $ownerId]);
    $advances = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM purchase_returns WHERE supplier_id = ? AND owner_id = ?");
    $stmt->execute([$supplierId, $ownerId]);

    return [
        'bills'        => $bills,
        'advances'     => $advances,
        'return_count' => (int)$stmt->fetchColumn(),
    ];
}

/**
 * What each supplier is owed, over every bill.
 *
 * On-account payments (purchase_id IS NULL) sit against the supplier rather than
 * a bill, so they are subtracted here; the ledger shows the same figure built the
 * other way round, which is the cross-check.
 *
 * @param string|null $to  'Y-m-d' - dues as at that date (inclusive)
 */
function supplierDueList($db, $ownerId, $to = null, $storeId = null, $q = '')
{
    $billWhere = ["owner_id = ?"];
    $billParams = [$ownerId];
    if ($to) {
        $billWhere[] = "purchase_date <= ?";
        $billParams[] = purchaseDateInput($to);
    }
    if ($storeId) {
        $billWhere[] = "store_id = ?";
        $billParams[] = (int)$storeId;
    }

    $payWhere = ["owner_id = ?", "purchase_id IS NULL"];
    $payParams = [$ownerId];
    if ($to) {
        $payWhere[] = "payment_date <= ?";
        $payParams[] = purchaseDateInput($to);
    }

    $supWhere = ["s.owner_id = ?"];
    $supParams = [$ownerId];
    if ($q !== '') {
        $supWhere[] = "(s.name LIKE ? OR s.company LIKE ? OR s.phone LIKE ?)";
        $like = '%' . $q . '%';
        $supParams[] = $like;
        $supParams[] = $like;
        $supParams[] = $like;
    }

    // The two aggregates are subqueries rather than conditions on the JOIN, so a
    // supplier with bills in another period still appears with zeros instead of
    // vanishing, and neither side multiplies the other's rows.
    $sql = "SELECT s.id AS supplier_id, s.name AS supplier_name, s.company, s.phone,
                   COALESCE(b.bills, 0) AS bills,
                   COALESCE(b.total_purchase, 0) AS total_purchase,
                   COALESCE(b.paid, 0) AS paid,
                   COALESCE(b.credited, 0) AS credited,
                   COALESCE(b.due, 0) AS bill_due,
                   COALESCE(pay.on_account, 0) AS on_account,
                   GREATEST(COALESCE(b.due, 0) - COALESCE(pay.on_account, 0), 0) AS outstanding,
                   b.oldest_due_date
              FROM suppliers s
              LEFT JOIN (
                    SELECT supplier_id, COUNT(*) bills, SUM(grand_total) total_purchase,
                           SUM(paid_amount) paid, SUM(credit_amount) credited,
                           SUM(due_amount) due,
                           MIN(CASE WHEN due_amount > 0.009 THEN purchase_date END) oldest_due_date
                      FROM purchases WHERE " . implode(' AND ', $billWhere) . "
                     GROUP BY supplier_id
              ) b ON b.supplier_id = s.id
              LEFT JOIN (
                    SELECT supplier_id, SUM(amount) on_account
                      FROM supplier_payments WHERE " . implode(' AND ', $payWhere) . "
                     GROUP BY supplier_id
              ) pay ON pay.supplier_id = s.id
             WHERE " . implode(' AND ', $supWhere) . "
             HAVING total_purchase > 0 OR on_account > 0
             ORDER BY outstanding DESC, s.name";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($billParams, $payParams, $supParams));
    return $stmt->fetchAll();
}

/**
 * The on-account payments themselves, newest first, keyed by supplier.
 *
 * The dues table above carries only their sum, which left a wrongly entered
 * advance with nowhere to be undone: a payment that sits on a bill can be deleted
 * from that bill's page, but an advance belongs to no bill, so no bill page ever
 * showed it.
 *
 * @return array supplier_id => payment rows
 */
function supplierAdvanceList($db, $ownerId, $to = null, $storeId = null)
{
    $where = ["owner_id = ?", "purchase_id IS NULL"];
    $params = [(int)$ownerId];
    if ($to) {
        $where[] = "payment_date <= ?";
        $params[] = purchaseDateInput($to);
    }
    if ($storeId) {
        $where[] = "store_id = ?";
        $params[] = (int)$storeId;
    }

    $stmt = $db->prepare("SELECT id, supplier_id, amount, payment_method, payment_date, reference, note
                            FROM supplier_payments
                           WHERE " . implode(' AND ', $where) . "
                        ORDER BY payment_date DESC, id DESC");
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['supplier_id']][] = $row;
    }
    return $out;
}

/**
 * The supplier's account: every bill as a debit, every payment and credit note
 * as a credit, oldest first, with the running balance.
 *
 * @return array opening / rows (each with balance) / closing
 */
function supplierLedger($db, $ownerId, $supplierId, $from = null, $to = null)
{
    $opening = 0.0;
    if ($from) {
        // Everything before the window, in the same debit/credit shape the rows
        // below use, so the running balance in the table is the real balance.
        $f = purchaseDateInput($from);
        $stmt = $db->prepare("SELECT
                (SELECT COALESCE(SUM(grand_total), 0) FROM purchases
                  WHERE owner_id = ? AND supplier_id = ? AND purchase_date < ?)
              - (SELECT COALESCE(SUM(amount), 0) FROM supplier_payments
                  WHERE owner_id = ? AND supplier_id = ? AND payment_date < ?)
              - (SELECT COALESCE(SUM(total - refund_amount), 0) FROM purchase_returns
                  WHERE owner_id = ? AND supplier_id = ? AND return_date < ?)");
        $stmt->execute([
            $ownerId, $supplierId, $f,
            $ownerId, $supplierId, $f,
            $ownerId, $supplierId, $f,
        ]);
        $opening = purchaseMoney($stmt->fetchColumn());
    }

    $rows = [];
    $w = ['owner_id = ?', 'supplier_id = ?'];
    $p = [$ownerId, $supplierId];
    if ($from) { $w[] = 'purchase_date >= ?'; $p[] = purchaseDateInput($from); }
    if ($to)   { $w[] = 'purchase_date <= ?'; $p[] = purchaseDateInput($to); }
    $stmt = $db->prepare("SELECT purchase_date dt, invoice_no ref, id AS ref_id,
                                 grand_total debit, 0 credit, 'Purchase' kind, note
                            FROM purchases WHERE " . implode(' AND ', $w) . " ORDER BY purchase_date, id");
    $stmt->execute($p);
    foreach ($stmt->fetchAll() as $r) { $rows[] = $r; }

    $w = ['owner_id = ?', 'supplier_id = ?'];
    $p = [$ownerId, $supplierId];
    if ($from) { $w[] = 'payment_date >= ?'; $p[] = purchaseDateInput($from); }
    if ($to)   { $w[] = 'payment_date <= ?'; $p[] = purchaseDateInput($to); }
    $stmt = $db->prepare("SELECT payment_date dt,
                             COALESCE((SELECT invoice_no FROM purchases WHERE id = supplier_payments.purchase_id), 'On account') ref,
                             purchase_id AS ref_id,
                             0 debit, amount credit, 'Payment' kind, note
                            FROM supplier_payments WHERE " . implode(' AND ', $w) . " ORDER BY payment_date, id");
    $stmt->execute($p);
    foreach ($stmt->fetchAll() as $r) { $rows[] = $r; }

    $w = ['owner_id = ?', 'supplier_id = ?'];
    $p = [$ownerId, $supplierId];
    if ($from) { $w[] = 'return_date >= ?'; $p[] = purchaseDateInput($from); }
    if ($to)   { $w[] = 'return_date <= ?'; $p[] = purchaseDateInput($to); }
    $stmt = $db->prepare("SELECT return_date dt, invoice_no ref, purchase_id AS ref_id,
                             0 debit, (total - refund_amount) credit, 'Return credit' kind, note
                            FROM purchase_returns WHERE " . implode(' AND ', $w) . " ORDER BY return_date, id");
    $stmt->execute($p);
    foreach ($stmt->fetchAll() as $r) { $rows[] = $r; }

    // 7.4's usort is not stable, so same-day rows need an explicit order: a day's
    // bills come before the payments made that day, which come before returns.
    $order = ['Purchase' => 0, 'Payment' => 1, 'Return credit' => 2];
    usort($rows, function ($a, $b) use ($order) {
        $cmp = strcmp($a['dt'], $b['dt']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return ($order[$a['kind']] ?? 9) - ($order[$b['kind']] ?? 9);
    });

    $balance = purchaseMoney($opening);
    foreach ($rows as $i => $row) {
        $balance = purchaseMoney($balance + (float)$row['debit'] - (float)$row['credit']);
        $rows[$i]['balance'] = $balance;
    }

    return ['opening' => purchaseMoney($opening), 'rows' => $rows, 'closing' => $balance];
}

/** Total outstanding for one supplier, every bill. */
function supplierDueTotal($db, $ownerId, $supplierId)
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE owner_id = ? AND supplier_id = ?");
    $stmt->execute([$ownerId, $supplierId]);
    $due = (float)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments
                            WHERE owner_id = ? AND supplier_id = ? AND purchase_id IS NULL");
    $stmt->execute([$ownerId, $supplierId]);
    return purchaseMoney($due - (float)$stmt->fetchColumn());
}

/**
 * Module totals for a KPI strip / report header.
 */
function purchaseSummary($db, $ownerId, $from, $to, $storeId = null)
{
    $where = "owner_id = ? AND purchase_date BETWEEN ? AND ?";
    $params = [$ownerId, purchaseDateInput($from), purchaseDateInput($to)];
    if ($storeId) {
        $where .= " AND store_id = ?";
        $params[] = (int)$storeId;
    }
    $stmt = $db->prepare("SELECT COUNT(*) bills,
                                 COALESCE(SUM(grand_total), 0) total,
                                 COALESCE(SUM(paid_amount), 0) paid,
                                 COALESCE(SUM(credit_amount), 0) credited,
                                 COALESCE(SUM(due_amount), 0) due
                            FROM purchases WHERE {$where}");
    $stmt->execute($params);
    $out = $stmt->fetch();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments
                            WHERE owner_id = ? AND purchase_id IS NULL AND payment_date BETWEEN ? AND ?");
    $stmt->execute([$ownerId, purchaseDateInput($from), purchaseDateInput($to)]);
    $out['advances'] = purchaseMoney($stmt->fetchColumn());

    $stmt = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM purchase_returns
                            WHERE owner_id = ? AND return_date BETWEEN ? AND ?");
    $stmt->execute([$ownerId, purchaseDateInput($from), purchaseDateInput($to)]);
    $out['returns'] = purchaseMoney($stmt->fetchColumn());

    return $out;
}
