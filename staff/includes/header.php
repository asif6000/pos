<?php
/**
 * Staff Header Component
 * Simplified navigation for staff members
 */

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
        <?php echo PAGE_TITLE ?? 'Dashboard'; ?> - POS System
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
                    <i class="fas fa-user-tie"></i>
                </div>
                <span class="sidebar-title"><?php echo htmlspecialchars($shopName); ?></span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <a href="dashboard.php" class="nav-item <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>My Dashboard</span>
                    </a>
                </div>
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
                        <div class="user-role">Staff</div>
                    </div>
                    <a href="../logout.php" class="header-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <header class="header">
                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title">
                        <?php echo PAGE_TITLE ?? 'Dashboard'; ?>
                    </h1>
                </div>
                <div class="header-right">
                    <a href="../logout.php" class="header-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </header>

            <div class="content">