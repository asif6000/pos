<?php
/**
 * POS System - Cashbook
 * Record Expenses with Name & Category
 */

require_once '../config/db.php';
require_once '../config/csrf.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('cashbook')) {
    setFlash('danger', 'You do not have permission to access the cashbook.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Expense');

$db = getDB();
$user = getCurrentUser();
$owner_id = $user['owner_id'] ?? $user['id'];
$store_id = $user['store_id'] ?? null;

// Create tables if they don't exist yet (safe migration)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cashbook_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        store_id INT NULL,
        user_id INT NOT NULL,
        type ENUM('cash_in','cash_out') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        note VARCHAR(255) NULL,
        category_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_owner (owner_id),
        INDEX idx_store (store_id),
        INDEX idx_created (created_at),
        INDEX idx_category (category_id)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    // Table creation is best-effort; real errors surface below
}

// Add category_id column if missing (safe migration)
try {
    $cols = $db->query("SHOW COLUMNS FROM cashbook_entries LIKE 'category_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE cashbook_entries ADD COLUMN category_id INT NULL AFTER note");
    }
} catch (PDOException $e) {
    // Column may already exist
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_owner_name (owner_id, name),
        INDEX idx_owner (owner_id)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    // Table creation is best-effort; real errors surface below
}

// A refund is paid out of one shop's drawer, so a manual entry has to say which
// drawer it belongs to. A store user is standing in theirs already; a platform
// account stands in none, so it names the shop here. Left with no store, a deposit
// would sit in a balance that every shop counts and each of them could pay the same
// note out twice.
$stores = [];
if (!$store_id) {
    if (isSuperAdminUser($user)) {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
        $stmt->execute([$owner_id]);
    }
    $stores = $stmt->fetchAll();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('expense.php', ['expense.php']);

    $action = $_POST['action'] ?? '';

    // The drawer this entry is booked against. A posted store id is only ever
    // accepted as one of the shops listed above, so the picker cannot be pointed
    // at another tenant's store.
    $entry_store_id = $store_id;
    if (!$entry_store_id) {
        $pick = (int)($_POST['store_id'] ?? 0);
        foreach ($stores as $s) {
            if ($pick && (int)$s['id'] === $pick) {
                $entry_store_id = $pick;
                break;
            }
        }
    }

    // What this drawer moves in is cash, so the entry records its method like every
    // other money line. On an install whose cashbook migration has not run the
    // column is not there yet, and the entry is still recorded without it.
    $methodCol = ensureCashbookDetailColumns() ? ", payment_method" : "";
    $methodVal = $methodCol ? ", 'cash')" : ")";

    if ($action === 'add') {
        $amount = (float)($_POST['amount'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');
        $category_id = (int)($_POST['category_id'] ?? 0);

        if (empty($note)) {
            setFlash('danger', 'Expense name is required.');
        } elseif ($amount <= 0) {
            setFlash('danger', 'Amount must be greater than zero.');
        } elseif ($category_id <= 0) {
            setFlash('danger', 'Please select an expense category.');
        } elseif (!$entry_store_id && $stores) {
            setFlash('danger', 'Please choose which shop this expense is paid from.');
        } else {
            // Verify category belongs to this owner
            $stmt = $db->prepare("SELECT id FROM expense_categories WHERE id = ? AND owner_id = ?");
            $stmt->execute([$category_id, $owner_id]);
            if (!$stmt->fetchColumn()) {
                setFlash('danger', 'Invalid expense category selected.');
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note, category_id" . $methodCol . ") VALUES (?, ?, ?, 'cash_out', ?, ?, ?" . $methodVal . ")");
                    $stmt->execute([$owner_id, $entry_store_id, $user['id'], round($amount, 2), $note, $category_id]);
                    setFlash('success', 'Expense of ' . formatCurrency($amount) . ' recorded successfully!');
                } catch (PDOException $e) {
                    setFlash('danger', 'Error recording entry. Please try again.');
                }
            }
        }
    } elseif ($action === 'add_deposit') {
        // Money put into the drawer by hand. A cash refund the till cannot cover is
        // refused, and this is how the shop makes it able to pay one.
        $amount = (float)($_POST['amount'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');
        if ($note === '') {
            $note = 'Cash deposit';
        }

        if ($amount <= 0) {
            setFlash('danger', 'Amount must be greater than zero.');
        } elseif (!$entry_store_id && $stores) {
            setFlash('danger', 'Please choose which shop is receiving this cash.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note" . $methodCol . ") VALUES (?, ?, ?, 'cash_in', ?, ?" . $methodVal . ")");
                $stmt->execute([$owner_id, $entry_store_id, $user['id'], round($amount, 2), $note]);
                setFlash('success', formatCurrency($amount) . ' deposited into the drawer.');
            } catch (PDOException $e) {
                setFlash('danger', 'Error recording the deposit. Please try again.');
            }
        }
    } elseif ($action === 'add_category') {
        $name = sanitize($_POST['category_name'] ?? '');
        if (empty($name)) {
            setFlash('danger', 'Category name is required.');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO expense_categories (owner_id, name) VALUES (?, ?)");
                $stmt->execute([$owner_id, $name]);
                setFlash('success', 'Expense category "' . $name . '" created successfully!');
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    setFlash('danger', 'This category name already exists.');
                } else {
                    setFlash('danger', 'Error creating category. Please try again.');
                }
            }
        }
    } elseif ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM expense_categories WHERE id = ? AND owner_id = ?");
            $stmt->execute([$id, $owner_id]);
            $stmt = $db->prepare("UPDATE cashbook_entries SET category_id = NULL WHERE category_id = ? AND owner_id = ?");
            $stmt->execute([$id, $owner_id]);
            setFlash('success', 'Expense category deleted successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting category.');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("DELETE FROM cashbook_entries WHERE id = ? AND owner_id = ?");
            $stmt->execute([$id, $owner_id]);
            setFlash('success', 'Entry deleted successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting entry.');
        }
    } elseif ($action === 'delete_bulk') {
        // The list is already owner + store scoped, but the delete re-applies
        // that scope so a hand-made request can only ever reach this shop's
        // own rows - and only this store's, for a store user.
        $ids = array_values(array_unique(array_filter(array_map('intval',
            explode(',', (string)($_POST['ids'] ?? ''))))));
        if (empty($ids)) {
            setFlash('danger', 'No entries were selected.');
        } else {
            $delSql = "DELETE FROM cashbook_entries WHERE owner_id = ?";
            $delParams = [$owner_id];
            if ($store_id) {
                $delSql .= " AND store_id = ?";
                $delParams[] = $store_id;
            }
            // The id list goes last, so $delParams stays in the order the
            // placeholders appear in the statement.
            $delSql .= " AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            $delParams = array_merge($delParams, $ids);
            try {
                $stmt = $db->prepare($delSql);
                $stmt->execute($delParams);
                setFlash('success', $stmt->rowCount() . ' entries deleted successfully!');
            } catch (PDOException $e) {
                setFlash('danger', 'Error deleting the selected entries.');
            }
        }
    }
    redirect('expense.php');
}

