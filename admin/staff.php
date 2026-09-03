<?php
/**
 * POS System - Staff Management
 * Add staff members and record salary payments (Weekly / Monthly)
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

if (!hasPermission('staff')) {
    setFlash('danger', 'You do not have permission to access staff management.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Staff');

$db = getDB();
$user = getCurrentUser();
$owner_id = $user['owner_id'] ?? $user['id'];
$store_id = $user['store_id'] ?? null;

// Create tables if they don't exist yet (safe migration)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        store_id INT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NULL,
        designation VARCHAR(100) NULL,
        salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        salary_type ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_owner (owner_id)
    ) ENGINE=InnoDB");

    // Migrate older tables created before salary_type existed
    $cols = $db->query("SHOW COLUMNS FROM staff")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('monthly_salary', $cols) && !in_array('salary', $cols)) {
        $db->exec("ALTER TABLE staff CHANGE COLUMN monthly_salary salary DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    if (!in_array('salary_type', $cols)) {
        $db->exec("ALTER TABLE staff ADD COLUMN salary_type ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly' AFTER salary");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS staff_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        owner_id INT NOT NULL,
        store_id INT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_date DATE NOT NULL,
        note VARCHAR(255) NULL,
        created_by INT NULL,
        working_days INT NULL,
        total_days INT NULL,
        daily_rate DECIMAL(12,2) NULL,
        earned_amount DECIMAL(12,2) NULL,
        bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
        advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff (staff_id),
        INDEX idx_date (payment_date)
    ) ENGINE=InnoDB");

    // Migrate existing staff_payments table - add new columns if missing
    $spCols = $db->query("SHOW COLUMNS FROM staff_payments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('working_days', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN working_days INT NULL AFTER created_by");
    }
    if (!in_array('total_days', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN total_days INT NULL AFTER working_days");
    }
    if (!in_array('daily_rate', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN daily_rate DECIMAL(12,2) NULL AFTER total_days");
    }
    if (!in_array('earned_amount', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN earned_amount DECIMAL(12,2) NULL AFTER daily_rate");
    }
    if (!in_array('bonus', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN bonus DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER earned_amount");
    }
    if (!in_array('advance_deduction', $spCols)) {
        $db->exec("ALTER TABLE staff_payments ADD COLUMN advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER bonus");
    }

    // Ensure cashbook tables exist so salary payments can update the balance
    $db->exec("CREATE TABLE IF NOT EXISTS expense_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_owner_name (owner_id, name),
        INDEX idx_owner (owner_id)
    ) ENGINE=InnoDB");
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

    // Staff login accounts: email + linked user_id
    $staffCols = $db->query("SHOW COLUMNS FROM staff")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('email', $staffCols)) {
        $db->exec("ALTER TABLE staff ADD COLUMN email VARCHAR(100) NULL AFTER phone");
    }
    if (!in_array('user_id', $staffCols)) {
        $db->exec("ALTER TABLE staff ADD COLUMN user_id INT NULL AFTER email");
    }

    // Ensure a 'staff' role exists for staff login accounts
    $roleExists = $db->query("SELECT COUNT(*) FROM roles WHERE slug = 'staff'")->fetchColumn();
    if (!$roleExists) {
        $db->exec("INSERT INTO roles (name, slug, description, status) VALUES ('Staff', 'staff', 'Staff member with own dashboard', 'active')");
    }
} catch (PDOException $e) {
    // Table creation is best-effort; real errors surface below
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $designation = sanitize($_POST['designation'] ?? '');
        $salary = (float)($_POST['salary'] ?? 0);
        $salary_type = sanitize($_POST['salary_type'] ?? 'monthly');
        $status = sanitize($_POST['status'] ?? 'active');

        if (empty($name)) {
            setFlash('danger', 'Staff name is required.');
        } elseif ($salary < 0) {
            setFlash('danger', 'Salary cannot be negative.');
        } elseif (!in_array($salary_type, ['weekly', 'monthly'])) {
            setFlash('danger', 'Invalid salary type.');
        } elseif ($action === 'add' && !empty($email) && empty($password)) {
            setFlash('danger', 'Password is required when a login email is provided.');
        } elseif (!empty($password) && strlen($password) < 6) {
            setFlash('danger', 'Password must be at least 6 characters.');
        } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Invalid email address.');
        } else {
            try {
                if ($action === 'add') {
                    $userId = null;
                    if (!empty($email)) {
                        // Check email is not already used by another user
                        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $chk->execute([$email]);
                        if ($chk->fetch()) {
                            setFlash('danger', 'A user with this email already exists.');
                            redirect('staff.php');
                        }
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id, status, owner_id) VALUES (?, ?, ?, 'staff', ?, ?, ?)");
                        $stmt->execute([$name, $email, $hashed, $store_id, $status, $owner_id]);
                        $userId = (int)$db->lastInsertId();
                    }
                    $stmt = $db->prepare("INSERT INTO staff (owner_id, store_id, name, phone, designation, salary, salary_type, status, email, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$owner_id, $store_id, $name, $phone ?: null, $designation ?: null, $salary, $salary_type, $status, $email ?: null, $userId]);
                    setFlash('success', 'Staff member added successfully!');
                } else {
                    // Fetch existing staff to find linked user account
                    $stmt = $db->prepare("SELECT id, user_id FROM staff WHERE id = ? AND owner_id = ?");
                    $stmt->execute([$id, $owner_id]);
                    $existing = $stmt->fetch();
                    if (!$existing) {
                        setFlash('danger', 'Staff member not found.');
                    } else {
                        $userId = (int)($existing['user_id'] ?? 0);

                        // Check duplicate email before making changes
                        if (!empty($email)) {
                            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                            $chk->execute([$email, $userId]);
                            if ($chk->fetch()) {
                                setFlash('danger', 'A user with this email already exists.');
                                redirect('staff.php');
                            }
                        }

                        // Update staff record
                        $stmt = $db->prepare("UPDATE staff SET name=?, phone=?, designation=?, salary=?, salary_type=?, status=?, email=? WHERE id=? AND owner_id=?");
                        $stmt->execute([$name, $phone ?: null, $designation ?: null, $salary, $salary_type, $status, $email ?: null, $id, $owner_id]);

                        // Sync linked user account
                        if (!empty($email)) {
                            if ($userId > 0) {
                                if (!empty($password)) {
                                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                                    $stmt = $db->prepare("UPDATE users SET name=?, email=?, password=?, status=?, store_id=? WHERE id=?");
                                    $stmt->execute([$name, $email, $hashed, $status, $store_id, $userId]);
                                } else {
                                    $stmt = $db->prepare("UPDATE users SET name=?, email=?, status=?, store_id=? WHERE id=?");
                                    $stmt->execute([$name, $email, $status, $store_id, $userId]);
                                }
                            } else {
                                // No user yet - create one
                                $hashed = password_hash(!empty($password) ? $password : bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
                                $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id, status, owner_id) VALUES (?, ?, ?, 'staff', ?, ?, ?)");
                                $stmt->execute([$name, $email, $hashed, $store_id, $status, $owner_id]);
                                $db->prepare("UPDATE staff SET user_id = ? WHERE id = ?")->execute([(int)$db->lastInsertId(), $id]);
                            }
                        } elseif ($userId > 0) {
                            // Email cleared - deactivate linked user
                            $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$userId]);
                        }
                        setFlash('success', 'Staff member updated successfully!');
                    }
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    } elseif ($action === 'pay') {
        $staff_id      = (int)($_POST['staff_id'] ?? 0);
        $payment_date  = sanitize($_POST['payment_date'] ?? date('Y-m-d'));
        $note          = sanitize($_POST['note'] ?? '');
        $working_days  = (int)($_POST['working_days'] ?? 0);
        $bonus         = (float)($_POST['bonus'] ?? 0);
        $advance       = (float)($_POST['advance_deduction'] ?? 0);
        $amount        = (float)($_POST['amount'] ?? 0); // net payable (calculated client-side, verified here)

        // Validate staff belongs to this owner
        $stmt = $db->prepare("SELECT id, name, salary, salary_type FROM staff WHERE id = ? AND owner_id = ?");
        $stmt->execute([$staff_id, $owner_id]);
        $staff = $stmt->fetch();

        if (!$staff) {
            setFlash('danger', 'Staff member not found.');
        } elseif ($working_days < 0) {
            setFlash('danger', 'Working days cannot be negative.');
        } elseif (!strtotime($payment_date)) {
            setFlash('danger', 'Invalid payment date.');
        } else {
            // Server-side recalculate for integrity (monthly = 30 days, weekly = 7 days)
            $payTs      = strtotime($payment_date);
            $totalDays  = $staff['salary_type'] === 'weekly' ? 7 : 30;
            $dailyRate  = $totalDays > 0 ? round((float)$staff['salary'] / $totalDays, 4) : 0;
            $earned     = round($dailyRate * $working_days, 2);

            // Already paid this period (week for weekly staff, month for monthly staff)
            if ($staff['salary_type'] === 'weekly') {
                $pStart = date('Y-m-d', strtotime('monday this week', $payTs));
                $pEnd   = date('Y-m-d', strtotime('sunday this week', $payTs));
            } else {
                $pStart = date('Y-m-01', $payTs);
                $pEnd   = date('Y-m-t', $payTs);
            }
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM staff_payments WHERE staff_id = ? AND payment_date BETWEEN ? AND ?");
            $stmt->execute([$staff_id, $pStart, $pEnd]);
            $alreadyPaid = (float)$stmt->fetchColumn();

            $netPayable = round($earned + $bonus - $advance - $alreadyPaid, 2);
            if ($netPayable < 0) $netPayable = 0;

            if ($netPayable <= 0) {
                setFlash('danger', 'Net payable amount must be greater than zero.');
            } else {
                try {
                    $db->beginTransaction();
                    $stmt = $db->prepare("INSERT INTO staff_payments
                        (staff_id, owner_id, store_id, amount, payment_date, note, created_by,
                         working_days, total_days, daily_rate, earned_amount, bonus, advance_deduction)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $staff_id, $owner_id, $store_id, $netPayable, $payment_date,
                        $note ?: null, $user['id'],
                        $working_days > 0 ? $working_days : null,
                        $working_days > 0 ? $totalDays : null,
                        $working_days > 0 ? $dailyRate : null,
                        $working_days > 0 ? $earned : null,
                        $bonus,
                        $advance
                    ]);

                    // Record salary as a cashbook expense so balance auto-updates
                    $stmt = $db->prepare("SELECT id FROM expense_categories WHERE owner_id = ? AND name = 'Salary'");
                    $stmt->execute([$owner_id]);
                    $salaryCat = $stmt->fetchColumn();
                    if (!$salaryCat) {
                        $stmt = $db->prepare("INSERT INTO expense_categories (owner_id, name) VALUES (?, 'Salary')");
                        $stmt->execute([$owner_id]);
                        $salaryCat = (int)$db->lastInsertId();
                    }
                    $stmt = $db->prepare("INSERT INTO cashbook_entries (owner_id, store_id, user_id, type, amount, note, category_id, created_at) VALUES (?, ?, ?, 'cash_out', ?, ?, ?, ?)");
                    $stmt->execute([$owner_id, $store_id, $user['id'], $netPayable, 'Staff salary: ' . $staff['name'], (int)$salaryCat, $payment_date . ' 00:00:00']);

                    $db->commit();
                    setFlash('success', 'Salary of ' . formatCurrency($netPayable) . ' paid to ' . sanitize($staff['name']) . '!');
                } catch (PDOException $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    setFlash('danger', 'Error recording salary payment.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $db->prepare("SELECT user_id FROM staff WHERE id = ? AND owner_id = ?");
            $stmt->execute([$id, $owner_id]);
            $linkedUser = $stmt->fetchColumn();
            if ($linkedUser) {
                $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$linkedUser]);
            }
            $db->prepare("DELETE FROM staff_payments WHERE staff_id = ?")->execute([$id]);
            $stmt = $db->prepare("DELETE FROM staff WHERE id = ? AND owner_id = ?");
            $stmt->execute([$id, $owner_id]);
            setFlash('success', 'Staff member deleted successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting staff member.');
        }
    }
    redirect('staff.php');
}

// Current period boundaries
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

// Staff list (owner scope) with period totals
$sql = "SELECT s.*,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) as paid_week,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id), 0) as total_paid
        FROM staff s WHERE s.owner_id = ?";
$params = [$monthStart, $monthEnd, $weekStart, $weekEnd, $owner_id];
if ($store_id) {
    $sql .= " AND s.store_id = ?";
    $params[] = $store_id;
}
$sql .= " ORDER BY s.status = 'active' DESC, s.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$staff = $stmt->fetchAll();

// Summary computed per period
$totalSalary = 0;
$totalPaid = 0;
$totalDue = 0;
foreach ($staff as $m) {
    $totalSalary += (float)$m['salary'];
    $paidPeriod = $m['salary_type'] === 'weekly' ? (float)$m['paid_week'] : (float)$m['paid_month'];
    $totalPaid += $paidPeriod;
    $totalDue += max(0, (float)$m['salary'] - $paidPeriod);
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
        <h2 style="margin-bottom: 0.25rem;">Staff</h2>
        <p class="text-muted">Add staff members and manage weekly or monthly salary payments</p>
    </div>
    <button class="btn btn-primary" onclick="openStaffModal('add')">
        <i class="fas fa-plus"></i> Add Staff
    </button>
    <button class="btn btn-success" onclick="openPayModal(null)">
        <i class="fas fa-hand-holding-usd"></i> Pay Salary
    </button>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Staff</div>
            <div class="stat-value">
                <?php echo count($staff); ?>
            </div>
            <div class="stat-change">Active members</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Salary Total</div>
            <div class="stat-value">
                <?php echo formatCurrency($totalSalary); ?>
            </div>
            <div class="stat-change">All staff combined</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-hand-holding-usd"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Paid This Period</div>
            <div class="stat-value">
                <?php echo formatCurrency($totalPaid); ?>
            </div>
            <div class="stat-change positive">Salary already given</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Due This Period</div>
            <div class="stat-value">
                <?php echo formatCurrency($totalDue); ?>
            </div>
            <div class="stat-change negative">Salary still to be paid</div>
        </div>
    </div>
</div>

<!-- Staff Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-user-tie"></i> Staff Members
        </h3>
        <span class="badge badge-primary"><?php echo count($staff); ?> staff</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Designation</th>
                        <th>Login</th>
                        <th>Salary</th>
                        <th>Paid (Period)</th>
                        <th>Due (Period)</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($staff)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">
                                No staff members yet. Click "Add Staff" to get started.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($staff as $member): ?>
                            <?php
                            $paidPeriod = $member['salary_type'] === 'weekly' ? $member['paid_week'] : $member['paid_month'];
                            $due = max(0, $member['salary'] - $paidPeriod);
                            $periodLabel = $member['salary_type'] === 'weekly' ? 'Week' : 'Month';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo sanitize($member['name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo sanitize($member['phone'] ?: '-'); ?>
                                </td>
                                <td class="text-muted">
                                    <?php echo sanitize($member['designation'] ?: '-'); ?>
                                </td>
                                <td>
                                    <?php if (!empty($member['email'])): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-user-lock"></i> <?php echo sanitize($member['email']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($member['salary']); ?></strong>
                                    <span class="badge badge-<?php echo $member['salary_type'] === 'weekly' ? 'warning' : 'primary'; ?>">
                                        <?php echo ucfirst($member['salary_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong class="text-success">
                                        <?php echo formatCurrency($paidPeriod); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($due > 0): ?>
                                        <strong class="text-danger"><?php echo formatCurrency($due); ?></strong>
                                    <?php else: ?>
                                        <span class="badge badge-success">Paid</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $member['status'] === 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($member['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-success"
                                            onclick='openPayModal(<?php echo json_encode($member); ?>)'
                                            title="Pay Salary">
                                            <i class="fas fa-hand-holding-usd"></i> Pay
                                        </button>
                                        <button class="btn btn-sm btn-outline"
                                            onclick='openStaffModal("edit", <?php echo json_encode($member); ?>)'
                                            title="Edit Staff">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger"
                                            onclick="deleteStaff(<?php echo $member['id']; ?>, '<?php echo sanitize($member['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Staff Modal -->
<div class="modal-overlay" id="staffModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="staffModalTitle">Add Staff</h3>
            <button class="modal-close" onclick="closeStaffModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="staffFormAction" value="add">
                <input type="hidden" name="id" id="staffId">

                <div class="form-group">
                    <label class="form-label required">Staff Name</label>
                    <input type="text" name="name" id="staffName" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" id="staffPhone" class="form-control" placeholder="01XXXXXXXXX">
                </div>

                <div class="form-group">
                    <label class="form-label">Login Email</label>
                    <input type="email" name="email" id="staffEmail" class="form-control"
                           placeholder="Email for staff login" oninput="updateStaffLoginHint()">
                    <small class="form-text" id="staffLoginHint">Staff can sign in and view their own dashboard.</small>
                </div>

                <div class="form-group">
                    <label class="form-label" id="staffPasswordLabel">Login Password</label>
                    <input type="password" name="password" id="staffPassword" class="form-control">
                    <small class="form-text">Minimum 6 characters</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Designation</label>
                    <input type="text" name="designation" id="staffDesignation" class="form-control" placeholder="e.g. Salesman, Cashier, Helper">
                </div>

                <div class="form-group">
                    <label class="form-label">Salary Type</label>
                    <select name="salary_type" id="staffSalaryType" class="form-control" onchange="updateSalaryLabel(); convertSalary();">
                        <option value="monthly">Monthly</option>
                        <option value="weekly">Weekly</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" id="staffSalaryLabel">Monthly Salary (৳)</label>
                    <input type="number" name="salary" id="staffSalary" class="form-control" min="0" step="0.01" placeholder="0.00" oninput="updateSalaryHint()">
                    <small class="form-text" id="staffSalaryHint"></small>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="staffStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStaffModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- Pay Salary Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Pay Salary</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST" id="payForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="staff_id" id="payStaffId">

                <div class="form-group">
                    <label class="form-label required">Select Staff</label>
                    <select id="payStaffSelect" class="form-control" onchange="onPayStaffChange()">
                        <option value="">-- Select Staff --</option>
                        <?php foreach ($staff as $m): ?>
                            <option value="<?php echo $m['id']; ?>">
                                <?php echo sanitize($m['name']); ?> (<?php echo ucfirst($m['salary_type']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Date</label>
                    <input type="date" name="payment_date" id="payDate" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>" required onchange="recalcPay()">
                </div>

                <!-- Calculation breakdown -->
                <div id="payBreakdown" style="display:none;">
                    <hr style="margin: 0.75rem 0; border-color: var(--border-color,#e5e7eb);">
                    <p style="font-weight:600; margin-bottom:0.75rem; color: var(--text-secondary, #6b7280); font-size:0.85rem; text-transform:uppercase; letter-spacing:0.05em;">Salary Breakdown</p>

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Base Salary (per period)</label>
                            <input type="text" id="paySalaryBase" class="form-control" readonly
                                   style="background:var(--bg-secondary,#f9fafb); font-weight:600;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Daily Rate (৳)</label>
                            <input type="text" id="payDailyRate" class="form-control" readonly
                                   style="background:var(--bg-secondary,#f9fafb);">
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:0.75rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Total Days in Month</label>
                            <input type="text" id="payTotalDays" class="form-control" readonly
                                   style="background:var(--bg-secondary,#f9fafb);">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label required">Working Days</label>
                            <input type="number" name="working_days" id="payWorkingDays" class="form-control"
                                   min="0" max="31" step="1" placeholder="0" oninput="recalcPay()">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:0.75rem;">
                        <label class="form-label">Earned Amount (Daily Rate × Working Days)</label>
                        <input type="text" id="payEarned" class="form-control" readonly
                               style="background:var(--bg-secondary,#f9fafb); font-weight:600; color: #059669;">
                    </div>

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Bonus (৳)</label>
                            <input type="number" name="bonus" id="payBonus" class="form-control"
                                   min="0" step="0.01" placeholder="0.00" oninput="recalcPay()"
                                   style="border-color:#10b981;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Advance Deduction (৳)</label>
                            <input type="number" name="advance_deduction" id="payAdvance" class="form-control"
                                   min="0" step="0.01" placeholder="0.00" oninput="recalcPay()"
                                   style="border-color:#ef4444;">
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top:0.75rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Already Paid This Period</label>
                            <input type="text" id="payPaid" class="form-control" readonly
                                   style="background:var(--bg-secondary,#f9fafb); color:#ef4444;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <!-- spacer -->
                        </div>
                    </div>

                    <!-- Net Payable highlight -->
                    <div id="payNetBox" style="margin-top:1rem; padding:1rem 1.25rem; border-radius:0.75rem;
                         background: linear-gradient(135deg,#ecfdf5,#d1fae5); border:2px solid #10b981;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div style="font-size:0.8rem; font-weight:600; color:#059669; text-transform:uppercase; letter-spacing:0.05em;">Net Payable</div>
                                <div style="font-size:0.75rem; color:#6b7280; margin-top:2px;">Earned + Bonus − Advance − Already Paid</div>
                            </div>
                            <div id="payNetDisplay" style="font-size:1.6rem; font-weight:800; color:#059669;">৳ 0.00</div>
                        </div>
                    </div>

                    <small class="form-text" id="payHint" style="display:none; margin-top:0.5rem;"></small>
                </div>

                <!-- Hidden amount field (auto-filled) -->
                <input type="number" name="amount" id="payAmount" style="display:none;" min="0.01" step="0.01" required>

                <div class="form-group" style="margin-top:0.75rem;">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" id="payNote" class="form-control"
                           placeholder="e.g. August salary, overtime bonus">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="btn btn-success" id="paySubmitBtn" disabled>
                    <i class="fas fa-hand-holding-usd"></i> Confirm Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
    const staffData = <?php echo json_encode($staff); ?>;

    function getStaffById(id) {
        return staffData.find(function (s) { return String(s.id) === String(id); });
    }

    function openStaffModal(action, member) {
        document.getElementById('staffFormAction').value = action;
        document.getElementById('staffModalTitle').textContent = action === 'add' ? 'Add Staff' : 'Edit Staff';
        document.getElementById('staffModal').classList.add('active');

        if (action === 'add') {
            document.getElementById('staffId').value = '';
            document.getElementById('staffName').value = '';
            document.getElementById('staffPhone').value = '';
            document.getElementById('staffEmail').value = '';
            document.getElementById('staffPassword').value = '';
            document.getElementById('staffDesignation').value = '';
            document.getElementById('staffSalaryType').value = 'monthly';
            updateSalaryLabel();
            document.getElementById('staffSalary').value = '';
            document.getElementById('staffStatus').value = 'active';
            document.getElementById('staffPasswordLabel').textContent = 'Login Password';
            document.getElementById('staffPassword').required = false;
            document.getElementById('staffPassword').placeholder = '';
            updateStaffLoginHint();
        } else {
            document.getElementById('staffId').value = member.id;
            document.getElementById('staffName').value = member.name;
            document.getElementById('staffPhone').value = member.phone || '';
            document.getElementById('staffEmail').value = member.email || '';
            document.getElementById('staffPassword').value = '';
            document.getElementById('staffDesignation').value = member.designation || '';
            document.getElementById('staffSalaryType').value = member.salary_type;
            updateSalaryLabel();
            document.getElementById('staffSalary').value = member.salary;
            document.getElementById('staffStatus').value = member.status;
            document.getElementById('staffPasswordLabel').textContent = 'New Password (optional)';
            document.getElementById('staffPassword').placeholder = 'Leave blank to keep current';
            updateStaffLoginHint();
        }
        updateSalaryHint();
    }

    function updateStaffLoginHint() {
        var email = document.getElementById('staffEmail').value.trim();
        var hint = document.getElementById('staffLoginHint');
        if (email) {
            hint.textContent = 'Staff can sign in with this email and view their own dashboard.';
        } else {
            hint.textContent = 'No login account will be created. Add an email to let staff sign in.';
        }
    }

    function updateSalaryLabel() {
        var type = document.getElementById('staffSalaryType').value;
        document.getElementById('staffSalaryLabel').textContent = (type === 'weekly' ? 'Weekly' : 'Monthly') + ' Salary (৳)';
        updateSalaryHint();
    }

    function updateSalaryHint() {
        var type = document.getElementById('staffSalaryType').value;
        var amount = parseFloat(document.getElementById('staffSalary').value) || 0;
        var hint = document.getElementById('staffSalaryHint');
        if (amount <= 0) { hint.textContent = ''; return; }
        if (type === 'monthly') {
            hint.textContent = '≈ ৳ ' + (amount / 4.33).toFixed(2) + ' per week';
        } else {
            hint.textContent = '≈ ৳ ' + (amount * 4.33).toFixed(2) + ' per month';
        }
    }

    function convertSalary() {
        var amount = parseFloat(document.getElementById('staffSalary').value) || 0;
        var type = document.getElementById('staffSalaryType').value;
        if (amount > 0) {
            var converted = type === 'monthly' ? amount * 4.33 : amount / 4.33;
            document.getElementById('staffSalary').value = converted.toFixed(2);
        }
        updateSalaryHint();
    }

    function closeStaffModal() {
        document.getElementById('staffModal').classList.remove('active');
    }

    function recalcPay() {
        var member  = getStaffById(document.getElementById('payStaffSelect').value);
        var breakdown = document.getElementById('payBreakdown');
        var submitBtn = document.getElementById('paySubmitBtn');

        if (!member) {
            document.getElementById('payStaffId').value = '';
            document.getElementById('payAmount').value  = '';
            breakdown.style.display = 'none';
            submitBtn.disabled = true;
            return;
        }

        breakdown.style.display = 'block';

        var salary      = parseFloat(member.salary) || 0;
        var salaryType  = member.salary_type;
        var dateStr     = document.getElementById('payDate').value || '<?php echo date('Y-m-d'); ?>';
        var totalDays   = salaryType === 'weekly' ? 7 : 30;
        var dailyRate   = totalDays > 0 ? salary / totalDays : 0;

        // Working days input
        var workingDays = parseInt(document.getElementById('payWorkingDays').value) || 0;
        if (workingDays > totalDays) {
            workingDays = totalDays;
            document.getElementById('payWorkingDays').value = totalDays;
        }

        // Bonus & Advance
        var bonus   = parseFloat(document.getElementById('payBonus').value) || 0;
        var advance = parseFloat(document.getElementById('payAdvance').value) || 0;

        // Earned = daily rate × working days
        var earned  = dailyRate * workingDays;

        // Already paid this period
        var paidPeriod = salaryType === 'weekly'
            ? parseFloat(member.paid_week) || 0
            : parseFloat(member.paid_month) || 0;

        // Net payable
        var net = earned + bonus - advance - paidPeriod;
        if (net < 0) net = 0;

        // Update display fields
        document.getElementById('payStaffId').value    = member.id;
        document.getElementById('paySalaryBase').value = '৳ ' + salary.toFixed(2) + ' / ' + salaryType;
        document.getElementById('payDailyRate').value  = '৳ ' + dailyRate.toFixed(2);
        document.getElementById('payTotalDays').value  = totalDays + ' days';
        document.getElementById('payEarned').value     = '৳ ' + earned.toFixed(2);
        document.getElementById('payPaid').value       = '৳ ' + paidPeriod.toFixed(2);
        document.getElementById('payNetDisplay').textContent = '৳ ' + net.toFixed(2);
        document.getElementById('payAmount').value     = net.toFixed(2);

        // Net payable box color
        var netBox = document.getElementById('payNetBox');
        if (net > 0) {
            netBox.style.background = 'linear-gradient(135deg,#ecfdf5,#d1fae5)';
            netBox.style.borderColor = '#10b981';
            document.getElementById('payNetDisplay').style.color = '#059669';
        } else {
            netBox.style.background = 'linear-gradient(135deg,#fef2f2,#fee2e2)';
            netBox.style.borderColor = '#ef4444';
            document.getElementById('payNetDisplay').style.color = '#dc2626';
        }

        // Hint
        var hint = document.getElementById('payHint');
        if (paidPeriod > 0 && earned > 0) {
            hint.textContent = 'Already paid ৳' + paidPeriod.toFixed(2) + ' this period — deducted from net payable.';
            hint.style.display = 'block';
        } else {
            hint.textContent = '';
            hint.style.display = 'none';
        }

        submitBtn.disabled = (net <= 0 || workingDays <= 0);
    }

    function onPayStaffChange() {
        recalcPay();
    }

    function openPayModal(member) {
        // Reset fields
        document.getElementById('payStaffSelect').value  = member ? member.id : '';
        document.getElementById('payDate').value         = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('payWorkingDays').value  = '';
        document.getElementById('payBonus').value        = '';
        document.getElementById('payAdvance').value      = '';
        document.getElementById('payNote').value         = '';
        document.getElementById('payBreakdown').style.display = 'none';
        document.getElementById('paySubmitBtn').disabled = true;
        onPayStaffChange();
        document.getElementById('payModal').classList.add('active');
    }

    function closePayModal() {
        document.getElementById('payModal').classList.remove('active');
    }

    function deleteStaff(id, name) {
        if (confirm('Delete staff "' + name + '"? All salary payment records for this staff will also be removed.')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    document.getElementById('staffModal').addEventListener('click', function (e) {
        if (e.target === this) closeStaffModal();
    });
    document.getElementById('payModal').addEventListener('click', function (e) {
        if (e.target === this) closePayModal();
    });
</script>

<?php include 'includes/footer.php'; ?>