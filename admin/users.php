<?php
/**
 * POS System - User Management
 * Needs the "users" permission - admin and owner always have it.
 */

require_once '../config/db.php';
require_once '../config/kpi-permissions.php';
startSecureSession();

requirePermission('users');

define('PAGE_TITLE', 'Users');

$db = getDB();
appSafeDdl($db, "CREATE TABLE IF NOT EXISTS user_kpi_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kpi_key VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_kpi (user_id, kpi_key),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB");
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = sanitize($_POST['role'] ?? 'cashier');
        $store_id = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;
        $status = sanitize($_POST['status'] ?? 'active');

        // Validation
        if (empty($name) || empty($email)) {
            setFlash('danger', 'Name and email are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('danger', 'Invalid email address.');
        } elseif ($action === 'add' && empty($password)) {
            setFlash('danger', 'Password is required for new users.');
        } elseif ($action === 'add' && strlen($password) < 6) {
            setFlash('danger', 'Password must be at least 6 characters.');
        } elseif (!$db->query("SELECT COUNT(*) FROM roles WHERE slug = " . $db->quote($role))->fetchColumn()) {
            // The role has to exist on the Roles page. It did not used to
            // matter: users.role was an ENUM of four literals that quietly
            // truncated anything else to '', so a bad slug produced a user with
            // no role and therefore no permissions, with nothing reported. The
            // column is a VARCHAR now, so the same typo would store verbatim
            // and hit the same wall later and quieter.
            setFlash('danger', "Role '" . htmlspecialchars($role, ENT_QUOTES, 'UTF-8')
                . "' does not exist. Create it on the Roles page first.");
        } else {
            try {
                // Check for duplicate email
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $id]);
                if ($stmt->fetch()) {
                    setFlash('danger', 'Email already exists.');
                } else {
                    if ($action === 'add') {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id, status, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $email, $hashedPassword, $role, $store_id, $status, $currentUser['owner_id']]);
                        setFlash('success', 'User created successfully!');
                    } else {
                        if (!empty($password)) {
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("UPDATE users SET name=?, email=?, password=?, role=?, store_id=?, status=? WHERE id=?");
                            $stmt->execute([$name, $email, $hashedPassword, $role, $store_id, $status, $id]);
                        } else {
                            $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=?, store_id=?, status=? WHERE id=?");
                            $stmt->execute([$name, $email, $role, $store_id, $status, $id]);
                        }
                        setFlash('success', 'User updated successfully!');
                    }
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    // ── Save one account's dashboard KPI cards ───────────────────────────────
    // Per account, not per role: two people on the same Staff role can be
    // trusted with different figures. Only staff/cashier accounts are limited
    // (see kpiGatedRoles()), so an admin/owner/manager row is refused here
    // instead of silently storing a list that would never be read.
    } elseif ($action === 'save_kpi') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $keys     = is_array($_POST['kpi'] ?? null) ? $_POST['kpi'] : [];
        $scopeOwnerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];

        $stmt = $db->prepare("SELECT id, name, role, owner_id FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target || (int)$target['owner_id'] !== $scopeOwnerId) {
            setFlash('danger', 'That account is not part of this shop.');
        } elseif (!in_array((string)$target['role'], kpiGatedRoles(), true)) {
            setFlash('warning', 'Dashboard KPI access applies to staff and cashier accounts. This account already has its role’s full dashboard.');
        } else {
            try {
                $valid = array_keys(dashboardKpiDefs());
                $db->beginTransaction();
                $db->prepare("DELETE FROM user_kpi_permissions WHERE user_id = ?")->execute([$targetId]);
                $ins = $db->prepare("INSERT IGNORE INTO user_kpi_permissions (user_id, kpi_key) VALUES (?, ?)");
                foreach ($keys as $k) {
                    if (in_array($k, $valid, true)) {
                        $ins->execute([$targetId, $k]);
                    }
                }
                $db->commit();
                clearPermissionCache();
                setFlash('success', 'Dashboard cards for ' . $target['name'] . ' saved. The change is live on their next page load.');
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                setFlash('danger', 'Error saving dashboard access. Has config/migration_kpi_permissions.sql been imported?');
            }
        }

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $currentUser = getCurrentUser();

        if ($id == $currentUser['id']) {
            setFlash('danger', 'You cannot delete your own account.');
        } else {
            try {
                // Check if user has sales
                $stmt = $db->prepare("SELECT COUNT(*) FROM sales WHERE user_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    // Just deactivate instead of delete
                    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                    $stmt->execute([$id]);
                    setFlash('success', 'User deactivated (has sales history).');
                } else {
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$id]);
                    // The KPI grants belong to the account, so they go with it.
                    // A missing table is not an error here - nothing to clean.
                    try {
                        $db->prepare("DELETE FROM user_kpi_permissions WHERE user_id = ?")->execute([$id]);
                    } catch (PDOException $ignored) {
                    }
                    setFlash('success', 'User deleted successfully!');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Error deleting user.');
            }
        }
    }
    redirect('users.php');
}

