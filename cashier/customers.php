<?php
/**
 * POS System - Cashier Customers View
 * View and add customers
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

// Cashier should only access POS page
if (hasRole('cashier')) {
    redirect('pos.php');
}

define('PAGE_TITLE', 'Customers');

$db = getDB();

// Handle add customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');

    if (!empty($name)) {
        try {
            $user = getCurrentUser();
            $stmt = $db->prepare("INSERT INTO customers (name, phone, address, owner_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $phone, $address, $user['owner_id']]);
            setFlash('success', 'Customer added successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error adding customer.');
        }
    }
    redirect('customers.php');
}

// Search
$search = sanitize($_GET['search'] ?? '');
$user = getCurrentUser();
$sql = "SELECT * FROM customers WHERE owner_id = ?";
$params = [$user['owner_id']];

if ($search) {
    $sql .= " AND (name LIKE ? OR phone LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <p class="text-muted">Quick customer lookup and addition</p>
    <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('active')">
        <i class="fas fa-plus"></i> Add Customer
    </button>
</div>

<!-- Search -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem;">
            <input type="text" name="search" class="form-control" placeholder="Search by name or phone..."
                value="<?php echo $search; ?>" style="flex: 1;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
        </form>
    </div>
</div>

<!-- Customers -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">No customers found</td>
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
                                <td class="text-muted">
                                    <?php echo sanitize($customer['address'] ?: '-'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Add Customer</h3>
            <button class="modal-close"
                onclick="document.getElementById('addModal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Name</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-control" placeholder="01XXXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                    onclick="document.getElementById('addModal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('addModal').addEventListener('click', function (e) {
        if (e.target === this) this.classList.remove('active');
    });
</script>

<?php include 'includes/footer.php'; ?>