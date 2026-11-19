<?php
/**
 * POS System - Customer Purchase History
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Customer History');

$db = getDB();
$user = getCurrentUser();

$customerId = (int)($_GET['id'] ?? 0);

if (!$customerId) {
    redirect('customers.php');
}

// Get customer details
// Customer names, numbers and what they bought belong to the shop that added
// them, so the id from the URL is read inside that shop only.
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND owner_id = ?");
$stmt->execute([$customerId, $user['owner_id']]);
$customer = $stmt->fetch();

if (!$customer) {
    redirect('customers.php');
}

// A counter account (staff/cashier) opens a customer's history only when that
// customer bought from THEM - the customers list it came from shows exactly
// those, so a hand-typed id cannot reach a colleague's customer.
list($ownScope, $ownParams) = ownSalesScopeSql($user, 's');
if (!empty($ownScope)) {
    $stmt = $db->prepare("SELECT 1 FROM sales WHERE customer_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$customerId, (int) $user['id']]);
    if (!$stmt->fetch()) {
        redirect('customers.php');
    }
}

// Get customer's purchase history
// The counter account's history lists its own bills with this customer, so the
// totals underneath answer for the same rows the table shows.
$stmt = $db->prepare("
    SELECT s.*, u.name as cashier_name,
    (SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id) as item_count
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.customer_id = ? AND " . saleOwnerScopeSql($user['owner_id'], 's', 'u') . "$ownScope
    ORDER BY s.created_at DESC
");
$stmt->execute(array_merge([$customerId, $user['owner_id'], $user['owner_id']], $ownParams));
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
<div class="stats-grid">
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
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <!-- A real empty state, not one grey line. Most customers in a
                         young shop have never bought, so this is the common case
                         rather than an edge case - and a single English sentence on
                         an otherwise Bengali page reads as a failed load rather
                         than as a fact about the customer. -->
                    <tr><td colspan="6">
                        <div class="cust-empty">
                            <i class="fas fa-receipt"></i>
                            <p class="mb-1"><strong><?php echo sanitize($customer['name']); ?></strong>
                                ekhono kono purchase kore nai.</p>
                            <p class="mb-0">Ei customer er kono sale record nai, tai kono invoice dekhano
                                jay na. Jodi eshe kine, ei page khulei invoice dekhabe.</p>
                        </div>
                    </td></tr>
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
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
