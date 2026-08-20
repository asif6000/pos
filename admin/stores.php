<?php
/**
 * POS System - Store Management
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Stores');
$db = getDB();
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');

    if ($action === 'add') {
        $ownerId = $currentUser['owner_id'] ?: $currentUser['id'];
    }
    
    // Admin User Details (only for add)
    $admin_name = sanitize($_POST['admin_name'] ?? '');
    $admin_email = sanitize($_POST['admin_email'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        if (empty($name)) {
            setFlash('danger', 'Store name is required.');
        } else {
            try {
                if ($action === 'add') {
                    // Check if email exists first if admin details provided
                    if (!empty($admin_email)) {
                        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $stmt->execute([$admin_email]);
                        if ($stmt->fetch()) {
                            throw new Exception("Email already exists.");
                        }
                    }

                    $db->beginTransaction();
                    
                    $ownerId = $currentUser['owner_id'] ?: $currentUser['id'];
                    $stmt = $db->prepare("INSERT INTO stores (name, address, phone, status, owner_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $address, $phone, $status, $ownerId]);
                    $store_id = $db->lastInsertId();
                    
                    // Create Admin User if details provided
                    if (!empty($admin_email) && !empty($admin_password)) {
                        $hashedPassword = password_hash($admin_password, PASSWORD_DEFAULT);
                        $admin_name = $admin_name ?: $name . " Admin";
                        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id, status, owner_id) VALUES (?, ?, ?, 'admin', ?, 'active', ?)");
                        $stmt->execute([$admin_name, $admin_email, $hashedPassword, $store_id, $ownerId]);
                    }
                    
                    $db->commit();
                    setFlash('success', 'Store created successfully!');
                    // Force refresh the store list
                    header('Location: stores.php');
                    exit;
                } else {
                    $stmt = $db->prepare("UPDATE stores SET name=?, address=?, phone=?, status=? WHERE id=?");
                    $stmt->execute([$name, $address, $phone, $status, $id]);
                    setFlash('success', 'Store updated successfully!');
                    // Force refresh the store list
                    header('Location: stores.php');
                    exit;
                }
            } catch (Exception $e) {
                setFlash('danger', $e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        try {
            $db->beginTransaction();
            
            // Find another store of the same owner to reassign users to
            $ownerId = $currentUser['owner_id'] ?: $currentUser['id'];
            $stmtOther = $db->prepare("SELECT id FROM stores WHERE owner_id = ? AND id != ? AND status = 'active' LIMIT 1");
            $stmtOther->execute([$ownerId, $id]);
            $targetStoreId = $stmtOther->fetchColumn();
            
            if ($targetStoreId) {
                // Reassign users to the other store
                $stmtUpdate = $db->prepare("UPDATE users SET store_id = ? WHERE store_id = ?");
                $stmtUpdate->execute([$targetStoreId, $id]);
                $reassignedCount = $stmtUpdate->rowCount();
                $msg = $reassignedCount > 0 
                    ? "Store deleted successfully! $reassignedCount assigned users have been reassigned to another store."
                    : "Store deleted successfully!";
            } else {
                // Set store_id to NULL
                $stmtUpdate = $db->prepare("UPDATE users SET store_id = NULL WHERE store_id = ?");
                $stmtUpdate->execute([$id]);
                $unassignedCount = $stmtUpdate->rowCount();
                $msg = $unassignedCount > 0 
                    ? "Store deleted successfully! $unassignedCount assigned users have been unassigned."
                    : "Store deleted successfully!";
            }
            
            // Delete the store
            $stmtDelete = $db->prepare("DELETE FROM stores WHERE id = ?");
            if ($stmtDelete->execute([$id])) {
                $db->commit();
                setFlash('success', $msg);
            } else {
                throw new Exception('Failed to delete store.');
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (strpos($e->getMessage(), 'foreign key') !== false) {
                setFlash('danger', 'Cannot delete store because it is referenced in stock transfers. Please remove the transfers first.');
            } else {
                setFlash('danger', 'Error deleting store: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('danger', 'Error deleting store: ' . $e->getMessage());
        }
    }
    redirect('stores.php');
}

// Get stores - with fallback for missing owner_id
$ownerId = $currentUser['owner_id'] ?: $currentUser['id'];
$stmt = $db->prepare("SELECT * FROM stores WHERE owner_id = ? ORDER BY id ASC");
$stmt->execute([$ownerId]);
$stores = $stmt->fetchAll();

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <h2 style="margin-bottom: 0.5rem;">Stores / Branches</h2>
        <p class="text-muted">Manage your store locations</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('add')">
        <i class="fas fa-plus"></i> Add Store
    </button>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Store Name</th>
                    <th>Address</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stores)): ?>
                    <tr><td colspan="5" class="text-center">No stores found.</td></tr>
                <?php endif; ?>
                <?php foreach ($stores as $store): ?>
                    <tr>
                        <td><strong><?php echo sanitize($store['name']); ?></strong></td>
                        <td><?php echo sanitize($store['address']); ?></td>
                        <td><?php echo sanitize($store['phone']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $store['status'] === 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($store['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-outline" onclick='editStore(<?php echo json_encode($store); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteStore(<?php echo $store['id']; ?>, '<?php echo sanitize($store['name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="storeModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Store</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="storeId">

                <div class="form-group">
                    <label class="form-label required">Store Name</label>
                    <input type="text" name="name" id="storeName" class="form-control" required placeholder="e.g. Main Branch">
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="storeAddress" class="form-control" rows="2" placeholder="Full address"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="storePhone" class="form-control" placeholder="Contact number">
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="storeStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div id="adminUserSection">
                    <hr>
                    <h4 style="margin-bottom: 1rem;">Store Admin User</h4>
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="admin_name" id="adminName" class="form-control" placeholder="e.g. Branch Manager">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="admin_email" id="adminEmail" class="form-control" placeholder="login@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="admin_password" id="adminPassword" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Store</button>
            </div>
        </form>
    </div>
</div>

<script>
    const storeModal = document.getElementById('storeModal');

    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Store' : 'Edit Store';
        
        if (action === 'add') {
            document.getElementById('storeId').value = '';
            document.getElementById('storeName').value = '';
            document.getElementById('storeAddress').value = '';
            document.getElementById('storePhone').value = '';
            document.getElementById('storeStatus').value = 'active';
            
            // Show and reset admin section
            document.getElementById('adminUserSection').style.display = 'block';
            document.getElementById('adminName').value = '';
            document.getElementById('adminEmail').value = '';
            document.getElementById('adminPassword').value = '';
        } else {
            // Hide admin section for edit
            document.getElementById('adminUserSection').style.display = 'none';
        }
        storeModal.classList.add('active');
    }

    function closeModal() {
        storeModal.classList.remove('active');
    }

    function editStore(store) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').textContent = 'Edit Store';
        document.getElementById('storeId').value = store.id;
        document.getElementById('storeName').value = store.name;
        document.getElementById('storeAddress').value = store.address;
        document.getElementById('storePhone').value = store.phone;
        document.getElementById('storeStatus').value = store.status;
        
        // Hide admin section for edit
        document.getElementById('adminUserSection').style.display = 'none';

        storeModal.classList.add('active');
    }

    function deleteStore(id, name) {
        if (confirm('Are you sure you want to delete store "' + name + '"?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