// Load expense categories (owner scope)
$stmt = $db->prepare("SELECT ec.*, (SELECT COUNT(*) FROM cashbook_entries ce WHERE ce.category_id = ec.id AND ce.owner_id = ec.owner_id) as usage_count
                      FROM expense_categories ec WHERE ec.owner_id = ? ORDER BY ec.name ASC");
$stmt->execute([$owner_id]);
$categories = $stmt->fetchAll();

// Totals (owner scope, all time)
$sumSql = "SELECT COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE 0 END), 0) as cash_in,
                  COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out,
                  COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE -amount END), 0) as balance
           FROM cashbook_entries ce WHERE ce.owner_id = ?";
$sumParams = [$owner_id];
if ($store_id) {
    $sumSql .= " AND ce.store_id = ?";
    $sumParams[] = $store_id;
}
$stmt = $db->prepare($sumSql);
$stmt->execute($sumParams);
$totals = $stmt->fetch();

// Today totals
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$todaySql = "SELECT COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE 0 END), 0) as cash_in,
                    COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out
             FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.created_at BETWEEN ? AND ?";
$todayParams = [$owner_id, $todayStart, $todayEnd];
if ($store_id) {
    $todaySql .= " AND ce.store_id = ?";
    $todayParams[] = $store_id;
}
$stmt = $db->prepare($todaySql);
$stmt->execute($todayParams);
$todayTotals = $stmt->fetch();

// Sales totals (owner scope, matched to cashbook scope)
$salesSql = "SELECT COALESCE(SUM(s.total), 0) as total
             FROM sales s JOIN users u ON s.user_id = u.id WHERE " . saleOwnerScopeSql($owner_id);
$salesParams = [$owner_id, $owner_id];
if ($store_id) {
    $salesSql .= " AND " . saleStoreExpr() . " = ?";
    $salesParams[] = $store_id;
}
$stmt = $db->prepare($salesSql);
$stmt->execute($salesParams);
$salesAllTime = (float)$stmt->fetchColumn();

