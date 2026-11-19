<?php
/**
 * Admin Header Component
 * Contains sidebar and top navigation
 */

if (!defined('PAGE_TITLE')) {
    define('PAGE_TITLE', 'Dashboard');
}

// The menu table itself lives in config/nav-menu.php, because the staff app reads
// the same rows through mobile_api/menu.php - two copies of a menu is how one
// screen gains an entry the other refuses.
require_once __DIR__ . '/../../config/nav-menu.php';

$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Plan / role feature gate for direct URL access.
//
// The sidebar already hides modules the shop's package does not include, but most
// pages only checked isLoggedIn(), so a hidden feature was still reachable by
// typing its URL. This is the one place that closes that: each module page maps to
// the permission it needs, and hasPermission() applies both the plan gate and the
// role's own permissions. Anything not listed (dashboard, settings, subscription,
// utility pages) is left open on purpose.
$pagePerm = [
    'pos'              => 'pos',
    'products'         => 'products',
    'categories'       => 'categories',
    'variables'        => 'variables',
    'stock'            => 'stock',
    'transfers'        => 'transfers',
    'suppliers'        => 'suppliers',
    'purchases'        => 'suppliers',
    'purchase-add'     => 'suppliers',
    'purchase-view'    => 'suppliers',
    'purchase-print'   => 'suppliers',
    'purchase-returns' => 'suppliers',
    'purchase-report'  => 'suppliers',
    'supplier-dues'    => 'suppliers',
    'supplier-ledger'  => 'suppliers',
    'sales'            => 'sales',
    'lottery-sales'    => 'sales',
    'returns'          => 'returns',
    'reports'          => 'reports',
    'discount-report'  => 'reports',
    'expense'          => 'cashbook',
    'cashbook'         => 'cashbook',
    'customers'        => 'customers',
    'customer-history' => 'customers',
    'google-contacts'  => 'google_contacts',
    'marketing'        => 'marketing',
    'sms-service'      => 'marketing',
    'users'            => 'users',
    'stores'           => 'stores',
    'staff'            => 'staff',
    'roles'            => 'roles',
    'barcode-settings' => 'barcode_settings',
    'print-labels'     => 'barcode_settings',
    'voucher-settings' => 'vouchers',
    'vouchers'         => 'vouchers',
    'ecommerce'        => 'ecommerce',
];
if (isset($pagePerm[$currentPage])) {
    // Through the same guard the pages themselves use, so one denied click gives
    // one answer: an owner is offered the package that includes it, and a staff
    // member - who cannot buy anything - is told their role lacks it and sent
    // back to their dashboard.
    requirePermission($pagePerm[$currentPage]);
}

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

// Soft subscription warning. Never blocks anything - it only nudges the people
// who can pay to renew when the plan is expired or about to expire.
$subWarn = null;
try {
    require_once dirname(__DIR__, 2) . '/config/subscription.php';
    $subOwnerId = (int)($user['owner_id'] ?? $user['id']);
    if (hasRole(['admin', 'owner', 'manager'])) {
        subEnsureTables($db);
        $st = subStatus($db, $subOwnerId);
        if ($st['state'] === 'expired' && $st['expires_at']) {
            $subWarn = ['bad', 'আপনার <b>' . $st['plan_name'] . '</b> প্ল্যানের মেয়াদ ' . date('d M Y', strtotime($st['expires_at'])) . '-এ শেষ হয়েছে। সিস্টেম চলছে, তবে নির্বিঘ্নে ব্যবহারের জন্য রিনু করুন।'];
        } elseif ($st['state'] === 'expiring' && $st['expires_at']) {
            $subWarn = ['warn', 'আপনার <b>' . $st['plan_name'] . '</b> প্ল্যানের মেয়াদ ' . date('d M Y', strtotime($st['expires_at'])) . ' পর্যন্ত (' . (int)$st['days_left'] . ' দিন বাকি)।'];
        }
    }
} catch (Exception $e) {
    $subWarn = null;
}

/* Staff app download, read from the same manifest the landing page and the
   dashboard use. basename() on purpose: the manifest value never reaches a
   path here as anything but a file name. */
