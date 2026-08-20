<?php
/**
 * POS System - Reports
 * Sales reports and analytics
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Reports');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'];

// Get date range
$reportType = sanitize($_GET['type'] ?? 'daily');
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));

// Calculate reports based on type
switch ($reportType) {
    case 'monthly':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        break;
    case 'yearly':
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-12-31');
        break;
}

// Sales summary - Filter by owner
$stmt = $db->prepare("
    SELECT 
        COUNT(s.id) as total_transactions,
        COALESCE(SUM(s.total), 0) as total_sales,
        COALESCE(SUM(s.discount_amount), 0) as total_discount,
        COALESCE(SUM(s.vat_amount), 0) as total_vat,
        COALESCE(AVG(s.total), 0) as avg_sale
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$summary = $stmt->fetch();

// Sales by payment method - Filter by owner
$stmt = $db->prepare("
    SELECT s.payment_method, COUNT(s.id) as count, COALESCE(SUM(s.total), 0) as total
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY s.payment_method
    ORDER BY total DESC
");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$paymentStats = $stmt->fetchAll();

// Daily sales chart data - Filter by owner
$stmt = $db->prepare("
    SELECT DATE(s.created_at) as sale_date, COALESCE(SUM(s.total), 0) as daily_total
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY DATE(s.created_at)
    ORDER BY sale_date
");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$dailySales = $stmt->fetchAll();

// Top selling products - Filter by owner
$stmt = $db->prepare("
    SELECT si.product_name, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN users u ON s.user_id = u.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY si.product_id, si.product_name
    ORDER BY total_qty DESC
    LIMIT 10
");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$topProducts = $stmt->fetchAll();

// Sales by category - Filter by owner
$stmt = $db->prepare("
    SELECT c.name as category_name, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    JOIN users u ON s.user_id = u.id
    JOIN products p ON si.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY p.category_id, c.name
    ORDER BY total_revenue DESC
");
$stmt->execute([$ownerId, $dateFrom, $dateTo]);
$categoryStats = $stmt->fetchAll();

// Hourly sales (for today if daily report)
if ($reportType === 'daily' && $dateFrom === date('Y-m-d')) {
    $stmt = $db->prepare("
        SELECT HOUR(created_at) as hour, COUNT(*) as count, COALESCE(SUM(total), 0) as total
        FROM sales 
        WHERE DATE(created_at) = ?
        GROUP BY HOUR(created_at)
        ORDER BY hour
    ");
    $stmt->execute([$dateFrom]);
    $hourlyStats = $stmt->fetchAll();
} else {
    $hourlyStats = [];
}

include 'includes/header.php';
?>

<!-- Report Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Report Type</label>
                <select name="type" class="form-control" onchange="this.form.submit()">
                    <option value="daily" <?php echo $reportType === 'daily' ? 'selected' : ''; ?>>Daily Report</option>
                    <option value="monthly" <?php echo $reportType === 'monthly' ? 'selected' : ''; ?>>Monthly Report</option>
                    <option value="yearly" <?php echo $reportType === 'yearly' ? 'selected' : ''; ?>>Yearly Report</option>
                    <option value="custom" <?php echo $reportType === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>
            <?php if ($reportType === 'custom' || $reportType === 'daily'): ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sync"></i> Generate
            </button>
            <a href="api/export-report.php?type=<?php echo $reportType; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&format=xls" class="btn btn-secondary">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <a href="api/export-report.php?type=<?php echo $reportType; ?>&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&format=pdf" class="btn btn-secondary" target="_blank">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Sales</div>
            <div class="stat-value"><?php echo formatCurrency($summary['total_sales']); ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo $summary['total_transactions']; ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-calculator"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Average Sale</div>
            <div class="stat-value"><?php echo formatCurrency($summary['avg_sale']); ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-undo"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Returns</div>
            <!-- Calculate total returns for period -->
            <?php
            $stmt = $db->prepare("SELECT COALESCE(SUM(r.total_amount), 0) as total FROM returns r JOIN users u ON r.user_id = u.id WHERE u.owner_id = ? AND DATE(r.created_at) BETWEEN ? AND ?");
            $stmt->execute([$ownerId, $dateFrom, $dateTo]);
            $returnTotal = $stmt->fetchColumn();
            ?>
            <div class="stat-value"><?php echo formatCurrency($returnTotal); ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-calculator"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Net Sales</div>
            <div class="stat-value"><?php echo formatCurrency($summary['total_sales'] - $returnTotal); ?></div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Top Selling Products -->
    <?php
    // Top selling products (deducting returns)
    $stmt = $db->prepare("
        SELECT 
            si.product_name, 
            SUM(si.quantity) - COALESCE(SUM(ri.quantity), 0) as total_qty, 
            SUM(si.total_price) - COALESCE(SUM(ri.total_price), 0) as total_revenue
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        LEFT JOIN returns r ON s.id = r.sale_id
        LEFT JOIN return_items ri ON r.id = ri.return_id AND si.product_id = ri.product_id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        GROUP BY si.product_id, si.product_name
        HAVING total_qty > 0
        ORDER BY total_qty DESC
        LIMIT 10
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $topProducts = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-trophy text-warning"></i> Top Selling Products (Net)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Net Qty</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topProducts)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No data</td></tr>
                    <?php else: ?>
                        <?php foreach ($topProducts as $i => $product): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo sanitize($product['product_name']); ?></td>
                                <td><span class="badge badge-primary"><?php echo $product['total_qty']; ?></span></td>
                                <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Sales by Payment Method -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-credit-card"></i> Payment Methods</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Method</th>
                        <th>Transactions</th>
                        <th>Amount</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paymentStats)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No data</td></tr>
                    <?php else: ?>
                        <?php foreach ($paymentStats as $stat): ?>
                            <?php $percentage = $summary['total_sales'] > 0 ? ($stat['total'] / $summary['total_sales']) * 100 : 0; ?>
                            <tr>
                                <td>
                                    <span class="badge badge-<?php echo $stat['payment_method'] === 'cash' ? 'success' : 'primary'; ?>">
                                        <?php echo ucfirst($stat['payment_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo $stat['count']; ?></td>
                                <td><?php echo formatCurrency($stat['total']); ?></td>
                                <td>
                                    <div style="background: var(--gray-200); border-radius: 4px; height: 8px; width: 100px;">
                                        <div style="background: var(--primary); border-radius: 4px; height: 100%; width: <?php echo $percentage; ?>%;"></div>
                                    </div>
                                    <small><?php echo number_format($percentage, 1); ?>%</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Sales by Category -->
    <?php
    // Sales by category (deducting returns)
    $stmt = $db->prepare("
        SELECT 
            c.name as category_name, 
            SUM(si.quantity) - COALESCE(SUM(ri.quantity), 0) as total_qty, 
            SUM(si.total_price) - COALESCE(SUM(ri.total_price), 0) as total_revenue
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN returns r ON s.id = r.sale_id
        LEFT JOIN return_items ri ON r.id = ri.return_id AND si.product_id = ri.product_id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        GROUP BY p.category_id, c.name
        HAVING total_revenue > 0
        ORDER BY total_revenue DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $categoryStats = $stmt->fetchAll();
    ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-tags"></i> Sales by Category (Net)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Items Sold</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categoryStats)): ?>
                        <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
                    <?php else: ?>
                        <?php foreach ($categoryStats as $stat): ?>
                            <tr>
                                <td><?php echo sanitize($stat['category_name'] ?? 'Uncategorized'); ?></td>
                                <td><?php echo $stat['total_qty']; ?></td>
                                <td><?php echo formatCurrency($stat['total_revenue']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Daily Sales Chart -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-chart-line"></i> Sales Trend</h3>
        </div>
        <div class="card-body">
            <?php if (empty($dailySales)): ?>
                <p class="text-center text-muted">No data available</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php 
                    $maxSale = max(array_column($dailySales, 'daily_total'));
                    foreach ($dailySales as $day): 
                        $percentage = $maxSale > 0 ? ($day['daily_total'] / $maxSale) * 100 : 0;
                    ?>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span style="width: 80px; font-size: 0.75rem;"><?php echo date('d M', strtotime($day['sale_date'])); ?></span>
                            <div style="flex: 1; background: var(--gray-200); border-radius: 4px; height: 20px;">
                                <div style="background: linear-gradient(90deg, var(--primary), var(--success)); border-radius: 4px; height: 100%; width: <?php echo $percentage; ?>%;"></div>
                            </div>
                            <span style="width: 80px; font-size: 0.75rem; text-align: right;"><?php echo formatCurrency($day['daily_total']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
