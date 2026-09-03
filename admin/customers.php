<?php
/**
 * POS System - Customer Management
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Customers');

$db = getDB();
$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        if (empty($name)) {
            setFlash('danger', 'Customer name is required.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, owner_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $email ?: null, $address, $currentUser['owner_id']]);
                    setFlash('success', 'Customer added successfully!');
                } else {
                    $stmt = $db->prepare("UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=?");
                    $stmt->execute([$name, $phone, $email ?: null, $address, $id]);
                    setFlash('success', 'Customer updated successfully!');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Set customer_id to NULL in sales instead of deleting
            $stmt = $db->prepare("UPDATE sales SET customer_id = NULL WHERE customer_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('success', 'Customer deleted successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting customer.');
        }
    }
    redirect('customers.php');
}

// Search - Filter by owner
$search = sanitize($_GET['search'] ?? '');
$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as total_orders,
        (SELECT COALESCE(SUM(total), 0) FROM sales WHERE customer_id = c.id) as total_spent
        FROM customers c WHERE c.owner_id = ?";
$params = [$currentUser['owner_id']];

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$sql .= " ORDER BY c.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

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
    <p class="text-muted">Manage your customer database</p>
    <button class="btn btn-primary" onclick="openModal('add')">
        <i class="fas fa-plus"></i> Add Customer
    </button>
</div>

<!-- Search -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                <input type="text" name="search" class="form-control" placeholder="Search by name, phone, or email..."
                    value="<?php echo $search; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
            <a href="customers.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Address</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No customers found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><strong>
                                    <?php echo sanitize($customer['name']); ?>
                                </strong></td>
                            <td>
                                <?php echo sanitize($customer['phone'] ?: '-'); ?>
                            </td>
                            <td>
                                <?php echo sanitize($customer['email'] ?: '-'); ?>
                            </td>
                            <td class="text-muted">
                                <?php echo sanitize($customer['address'] ?: '-'); ?>
                            </td>
                            <td><span class="badge badge-primary">
                                    <?php echo $customer['total_orders']; ?>
                                </span></td>
                            <td><strong>
                                    <?php echo formatCurrency($customer['total_spent']); ?>
                                </strong></td>
                            <td>
                                <div class="table-actions">
                                    <a href="customer-history.php?id=<?php echo $customer['id']; ?>"
                                        class="btn btn-sm btn-outline" title="View History">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline"
                                        onclick='editCustomer(<?php echo json_encode($customer); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo sanitize($customer['name']); ?>')">
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

<!-- Modal -->
<div class="modal-overlay" id="customerModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Customer</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="customerId">

                <div class="form-group">
                    <label class="form-label required">Customer Name</label>
                    <input type="text" name="name" id="customerName" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" id="customerPhone" class="form-control" placeholder="01XXXXXXXXX">
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="customerEmail" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="customerAddress" class="form-control" rows="2"></textarea>
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
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Customer' : 'Edit Customer';
        document.getElementById('customerModal').classList.add('active');
        if (action === 'add') {
            document.getElementById('customerId').value = '';
            document.getElementById('customerName').value = '';
            document.getElementById('customerPhone').value = '';
            document.getElementById('customerEmail').value = '';
            document.getElementById('customerAddress').value = '';
        }
    }

    function closeModal() {
        document.getElementById('customerModal').classList.remove('active');
    }

    function editCustomer(customer) {
        openModal('edit');
        document.getElementById('customerId').value = customer.id;
        document.getElementById('customerName').value = customer.name;
        document.getElementById('customerPhone').value = customer.phone || '';
        document.getElementById('customerEmail').value = customer.email || '';
        document.getElementById('customerAddress').value = customer.address || '';
    }

    function deleteCustomer(id, name) {
        if (confirm('Delete customer "' + name + '"? Their sales history will be preserved.')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    document.getElementById('customerModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>