$apk = ['file' => '', 'version' => '', 'href' => ''];
try {
    $mfRaw = @file_get_contents(dirname(__DIR__, 2) . '/downloads/manifest.json');
    $mf = $mfRaw ? json_decode($mfRaw, true) : null;
    if (is_array($mf) && !empty($mf['android']['available'])) {
        $rel = basename((string)($mf['android']['file'] ?? ''));
        if ($rel !== '' && is_file(dirname(__DIR__, 2) . '/downloads/android/' . $rel)) {
            $apk['file']    = $rel;
            $apk['version'] = (string)($mf['android']['version'] ?? '');
            $apk['href']    = '../downloads/android/' . rawurlencode($rel);
        }
    }
} catch (Exception $e) {
    $apk = ['file' => '', 'version' => '', 'href' => ''];
}

/* ── Sidebar menu ─────────────────────────────────────────────────────────
   The rows themselves live in config/nav-menu.php, because the staff app reads
   that same table through mobile_api/menu.php. Two copies of a menu is how one
   screen ends up offering an entry the other hides, so there is one list here
   and one visibility rule (navItemVisible) for both.
   navIconHtml() and renderSidebarNav() below stay: they are how a phone-width
   browser is handed the table, not what decides what is in it. */
$navGroups = navGroups();

function navIconHtml(array $item)
{
    $tint = isset($item['tint']) ? ' style="color:' . htmlspecialchars($item['tint']) . ';"' : '';
    return '<span class="nav-ico"><i class="' . htmlspecialchars($item['icon']) . '"' . $tint . '></i></span>';
}

