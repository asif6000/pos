<?php
/**
 * POS System - Stock Management
 * Track stock levels and manage inventory
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Stock Management');

$db = getDB();
$currentUser = getCurrentUser();

$ownerId = (int)$currentUser['owner_id'];
$isSuper = isSuperAdminUser($currentUser);
$userStoreId = $currentUser['store_id'];

// Every active store this account may see and act on. A store-bound account is
// confined to its own; an owner/admin sees its own shops; the platform account
// sees every shop. It is also the list the posted store is validated against,
// so stock can never be moved through a crafted form.
if ($isSuper) {
    $ownerStores = $db->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
    $ownerStores = $stmt->fetchAll();
}
$ownerStoreIds = array_map('intval', array_column($ownerStores, 'id'));

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $type = sanitize($_POST['type'] ?? 'adjustment');
    $note = sanitize($_POST['note'] ?? '');
    
    // Determine Store ID (CurrentUser's store or the chosen one from the form)
    if ($userStoreId) {
        $store_id = (int)$userStoreId;
    } else {
        $postedStore = (int)($_POST['store_id'] ?? 0);
        $store_id = in_array($postedStore, $ownerStoreIds, true)
            ? $postedStore
            : ($ownerStoreIds ? $ownerStoreIds[0] : 0);
    }
    
    if ($productId && $quantity != 0 && $store_id) {
        try {
            // A 'purchase' adjustment books a Cash Out in the cashbook further
            // down, and that table's runtime migration is DDL - which cannot run
            // once the transaction below is open, because MySQL would commit the
            // stock change with it. So the schema is settled first.
            ensureCashbookWriteReady();
            $db->beginTransaction();
            
            if ($type === 'transfer') {
                $toStoreId = (int)($_POST['to_store_id'] ?? 0);
                if (!$toStoreId) throw new Exception("Destination store is required for transfer");
                if ($toStoreId === $store_id) throw new Exception("Source and Destination stores cannot be the same");
                if (!in_array($toStoreId, $ownerStoreIds, true)) throw new Exception("Destination store is not available");
                
                // Absolute quantity for transfer logic
                $transferQty = abs($quantity);

                $stmt = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ? FOR UPDATE");
                $stmt->execute([$store_id, $productId]);
                $sourceStock = $stmt->fetchColumn();

                if ($sourceStock === false) {
                    $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)")
                        ->execute([$store_id, $productId]);
                    $sourceStock = 0;
                }

                if ($sourceStock < $transferQty) {
                    throw new Exception("Insufficient stock in source store");
                }

                $stmt = $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?");
                $stmt->execute([$transferQty, $store_id, $productId]);

                // 3. Add to Destination
                $stmt = $db->prepare("SELECT 1 FROM store_stocks WHERE store_id = ? AND product_id = ?");
                $stmt->execute([$toStoreId, $productId]);
                if (!$stmt->fetch()) {
                    $stmt = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
                    $stmt->execute([$toStoreId, $productId]);
                }
                $stmt = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
                $stmt->execute([$transferQty, $toStoreId, $productId]);

                // 4. Log History
                $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, note, user_id) VALUES (?, ?, 'transfer', ?, ?)");
                $stmt->execute([$productId, -$transferQty, "Transfer OUT to Store $toStoreId. $note", $currentUser['id']]);
                $stmt->execute([$productId, $transferQty, "Transfer IN from Store $store_id. $note", $currentUser['id']]);

            } else {
                // Check if record exists in store_stocks
                $stmt = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
                $stmt->execute([$store_id, $productId]);
                $exists = $stmt->fetch();
                
                if (!$exists) {
                    $stmt = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, 0)");
                    $stmt->execute([$store_id, $productId]);
                }
                
                $stmt = $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?");
                $stmt->execute([$quantity, $store_id, $productId]);
                
                $noteWithStore = "Store: $store_id. " . $note;
                $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, note, user_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$productId, $quantity, $type, $noteWithStore, $currentUser['id']]);

                // Auto Cash Out entry for stock purchases (positive qty only)
                if ($type === 'purchase' && $quantity > 0) {
                    $stmtBuy = $db->prepare("SELECT name, buy_price FROM products WHERE id = ?");
                    $stmtBuy->execute([$productId]);
                    $prodBuy = $stmtBuy->fetch();
                    if ($prodBuy) {
                        $purchaseAmount = (float) $prodBuy['buy_price'] * $quantity;
                        if ($purchaseAmount > 0) {
                            addAutoCashbookEntry('cash_out', $purchaseAmount, 'Purchase: ' . $prodBuy['name'] . ' x' . $quantity, 'purchase', $productId);
                        }
                    }
                }
            }
            
            $db->commit();
            setFlash('success', 'Stock updated successfully!');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
    }
    redirect('stock.php');
}

// Get products with stock info (scoped to store)
$search = sanitize($_GET['search'] ?? '');
$filter = sanitize($_GET['filter'] ?? '');
$view_store_id = (int)($_GET['view_store'] ?? 0);

// Logic:
// If Store Admin: Always view their store
// If Global Admin:
//    - If view_store_id is set (and is one of their shops), view that store
//    - If view_store_id is 0 (All), view aggregated global stock

if ($userStoreId) {
    $store_id = (int)$userStoreId;
    $isGlobalView = false;
} elseif ($view_store_id > 0 && in_array($view_store_id, $ownerStoreIds, true)) {
    $store_id = $view_store_id;
    $isGlobalView = false;
} else {
    // The first shop is only a default for the adjustment form; the list itself
    // stays aggregated because the global view is what was asked for.
    $store_id = $ownerStoreIds ? $ownerStoreIds[0] : 0;
    $isGlobalView = true;
}

$stores = $ownerStores;

// Products and their real quantity for this view.
//
// store_stocks is the ledger every movement writes to (purchase, sale, sale
// edit/delete, return, exchange, transfer, manual adjustment), so summing it is
// the live figure. products.stock is only a legacy mirror kept by purchases.php
// and goes stale the moment a sale happens - which is why the list used to show
// quantities that did not match the movement history.
//
// LEFT JOIN, not JOIN: a product with no row in the selected store still exists
// and its quantity there is genuinely 0. The old INNER JOIN dropped those rows
// from the page entirely.
$sqlParams = [];
if ($isGlobalView) {
    // The total a shop sees is the sum across its own active stores, matching
    // the store-wise summary below. A row left in another shop's store - or in
    // a branch that has since been closed - cannot inflate this figure.
    $storeJoin = "LEFT JOIN store_stocks ss ON p.id = ss.product_id
            LEFT JOIN stores st ON st.id = ss.store_id AND st.status = 'active'" . ($isSuper ? "" : " AND st.owner_id = ?");
    $sql = "SELECT p.*, c.name as category_name, COALESCE(SUM(CASE WHEN st.id IS NOT NULL THEN ss.quantity ELSE 0 END), 0) as current_stock 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            $storeJoin
            WHERE p.status = 'active'";
    $groupBy = " GROUP BY p.id";
    if (!$isSuper) { $sqlParams[] = $ownerId; }
} else {
    $sql = "SELECT p.*, c.name as category_name, COALESCE(ss.quantity, 0) as current_stock 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
            WHERE p.status = 'active'";
    $sqlParams[] = $store_id;
    $groupBy = "";
}

// Tenant scope. The platform account owns no shop and is the only caller that
// may read every shop's rows.
if (!$isSuper) {
    $sql .= " AND p.owner_id = ?";
    $sqlParams[] = $ownerId;
}

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $sqlParams[] = "%$search%";
    $sqlParams[] = "%$search%";
}

// HAVING has to follow GROUP BY, so the aggregate view appends it with the
// group clause rather than before it (the old order was a syntax error whenever
// either stock filter was used on the global view).
if ($isGlobalView) {
    if ($filter === 'low') $sql .= $groupBy . " HAVING current_stock <= p.min_stock";
    elseif ($filter === 'out') $sql .= $groupBy . " HAVING current_stock = 0";
    else $sql .= $groupBy;
} else {
    if ($filter === 'low') $sql .= " AND COALESCE(ss.quantity, 0) <= p.min_stock";
    elseif ($filter === 'out') $sql .= " AND COALESCE(ss.quantity, 0) = 0";
}

$sql .= " ORDER BY current_stock ASC, p.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($sqlParams);
$products = $stmt->fetchAll();

// Summary stats
$totalProducts = 0;
if ($isGlobalView) {
    $countSql = "SELECT COUNT(*) FROM products p WHERE p.status = 'active'" . ($isSuper ? "" : " AND p.owner_id = ?");
    $stmt = $db->prepare($countSql);
    $stmt->execute($isSuper ? [] : [$ownerId]);
    $totalProducts = (int)$stmt->fetchColumn();

    // Global Stats: per-product aggregate, then count the rows that match.
    $innerFrom = "FROM products p
                  $storeJoin
                  WHERE p.status = 'active'" . ($isSuper ? "" : " AND p.owner_id = ?");
    $innerParams = $isSuper ? [] : [$ownerId, $ownerId];

    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT p.id, COALESCE(SUM(CASE WHEN st.id IS NOT NULL THEN ss.quantity ELSE 0 END), 0) stock, p.min_stock
        $innerFrom GROUP BY p.id) t WHERE stock <= min_stock");
    $stmt->execute($innerParams);
    $lowStockCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT p.id, COALESCE(SUM(CASE WHEN st.id IS NOT NULL THEN ss.quantity ELSE 0 END), 0) stock
        $innerFrom GROUP BY p.id) t WHERE stock = 0");
    $stmt->execute($innerParams);
    $outOfStockCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(stock * buy_price), 0) FROM (
        SELECT p.buy_price, COALESCE(SUM(CASE WHEN st.id IS NOT NULL THEN ss.quantity ELSE 0 END), 0) as stock
        $innerFrom GROUP BY p.id) t");
    $stmt->execute($innerParams);
    $totalStockValue = (float)$stmt->fetchColumn();
} else {
    $scopeSql = "FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
                 WHERE p.status = 'active'" . ($isSuper ? "" : " AND p.owner_id = ?");
    $scopeParams = $isSuper ? [$store_id] : [$store_id, $ownerId];

    $stmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE p.status = 'active'" . ($isSuper ? "" : " AND p.owner_id = ?"));
    $stmt->execute($isSuper ? [] : [$ownerId]);
    $totalProducts = (int)$stmt->fetchColumn();

    // Store Stats
    $stmt = $db->prepare("SELECT COUNT(*) $scopeSql AND COALESCE(ss.quantity, 0) <= p.min_stock");
    $stmt->execute($scopeParams);
    $lowStockCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) $scopeSql AND COALESCE(ss.quantity, 0) = 0");
    $stmt->execute($scopeParams);
    $outOfStockCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(ss.quantity, 0) * p.buy_price), 0) $scopeSql");
    $stmt->execute($scopeParams);
    $totalStockValue = (float)$stmt->fetchColumn();
}

// Store-wise summary: one row per shop, counting only that shop's own rows.
// The product join keeps inactive / other-owner products out of the totals, so
// a row left behind by a deleted product cannot inflate a store's quantity.
$summaryJoin = "LEFT JOIN products p ON p.id = ss.product_id AND p.status = 'active'";
$summaryWhere = "st.status = 'active'";
$summaryParams = [];
if (!$isSuper) {
    $summaryJoin .= " AND p.owner_id = ?";
    $summaryParams[] = $ownerId;
    $summaryWhere .= " AND st.owner_id = ?";
    $summaryParams[] = $ownerId;
}
if ($userStoreId) {
    $summaryWhere .= " AND st.id = ?";
    $summaryParams[] = (int)$userStoreId;
}

$stmt = $db->prepare("SELECT st.id, st.name,
        COUNT(p.id) AS product_types,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL THEN ss.quantity ELSE 0 END), 0) AS total_qty,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL THEN ss.quantity * p.buy_price ELSE 0 END), 0) AS stock_value,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL AND ss.quantity <= p.min_stock THEN 1 ELSE 0 END), 0) AS low_count,
        COALESCE(SUM(CASE WHEN p.id IS NOT NULL AND ss.quantity = 0 THEN 1 ELSE 0 END), 0) AS out_count
    FROM stores st
    LEFT JOIN store_stocks ss ON ss.store_id = st.id
    $summaryJoin
    WHERE $summaryWhere
    GROUP BY st.id, st.name
    ORDER BY st.name ASC");
$stmt->execute($summaryParams);
$storeSummary = $stmt->fetchAll();

include 'includes/header.php';
?>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Products</div>
            <div class="stat-value"><?php echo $totalProducts; ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon yellow">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Low Stock</div>
            <div class="stat-value"><?php echo $lowStockCount; ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value"><?php echo $outOfStockCount; ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Stock Value</div>
            <div class="stat-value"><?php echo formatCurrency($totalStockValue); ?></div>
        </div>
    </div>
</div>

<!-- Store-Wise Stock Summary -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-store"></i> Store-Wise Stock Summary</h3>
        <a href="store-stock-details.php" class="btn btn-sm btn-outline">
            <i class="fas fa-list"></i> View Details
        </a>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Store</th>
                    <th>Product Types</th>
                    <th>Available Qty (Pcs)</th>
                    <th>Stock Value</th>
                    <th>Low Stock</th>
                    <th>Out of Stock</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($storeSummary)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No stores found</td></tr>
                <?php else: ?>
                    <?php foreach ($storeSummary as $row): ?>
                        <?php $rowQty = (int)$row['total_qty']; ?>
                        <tr>
                            <td><strong><?php echo sanitize($row['name']); ?></strong></td>
                            <td><?php echo number_format((int)$row['product_types']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $rowQty > 0 ? 'success' : ($rowQty === 0 ? 'danger' : 'warning'); ?>">
                                    <?php echo number_format($rowQty); ?> pcs
                                </span>
                            </td>
                            <td><?php echo formatCurrency((float)$row['stock_value']); ?></td>
                            <td>
                                <?php if ((int)$row['low_count'] > 0): ?>
                                    <span class="badge badge-warning"><?php echo (int)$row['low_count']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$row['out_count'] > 0): ?>
                                    <span class="badge badge-danger"><?php echo (int)$row['out_count']; ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline" href="store-stock-details.php?store_id=<?php echo (int)$row['id']; ?>">
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin: 1.5rem 0;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?php echo $search; ?>">
            </div>
            
            <?php if (!$userStoreId): ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Store View</label>
                <select name="view_store" class="form-control">
                    <option value="0" <?php echo $view_store_id == 0 ? 'selected' : ''; ?>>All Stores (Global)</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo $view_store_id == $store['id'] ? 'selected' : ''; ?>><?php echo sanitize($store['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Filter</label>
                <select name="filter" class="form-control">
                    <option value="">All Products</option>
                    <option value="low" <?php echo $filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out" <?php echo $filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="stock.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Min Stock</th>
                    <th>Status</th>
                    <th>Value</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No products found</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <strong><?php echo sanitize($product['name']); ?></strong>
                                <?php if ($product['barcode']): ?>
                                    <br><small class="text-muted"><?php echo $product['barcode']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo sanitize($product['category_name'] ?? 'Uncategorized'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $product['current_stock'] == 0 ? 'danger' : ($product['current_stock'] <= $product['min_stock'] ? 'warning' : 'success'); ?>">
                                    <?php echo $product['current_stock']; ?> <?php echo $product['unit']; ?>
                                </span>
                            </td>
                            <td><?php echo $product['min_stock']; ?></td>
                            <td>
                                <?php if ($product['current_stock'] == 0): ?>
                                    <span class="badge badge-danger">Out of Stock</span>
                                <?php elseif ($product['current_stock'] <= $product['min_stock']): ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-success">In Stock</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatCurrency($product['current_stock'] * $product['buy_price']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="adjustStock(<?php echo $product['id']; ?>, '<?php echo sanitize($product['name']); ?>', <?php echo $product['current_stock']; ?>)">
                                    <i class="fas fa-plus-minus"></i> Adjust
                                </button>
                                <?php if ($product['current_stock'] > 0): ?>
                                    <button class="btn btn-sm btn-info" onclick="initiateTransfer(<?php echo $product['id']; ?>, '<?php echo addslashes(sanitize($product['name'])); ?>', <?php echo $product['current_stock']; ?>, <?php echo $isGlobalView ? 'true' : 'false'; ?>)">
                                        <i class="fas fa-exchange-alt"></i> Transfer
                                    </button>
                                <?php endif; ?>
                                
                                <button class="btn btn-sm btn-outline" onclick="viewHistory(<?php echo $product['id']; ?>)">
                                    <i class="fas fa-history"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal-overlay" id="adjustModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Adjust Stock</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="product_id" id="adjustProductId">
                <p><strong id="adjustProductName"></strong></p>
                <p class="text-muted">Current Stock: <span id="currentStock">0</span></p>
                
                <?php if (!$currentUser['store_id']): ?>
                <div class="form-group">
                    <label class="form-label required">Store</label>
                    <select name="store_id" id="adjustStore" class="form-control">
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo sanitize($store['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label required">Adjustment Type</label>
                    <select name="type" id="adjustType" class="form-control" required>
                        <option value="purchase">Add Stock (Purchase)</option>
                        <option value="adjustment">Adjustment</option>
                        <option value="return">Return</option>
                        <option value="transfer">Transfer Stock</option>
                    </select>
                </div>

                <div class="form-group" id="adjustToStoreGroup" style="display: none;">
                    <label class="form-label required">To Store (Destination)</label>
                    <select name="to_store_id" id="adjustToStore" class="form-control">
                        <option value="">Select Destination</option>
                        <?php 
                        // Same owner-scoped list as everywhere else on the page;
                        // this used to query every active store in the database.
                        foreach ($ownerStores as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo sanitize($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Quantity</label>
                    <input type="number" name="quantity" id="adjustQuantity" class="form-control" required>
                    <small class="form-text">Use positive number to add, negative to reduce</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Stock Transfer Modal -->
<div class="modal-overlay" id="transferModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-exchange-alt"></i> Transfer Stock</h3>
            <button class="modal-close" onclick="closeTransferModal()">&times;</button>
        </div>
        <form id="quickTransferForm" onsubmit="processQuickTransfer(event)">
            <div class="modal-body">
                <input type="hidden" id="transferProductId">

                <p style="margin-bottom: 0.5rem;">Product: <strong><span id="transferProductName"></span></strong></p>
                <p class="text-muted" style="margin-bottom: 1rem;">Available: <strong><span id="transferAvailable">0</span></strong></p>

                <!-- From Store (shown in Global view) -->
                <div class="form-group" id="transferFromStoreGroup">
                    <label class="form-label required">From Store</label>
                    <select id="transferFromStoreId" class="form-control" required>
                        <option value="">Select Source Store</option>
                        <?php foreach ($ownerStores as $st): ?>
                            <option value="<?php echo $st['id']; ?>"><?php echo sanitize($st['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">To Store</label>
                    <select id="transferToStoreId" class="form-control" required>
                        <option value="">Select Destination</option>
                        <?php foreach ($ownerStores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo sanitize($store['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Quantity</label>
                    <input type="number" id="transferQuantity" class="form-control" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Note (optional)</label>
                    <textarea id="transferNote" class="form-control" rows="2" placeholder="Reason for transfer..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnTransferSubmit"><i class="fas fa-exchange-alt"></i> Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- History Modal -->
<div class="modal-overlay" id="historyModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Stock History</h3>
            <button class="modal-close" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div class="modal-body" id="historyContent">
            <div class="text-center"><div class="spinner"></div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeHistoryModal()">Close</button>
        </div>
    </div>
</div>

<script>
function adjustStock(id, name, stock) {
    document.getElementById('adjustProductId').value = id;
    document.getElementById('adjustProductName').textContent = name;
    document.getElementById('currentStock').textContent = stock;
    document.getElementById('adjustQuantity').value = '';
    
    // Reset transfer fields
    document.getElementById('adjustType').value = 'purchase';
    document.getElementById('adjustToStoreGroup').style.display = 'none';
    document.getElementById('adjustToStore').required = false;

    document.getElementById('adjustModal').classList.add('active');
}

// Watch Adjustment Type
document.getElementById('adjustType').addEventListener('change', function() {
    const isTransfer = this.value === 'transfer';
    const toStoreGroup = document.getElementById('adjustToStoreGroup');
    const toStoreSelect = document.getElementById('adjustToStore');
    
    toStoreGroup.style.display = isTransfer ? 'block' : 'none';
    toStoreSelect.required = isTransfer;
    
    // Update help text for quantity
    const helpText = document.querySelector('#adjustModal .form-text');
    if (isTransfer) {
        helpText.textContent = "Enter positive quantity to transfer";
    } else {
        helpText.textContent = "Use positive number to add, negative to reduce";
    }
});

function closeModal() {
    document.getElementById('adjustModal').classList.remove('active');
}

async function viewHistory(productId) {
    document.getElementById('historyModal').classList.add('active');
    
    try {
        const response = await fetch(`api/stock-history.php?product_id=${productId}`);
        const data = await response.json();
        
        if (data.success) {
            let html = '<table class="table"><thead><tr><th>Date</th><th>Type</th><th>Change</th><th>Note</th><th>User</th></tr></thead><tbody>';
            
            if (data.history.length === 0) {
                html += '<tr><td colspan="5" class="text-center text-muted">No history found</td></tr>';
            } else {
                data.history.forEach(h => {
                    const changeClass = h.quantity_change > 0 ? 'text-success' : 'text-danger';
                    const changePrefix = h.quantity_change > 0 ? '+' : '';
                    html += `<tr>
                        <td>${h.date}</td>
                        <td><span class="badge badge-primary">${h.type}</span></td>
                        <td class="${changeClass}">${changePrefix}${h.quantity_change}</td>
                        <td>${h.note || '-'}</td>
                        <td>${h.user_name}</td>
                    </tr>`;
                });
            }
            
            html += '</tbody></table>';
            document.getElementById('historyContent').innerHTML = html;
        }
    } catch (error) {
        document.getElementById('historyContent').innerHTML = '<p class="text-danger">Error loading history</p>';
    }
}

function closeHistoryModal() {
    document.getElementById('historyModal').classList.remove('active');
}

document.getElementById('adjustModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('historyModal').addEventListener('click', function(e) {
    if (e.target === this) closeHistoryModal();
});
</script>

<script>
// Transfer Logic
const transferModal = document.getElementById('transferModal');
const isGlobalView = <?php echo $isGlobalView ? 'true' : 'false'; ?>;
const defaultStoreId = <?php echo (int)$store_id; ?>;

function initiateTransfer(id, name, stock, globalView) {
    document.getElementById('transferProductId').value = id;
    document.getElementById('transferProductName').textContent = name;
    document.getElementById('transferAvailable').textContent = stock;
    document.getElementById('transferQuantity').max = stock;
    document.getElementById('transferQuantity').value = '';
    document.getElementById('transferNote').value = '';

    const fromGroup = document.getElementById('transferFromStoreGroup');
    const fromSelect = document.getElementById('transferFromStoreId');
    const toSelect   = document.getElementById('transferToStoreId');

    if (globalView) {
        // Global view: user picks From Store
        fromGroup.style.display = 'block';
        fromSelect.required = true;
        fromSelect.value = '';
    } else {
        // Store-specific view: From Store is fixed
        fromGroup.style.display = 'none';
        fromSelect.required = false;
        fromSelect.value = defaultStoreId;
    }

    toSelect.value = '';
    transferModal.classList.add('active');
}

function closeTransferModal() {
    transferModal.classList.remove('active');
}

// Prevent selecting same store for From & To
document.getElementById('transferFromStoreId').addEventListener('change', function() {
    const fromId = this.value;
    const toOptions = document.getElementById('transferToStoreId').options;
    for (let opt of toOptions) {
        opt.disabled = opt.value === fromId;
    }
});

async function processQuickTransfer(e) {
    e.preventDefault();

    const productId  = document.getElementById('transferProductId').value;
    const name       = document.getElementById('transferProductName').textContent;
    const fromSelect = document.getElementById('transferFromStoreId');
    const fromStore  = fromSelect.style.display === 'none'
                        ? defaultStoreId  // store-specific view
                        : fromSelect.value;
    const toStore    = document.getElementById('transferToStoreId').value;
    const quantity   = parseInt(document.getElementById('transferQuantity').value);
    const note       = document.getElementById('transferNote').value;
    const available  = parseInt(document.getElementById('transferAvailable').textContent);

    if (!fromStore) { alert('Please select a source store'); return; }
    if (!toStore)   { alert('Please select a destination store'); return; }
    if (fromStore == toStore) { alert('Source and destination store cannot be the same'); return; }
    if (quantity > available) { alert('Quantity exceeds available stock'); return; }

    const btn = document.getElementById('btnTransferSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    try {
        const response = await fetch('api/process-transfer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                from_store_id: fromStore,
                to_store_id:   toStore,
                items: [{ id: productId, name: name, quantity: quantity }],
                note: note
            })
        });

        const result = await response.json();

        if (result.success) {
            alert('✅ Stock transferred successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + result.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Transfer';
        }
    } catch (error) {
        alert('Error processing transfer. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Transfer';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
