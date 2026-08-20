<?php
/**
 * Admin Header Component
 * Contains sidebar and top navigation
 */

if (!defined('PAGE_TITLE')) {
    define('PAGE_TITLE', 'Dashboard');
}

$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Get shop name from settings - filter by owner_id
$db = getDB();
$settings = [];
try {
    $ownerId = $user['owner_id'] ?? $user['id'];
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    // Fallback: if no settings found for owner, try without filter
    if (empty($settings)) {
        $stmt2 = $db->query("SELECT setting_key, setting_value FROM settings LIMIT 50");
        while ($row = $stmt2->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    $settings = [];
}
$shopName = $settings['shop_name'] ?? 'POS System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo PAGE_TITLE; ?> - POS System
    </title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-cash-register"></i>
                </div>
                <span class="sidebar-title"><?php echo htmlspecialchars($shopName); ?></span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <?php if (hasPermission('dashboard')): ?>
                    <a href="dashboard.php"
                        class="nav-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('pos')): ?>
                    <a href="pos.php" class="nav-item <?php echo $currentPage === 'pos' ? 'active' : ''; ?>">
                        <i class="fas fa-cash-register"></i>
                        <span>POS / Billing</span>
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (hasPermission('products') || hasPermission('categories') || hasPermission('stock') || hasPermission('transfers')): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Inventory</div>
                    <?php if (hasPermission('products')): ?>
                    <a href="products.php" class="nav-item <?php echo $currentPage === 'products' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i>
                        <span>Products</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('categories')): ?>
                    <a href="categories.php"
                        class="nav-item <?php echo $currentPage === 'categories' ? 'active' : ''; ?>">
                        <i class="fas fa-tags"></i>
                        <span>Categories</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('stock')): ?>
                    <a href="stock.php" class="nav-item <?php echo $currentPage === 'stock' ? 'active' : ''; ?>">
                        <i class="fas fa-warehouse"></i>
                        <span>Stock Management</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('transfers')): ?>
                    <a href="transfers.php" class="nav-item <?php echo $currentPage === 'transfers' ? 'active' : ''; ?>">
                        <i class="fas fa-exchange-alt"></i>
                        <span>Transfers</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (hasPermission('sales') || hasPermission('returns') || hasPermission('reports') || hasPermission('cashbook')): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Sales</div>
                    <?php if (hasPermission('sales')): ?>
                    <a href="sales.php" class="nav-item <?php echo $currentPage === 'sales' ? 'active' : ''; ?>">
                        <i class="fas fa-receipt"></i>
                        <span>Sales List</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('returns')): ?>
                    <a href="returns.php" class="nav-item <?php echo $currentPage === 'returns' ? 'active' : ''; ?>">
                        <i class="fas fa-undo"></i>
                        <span>Returns</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('reports')): ?>
                    <a href="reports.php" class="nav-item <?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('cashbook')): ?>
                    <a href="expense.php" class="nav-item <?php echo $currentPage === 'expense' ? 'active' : ''; ?>">
                        <i class="fas fa-book"></i>
                        <span>Expense</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (hasPermission('customers') || hasPermission('users') || hasPermission('stores') || hasPermission('roles') || hasPermission('settings') || hasPermission('barcode_settings') || hasPermission('vouchers')): ?>
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <?php if (hasPermission('customers')): ?>
                    <a href="customers.php"
                        class="nav-item <?php echo $currentPage === 'customers' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Customers</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('users')): ?>
                    <a href="users.php" class="nav-item <?php echo $currentPage === 'users' ? 'active' : ''; ?>">
                        <i class="fas fa-user-cog"></i>
                        <span>Users</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('stores')): ?>
                    <a href="stores.php" class="nav-item <?php echo $currentPage === 'stores' ? 'active' : ''; ?>">
                        <i class="fas fa-store"></i>
                        <span>Stores</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('staff')): ?>
                    <a href="staff.php" class="nav-item <?php echo $currentPage === 'staff' ? 'active' : ''; ?>">
                        <i class="fas fa-user-tie"></i>
                        <span>Staff</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('roles')): ?>
                    <a href="roles.php" class="nav-item <?php echo $currentPage === 'roles' ? 'active' : ''; ?>">
                        <i class="fas fa-user-tag"></i>
                        <span>Roles & Permissions</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('settings')): ?>
                    <a href="settings.php" class="nav-item <?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('barcode_settings')): ?>
                    <a href="barcode-settings.php"
                        class="nav-item <?php echo $currentPage === 'barcode-settings' ? 'active' : ''; ?>">
                        <i class="fas fa-barcode"></i>
                        <span>Barcode Settings</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('vouchers')): ?>
                    <a href="voucher-settings.php" class="nav-item <?php echo $currentPage === 'voucher-settings' ? 'active' : ''; ?>">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Voucher Settings</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </nav>

            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?php echo sanitize($user['name']); ?>
                        </div>
                        <div class="user-role">
                            <?php echo ucfirst($user['role']); ?>
                        </div>
                    </div>
                    <a href="../logout.php" class="header-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">
                        <?php echo PAGE_TITLE; ?>
                    </h1>
                </div>

                <nav class="header-shortcuts">
                    <?php if (hasPermission('pos')): ?>
                        <a href="pos.php" class="header-shortcut <?php echo $currentPage === 'pos' ? 'active' : ''; ?>" title="POS / Billing"><i class="fas fa-cash-register"></i><span>POS</span></a>
                    <?php endif; ?>
                    <?php if (hasPermission('products')): ?>
                        <a href="products.php" class="header-shortcut <?php echo $currentPage === 'products' ? 'active' : ''; ?>" title="Products"><i class="fas fa-box"></i><span>Products</span></a>
                    <?php endif; ?>
                    <?php if (hasPermission('sales')): ?>
                        <a href="sales.php" class="header-shortcut <?php echo $currentPage === 'sales' ? 'active' : ''; ?>" title="Sales"><i class="fas fa-receipt"></i><span>Sales</span></a>
                    <?php endif; ?>
                    <?php if (hasPermission('cashbook')): ?>
                        <a href="expense.php" class="header-shortcut <?php echo $currentPage === 'expense' ? 'active' : ''; ?>" title="Expense"><i class="fas fa-book"></i><span>Expense</span></a>
                    <?php endif; ?>
                    <?php if (hasPermission('reports')): ?>
                        <a href="reports.php" class="header-shortcut <?php echo $currentPage === 'reports' ? 'active' : ''; ?>" title="Reports"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                    <?php endif; ?>
                    <?php if (hasPermission('stock')): ?>
                        <a href="stock.php" class="header-shortcut <?php echo $currentPage === 'stock' ? 'active' : ''; ?>" title="Stock"><i class="fas fa-warehouse"></i><span>Stock</span></a>
                    <?php endif; ?>
                </nav>

                <div class="header-right">
                    <button class="header-btn" id="fullscreenBtn" title="Fullscreen">
                        <i class="fas fa-expand"></i>
                    </button>
                    <a href="../logout.php" class="header-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </header>

            <!-- Page Content -->
            <div class="content">