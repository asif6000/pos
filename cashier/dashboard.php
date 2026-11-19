<?php
/**
 * POS System - Cashier Dashboard
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'My Dashboard');

$db = getDB();
$user = getCurrentUser();

$cashierId = $user['id'];
$ownerId = $user['owner_id'] ?? $user['id'];

// Get Today's Sales for this cashier
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$stmt = $db->prepare("SELECT COUNT(id) as total_invoices, COALESCE(SUM(total), 0) as total_sales, COALESCE(SUM(discount_amount), 0) as total_discount FROM sales WHERE cashier_id = ? AND created_at BETWEEN ? AND ?");
$stmt->execute([$cashierId, $todayStart, $todayEnd]);
$todayStats = $stmt->fetch();

// Get This Month's Sales for this cashier
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

$stmt = $db->prepare("SELECT COUNT(id) as total_invoices, COALESCE(SUM(total), 0) as total_sales FROM sales WHERE cashier_id = ? AND created_at BETWEEN ? AND ?");
$stmt->execute([$cashierId, $monthStart, $monthEnd]);
$monthStats = $stmt->fetch();

// Get Recent Sales
$stmt = $db->prepare("SELECT * FROM sales WHERE cashier_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$cashierId]);
$recentSales = $stmt->fetchAll();

include 'includes/header.php';
?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">My Today's Sale</div>
            <div class="stat-value"><?php echo formatCurrency($todayStats['total_sales']); ?></div>
            <div class="stat-desc"><?php echo $todayStats['total_invoices']; ?> invoices</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">My Month's Sale</div>
            <div class="stat-value"><?php echo formatCurrency($monthStats['total_sales']); ?></div>
            <div class="stat-desc"><?php echo $monthStats['total_invoices']; ?> invoices</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-tags"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Today's Discount</div>
            <div class="stat-value"><?php echo formatCurrency($todayStats['total_discount']); ?></div>
        </div>
    </div>
</div>

<!-- Recent Sales -->
<div class="card" style="margin-top: 2rem;">
    <div class="card-header">
        <div class="card-title">My Recent Sales</div>
        <a href="sales.php" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Time</th>
                    <th>Total</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentSales)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No sales yet</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td><strong><?php echo sanitize($sale['invoice_number']); ?></strong></td>
                            <td><?php echo date('h:i A', strtotime($sale['created_at'])); ?></td>
                            <td><strong><?php echo formatCurrency($sale['total']); ?></strong></td>
                            <td><span class="badge badge-primary"><?php echo ucfirst($sale['payment_method']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
