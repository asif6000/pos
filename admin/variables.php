<?php
/**
 * POS System - Product Variables Management
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
// Assume hasPermission('products') covers this for now, or check generic inventory permission
if (!hasPermission('products') && !hasPermission('categories') && !hasPermission('variables')) {
     redirect('dashboard.php');
}

define('PAGE_TITLE', 'Variables (Sizes & Colors)');

$db = getDB();
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $type = sanitize($_POST['type'] ?? 'size');
        $status = sanitize($_POST['status'] ?? 'active');

        if (empty($name)) {
            setFlash('danger', 'Variable name is required.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO product_variables (name, type, status, owner_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $type, $status, $currentUser['owner_id']]);
                    setFlash('success', 'Variable added successfully!');
                } else {
                    $stmt = $db->prepare("UPDATE product_variables SET name=?, type=?, status=? WHERE id=? AND owner_id=?");
                    $stmt->execute([$name, $type, $status, $id, $currentUser['owner_id']]);
                    setFlash('success', 'Variable updated successfully!');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Check if variable is used in products
            $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE size_id = ? OR color_id = ?");
            $stmt->execute([$id, $id]);
            if ($stmt->fetchColumn() > 0) {
                setFlash('danger', 'Cannot delete variable that is assigned to products.');
            } else {
                $stmt = $db->prepare("DELETE FROM product_variables WHERE id = ? AND owner_id = ?");
                $stmt->execute([$id, $currentUser['owner_id']]);
                setFlash('success', 'Variable deleted successfully!');
            }
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting variable.');
        }
    }
    redirect('variables.php');
}

// Get variables
$stmt = $db->prepare("
    SELECT * 
    FROM product_variables 
    WHERE owner_id = ?
    ORDER BY type, name
");
$stmt->execute([$currentUser['owner_id']]);
$variables = $stmt->fetchAll();

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
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <p class="text-muted">Manage product sizes and colors</p>
    <button class="btn btn-primary" onclick="openModal('add')">
        <i class="fas fa-plus"></i> Add Variable
    </button>
</div>

<!-- Variables Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($variables)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No variables found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($variables as $variable): ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?php echo $variable['type'] === 'size' ? 'info' : 'secondary'; ?>">
                                    <?php echo ucfirst($variable['type']); ?>
                                </span>
                            </td>
                            <td><strong><?php echo sanitize($variable['name']); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $variable['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($variable['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <button class="btn btn-sm btn-outline"
                                        onclick='editVariable(<?php echo json_encode($variable); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="deleteVariable(<?php echo $variable['id']; ?>, '<?php echo sanitize($variable['name']); ?>')">
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

<!-- Modal -->
<div class="modal-overlay" id="variableModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Variable</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="variableId">

                <div class="form-group">
                    <label class="form-label required">Type</label>
                    <select name="type" id="variableType" class="form-control" required>
                        <option value="">Select Type</option>
                        <option value="size">Size</option>
                        <option value="color">Color</option>
                        <option value="unit">Unit (kg, gram, etc.)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Name / Value</label>
                    <input type="text" name="name" id="variableName" class="form-control" required placeholder="e.g. XL or Red">
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="variableStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
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
    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Variable' : 'Edit Variable';
        document.getElementById('variableModal').classList.add('active');
        if (action === 'add') {
            document.getElementById('variableId').value = '';
            document.getElementById('variableType').value = 'size';
            document.getElementById('variableName').value = '';
            document.getElementById('variableStatus').value = 'active';
        }
    }

    function closeModal() {
        document.getElementById('variableModal').classList.remove('active');
    }

    function editVariable(variable) {
        openModal('edit');
        document.getElementById('variableId').value = variable.id;
        document.getElementById('variableType').value = variable.type;
        document.getElementById('variableName').value = variable.name;
        document.getElementById('variableStatus').value = variable.status;
    }

    function deleteVariable(id, name) {
        if (confirm('Delete variable "' + name + '"?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    document.getElementById('variableModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>