$stmt = $db->prepare($salesSql . " AND s.created_at BETWEEN ? AND ?");
$stmt->execute(array_merge($salesParams, [$todayStart, $todayEnd]));
$salesToday = (float)$stmt->fetchColumn();

// The day's net, so the Balance card uses the same window as the two cards
// beside it instead of mixing today's sales with all-time expenses.
//
// The all-time figure is the cashbook's own, not "Sales - Expenses" any more. A
// refund is taken off the sale it came from AND stands here as a cash_out row, so
// that formula subtracted the same money twice. Every movement that touches cash is
// already a line in the ledger - a sale's collection, an exchange extra, an expense,
// a refund - so cash in minus cash out tells the whole story with nothing doubled.
//
// It is read through the same helper the refund guard uses, so the number on this
// card is the number a cash refund is checked against.
$cash = cashLedgerState($owner_id, $store_id);
$cashInHand = $cash['balance'];
$netToday = (float)$todayTotals['cash_in'] - (float)$todayTotals['cash_out'];

// The names the refund methods are shown under, the same words the returns page
// offers the cashier.
$methodLabels = [
    'cash'         => 'Cash',
    'bkash'        => 'bKash',
    'nagad'        => 'Nagad',
    'rocket'       => 'Rocket',
    'card'         => 'Card',
    'bank'         => 'Bank',
    'store_credit' => 'Store Credit',
];

// Category totals (expenses by category, owner scope)
$catTotalsSql = "SELECT COALESCE(SUM(ce.amount), 0) as total, COUNT(ce.id) as cnt
                 FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.type = 'cash_out' AND ce.category_id IS NOT NULL";
$catTotalsParams = [$owner_id];
if ($store_id) {
    $catTotalsSql .= " AND ce.store_id = ?";
    $catTotalsParams[] = $store_id;
}
$stmt = $db->prepare($catTotalsSql);
$stmt->execute($catTotalsParams);
$catTotals = $stmt->fetch();

// Entries list (owner scope)
$sql = "SELECT ce.*, u.name as user_name, ec.name as category_name
        FROM cashbook_entries ce
        LEFT JOIN users u ON ce.user_id = u.id
        LEFT JOIN expense_categories ec ON ce.category_id = ec.id
        WHERE ce.owner_id = ?";
$params = [$owner_id];
if ($store_id) {
    $sql .= " AND ce.store_id = ?";
    $params[] = $store_id;
}
$sql .= " ORDER BY ce.created_at DESC, ce.id DESC LIMIT 300";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

/**
 * The shop a hand-written entry is booked against, for an account that stands in
 * none of them - a platform admin, whose own store_id is empty. Prints nothing for
 * a store user, whose entries already know which drawer they belong to.
 */