// Get users with store info - Filter by owner
$ownerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];
$sql = "SELECT u.*, s.name as store_name FROM users u LEFT JOIN stores s ON u.store_id = s.id";
$sql .= " WHERE u.owner_id = " . $ownerId;

// If specific store admin, filter further (include users with no store)
$userStoreId = $currentUser['store_id'] ?? null;
if ($userStoreId) {
    $sql .= " AND (u.store_id = " . (int)$userStoreId . " OR u.store_id IS NULL)";
}

$sql .= " ORDER BY u.created_at DESC";
$users = $db->query($sql)->fetchAll();

// Get roles and stores for modal - Filter by owner
$roles = $db->query("SELECT * FROM roles WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$stores = $db->query("SELECT * FROM stores WHERE status = 'active' AND owner_id = " . $ownerId . " ORDER BY name ASC")->fetchAll();

// Dashboard KPI grants per account, for the modal to tick. Read through the
// shop's own users so another shop's rows are never even loaded.
$kpiByUser = [];
try {
    $stmt = $db->prepare("SELECT p.user_id, p.kpi_key FROM user_kpi_permissions p
        JOIN users u ON p.user_id = u.id WHERE u.owner_id = ?");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll() as $row) {
        $kpiByUser[(int)$row['user_id']][] = $row['kpi_key'];
    }
} catch (Exception $e) {
    // Table not imported yet: every account simply has no grants.
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

<div class="page-toolbar">
    <p class="text-muted">Manage system users and their access</p>
    <div>
        <a href="roles.php" class="btn btn-outline" style="margin-right: 0.5rem;">
            <i class="fas fa-user-tag"></i> Roles
        </a>
        <a href="stores.php" class="btn btn-outline" style="margin-right: 0.5rem;">
            <i class="fas fa-store"></i> Stores
        </a>
        <button class="btn btn-primary" onclick="openModal('add')">
            <i class="fas fa-plus"></i> Add User
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Store</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <div class="user-avatar" style="width: 36px; height: 36px; font-size: 0.875rem;">
                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                </div>
                                <strong>
                                    <?php echo sanitize($user['name']); ?>
                                </strong>
                            </div>
                        </td>
                        <td>
                            <?php echo sanitize($user['email']); ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $user['role'] === 'admin' ? 'danger' : 'primary'; ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user['store_name']): ?>
                                <span class="badge badge-info"><i class="fas fa-store"></i> <?php echo sanitize($user['store_name']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $user['status'] === 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($user['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo date('d M Y', strtotime($user['created_at'])); ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-outline"
                                    onclick='editUser(<?php echo json_encode($user); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (in_array((string)$user['role'], kpiGatedRoles(), true)): ?>
                                    <?php $kpiCount = count($kpiByUser[(int)$user['id']] ?? []); ?>
                                    <button class="btn btn-sm btn-outline"
                                        onclick='openKpiModal(<?php echo (int)$user['id']; ?>, <?php echo json_encode($user['name']); ?>)'
                                        title="Dashboard KPI cards: <?php echo $kpiCount; ?> of <?php echo count(dashboardKpiDefs()); ?> enabled">
                                        <i class="fas fa-chart-pie"></i> KPI
                                        <?php if ($kpiCount > 0): ?><span class="badge badge-primary"><?php echo $kpiCount; ?></span><?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($user['id'] != getCurrentUser()['id']): ?>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo sanitize($user['name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add User</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="userId">

                <div class="form-group">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="name" id="userName" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Email Address</label>
                    <input type="email" name="email" id="userEmail" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label" id="passwordLabel">Password</label>
                    <input type="password" name="password" id="userPassword" class="form-control">
                    <small class="form-text" id="passwordHelp">Minimum 6 characters</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" id="userRole" class="form-control">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['slug']; ?>"><?php echo $role['name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Store</label>
                    <select name="store_id" id="userStore" class="form-control">
                        <option value="">Select Store (Optional)</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo ($userStoreId && $store['id'] == $userStoreId) ? 'selected' : ''; ?>>
                                <?php echo $store['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="userStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Dashboard KPI access (per account) -->
<div class="modal-overlay" id="kpiModal">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <h3 class="modal-title" id="kpiModalTitle">Dashboard KPI Cards</h3>
            <button class="modal-close" onclick="closeKpiModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_kpi">
            <input type="hidden" name="user_id" id="kpiUserId">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: 1rem; font-size: 13px;">
                    <i class="fas fa-info-circle"></i>
                    Tick the cards this person may see on the dashboard. An unticked card is not
                    built into the page at all &mdash; its figure is never sent. Everything starts
                    unticked for a new account.
                </p>

                <div class="cols-2" style="gap: 0.4rem;">
                    <?php foreach (dashboardKpiDefs() as $kpiKey => $kpiMeta): ?>
                        <label class="perm-row" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.5rem; border-radius: 6px; cursor: pointer;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                            <input type="checkbox" name="kpi[]" value="<?php echo $kpiKey; ?>"
                                class="kpi-checkbox"
                                data-key="<?php echo $kpiKey; ?>"
                                style="width: 15px; height: 15px; cursor: pointer;">
                            <i class="fas <?php echo $kpiMeta['icon']; ?>" style="color: #6b7280; width: 14px; font-size: 13px;"></i>
                            <span style="font-size: 13px;"><?php echo htmlspecialchars($kpiMeta['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllKpi(true)">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAllKpi(false)">
                        <i class="fas fa-square"></i> Deselect All
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeKpiModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save KPI Access</button>
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
    const userModal = document.getElementById('userModal');

    // Ticked KPI cards per account, keyed by user id.
    const kpiByUser = <?php echo json_encode($kpiByUser); ?>;

    function openKpiModal(userId, name) {
        document.getElementById('kpiUserId').value = userId;
        document.getElementById('kpiModalTitle').textContent = 'Dashboard KPI Cards: ' + name;
        const granted = kpiByUser[userId] || [];
        document.querySelectorAll('.kpi-checkbox').forEach(cb => {
            cb.checked = granted.includes(cb.dataset.key);
        });
        document.getElementById('kpiModal').classList.add('active');
    }

    function closeKpiModal() {
        document.getElementById('kpiModal').classList.remove('active');
    }

    function toggleAllKpi(state) {
        document.querySelectorAll('.kpi-checkbox').forEach(cb => cb.checked = state);
    }

    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add User' : 'Edit User';

        if (action === 'add') {
            // Clear fields for new user
            document.getElementById('userId').value = '';
            document.getElementById('userName').value = '';
            // The input IDs are: userName, userEmail, userPassword, userRole, userStatus
            document.getElementById('userEmail').value = '';
            document.getElementById('userPassword').value = '';
            document.getElementById('userRole').value = 'cashier';
            document.getElementById('userStore').value = '';
            document.getElementById('userStatus').value = 'active';

            // Password required for new users
            document.getElementById('userPassword').required = true;
            document.getElementById('passwordLabel').classList.add('required');
            document.getElementById('passwordHelp').textContent = 'Minimum 6 characters';
        }

        userModal.classList.add('active');
    }

    function closeModal() {
        userModal.classList.remove('active');
    }

    function editUser(user) {
        // First open modal in edit mode (this sets title)
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').textContent = 'Edit User';

        // Populate fields
        document.getElementById('userId').value = user.id;
        document.getElementById('userName').value = user.name;
        document.getElementById('userEmail').value = user.email;
        document.getElementById('userPassword').value = ''; // Clear password field
        document.getElementById('userRole').value = user.role;
        document.getElementById('userStore').value = user.store_id || '';
        document.getElementById('userStatus').value = user.status;

        // Password optional for editing
        document.getElementById('userPassword').required = false;
        document.getElementById('passwordLabel').classList.remove('required');
        document.getElementById('passwordHelp').textContent = 'Leave blank to keep current password';

        userModal.classList.add('active');
    }

    function deleteUser(id, name) {
        if (confirm('Are you sure you want to delete user "' + name + '"?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Close modal when clicking outside
    window.onclick = function (event) {
        if (event.target == userModal) {
            closeModal();
        }
        if (event.target == document.getElementById('kpiModal')) {
            closeKpiModal();
        }
    }

    // Check URL params for auto-open
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'add') {
            openModal('add');
            const role = urlParams.get('role');
            if (role) {
                const roleSelect = document.getElementById('userRole');
                if (roleSelect) roleSelect.value = role;
            }
        }
    });
</script>

<?php include 'includes/footer.php'; ?>