function renderSidebarNav(array $groups, $currentPage)
{
    $html = '';
    foreach ($groups as $g) {
        $kids = [];
        foreach (isset($g['children']) ? $g['children'] : [] as $c) {
            if (navItemVisible($c)) {
                $kids[] = $c;
            }
        }
        $selfOk      = navItemVisible($g);
        $selfActive  = $selfOk && $currentPage === $g['page'];
        $kidActive   = false;
        foreach ($kids as $c) {
            if ($currentPage === $c['page']) {
                $kidActive = true;
            }
        }

        if (!$selfOk && !$kids) {
            continue;
        }

        $html .= '<div class="nav-group' . (($selfActive || $kidActive) ? ' open has-active' : '') . '"'
              .  ' data-nav="' . htmlspecialchars($g['key']) . '">';

        $inner = navIconHtml($g) . '<span class="nav-label">' . htmlspecialchars($g['label']) . '</span>';
        $row = '';
        if ($selfOk) {
            $row .= '<a href="' . htmlspecialchars($g['href']) . '" class="nav-item nav-parent'
                 .  ($selfActive ? ' active' : '') . '">' . $inner . '</a>';
        } else {
            $row .= '<button type="button" class="nav-item nav-parent" data-nav-toggle="1"'
                 .  ' aria-expanded="' . (($selfActive || $kidActive) ? 'true' : 'false') . '">'
                 .  $inner . '</button>';
        }
        if ($kids) {
            $row .= '<button type="button" class="nav-caret" data-nav-toggle="1"'
                 .  ' aria-expanded="' . (($selfActive || $kidActive) ? 'true' : 'false') . '"'
                 .  ' title="Show / hide"><i class="fas fa-chevron-right"></i></button>';
        }
        $html .= '<div class="nav-row">' . $row . '</div>';

        if ($kids) {
            $html .= '<div class="nav-sub">';
            foreach ($kids as $c) {
                $html .= '<a href="' . htmlspecialchars($c['href']) . '" class="nav-item nav-child'
                      .  ($currentPage === $c['page'] ? ' active' : '') . '">'
                      .  navIconHtml($c) . '<span class="nav-label">' . htmlspecialchars($c['label']) . '</span></a>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
    }
    return $html;
}
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <!--
        Declared so the browser stops asking for /favicon.ico and getting a 404.
        It only falls back to that path when no icon is named, and the 404 shows
        up in the console on every single page - looking like a broken build
        rather than a missing decoration.
    -->
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
        /*
            Desktop notification when a sale is rung up on the mobile app.

            Included here rather than per page because every admin page already
            pulls in this header, so one edit covers the POS, the dashboard and
            the sales list. assetUrl() stamps a cache-busting query on it, which
            matters: a fixed JavaScript file otherwise stays in the browser cache
            indefinitely and an edit appears to have done nothing.

            It is a small, self-contained script with no dependency on the rest of
            the page, and it swallows its own errors - an unreachable poller must
            never take the POS down with it.
        */
        document.addEventListener('DOMContentLoaded', function () {
            var s = document.createElement('script');
            s.src = <?php echo json_encode(assetUrl('assets/js/sale-notify.js')); ?>;
            s.async = true;
            document.head.appendChild(s);
        });
    </script>
    <script>
        /* Apply the saved admin theme before first paint so a dark-mode user
           never sees a white flash. Same key the header toggle writes. */
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('pos.admin.theme') || 'light'; } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body>
    <div class="app-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="<?php echo assetUrl('assets/img/ava_logo_mark.png'); ?>" alt="">
                </div>
                <div class="sidebar-brand">
                    <span class="sidebar-title"><?php echo htmlspecialchars($shopName); ?></span>
                    <span class="sidebar-sub">Smart Collection</span>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php echo renderSidebarNav($navGroups, $currentPage); ?>
            </nav>

            <div class="sidebar-footer">
                <?php if ($apk['file']): ?>
                <div class="side-app">
                    <div class="sa-top">
                        <span class="sa-phone"><i class="fas fa-mobile-screen-button"></i></span>
                        <div class="sa-copy">
                            <strong>Android App<?php echo $apk['version'] ? ' v' . htmlspecialchars($apk['version']) : ''; ?></strong>
                            <small>Manage your business from anywhere.</small>
                        </div>
                        <i class="fab fa-android sa-droid"></i>
                    </div>
                    <a class="sa-btn" href="<?php echo htmlspecialchars($apk['href']); ?>" download>
                        <i class="fas fa-download"></i> Download Now
                    </a>
                </div>
                <?php endif; ?>

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

                <div class="side-credit">
                    <img src="<?php echo assetUrl('assets/img/ava_logo_mark.png'); ?>" alt="">
                    <span>
                        <strong>AVA IT Solution</strong>
                        <small>Smart Collection</small>
                    </span>
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
                    <button class="theme-toggle" id="adminThemeToggle" type="button" title="Toggle light / dark" aria-label="Toggle light or dark theme">
                        <i class="fas fa-moon" id="adminThemeIcon"></i>
                    </button>
                    <button class="header-btn" id="fullscreenBtn" title="Fullscreen">
                        <i class="fas fa-expand"></i>
                    </button>
                    <a href="../logout.php" class="header-btn" title="Logout">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </header>
            <script>
                /* Light/dark switch for the whole admin panel. Persisted per
                   browser; the <head> bootstrap applies it on the next load. */
                (function () {
                    var btn = document.getElementById('adminThemeToggle');
                    var icon = document.getElementById('adminThemeIcon');
                    function apply(theme) {
                        document.documentElement.setAttribute('data-theme', theme);
                        if (icon) { icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon'; }
                        try { localStorage.setItem('pos.admin.theme', theme); } catch (e) {}
                    }
                    var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                    if (icon) { icon.className = current === 'dark' ? 'fas fa-sun' : 'fas fa-moon'; }
                    if (btn) {
                        btn.addEventListener('click', function () {
                            apply(current === 'dark' ? 'light' : 'dark');
                            current = current === 'dark' ? 'light' : 'dark';
                        });
                    }
                })();
            </script>

            <!-- Page Content -->
            <div class="content">
            <?php if (!empty($subWarn)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.9rem;<?php echo $subWarn[0] === 'bad' ? 'background:rgba(225,29,72,.1);border:1px solid rgba(225,29,72,.35);color:#9f1239;' : 'background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.4);color:#92400e;'; ?>">
                    <span><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i><?php echo $subWarn[1]; ?></span>
                    <a href="../home.php#pricing" style="background:#e11d48;color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;font-weight:700;white-space:nowrap;">রিনু / প্ল্যান দেখুন</a>
                </div>
            <?php endif; ?>