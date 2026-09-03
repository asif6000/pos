<?php
/**
 * POS System - User Management
 * Admin only - manage system users
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Users');

$db = getDB();
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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
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
        <div class="table-responsive">
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

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
    const userModal = document.getElementById('userModal');

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