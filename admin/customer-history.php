<?php
/**
 * POS System - Customer Purchase History
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Customer History');

$db = getDB();

$customerId = (int)($_GET['id'] ?? 0);

if (!$customerId) {
    redirect('customers.php');
}

// Get customer details
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    redirect('customers.php');
}

// Get customer's purchase history
$stmt = $db->prepare("
    SELECT s.*, u.name as cashier_name,
    (SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id) as item_count
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.customer_id = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$customerId]);
$purchases = $stmt->fetchAll();

// Calculate stats
$totalSpent = array_sum(array_column($purchases, 'total'));
$totalOrders = count($purchases);
$avgOrderValue = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;

include 'includes/header.php';
?>

<div style="margin-bottom: 1.5rem;">
    <a href="customers.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Customers
    </a>
</div>

<!-- Customer Info Card -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <div style="display: flex; align-items: flex-start; gap: 1.5rem;">
            <div class="user-avatar" style="width: 60px; height: 60px; font-size: 1.5rem;">
                <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
            </div>
            <div style="flex: 1;">
                <h2 style="margin-bottom: 0.5rem;"><?php echo sanitize($customer['name']); ?></h2>
                <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                    <?php if ($customer['phone']): ?>
                        <div><i class="fas fa-phone text-muted"></i> <?php echo sanitize($customer['phone']); ?></div>
                    <?php endif; ?>
                    <?php if ($customer['email']): ?>
                        <div><i class="fas fa-envelope text-muted"></i> <?php echo sanitize($customer['email']); ?></div>
                    <?php endif; ?>
                    <?php if ($customer['address']): ?>
                        <div><i class="fas fa-map-marker-alt text-muted"></i> <?php echo sanitize($customer['address']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Spent</div>
            <div class="stat-value"><?php echo formatCurrency($totalSpent); ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-shopping-bag"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?php echo $totalOrders; ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-calculator"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Avg. Order Value</div>
            <div class="stat-value"><?php echo formatCurrency($avgOrderValue); ?></div>
        </div>
    </div>
</div>

<!-- Purchase History -->
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> Purchase History</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Cashier</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No purchases yet</td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $purchase): ?>
                        <tr>
                            <td><strong><?php echo sanitize($purchase['invoice_number']); ?></strong></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($purchase['created_at'])); ?></td>
                            <td><?php echo $purchase['item_count']; ?> items</td>
                            <td><strong><?php echo formatCurrency($purchase['total']); ?></strong></td>
                            <td>
                                <span class="badge badge-primary"><?php echo ucfirst($purchase['payment_method']); ?></span>
                            </td>
                            <td><?php echo sanitize($purchase['cashier_name']); ?></td>
                            <td>
                                <a href="sales.php?search=<?php echo urlencode($purchase['invoice_number']); ?>" class="btn btn-sm btn-outline">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
