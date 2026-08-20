<?php
/**
 * POS System - Categories Management
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Categories');

$db = getDB();
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $status = sanitize($_POST['status'] ?? 'active');

        if (empty($name)) {
            setFlash('danger', 'Category name is required.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO categories (name, description, status, owner_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $status, $currentUser['owner_id']]);
                    setFlash('success', 'Category added successfully!');
                } else {
                    $stmt = $db->prepare("UPDATE categories SET name=?, description=?, status=? WHERE id=?");
                    $stmt->execute([$name, $description, $status, $id]);
                    setFlash('success', 'Category updated successfully!');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Check if category has products
            $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                setFlash('danger', 'Cannot delete category with products.');
            } else {
                $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->execute([$id]);
                setFlash('success', 'Category deleted successfully!');
            }
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting category.');
        }
    }
    redirect('categories.php');
}

// Get categories with product count - Filter by owner
$stmt = $db->prepare("
    SELECT c.*, COUNT(p.id) as product_count 
    FROM categories c 
    LEFT JOIN products p ON c.id = p.category_id 
    WHERE c.owner_id = ?
    GROUP BY c.id 
    ORDER BY c.name
");
$stmt->execute([$currentUser['owner_id']]);
$categories = $stmt->fetchAll();

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
    <p class="text-muted">Organize your products by category</p>
    <button class="btn btn-primary" onclick="openModal('add')">
        <i class="fas fa-plus"></i> Add Category
    </button>
</div>

<!-- Categories Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Products</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">No categories found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><strong>
                                    <?php echo sanitize($category['name']); ?>
                                </strong></td>
                            <td class="text-muted">
                                <?php echo sanitize($category['description'] ?: '-'); ?>
                            </td>
                            <td><span class="badge badge-primary">
                                    <?php echo $category['product_count']; ?>
                                </span></td>
                            <td>
                                <span
                                    class="badge badge-<?php echo $category['status'] === 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($category['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <button class="btn btn-sm btn-outline"
                                        onclick='editCategory(<?php echo json_encode($category); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($category['product_count'] == 0): ?>
                                        <button class="btn btn-sm btn-danger"
                                            onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo sanitize($category['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="categoryModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Category</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="categoryId">

                <div class="form-group">
                    <label class="form-label required">Category Name</label>
                    <input type="text" name="name" id="categoryName" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="categoryDescription" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="categoryStatus" class="form-control">
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
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Category' : 'Edit Category';
        document.getElementById('categoryModal').classList.add('active');
        if (action === 'add') {
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('categoryStatus').value = 'active';
        }
    }

    function closeModal() {
        document.getElementById('categoryModal').classList.remove('active');
    }

    function editCategory(category) {
        openModal('edit');
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryName').value = category.name;
        document.getElementById('categoryDescription').value = category.description || '';
        document.getElementById('categoryStatus').value = category.status;
    }

    function deleteCategory(id, name) {
        if (confirm('Delete category "' + name + '"?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    document.getElementById('categoryModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>