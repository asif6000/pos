<?php
/**
 * POS System - Cashbook
 * Record Expenses with Name & Category
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $amount = (float)($_POST['amount'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');
        $categoryName = sanitize($_POST['category_name'] ?? '');

        if (empty($note)) {
            setFlash('danger', 'Expense name is required.');
        } elseif ($amount <= 0) {
            setFlash('danger', 'Amount must be greater than zero.');
        } else {
            $category_id = null;
            if (!empty($categoryName)) {
                // Find or create category
                $stmt = $db->prepare("SELECT id FROM expense_categories WHERE name = ? AND owner_id = ?");
                $stmt->execute([$categoryName, $owner_id]);
                $category_id = $stmt->fetchColumn();
                if (!$category_id) {
                    $stmt = $db->prepare("INSERT INTO expense_categories (owner_id, name) VALUES (?, ?)");
                    $stmt->execute([$owner_id, $categoryName]);
                    $category_id = $db->lastInsertId();
                }
            }
            try {
                $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note, category_id) VALUES (?, ?, ?, 'cash_out', ?, ?, ?)");
                $stmt->execute([$owner_id, $store_id, $user['id'], $amount, $note, $category_id]);
                setFlash('success', 'Expense of ' . formatCurrency($amount) . ' recorded successfully!');
            } catch (PDOException $e) {
                setFlash('danger', 'Error recording entry. Please try again.');
            }
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
             FROM sales s JOIN users u ON s.user_id = u.id WHERE u.owner_id = ?";
$salesParams = [$owner_id];
if ($store_id) {
    $salesSql .= " AND u.store_id = ?";
    $salesParams[] = $store_id;
}
$stmt = $db->prepare($salesSql);
$stmt->execute($salesParams);
$salesAllTime = (float)$stmt->fetchColumn();

$stmt = $db->prepare($salesSql . " AND s.created_at BETWEEN ? AND ?");
$stmt->execute(array_merge($salesParams, [$todayStart, $todayEnd]));
$salesToday = (float)$stmt->fetchColumn();

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
<div style="margin-bottom: 1.5rem;">
    <h2 style="margin-bottom: 0.25rem;">Expense</h2>
    <p class="text-muted">Record and track expenses with name &amp; category</p>
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
        <div class="stat-icon <?php echo ($salesAllTime - $totals['cash_out']) >= 0 ? 'green' : 'red'; ?>">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Balance (Sales - Expenses)</div>
            <div class="stat-value">
                <?php echo formatCurrency($salesAllTime - $totals['cash_out']); ?>
            </div>
            <div class="stat-change <?php echo ($salesAllTime - $totals['cash_out']) >= 0 ? 'positive' : 'negative'; ?>">
                Auto-updates with expenses
            </div>
        </div>
    </div>
</div>

<!-- Add Expense Form (inline) -->
<div class="card" style="margin-bottom: 1.75rem;">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-plus-circle"></i> Add Expense
        </h3>
    </div>
    <div class="card-body">
        <form method="POST" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="flex: 2; min-width: 220px;">
                <label class="form-label required">Expense Name</label>
                <input type="text" name="note" class="form-control" placeholder="e.g. Shop rent, Electricity bill..." required>
            </div>
            <div class="form-group" style="flex: 1; min-width: 160px;">
                <label class="form-label required">Amount (৳)</label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
            </div>
            <div class="form-group" style="flex: 0 0 auto;">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-save"></i> Save Expense
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Entries Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Transactions
        </h3>
        <span class="badge badge-primary"><?php echo count($entries); ?> entries</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
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
                            <td colspan="7" class="text-center text-muted">
                                No expenses yet. Use the form above to get started.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
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

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
    function deleteEntry(id) {
        if (confirm('Delete this cashbook entry?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
</script>

<?php include 'includes/footer.php'; ?>