function cashbookStoreField($stores)
{
    if (empty($stores)) {
        return '';
    }
    ob_start();
?>
    <div class="form-group">
        <label class="form-label required">Shop</label>
        <select name="store_id" class="form-control" required>
            <option value="">Select shop...</option>
            <?php foreach ($stores as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>"<?php echo count($stores) === 1 ? ' selected' : ''; ?>>
                    <?php echo sanitize($s['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <small class="form-text">The drawer this money moves in or out of.</small>
    </div>
<?php
    return ob_get_clean();
}

include 'includes/header.php';
?>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="margin-bottom: 0.25rem;">Expense</h2>
        <p class="text-muted">Record expenses and cash deposits, and see what the drawer holds</p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <button class="btn btn-outline" onclick="openCategoryModal()">
            <i class="fas fa-tags"></i> Expense Categories
        </button>
        <button class="btn btn-success" onclick="openDepositModal()">
            <i class="fas fa-arrow-down"></i> Add Deposit
        </button>
        <button class="btn btn-danger" onclick="openEntryModal()">
            <i class="fas fa-arrow-up"></i> Add Expense
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-shopping-bag"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Sales (Today)</div>
            <div class="stat-value">
                <?php echo formatCurrency($salesToday); ?>
            </div>
            <div class="stat-change">
                <?php echo formatCurrency($salesAllTime); ?> total
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Expenses (Today)</div>
            <div class="stat-value">
                <?php echo formatCurrency($todayTotals['cash_out']); ?>
            </div>
            <div class="stat-change negative">
                <?php echo formatCurrency($totals['cash_out']); ?> total
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon <?php echo $cashInHand >= 0 ? 'green' : 'red'; ?>">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Cash Balance (In - Out)</div>
            <div class="stat-value">
                <?php echo formatCurrency($cashInHand); ?>
            </div>
            <div class="stat-change <?php echo $netToday >= 0 ? 'positive' : 'negative'; ?>">
                <?php if ($cash['cashIn'] <= 0.009): ?>
                    Nothing has been recorded in this drawer yet
                <?php else: ?>
                    Today: <?php echo ($netToday < 0 ? '-' : '+') . formatCurrency(abs($netToday)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Entries Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Transactions
        </h3>
        <!-- The select-all lives here rather than in the table head because a
             phone hides <thead>, and "select all then delete" has to work there
             too. -->
        <div style="display: flex; gap: 0.9rem; align-items: center; flex-wrap: wrap;">
            <label class="chk-all" style="display: inline-flex; gap: .4rem; align-items: center; font-size: .82rem; font-weight: 600; cursor: pointer;">
                <input type="checkbox" id="checkAll"> Select all
            </label>
            <button type="button" class="btn btn-sm btn-danger" id="bulkDeleteBtn" style="display: none;" onclick="deleteSelected()">
                <i class="fas fa-trash"></i> Delete Selected (<span id="bulkCount">0</span>)
            </button>
            <span class="badge badge-primary"><?php echo count($entries); ?> entries</span>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 36px;"></th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Expense Name</th>
                        <th>Category</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                No expenses yet. Click "Add Expense" to get started.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td class="cell-check">
                                    <input type="checkbox" class="entry-chk" value="<?php echo (int)$entry['id']; ?>">
                                </td>
                                <td>
                                    <?php echo date('d M, h:i A', strtotime($entry['created_at'])); ?>
                                </td>
                                <td>
                                    <?php if ($entry['type'] === 'cash_in'): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-arrow-down"></i> Cash In
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-arrow-up"></i> Expense
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo sanitize($entry['note'] ?: '-'); ?>
                                    <?php
                                    // Which bill a refund came back from and how the customer was paid, so the
                                    // same taka never has to be explained twice.
                                    $line_bill = trim((string)($entry['invoice_no'] ?? ''));
                                    $line_method = $methodLabels[(string)($entry['payment_method'] ?? '')] ?? '';
                                    ?>
                                    <?php if ($line_bill !== '' || $line_method !== ''): ?>
                                        <div class="text-muted" style="font-size: .78rem;">
                                            <?php
                                            $bits = [];
                                            if ($line_bill !== '') { $bits[] = '#' . sanitize($line_bill); }
                                            if ($line_method !== '') { $bits[] = $line_method; }
                                            echo implode(' · ', $bits);
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($entry['category_name']): ?>
                                        <span class="badge badge-primary">
                                            <i class="fas fa-tag"></i>
                                            <?php echo sanitize($entry['category_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo sanitize($entry['user_name'] ?? 'System'); ?>
                                </td>
                                <td>
                                    <strong class="<?php echo $entry['type'] === 'cash_in' ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo ($entry['type'] === 'cash_in' ? '+' : '-') . formatCurrency($entry['amount']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="deleteEntry(<?php echo $entry['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="modal-overlay" id="entryModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Add Expense</h3>
            <button class="modal-close" onclick="closeEntryModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <?php echo cashbookStoreField($stores); ?>

                <div class="form-group">
                    <label class="form-label required">Expense Name</label>
                    <input type="text" name="note" id="entryNote" class="form-control" placeholder="e.g. Shop rent, Electricity bill..." required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Expense Category</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <select name="category_id" id="entryCategory" class="form-control" required style="flex: 1;">
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo sanitize($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline" title="Create new category" onclick="openCategoryModal()">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <small class="form-text">Select a category or create a new one</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Amount</label>
                    <div class="input-group">
                        <input type="number" name="amount" id="entryAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                        <button type="button" class="btn btn-outline" style="border-radius: 0 var(--border-radius) var(--border-radius) 0; border-left: none;">৳</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEntryModal()">Cancel</button>
                <button type="submit" class="btn btn-danger" id="entrySubmitBtn">
                    <i class="fas fa-save"></i> Save Expense
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Deposit Modal -->
<div class="modal-overlay" id="depositModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Add Deposit</h3>
            <button class="modal-close" onclick="closeDepositModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_deposit">
                <?php echo cashbookStoreField($stores); ?>

                <div class="form-group">
                    <label class="form-label">Deposit Name</label>
                    <input type="text" name="note" id="depositNote" class="form-control" placeholder="Cash deposit" >
                    <small class="form-text">Left blank it is booked as a plain cash deposit.</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Amount</label>
                    <div class="input-group">
                        <input type="number" name="amount" id="depositAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                        <button type="button" class="btn btn-outline" style="border-radius: 0 var(--border-radius) var(--border-radius) 0; border-left: none;">৳</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDepositModal()">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Deposit
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Expense Category Modal -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-tags"></i> Expense Categories
            </h3>
            <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        <form method="POST" style="margin: 0;">
            <div class="modal-body">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_category">

                <div class="form-group">
                    <label class="form-label required">New Category Name</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" name="category_name" class="form-control" placeholder="e.g. Utility, Rent, Salary..." required style="flex: 1;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <div class="modal-body" style="padding-top: 0; max-height: 300px; overflow-y: auto;">
            <table class="table">
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td class="text-center text-muted">No categories yet</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-tag" style="color: var(--primary); margin-right: 0.5rem;"></i>
                                    <?php echo sanitize($cat['name']); ?>
                                </td>
                                <td style="width: 100px; text-align: right;" class="text-muted">
                                    <?php echo $cat['usage_count']; ?> used
                                </td>
                                <td style="width: 50px; text-align: right;">
                                    <button class="btn btn-sm btn-danger" title="Delete category"
                                        onclick="deleteCategory(<?php echo $cat['id']; ?>, '<?php echo sanitize($cat['name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Delete Category Form -->
<form id="deleteCategoryForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_category">
    <input type="hidden" name="id" id="deleteCategoryId">
</form>

<!-- Bulk Delete Form - the ticked ids are written in by JS on submit -->
<form id="bulkDeleteForm" method="POST" style="display: none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_bulk">
    <input type="hidden" name="ids" id="bulkIds">
</form>

<script>
    function openEntryModal() {
        document.getElementById('entryAmount').value = '';
        document.getElementById('entryNote').value = '';
        document.getElementById('entryCategory').value = '';
        document.getElementById('entryModal').classList.add('active');
        document.getElementById('entryNote').focus();
    }

    function closeEntryModal() {
        document.getElementById('entryModal').classList.remove('active');
    }

    function openDepositModal() {
        document.getElementById('depositAmount').value = '';
        document.getElementById('depositNote').value = '';
        document.getElementById('depositModal').classList.add('active');
        document.getElementById('depositAmount').focus();
    }

    function closeDepositModal() {
        document.getElementById('depositModal').classList.remove('active');
    }

    function openCategoryModal() {
        document.getElementById('categoryModal').classList.add('active');
    }

    function closeCategoryModal() {
        document.getElementById('categoryModal').classList.remove('active');
    }

    function deleteEntry(id) {
        if (confirm('Delete this cashbook entry?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    function deleteCategory(id, name) {
        if (confirm('Delete expense category "' + name + '"?')) {
            document.getElementById('deleteCategoryId').value = id;
            document.getElementById('deleteCategoryForm').submit();
        }
    }

    // ── Select all / bulk delete ───────────────────────────────────────────
    var checkAllBox = document.getElementById('checkAll');

    function entryChecks() {
        return [].slice.call(document.querySelectorAll('.entry-chk'));
    }

    function bulkSelected() {
        return entryChecks().filter(function (c) { return c.checked; })
            .map(function (c) { return c.value; });
    }

    function refreshBulk() {
        var ids = bulkSelected();
        var total = entryChecks().length;
        document.getElementById('bulkCount').textContent = ids.length;
        document.getElementById('bulkDeleteBtn').style.display = ids.length ? '' : 'none';
        checkAllBox.checked = (ids.length > 0 && ids.length === total);
        checkAllBox.indeterminate = (ids.length > 0 && ids.length < total);
    }

    checkAllBox.addEventListener('change', function () {
        entryChecks().forEach(function (c) { c.checked = checkAllBox.checked; });
        refreshBulk();
    });

    entryChecks().forEach(function (c) { c.addEventListener('change', refreshBulk); });

    function deleteSelected() {
        var ids = bulkSelected();
        if (!ids.length) { return; }
        if (!confirm('Delete ' + ids.length + ' selected '
                + (ids.length === 1 ? 'entry' : 'entries') + '? This cannot be undone.')) { return; }
        document.getElementById('bulkIds').value = ids.join(',');
        document.getElementById('bulkDeleteForm').submit();
    }

    document.getElementById('entryModal').addEventListener('click', function (e) {
        if (e.target === this) closeEntryModal();
    });

    document.getElementById('depositModal').addEventListener('click', function (e) {
        if (e.target === this) closeDepositModal();
    });

    document.getElementById('categoryModal').addEventListener('click', function (e) {
        if (e.target === this) closeCategoryModal();
    });
</script>

<?php include 'includes/footer.php'; ?>