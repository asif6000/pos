<?php
/**
 * Cashbook Management Core Module
 * Handles accounts (cashbooks), transactions (cash in, cash out),
 * book-to-book transfers, voiding/reversals, POS reconciliation,
 * and auto-entry synchronization.
 */

require_once __DIR__ . '/db.php';

/**
 * Ensure cashbook tables and new columns exist.
 * Safe migration that runs smoothly without throwing errors.
 */
function ensureCashbookModuleTables()
{
    static $initialized = false;
    if ($initialized) return true;
    // Settled this day already - skip the CREATEs, the SHOWs and the backfill
    // scan and answer from the stamp. The static above only lives for this one
    // request; the stamp is what keeps the next sale from paying for them again.
    if (appSchemaFlagOk('cashbook-module-tables')) {
        $initialized = true;
        return true;
    }
    // Raised before any work happens, not after it. The legacy backfill at the
    // bottom of this function reaches for the shop's default book through
    // ensureDefaultCashbooksForOwner(), and resolveCashbookId(), both of which
    // call back into here. With the flag only set on the way out, a single old
    // row with no cashbook_id turned that into a call that never came back.
    $initialized = true;

    $db = getDB();

    // 1. Create cash_books table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS cash_books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            store_id INT NULL,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'cash',
            supplier_id INT NULL,
            account_number VARCHAR(100) NULL,
            bank_name VARCHAR(100) NULL,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            opening_date DATE NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_id),
            INDEX idx_store (store_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('create cash_books table: ' . $e->getMessage());
    }

    // 1b. The supplier whose payments leave this book. A shop that pays one
    // supplier from one account ("Rahim's bKash") should not have that money come
    // out of the till's default drawer just because nothing else said otherwise.
    try {
        if (empty($db->query("SHOW COLUMNS FROM cash_books LIKE 'supplier_id'")->fetchAll())) {
            appSafeDdl($db, "ALTER TABLE cash_books ADD COLUMN supplier_id INT NULL AFTER type, ADD INDEX idx_cb_supplier (supplier_id)");
        }
    } catch (PDOException $e) {
        error_log('alter cash_books supplier_id: ' . $e->getMessage());
    }

    // 2. Create cashbook_transfers table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS cashbook_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            store_id INT NULL,
            user_id INT NOT NULL,
            from_book_id INT NOT NULL,
            to_book_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            transfer_date DATE NOT NULL,
            reference_no VARCHAR(100) NULL,
            note VARCHAR(255) NULL,
            entry_in_id INT NULL,
            entry_out_id INT NULL,
            is_void TINYINT(1) NOT NULL DEFAULT 0,
            void_reason VARCHAR(255) NULL,
            voided_by INT NULL,
            voided_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_id),
            INDEX idx_store (store_id),
            INDEX idx_from_book (from_book_id),
            INDEX idx_to_book (to_book_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('create cashbook_transfers table: ' . $e->getMessage());
    }

    // 3. Add columns to cashbook_entries if missing
    try {
        $cols = $db->query("SHOW COLUMNS FROM cashbook_entries")->fetchAll(PDO::FETCH_COLUMN);
        $colSet = array_flip($cols);

        if (!isset($colSet['cashbook_id'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN cashbook_id INT NULL AFTER store_id, ADD INDEX idx_cashbook (cashbook_id)");
        }
        if (!isset($colSet['entry_date'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN entry_date DATE NULL AFTER amount");
        }
        if (!isset($colSet['reference_no'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN reference_no VARCHAR(100) NULL AFTER note");
        }
        if (!isset($colSet['attachment'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN attachment VARCHAR(255) NULL AFTER reference_no");
        }
        if (!isset($colSet['is_void'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0 AFTER attachment, ADD INDEX idx_is_void (is_void)");
        }
        if (!isset($colSet['void_reason'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN void_reason VARCHAR(255) NULL AFTER is_void");
        }
        if (!isset($colSet['voided_by'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN voided_by INT NULL AFTER void_reason");
        }
        if (!isset($colSet['voided_at'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN voided_at DATETIME NULL AFTER voided_by");
        }
        if (!isset($colSet['transfer_id'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN transfer_id INT NULL AFTER source_id, ADD INDEX idx_transfer (transfer_id)");
        }
        if (!isset($colSet['idempotency_key'])) {
            $db->exec("ALTER TABLE cashbook_entries ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER transfer_id, ADD INDEX idx_cb_idempotency (idempotency_key)");
        }

        // Backfill legacy null cashbook_id entries to default book
        $nullOwners = $db->query("SELECT DISTINCT owner_id, store_id FROM cashbook_entries WHERE cashbook_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($nullOwners as $no) {
            ensureDefaultCashbooksForOwner($db, (int)$no['owner_id'], $no['store_id'] ? (int)$no['store_id'] : null);
            $defId = resolveCashbookId($db, (int)$no['owner_id'], $no['store_id'] ? (int)$no['store_id'] : null, 'cash');
            if ($defId) {
                $db->prepare("UPDATE cashbook_entries SET cashbook_id = ?, entry_date = COALESCE(entry_date, DATE(created_at)) WHERE owner_id = ? AND cashbook_id IS NULL")
                   ->execute([$defId, (int)$no['owner_id']]);
            }
        }
    } catch (PDOException $e) {
        error_log('alter cashbook_entries: ' . $e->getMessage());
    }

    // Everything above verified or built what it needs, so the next request
    // starts from the stamp instead of paying for this again. A host without
    // the rights never reaches a good end here - and a check that failed is
    // deliberately left unstamped.
    appSchemaFlagSet('cashbook-module-tables');
    $initialized = true;
    return true;
}

/**
 * Provision standard cashbooks for a tenant if none exist.
 */
function ensureDefaultCashbooksForOwner($db, $ownerId, $storeId = null)
{
    ensureCashbookModuleTables();
    $ownerId = (int)$ownerId;
    if ($ownerId <= 0) return;

    $stmt = $db->prepare("SELECT COUNT(*) FROM cash_books WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $today = date('Y-m-d');
        $defaults = [
            ['name' => 'Main Cash', 'type' => 'cash', 'is_default' => 1],
            ['name' => 'Shop Cash', 'type' => 'cash', 'is_default' => 0],
            ['name' => 'Bank Account', 'type' => 'bank', 'is_default' => 0],
            ['name' => 'bKash', 'type' => 'bkash', 'is_default' => 0],
            ['name' => 'Nagad', 'type' => 'nagad', 'is_default' => 0],
        ];
        $insert = $db->prepare("INSERT INTO cash_books (owner_id, store_id, name, type, opening_balance, opening_date, is_default, status) VALUES (?, ?, ?, ?, 0.00, ?, ?, 'active')");
        foreach ($defaults as $d) {
            $insert->execute([$ownerId, $storeId ?: null, $d['name'], $d['type'], $today, $d['is_default']]);
        }
    }
}

/**
 * Get all cashbooks for an owner with calculated real-time running balance.
 */
function getCashbooksWithBalances($db, $ownerId, $storeId = null, $includeArchived = false)
{
    ensureCashbookModuleTables();
    ensureDefaultCashbooksForOwner($db, $ownerId, $storeId);

    $sql = "SELECT cb.*,
                COALESCE(SUM(CASE 
                    WHEN ce.is_void = 0 AND ce.type = 'cash_in' THEN ce.amount
                    WHEN ce.is_void = 0 AND ce.type = 'cash_out' THEN -ce.amount
                    ELSE 0 
                END), 0) AS total_movement,
                (cb.opening_balance + COALESCE(SUM(CASE 
                    WHEN ce.is_void = 0 AND ce.type = 'cash_in' THEN ce.amount
                    WHEN ce.is_void = 0 AND ce.type = 'cash_out' THEN -ce.amount
                    ELSE 0 
                END), 0)) AS current_balance
            FROM cash_books cb
            LEFT JOIN cashbook_entries ce ON ce.cashbook_id = cb.id
            WHERE cb.owner_id = ?";
    
    $params = [(int)$ownerId];

    if (!$includeArchived) {
        $sql .= " AND cb.status = 'active'";
    }

    $sql .= " GROUP BY cb.id ORDER BY cb.is_default DESC, cb.status ASC, cb.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['opening_balance'] = round((float)$r['opening_balance'], 2);
        $r['current_balance'] = round((float)$r['current_balance'], 2);
        $r['total_movement']  = round((float)$r['total_movement'], 2);
    }
    return $rows;
}

/**
 * Get a single cashbook by ID with balance.
 */
function getCashbookById($db, $bookId, $ownerId)
{
    ensureCashbookModuleTables();
    $sql = "SELECT cb.*,
                (cb.opening_balance + COALESCE(SUM(CASE 
                    WHEN ce.is_void = 0 AND ce.type = 'cash_in' THEN ce.amount
                    WHEN ce.is_void = 0 AND ce.type = 'cash_out' THEN -ce.amount
                    ELSE 0 
                END), 0)) AS current_balance
            FROM cash_books cb
            LEFT JOIN cashbook_entries ce ON ce.cashbook_id = cb.id
            WHERE cb.id = ? AND cb.owner_id = ?
            GROUP BY cb.id";
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$bookId, (int)$ownerId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($book) {
        $book['opening_balance'] = round((float)$book['opening_balance'], 2);
        $book['current_balance'] = round((float)$book['current_balance'], 2);
    }
    return $book;
}

/**
 * Resolve the appropriate cashbook ID for a payment method or store.
 */
function resolveCashbookId($db, $ownerId, $storeId = null, $method = null)
{
    ensureCashbookModuleTables();
    ensureDefaultCashbooksForOwner($db, $ownerId, $storeId);

    $ownerId = (int)$ownerId;
    $method = strtolower(trim((string)$method));

    // Try matching method directly by book type or name
    if (!empty($method) && in_array($method, ['bkash', 'nagad', 'rocket', 'bank', 'card'], true)) {
        $targetType = in_array($method, ['bank', 'card']) ? 'bank' : $method;
        $stmt = $db->prepare("SELECT id FROM cash_books WHERE owner_id = ? AND status = 'active' AND (type = ? OR LOWER(name) LIKE ?) ORDER BY is_default DESC, id ASC LIMIT 1");
        $stmt->execute([$ownerId, $targetType, '%' . $targetType . '%']);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    // Default book
    $stmt = $db->prepare("SELECT id FROM cash_books WHERE owner_id = ? AND status = 'active' AND is_default = 1 LIMIT 1");
    $stmt->execute([$ownerId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    // Fallback: first active book
    $stmt = $db->prepare("SELECT id FROM cash_books WHERE owner_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
    $stmt->execute([$ownerId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : 0;
}

/**
 * The book a supplier's payments leave from, or null when no book claims them.
 *
 * Null is the answer most shops get, and callers must treat it that way: the
 * payment then carries no book and is filed as it always was.
 */
function cashbookIdAssignedToSupplier($db, $ownerId, $supplierId)
{
    $supplierId = (int)$supplierId;
    if ($supplierId <= 0) {
        return null;
    }
    try {
        $stmt = $db->prepare("SELECT id FROM cash_books
                      WHERE owner_id = ? AND supplier_id = ? AND status = 'active'
                      ORDER BY is_default DESC, id ASC LIMIT 1");
        $stmt->execute([(int)$ownerId, $supplierId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (PDOException $e) {
        // An install whose cash_books has no supplier_id column and whose DB
        // user cannot ALTER simply has no assigned books; it must still pay.
        error_log('cashbookIdAssignedToSupplier: ' . $e->getMessage());
        return null;
    }
}

/**
 * The books a payment may leave from, keyed by id, each with its name and the
 * supplier assigned to it.
 *
 * Active only, and the shop's own: an archived book is closed, and another
 * shop's book is not this shop's money. Ordered the way the cashbook page
 * orders them so the same name is never in a different place in two lists.
 */
function cashbookPaymentBooks($db, $ownerId)
{
    $books = [];
    try {
        $stmt = $db->prepare("SELECT id, name, supplier_id FROM cash_books
                      WHERE owner_id = ? AND status = 'active'
                      ORDER BY is_default DESC, name ASC");
        $stmt->execute([(int)$ownerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $books[(int)$row['id']] = [
                'name' => $row['name'],
                'supplier_id' => (int)($row['supplier_id'] ?? 0),
            ];
        }
    } catch (PDOException $e) {
        error_log('cashbookPaymentBooks: ' . $e->getMessage());
    }
    return $books;
}

/** May this shop pay out of that book right now? */
function cashbookIsPayableBook($db, $ownerId, $bookId)
{
    $bookId = (int)$bookId;
    if ($bookId <= 0) {
        return false;
    }
    return array_key_exists($bookId, cashbookPaymentBooks($db, $ownerId));
}

/**
 * Create or update a cashbook.
 */
function saveCashbook($db, $ownerId, $storeId, $data, $id = null)
{
    ensureCashbookModuleTables();
    $ownerId = (int)$ownerId;
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Cashbook name is required.'];
    }

    $type = strtolower(trim((string)($data['type'] ?? 'cash')));
    if (!in_array($type, ['cash', 'bank', 'bkash', 'nagad', 'rocket', 'other'], true)) {
        $type = 'cash';
    }

    $openingBalance = round((float)($data['opening_balance'] ?? 0), 2);
    $openingDate    = !empty($data['opening_date']) ? date('Y-m-d', strtotime($data['opening_date'])) : date('Y-m-d');

    // A field the form stopped carrying is not a field to clear. The Create/Edit
    // book modal no longer shows the account number, the bank name or the default
    // tick, so an edit that sends none of them must leave the stored ones alone -
    // otherwise renaming a book would blank its account and, worse, un-default the
    // book every POS payment with no book of its own falls back to.
    $kept = null;
    if ($id && (int)$id > 0) {
        $stmt = $db->prepare("SELECT account_number, bank_name, is_default, supplier_id FROM cash_books WHERE id = ? AND owner_id = ?");
        $stmt->execute([(int)$id, $ownerId]);
        $kept = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $accountNumber = trim((string)($data['account_number'] ?? ($kept['account_number'] ?? '')));
    $bankName      = trim((string)($data['bank_name'] ?? ($kept['bank_name'] ?? '')));
    $isDefault     = !empty($data['is_default'] ?? ($kept['is_default'] ?? 0)) ? 1 : 0;

    // Which supplier this book pays. One supplier, one book: if two active books
    // claimed the same supplier, a payment to them would have two answers for
    // where the money left, and the drawer totals would disagree afterwards.
    $supplierId = (int)($data['supplier_id'] ?? ($kept['supplier_id'] ?? 0));
    if ($supplierId && appTableExists($db, 'suppliers')) {
        $stmt = $db->prepare("SELECT id FROM suppliers WHERE id = ? AND owner_id = ?");
        $stmt->execute([$supplierId, $ownerId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'Choose a supplier of this shop.'];
        }
        $stmt = $db->prepare("SELECT name FROM cash_books
                      WHERE owner_id = ? AND supplier_id = ? AND status = 'active' AND id <> ? LIMIT 1");
        $stmt->execute([$ownerId, $supplierId, (int)$id]);
        $other = $stmt->fetchColumn();
        if ($other) {
            return ['ok' => false, 'error' => 'That supplier is already paid from ' . $other . '.'];
        }
    } else {
        $supplierId = 0;
    }

    $db->beginTransaction();
    try {
        if ($isDefault) {
            // Unset other defaults for this owner
            $db->prepare("UPDATE cash_books SET is_default = 0 WHERE owner_id = ?")->execute([$ownerId]);
        }

        if ($id && (int)$id > 0) {
            $stmt = $db->prepare("UPDATE cash_books SET name = ?, type = ?, supplier_id = ?, account_number = ?, bank_name = ?, opening_balance = ?, opening_date = ?, is_default = ? WHERE id = ? AND owner_id = ?");
            $stmt->execute([$name, $type, $supplierId ?: null, $accountNumber, $bankName, $openingBalance, $openingDate, $isDefault, (int)$id, $ownerId]);
            $bookId = (int)$id;
        } else {
            $stmt = $db->prepare("INSERT INTO cash_books (owner_id, store_id, name, type, supplier_id, account_number, bank_name, opening_balance, opening_date, is_default, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$ownerId, $storeId ?: null, $name, $type, $supplierId ?: null, $accountNumber, $bankName, $openingBalance, $openingDate, $isDefault]);
            $bookId = (int)$db->lastInsertId();
        }
        $db->commit();
        return ['ok' => true, 'id' => $bookId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Archive or Restore a cashbook.
 */
function toggleCashbookStatus($db, $bookId, $ownerId)
{
    ensureCashbookModuleTables();
    $book = getCashbookById($db, $bookId, $ownerId);
    if (!$book) {
        return ['ok' => false, 'error' => 'Cashbook not found.'];
    }
    if ($book['is_default'] && $book['status'] === 'active') {
        return ['ok' => false, 'error' => 'Default cashbook cannot be archived. Set another book as default first.'];
    }

    $newStatus = $book['status'] === 'active' ? 'archived' : 'active';
    $stmt = $db->prepare("UPDATE cash_books SET status = ? WHERE id = ? AND owner_id = ?");
    $stmt->execute([$newStatus, (int)$bookId, (int)$ownerId]);
    return ['ok' => true, 'status' => $newStatus];
}

/**
 * Delete a cashbook and everything booked inside it.
 *
 * Archive is the door for a book whose history the shop wants to keep; this is
 * the door for one they do not. Its ledger lines go with it, because a line in a
 * book that no longer exists is readable by nobody - it would sit in the list
 * with no name against it and still move the totals.
 *
 * A transfer is the one thing that cannot be cut in half: it has a leg in the
 * other book as well, so the whole movement - both legs and the transfer record -
 * goes with it. Leaving the other side behind would show money that arrived from
 * nowhere.
 *
 * If the deleted book was the default, the next active book takes the title over,
 * because a POS payment with no book of its own has to land somewhere. Delete the
 * last book and the module provisions a fresh default set the next time anything
 * asks for one (resolveCashbookId -> ensureDefaultCashbooksForOwner).
 */
function deleteCashbook($db, $ownerId, $bookId)
{
    ensureCashbookModuleTables();
    $ownerId = (int)$ownerId;
    $bookId  = (int)$bookId;

    $book = getCashbookById($db, $bookId, $ownerId);
    if (!$book) {
        return ['ok' => false, 'error' => 'Cashbook not found.'];
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM cashbook_entries WHERE cashbook_id = ? AND owner_id = ?");
    $stmt->execute([$bookId, $ownerId]);
    $lines = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT id FROM cashbook_transfers WHERE owner_id = ? AND (from_book_id = ? OR to_book_id = ?)");
    $stmt->execute([$ownerId, $bookId, $bookId]);
    $transferIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $wasDefault = !empty($book['is_default']);

    $db->beginTransaction();
    try {
        if ($transferIds) {
            $in = implode(',', array_fill(0, count($transferIds), '?'));
            $db->prepare("DELETE FROM cashbook_entries WHERE owner_id = ? AND transfer_id IN ($in)")
               ->execute(array_merge([$ownerId], $transferIds));
            $db->prepare("DELETE FROM cashbook_transfers WHERE owner_id = ? AND id IN ($in)")
               ->execute(array_merge([$ownerId], $transferIds));
        }

        $db->prepare("DELETE FROM cashbook_entries WHERE cashbook_id = ? AND owner_id = ?")
           ->execute([$bookId, $ownerId]);
        $db->prepare("DELETE FROM cash_books WHERE id = ? AND owner_id = ?")
           ->execute([$bookId, $ownerId]);

        if ($wasDefault) {
            $next = $db->prepare("SELECT MIN(id) FROM cash_books WHERE owner_id = ? AND status = 'active'");
            $next->execute([$ownerId]);
            $nextId = (int)$next->fetchColumn();
            if ($nextId) {
                $db->prepare("UPDATE cash_books SET is_default = 1 WHERE id = ? AND owner_id = ?")
                   ->execute([$nextId, $ownerId]);
            }
        }

        $db->commit();
        return ['ok' => true, 'name' => $book['name'], 'lines' => $lines, 'transfers' => count($transferIds)];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('delete cashbook: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The cashbook could not be deleted. Please try again.'];
    }
}

/**
 * Post a manual Cash In or Cash Out entry with atomic transaction,
 * idempotency token, category, reference, and optional attachment.
 */
function recordCashbookEntry($db, $actor, $data, $file = null)
{
    ensureCashbookModuleTables();

    $ownerId = (int)$actor['owner_id'];
    $storeId = !empty($actor['store_id']) ? (int)$actor['store_id'] : (!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $userId  = (int)($actor['user_id'] ?? $actor['id'] ?? 0);

    $type = strtolower(trim((string)($data['type'] ?? '')));
    if (!in_array($type, ['cash_in', 'cash_out'], true)) {
        return ['ok' => false, 'error' => 'Invalid entry type. Must be Cash In or Cash Out.'];
    }

    $amount = round((float)($data['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Amount must be greater than zero.'];
    }

    $bookId = (int)($data['cashbook_id'] ?? 0);
    if ($bookId <= 0) {
        $bookId = resolveCashbookId($db, $ownerId, $storeId, $data['payment_method'] ?? null);
    }

    $book = getCashbookById($db, $bookId, $ownerId);
    if (!$book || $book['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Please select an active Cashbook.'];
    }

    $note = sanitize(trim((string)($data['note'] ?? '')));
    if ($note === '') {
        $note = $type === 'cash_in' ? 'Cash In Receipt' : 'Expense Payment';
    }

    $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;
    $method     = !empty($data['payment_method']) ? strtolower(trim((string)$data['payment_method'])) : ($book['type'] ?: 'cash');
    $reference  = !empty($data['reference_no']) ? sanitize(trim((string)$data['reference_no'])) : null;
    $entryDate  = !empty($data['entry_date']) ? date('Y-m-d', strtotime($data['entry_date'])) : date('Y-m-d');
    $idempotency = !empty($data['idempotency_key']) ? trim((string)$data['idempotency_key']) : null;

    // Duplicate check
    if ($idempotency) {
        $stmtCheck = $db->prepare("SELECT id FROM cashbook_entries WHERE owner_id = ? AND idempotency_key = ? LIMIT 1");
        $stmtCheck->execute([$ownerId, $idempotency]);
        $existingId = $stmtCheck->fetchColumn();
        if ($existingId) {
            return ['ok' => true, 'id' => (int)$existingId, 'duplicate' => true];
        }
    }

    // Handle attachment upload
    $attachmentPath = null;
    if ($file && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => 'Invalid attachment format. Only JPG, PNG, WEBP, and PDF files are allowed.'];
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Attachment size must not exceed 5MB.'];
        }

        $uploadDir = __DIR__ . '/../uploads/cashbook';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $fileName = 'cb_' . $ownerId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $fileName;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $attachmentPath = 'uploads/cashbook/' . $fileName;
        }
    }

    $createdTimestamp = $entryDate . ' ' . date('H:i:s');

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO cashbook_entries 
            (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, category_id, payment_method, reference_no, attachment, source_type, idempotency_key, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?)");
        $stmt->execute([
            $ownerId,
            $storeId ?: null,
            $bookId,
            $userId,
            $type,
            $amount,
            $entryDate,
            $note,
            $categoryId,
            $method,
            $reference,
            $attachmentPath,
            $idempotency,
            $createdTimestamp
        ]);
        $entryId = (int)$db->lastInsertId();
        $db->commit();
        return ['ok' => true, 'id' => $entryId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Failed to record entry: ' . $e->getMessage()];
    }
}

/**
 * Execute atomic Book-to-Book Transfer.
 * Creates transfer record and two matching entries (Out from Book A, In to Book B).
 * Neither entry counts as business income or business expense!
 */
function transferCashbookFunds($db, $actor, $data)
{
    ensureCashbookModuleTables();

    $ownerId = (int)$actor['owner_id'];
    $storeId = !empty($actor['store_id']) ? (int)$actor['store_id'] : (!empty($data['store_id']) ? (int)$data['store_id'] : null);
    $userId  = (int)($actor['user_id'] ?? $actor['id'] ?? 0);

    $fromBookId = (int)($data['from_book_id'] ?? 0);
    $toBookId   = (int)($data['to_book_id'] ?? 0);

    if ($fromBookId <= 0 || $toBookId <= 0) {
        return ['ok' => false, 'error' => 'Please select both source and destination cashbooks.'];
    }
    if ($fromBookId === $toBookId) {
        return ['ok' => false, 'error' => 'Source and destination cashbooks cannot be the same.'];
    }

    $amount = round((float)($data['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Transfer amount must be greater than zero.'];
    }

    $fromBook = getCashbookById($db, $fromBookId, $ownerId);
    $toBook   = getCashbookById($db, $toBookId, $ownerId);

    if (!$fromBook || $fromBook['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Selected source cashbook is invalid or inactive.'];
    }
    if (!$toBook || $toBook['status'] !== 'active') {
        return ['ok' => false, 'error' => 'Selected destination cashbook is invalid or inactive.'];
    }

    $transferDate = !empty($data['transfer_date']) ? date('Y-m-d', strtotime($data['transfer_date'])) : date('Y-m-d');
    $reference    = !empty($data['reference_no']) ? sanitize(trim((string)$data['reference_no'])) : null;
    $userNote     = sanitize(trim((string)($data['note'] ?? '')));
    $createdTime  = $transferDate . ' ' . date('H:i:s');

    $db->beginTransaction();
    try {
        // 1. Insert into cashbook_transfers
        $stmtT = $db->prepare("INSERT INTO cashbook_transfers 
            (owner_id, store_id, user_id, from_book_id, to_book_id, amount, transfer_date, reference_no, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtT->execute([
            $ownerId, $storeId ?: null, $userId, $fromBookId, $toBookId, $amount, $transferDate, $reference, $userNote, $createdTime
        ]);
        $transferId = (int)$db->lastInsertId();

        $noteOut = 'Transfer to ' . $toBook['name'] . ($userNote ? ' (' . $userNote . ')' : '');
        $noteIn  = 'Transfer from ' . $fromBook['name'] . ($userNote ? ' (' . $userNote . ')' : '');

        // 2. Insert Out entry for From Book
        $stmtOut = $db->prepare("INSERT INTO cashbook_entries
            (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, payment_method, reference_no, source_type, source_id, transfer_id, created_at)
            VALUES (?, ?, ?, ?, 'cash_out', ?, ?, ?, ?, ?, 'transfer', ?, ?, ?)");
        $stmtOut->execute([
            $ownerId, $storeId ?: null, $fromBookId, $userId, $amount, $transferDate, $noteOut, $fromBook['type'], $reference, $transferId, $transferId, $createdTime
        ]);
        $entryOutId = (int)$db->lastInsertId();

        // 3. Insert In entry for To Book
        $stmtIn = $db->prepare("INSERT INTO cashbook_entries
            (owner_id, store_id, cashbook_id, user_id, type, amount, entry_date, note, payment_method, reference_no, source_type, source_id, transfer_id, created_at)
            VALUES (?, ?, ?, ?, 'cash_in', ?, ?, ?, ?, ?, 'transfer', ?, ?, ?)");
        $stmtIn->execute([
            $ownerId, $storeId ?: null, $toBookId, $userId, $amount, $transferDate, $noteIn, $toBook['type'], $reference, $transferId, $transferId, $createdTime
        ]);
        $entryInId = (int)$db->lastInsertId();

        // 4. Update transfer record with entry IDs
        $db->prepare("UPDATE cashbook_transfers SET entry_out_id = ?, entry_in_id = ? WHERE id = ?")
           ->execute([$entryOutId, $entryInId, $transferId]);

        $db->commit();
        return ['ok' => true, 'transfer_id' => $transferId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Transfer failed: ' . $e->getMessage()];
    }
}

/**
 * Void / Reverse a transaction cleanly without hard deletion.
 * If the transaction is part of a book transfer, both sides of the transfer are voided together.
 */
function voidCashbookEntry($db, $actor, $entryId, $reason)
{
    ensureCashbookModuleTables();

    $ownerId = (int)$actor['owner_id'];
    $userId  = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    $reason  = sanitize(trim((string)$reason));

    if (empty($reason)) {
        return ['ok' => false, 'error' => 'A reason is required to void this transaction.'];
    }

    $stmt = $db->prepare("SELECT * FROM cashbook_entries WHERE id = ? AND owner_id = ?");
    $stmt->execute([(int)$entryId, $ownerId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }
    if ((int)$entry['is_void'] === 1) {
        return ['ok' => false, 'error' => 'This transaction is already voided.'];
    }

    $db->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');

        // Check if entry belongs to a transfer
        if ($entry['source_type'] === 'transfer' && !empty($entry['transfer_id'])) {
            $transferId = (int)$entry['transfer_id'];
            // Void both linked entries
            $db->prepare("UPDATE cashbook_entries SET is_void = 1, void_reason = ?, voided_by = ?, voided_at = ? WHERE transfer_id = ? AND owner_id = ?")
               ->execute([$reason, $userId, $now, $transferId, $ownerId]);
            // Void transfer record
            $db->prepare("UPDATE cashbook_transfers SET is_void = 1, void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ? AND owner_id = ?")
               ->execute([$reason, $userId, $now, $transferId, $ownerId]);
        } else {
            // Void single entry
            $db->prepare("UPDATE cashbook_entries SET is_void = 1, void_reason = ?, voided_by = ?, voided_at = ? WHERE id = ? AND owner_id = ?")
               ->execute([$reason, $userId, $now, (int)$entryId, $ownerId]);
        }

        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Failed to void transaction: ' . $e->getMessage()];
    }
}

/**
 * Hard-delete a ledger line.
 *
 * Void is the audited door and this is the other one: the line goes, the book's
 * balance moves with it, and nothing is left to read afterwards. A line written
 * by a sale or a purchase carries only the money side of that bill, so deleting
 * it does not edit the bill - it stops this ledger showing the payment, which is
 * what clearing a wrong figure after a stock-take actually needs.
 *
 * A transfer is the one shape that cannot be deleted in half. Both books hold a
 * line for the same movement, so removing one would leave money that went out
 * and never arrived anywhere; both sides and the transfer record go together.
 */
function deleteCashbookEntry($db, $actor, $entryId)
{
    ensureCashbookModuleTables();

    $ownerId = (int)$actor['owner_id'];
    $storeId = !empty($actor['store_id']) ? (int)$actor['store_id'] : null;

    $stmt = $db->prepare("SELECT id, store_id, source_type, transfer_id FROM cashbook_entries WHERE id = ? AND owner_id = ?");
    $stmt->execute([(int)$entryId, $ownerId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entry) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }
    // A store user may clear their own lines and the shop-wide ones, never
    // another branch's - the same boundary the ledger list is drawn with.
    if ($storeId && $entry['store_id'] !== null && (int)$entry['store_id'] !== $storeId) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }

    $db->beginTransaction();
    try {
        if ($entry['source_type'] === 'transfer' && !empty($entry['transfer_id'])) {
            $transferId = (int)$entry['transfer_id'];
            $db->prepare("DELETE FROM cashbook_entries WHERE transfer_id = ? AND owner_id = ?")
               ->execute([$transferId, $ownerId]);
            $db->prepare("DELETE FROM cashbook_transfers WHERE id = ? AND owner_id = ?")
               ->execute([$transferId, $ownerId]);
        } else {
            $db->prepare("DELETE FROM cashbook_entries WHERE id = ? AND owner_id = ?")
               ->execute([(int)$entryId, $ownerId]);
        }
        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('delete cashbook entry: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The transaction could not be deleted. Please try again.'];
    }
}

/**
 * Get Cashbook summary statistics (Today, Total, Balance, Net Cash Flow).
 * Transferred funds are excluded from Income/Expense/Net Cash Flow to avoid inflation!
 */
function getCashbookSummaryStats($db, $ownerId, $storeId = null, $bookId = null, $dateFrom = null, $dateTo = null)
{
    ensureCashbookModuleTables();
    $ownerId = (int)$ownerId;
    $today = date('Y-m-d');

    // 1. Total Balance across all active cashbooks
    //
    // Read as two sums rather than one joined row. Joining a book to its own
    // movements to get both figures at once repeats the opening balance once per
    // movement - a drawer opened at 1,000 with five lines behind it reports 5,000
    // opened and every card on the page is out by the difference.
    $oSql = "SELECT COALESCE(SUM(opening_balance), 0) FROM cash_books
             WHERE owner_id = ? AND status = 'active'";
    $oParams = [$ownerId];
    $mWhere = " AND cb.status = 'active'";
    if ($storeId) {
        $oSql .= " AND (store_id = ? OR store_id IS NULL)";
        $oParams[] = (int)$storeId;
        $mWhere .= " AND (cb.store_id = ? OR cb.store_id IS NULL)";
    }
    if ($bookId) {
        $oSql .= " AND id = ?";
        $oParams[] = (int)$bookId;
        $mWhere .= " AND cb.id = ?";
    }
    $stmt = $db->prepare($oSql);
    $stmt->execute($oParams);
    $totalOpening = (float)$stmt->fetchColumn();

    $mSql = "SELECT COALESCE(SUM(CASE WHEN ce.type = 'cash_in' THEN ce.amount
                                      WHEN ce.type = 'cash_out' THEN -ce.amount
                                      ELSE 0 END), 0)
             FROM cashbook_entries ce
             JOIN cash_books cb ON cb.id = ce.cashbook_id
             WHERE ce.is_void = 0 AND cb.owner_id = ?" . $mWhere;
    $mParams = [$ownerId];
    if ($storeId) {
        $mParams[] = (int)$storeId;
    }
    if ($bookId) {
        $mParams[] = (int)$bookId;
    }
    $stmt = $db->prepare($mSql);
    $stmt->execute($mParams);
    $totalBalance = round($totalOpening + (float)$stmt->fetchColumn(), 2);

    // 2. Today's Cash In and Cash Out (excluding transfers and voids)
    $todaySql = "SELECT 
        COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE 0 END), 0) as today_in,
        COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount ELSE 0 END), 0) as today_out
        FROM cashbook_entries
        WHERE owner_id = ? AND is_void = 0 AND (source_type != 'transfer' OR source_type IS NULL)
        AND DATE(COALESCE(entry_date, created_at)) = ?";
    $todayParams = [$ownerId, $today];
    if ($storeId) {
        $todaySql .= " AND (store_id = ? OR store_id IS NULL)";
        $todayParams[] = (int)$storeId;
    }
    if ($bookId) {
        $todaySql .= " AND cashbook_id = ?";
        $todayParams[] = (int)$bookId;
    }
    $tStmt = $db->prepare($todaySql);
    $tStmt->execute($todayParams);
    $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
    $todayIn  = round((float)($tRow['today_in'] ?? 0), 2);
    $todayOut = round((float)($tRow['today_out'] ?? 0), 2);

    // 3. Filtered Range Cash In, Cash Out, Net Cash Flow
    $flowSql = "SELECT 
        COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE 0 END), 0) as range_in,
        COALESCE(SUM(CASE WHEN type = 'cash_out' THEN amount ELSE 0 END), 0) as range_out
        FROM cashbook_entries
        WHERE owner_id = ? AND is_void = 0 AND (source_type != 'transfer' OR source_type IS NULL)";
    $flowParams = [$ownerId];
    if ($storeId) {
        $flowSql .= " AND (store_id = ? OR store_id IS NULL)";
        $flowParams[] = (int)$storeId;
    }
    if ($bookId) {
        $flowSql .= " AND cashbook_id = ?";
        $flowParams[] = (int)$bookId;
    }
    if ($dateFrom) {
        $flowSql .= " AND DATE(COALESCE(entry_date, created_at)) >= ?";
        $flowParams[] = $dateFrom;
    }
    if ($dateTo) {
        $flowSql .= " AND DATE(COALESCE(entry_date, created_at)) <= ?";
        $flowParams[] = $dateTo;
    }
    $fStmt = $db->prepare($flowSql);
    $fStmt->execute($flowParams);
    $fRow = $fStmt->fetch(PDO::FETCH_ASSOC);
    $rangeIn  = round((float)($fRow['range_in'] ?? 0), 2);
    $rangeOut = round((float)($fRow['range_out'] ?? 0), 2);
    $netCashFlow = round($rangeIn - $rangeOut, 2);

    return [
        'total_balance' => $totalBalance,
        'today_in'      => $todayIn,
        'today_out'     => $todayOut,
        'range_in'      => $rangeIn,
        'range_out'     => $rangeOut,
        'net_cash_flow' => $netCashFlow,
    ];
}

/**
 * Get POS Payment vs Cashbook Reconciliation data.
 * Identifies differences between POS Sales / Returns and Cashbook records.
 */
function getCashbookReconciliation($db, $ownerId, $storeId = null, $dateFrom = null, $dateTo = null)
{
    ensureCashbookModuleTables();
    $ownerId = (int)$ownerId;

    // 1. POS Sales grouped by payment method
    $sSql = "SELECT payment_method, COUNT(*) as tx_count, COALESCE(SUM(paid_amount), 0) as total_amount
             FROM sales WHERE owner_id = ?";
    $sParams = [$ownerId];
    if ($storeId) {
        $sSql .= " AND (store_id = ? OR store_id IS NULL)";
        $sParams[] = (int)$storeId;
    }
    if ($dateFrom) {
        $sSql .= " AND DATE(created_at) >= ?";
        $sParams[] = $dateFrom;
    }
    if ($dateTo) {
        $sSql .= " AND DATE(created_at) <= ?";
        $sParams[] = $dateTo;
    }
    $sSql .= " GROUP BY payment_method";
    $sStmt = $db->prepare($sSql);
    $sStmt->execute($sParams);
    $salesByMethod = [];
    while ($r = $sStmt->fetch(PDO::FETCH_ASSOC)) {
        $m = strtolower(trim($r['payment_method'] ?: 'cash'));
        $salesByMethod[$m] = [
            'count'  => (int)$r['tx_count'],
            'amount' => round((float)$r['total_amount'], 2)
        ];
    }

    // 2. POS Returns grouped by payment method
    $rSql = "SELECT COALESCE(payment_method, 'cash') as payment_method, COUNT(*) as tx_count, COALESCE(SUM(refund_amount), 0) as total_refund
             FROM returns WHERE owner_id = ?";
    $rParams = [$ownerId];
    if ($storeId) {
        $rSql .= " AND (store_id = ? OR store_id IS NULL)";
        $rParams[] = (int)$storeId;
    }
    if ($dateFrom) {
        $rSql .= " AND DATE(created_at) >= ?";
        $rParams[] = $dateFrom;
    }
    if ($dateTo) {
        $rSql .= " AND DATE(created_at) <= ?";
        $rParams[] = $dateTo;
    }
    $rSql .= " GROUP BY payment_method";
    $rStmt = $db->prepare($rSql);
    $rStmt->execute($rParams);
    $returnsByMethod = [];
    while ($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        $m = strtolower(trim($r['payment_method'] ?: 'cash'));
        $returnsByMethod[$m] = [
            'count'  => (int)$r['tx_count'],
            'amount' => round((float)$r['total_refund'], 2)
        ];
    }

    // 3. Cashbook Recorded Movements by payment method (source_type 'sale' and 'return')
    $cbSql = "SELECT COALESCE(payment_method, 'cash') as payment_method, source_type, type,
                     COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total_amount
              FROM cashbook_entries 
              WHERE owner_id = ? AND is_void = 0 AND source_type IN ('sale', 'return')";
    $cbParams = [$ownerId];
    if ($storeId) {
        $cbSql .= " AND (store_id = ? OR store_id IS NULL)";
        $cbParams[] = (int)$storeId;
    }
    if ($dateFrom) {
        $cbSql .= " AND DATE(COALESCE(entry_date, created_at)) >= ?";
        $cbParams[] = $dateFrom;
    }
    if ($dateTo) {
        $cbSql .= " AND DATE(COALESCE(entry_date, created_at)) <= ?";
        $cbParams[] = $dateTo;
    }
    $cbSql .= " GROUP BY payment_method, source_type, type";
    $cbStmt = $db->prepare($cbSql);
    $cbStmt->execute($cbParams);

    $cbSales = [];
    $cbRefunds = [];
    while ($r = $cbStmt->fetch(PDO::FETCH_ASSOC)) {
        $m = strtolower(trim($r['payment_method'] ?: 'cash'));
        if ($r['source_type'] === 'sale') {
            $cbSales[$m] = ($cbSales[$m] ?? 0.0) + round((float)$r['total_amount'], 2);
        } elseif ($r['source_type'] === 'return') {
            $cbRefunds[$m] = ($cbRefunds[$m] ?? 0.0) + round((float)$r['total_amount'], 2);
        }
    }

    $allMethods = array_unique(array_merge(
        array_keys($salesByMethod),
        array_keys($returnsByMethod),
        array_keys($cbSales),
        array_keys($cbRefunds),
        ['cash', 'bkash', 'nagad', 'bank']
    ));
    sort($allMethods);

    $report = [];
    $totalPosSales = 0.0;
    $totalPosReturns = 0.0;
    $totalCbSales = 0.0;
    $totalCbReturns = 0.0;

    foreach ($allMethods as $m) {
        $posSale   = $salesByMethod[$m]['amount'] ?? 0.0;
        $posReturn = $returnsByMethod[$m]['amount'] ?? 0.0;
        $cbSale    = $cbSales[$m] ?? 0.0;
        $cbReturn  = $cbRefunds[$m] ?? 0.0;

        $posNet = round($posSale - $posReturn, 2);
        $cbNet  = round($cbSale - $cbReturn, 2);
        $variance = round($posNet - $cbNet, 2);

        $totalPosSales   += $posSale;
        $totalPosReturns += $posReturn;
        $totalCbSales    += $cbSale;
        $totalCbReturns  += $cbReturn;

        $report[] = [
            'method'     => ucfirst($m),
            'pos_sale'   => $posSale,
            'pos_return' => $posReturn,
            'pos_net'    => $posNet,
            'cb_sale'    => $cbSale,
            'cb_return'  => $cbReturn,
            'cb_net'     => $cbNet,
            'variance'   => $variance,
            'status'     => abs($variance) < 0.01 ? 'balanced' : 'discrepancy'
        ];
    }

    return [
        'rows' => $report,
        'total_pos_net'  => round($totalPosSales - $totalPosReturns, 2),
        'total_cb_net'   => round($totalCbSales - $totalCbReturns, 2),
        'total_variance' => round(($totalPosSales - $totalPosReturns) - ($totalCbSales - $totalCbReturns), 2),
    ];
}
