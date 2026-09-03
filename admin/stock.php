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
requirePermission();

define('PAGE_TITLE', 'Stock Management');

$db = getDB();
$currentUser = getCurrentUser();

// Handle stock adjustment
// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $type = sanitize($_POST['type'] ?? 'adjustment');
    $note = sanitize($_POST['note'] ?? '');
    
    // Determine Store ID (CurrentUser's store or Default to 1/Main)
    // Determine Store ID (CurrentUser's store or Default to 1/Main)
    // Determine Store ID (CurrentUser's store or Default to 1/Main)
    $userStoreId = $currentUser['store_id'];
    
    // If admin has no store, handle store identification
    if (!$userStoreId) {
        // If they posted a store_id, use it
        if (isset($_POST['store_id']) && (int)$_POST['store_id'] > 0) {
            $store_id = (int)$_POST['store_id'];
        } else {
            // Otherwise, pick the FIRST available store from DB as fallback (filtered by owner)
            $stmt = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
            $stmt->execute([$currentUser['owner_id']]);
            $store_id = $stmt->fetchColumn();
            if (!$store_id) {
                $stmt = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
                $store_id = $stmt->fetchColumn();
            }
        }
    } else {
        $store_id = $userStoreId;
    }
    
    if ($productId && $quantity != 0) {
        try {
            $db->beginTransaction();
            
            if ($type === 'transfer') {
                $toStoreId = (int)($_POST['to_store_id'] ?? 0);
                if (!$toStoreId) throw new Exception("Destination store is required for transfer");
                if ($toStoreId === $store_id) throw new Exception("Source and Destination stores cannot be the same");
                
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

$currentUser = getCurrentUser();
$userStoreId = $currentUser['store_id'];

// Logic:
// If Store Admin: Always view their store
// If Global Admin:
//    - If view_store_id is set, view that store
//    - If view_store_id is 0 (All), view aggregated global stock

if ($userStoreId) {
    $store_id = $userStoreId;
    $isGlobalView = false;
} else {
    // Global Admin
    if ($view_store_id > 0) {
        $store_id = $view_store_id;
        $isGlobalView = false;
    } else {
        // Find default store for adjustments
        $stmt = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
        $store_id = $stmt->fetchColumn() ?: 1;
        $isGlobalView = true;
    }
}

// Select products and stock
if ($isGlobalView) {
    // Aggregated View
    $sql = "SELECT p.*, c.name as category_name, COALESCE(SUM(ss.quantity), 0) as current_stock 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN store_stocks ss ON p.id = ss.product_id
            WHERE p.status = 'active'";
    // Verify grouping
    $groupBy = " GROUP BY p.id";
} else {
    // Store Specific View
    $sql = "SELECT p.*, c.name as category_name, COALESCE(ss.quantity, 0) as current_stock 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
            WHERE p.status = 'active'";
    $groupBy = "";
}
        
$params = [];
if (!$isGlobalView) {
    $params[] = $store_id;
}

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'low') {
    if ($isGlobalView) $sql .= " HAVING current_stock <= p.min_stock";
    else $sql .= " AND COALESCE(ss.quantity, 0) <= p.min_stock";
} elseif ($filter === 'out') {
    if ($isGlobalView) $sql .= " HAVING current_stock = 0";
    else $sql .= " AND COALESCE(ss.quantity, 0) = 0";
}

$sql .= $groupBy . " ORDER BY stock ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Stores List for Filter (Global Admin only)
$stores = [];
if (!$userStoreId) {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ?");
    $stmt->execute([$currentUser['owner_id']]);
    $stores = $stmt->fetchAll();
}

// Summary stats
$totalProducts = count($products); // Approximate for filtered list

if ($isGlobalView) {
    // Global Stats — use store_stocks (real data) instead of products.stock
    $lowStockCount = (int)$db->query("SELECT COUNT(*) FROM (SELECT p.id, COALESCE(SUM(ss.quantity), 0) as qty, p.min_stock FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id WHERE p.status = 'active' GROUP BY p.id, p.min_stock HAVING qty > 0 AND qty <= p.min_stock) t")->fetchColumn();
    $outOfStockCount = (int)$db->query("SELECT COUNT(*) FROM (SELECT p.id, COALESCE(SUM(ss.quantity), 0) as qty FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id WHERE p.status = 'active' GROUP BY p.id HAVING qty = 0) t")->fetchColumn();
    $totalStockValue = (float)$db->query("SELECT COALESCE(SUM(sub.total_val), 0) FROM (SELECT p.id, COALESCE(SUM(ss.quantity), 0) * p.buy_price AS total_val FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id WHERE p.status = 'active' GROUP BY p.id) sub")->fetchColumn();
    $totalProducts = (int)$db->query("SELECT COUNT(*) FROM (SELECT p.id, COALESCE(SUM(ss.quantity), 0) as qty FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id WHERE p.status = 'active' GROUP BY p.id HAVING qty > 0) t")->fetchColumn();
} else {
    // Store Stats
    $lowStockCount = $db->query("SELECT COUNT(*) FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = $store_id WHERE COALESCE(ss.quantity, 0) <= p.min_stock AND p.status = 'active'")->fetchColumn();
    $outOfStockCount = $db->query("SELECT COUNT(*) FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = $store_id WHERE COALESCE(ss.quantity, 0) = 0 AND p.status = 'active'")->fetchColumn();
    $totalStockValue = $db->query("SELECT COALESCE(SUM(COALESCE(ss.quantity, 0) * p.buy_price), 0) FROM products p LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = $store_id WHERE p.status = 'active'")->fetchColumn();
}

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
            <div class="stat-value"><span id="lowStockCount"><?php echo $lowStockCount; ?></span></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Out of Stock</div>
            <div class="stat-value"><span id="outOfStockCount"><?php echo $outOfStockCount; ?></span></div>
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
        <div class="table-responsive">
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
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="adjustStock(<?php echo $product['id']; ?>, '<?php echo sanitize($product['name']); ?>', <?php echo $product['current_stock']; ?>)">
                                    <i class="fas fa-plus-minus"></i> Adjust
                                </button>
                                <?php if ($isGlobalView && $product['current_stock'] > 0): ?>
                                    <!-- Maybe show breakdown or simple info that it's global -->
                                <?php endif; ?>
                                
                                <?php if (!$isGlobalView && $product['current_stock'] > 0): ?>
                                    <button class="btn btn-sm btn-info" onclick="initiateTransfer(<?php echo $product['id']; ?>, '<?php echo sanitize($product['name']); ?>', <?php echo $product['current_stock']; ?>)">
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
                        // Reuse stores list or fetch again if context varies
                        // For Store Admin, they need OTHER stores.
                        $otherStores = $db->query("SELECT id, name FROM stores WHERE status='active'")->fetchAll();
                        foreach ($otherStores as $s): ?>
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
            <h3 class="modal-title">Transfer Stock</h3>
            <button class="modal-close" onclick="closeTransferModal()">&times;</button>
        </div>
        <form id="quickTransferForm" onsubmit="processQuickTransfer(event)">
            <div class="modal-body">
                <input type="hidden" id="transferProductId">
                <input type="hidden" id="transferFromStoreId" value="<?php echo $store_id; ?>">
                
                <p>Transfer <strong><span id="transferProductName"></span></strong></p>
                <p class="text-muted">From: <strong><?php 
                    echo "Current Store"; 
                ?></strong></p>
                <p class="text-muted">Available: <span id="transferAvailable">0</span></p>

                <div class="form-group">
                    <label class="form-label required">To Store</label>
                    <select id="transferToStoreId" class="form-control" required>
                        <option value="">Select Destination</option>
                        <?php 
                        // Fetch all stores again if not already fetched
                        $allStores = $db->query("SELECT id, name FROM stores WHERE status='active' AND id != " . (int)$store_id)->fetchAll();
                        foreach ($allStores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo sanitize($store['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Quantity</label>
                    <input type="number" id="transferQuantity" class="form-control" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea id="transferNote" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnTransferSubmit">Transfer</button>
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

function initiateTransfer(id, name, stock) {
    document.getElementById('transferProductId').value = id;
    document.getElementById('transferProductName').textContent = name;
    document.getElementById('transferAvailable').textContent = stock;
    document.getElementById('transferQuantity').max = stock;
    document.getElementById('transferQuantity').value = '';
    
    // Reset Store Select
    document.getElementById('transferToStoreId').value = '';
    
    transferModal.classList.add('active');
}

function closeTransferModal() {
    transferModal.classList.remove('active');
}

async function processQuickTransfer(e) {
    e.preventDefault();
    
    const productId = document.getElementById('transferProductId').value;
    const name = document.getElementById('transferProductName').textContent;
    const fromStore = document.getElementById('transferFromStoreId').value;
    const toStore = document.getElementById('transferToStoreId').value;
    const quantity = document.getElementById('transferQuantity').value;
    const note = document.getElementById('transferNote').value;
    const available = parseInt(document.getElementById('transferAvailable').textContent);

    if (!toStore) {
        alert('Please select a destination store');
        return;
    }
    
    if (quantity > available) {
        alert('Quantity exceeds available stock');
        return;
    }

    const btn = document.getElementById('btnTransferSubmit');
    btn.disabled = true;
    btn.innerHTML = 'Processing...';

    const items = [{
        id: productId,
        name: name,
        quantity: quantity,
        available: available // purely for reference if needed
    }];

    try {
        const response = await fetch('api/process-transfer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                from_store_id: fromStore,
                to_store_id: toStore,
                items: items,
                note: note
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Stock transferred successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + result.message);
            btn.disabled = false;
            btn.innerHTML = 'Transfer';
        }
    } catch (error) {
        alert('Error processing transfer');
        btn.disabled = false;
        btn.innerHTML = 'Transfer';
    }
}
</script>

<script>
(function() {
    var storeParam = <?php echo $isGlobalView ? '""' : json_encode((int)$store_id); ?>;
    var url = 'api/low-stock.php' + (storeParam ? '?store_id=' + storeParam : '');

    function refreshStockCounts() {
        fetch(url, { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.authenticated) return;
                var lowEl = document.getElementById('lowStockCount');
                var outEl = document.getElementById('outOfStockCount');
                if (lowEl && data.low_stock !== undefined) {
                    var v = parseInt(data.low_stock, 10);
                    if (!isNaN(v) && v !== parseInt(lowEl.textContent, 10)) lowEl.textContent = v;
                }
                if (outEl && data.out_of_stock !== undefined) {
                    var v2 = parseInt(data.out_of_stock, 10);
                    if (!isNaN(v2) && v2 !== parseInt(outEl.textContent, 10)) outEl.textContent = v2;
                }
            })
            .catch(function() {});
    }

    setInterval(refreshStockCounts, 15000);
})();
</script>

<?php include 'includes/footer.php'; ?>
