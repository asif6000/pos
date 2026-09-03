<?php
/**
 * POS System - Products Management
 * Add, Edit, Delete, and Search Products
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Products');

$db = getDB();
$currentUser = getCurrentUser();
$error = '';
$success = '';

// Role-based access: only admin role (super admin + sub admin) can assign products to stores
$isSuperAdmin = ($currentUser['role'] === 'admin' && empty($currentUser['owner_id']));
$canAssignStore = ($currentUser['role'] === 'admin');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $barcode = sanitize($_POST['barcode'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $size_id = (int) ($_POST['size_id'] ?? 0);
        $color_id = (int) ($_POST['color_id'] ?? 0);
        $buy_price = (float) ($_POST['buy_price'] ?? 0);
        $sell_price = (float) ($_POST['sell_price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $min_stock = (int) ($_POST['min_stock'] ?? 10);
        $unit = sanitize($_POST['unit'] ?? 'piece');
        $comment = sanitize($_POST['comment'] ?? '');
        $status = sanitize($_POST['status'] ?? 'active');
        $store_id = (int) ($_POST['store_id'] ?? 0);
        $ownerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];

        if (empty($name)) {
            $error = 'Product name is required.';
        } elseif ($sell_price <= 0) {
            $error = 'Sell price must be greater than 0.';
        } else {
            try {
                $db->beginTransaction();

                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO products (name, barcode, category_id, size_id, color_id, buy_price, sell_price, stock, min_stock, unit, comment, status, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $barcode ?: null, $category_id ?: null, $size_id ?: null, $color_id ?: null, $buy_price, $sell_price, $stock, $min_stock, $unit, $comment, $status, $ownerId]);
                    $productId = $db->lastInsertId();

                    if ($store_id) {
                        $db->prepare("INSERT IGNORE INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$store_id, $productId, $stock]);
                    }

                    $success = 'Product added successfully!';
                } else {
                    $stmt = $db->prepare("UPDATE products SET name=?, barcode=?, category_id=?, size_id=?, color_id=?, buy_price=?, sell_price=?, min_stock=?, unit=?, comment=?, status=? WHERE id=?");
                    $stmt->execute([$name, $barcode ?: null, $category_id ?: null, $size_id ?: null, $color_id ?: null, $buy_price, $sell_price, $min_stock, $unit, $comment, $status, $id]);

                    if ($store_id) {
                        $check = $db->prepare("SELECT 1 FROM store_stocks WHERE store_id = ? AND product_id = ?");
                        $check->execute([$store_id, $id]);
                        if ($check->fetch()) {
                            $db->prepare("UPDATE store_stocks SET quantity = ? WHERE store_id = ? AND product_id = ?")->execute([$stock, $store_id, $id]);
                        } else {
                            $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$store_id, $id, $stock]);
                        }
                    }

                    $success = 'Product updated successfully!';
                }

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = 'Barcode already exists.';
                } else {
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $db->beginTransaction();
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            $db->prepare("DELETE FROM store_stocks WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM stock_history WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM sale_items WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM return_items WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM transfer_items WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $db->commit();
            $success = 'Product deleted successfully!';
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                try { $db->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Exception $ignored) {}
                $db->rollBack();
            }
            $error = 'Cannot delete product. Error: ' . $e->getMessage();
        }
    }

    if ($success)
        setFlash('success', $success);
    if ($error)
        setFlash('danger', $error);
    redirect('products.php');
}

// Get owner ID for queries
$ownerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];

// Get categories for dropdown - Super admin: all categories; others: filter by owner
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM categories WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$categories = $stmt->fetchAll();

// Get sizes
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM product_variables WHERE type = 'size' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM product_variables WHERE type = 'size' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$sizes = $stmt->fetchAll();

// Get colors
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM product_variables WHERE type = 'color' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM product_variables WHERE type = 'color' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$colors = $stmt->fetchAll();

// Get units (custom units from Variables, e.g. kg, gram, etc.)
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM product_variables WHERE type = 'unit' AND status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM product_variables WHERE type = 'unit' AND status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$units = $stmt->fetchAll();

// Search and filter settings
$search = sanitize($_GET['search'] ?? '');
$category = (int) ($_GET['category'] ?? 0);
$status_filter = sanitize($_GET['status'] ?? '');
$view_store_id = (int)($_GET['view_store'] ?? 0);

$currentUser = getCurrentUser();
$userStoreId = $currentUser['store_id'];

// Validate user's assigned store still exists (may have been deleted)
if ($userStoreId) {
    $stmt = $db->prepare("SELECT name FROM stores WHERE id = ? AND status = 'active'");
    $stmt->execute([$userStoreId]);
    $storeName = $stmt->fetchColumn();
    if (!$storeName) {
        $userStoreId = 0;
        $storeName = '';
    }
} else {
    $storeName = '';
}

// Determine Scoping Logic (to match stock.php)
if ($userStoreId) {
    $scope_store_id = $userStoreId;
    $isGlobalView = false;
} else {
    if ($view_store_id > 0) {
        $scope_store_id = $view_store_id;
        $isGlobalView = false;
    } else {
        $scope_store_id = 0;
        $isGlobalView = true;
    }
}

// Select products with joined store_stocks - Super admin sees all, others filter by owner
if ($isGlobalView) {
    $sql = "SELECT p.*, c.name as category_name, sz.name as size_name, cl.name as color_name, COALESCE(SUM(ss.quantity), 0) as current_stock,
            GROUP_CONCAT(DISTINCT CONCAT(st.name, '::', ss.store_id, '::', ss.quantity) SEPARATOR '||') as store_list
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN product_variables sz ON p.size_id = sz.id
            LEFT JOIN product_variables cl ON p.color_id = cl.id
            LEFT JOIN store_stocks ss ON p.id = ss.product_id
            LEFT JOIN stores st ON ss.store_id = st.id
            WHERE 1=1";
    $groupBy = " GROUP BY p.id";
    $params = [];
    if (!$isSuperAdmin) {
        $sql .= " AND p.owner_id = ?";
        $params[] = $ownerId;
    }
} else {
    $sql = "SELECT p.*, c.name as category_name, sz.name as size_name, cl.name as color_name, COALESCE(ss.quantity, 0) as current_stock,
            GROUP_CONCAT(DISTINCT CONCAT(st.name, '::', ss.store_id, '::', ss.quantity) SEPARATOR '||') as store_list
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN product_variables sz ON p.size_id = sz.id
            LEFT JOIN product_variables cl ON p.color_id = cl.id
            JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
            LEFT JOIN stores st ON ss.store_id = st.id
            WHERE 1=1";
    $groupBy = " GROUP BY p.id";
    $params = [$scope_store_id];
    if (!$isSuperAdmin) {
        $sql .= " AND p.owner_id = ?";
        $params[] = $ownerId;
    }
}

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category;
}

if ($status_filter) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}

$sql .= $groupBy . " ORDER BY p.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get stores for dropdowns - Super admin: all stores; Sub admin: only stores they created; Cashier/Staff: their own
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$stores = $stmt->fetchAll();
include 'includes/header.php';
?>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <p class="text-muted">Manage your products and inventory</p>
    <div style="display: flex; gap: 0.5rem;">
        <button class="btn btn-outline" onclick="openLabelModal()">
            <i class="fas fa-barcode"></i> Print Labels
        </button>
        <button class="btn btn-primary" onclick="openModal('add')">
            <i class="fas fa-plus"></i> Add Product
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control"
                    placeholder="Enter Product name / SKU / Scan bar code" value="<?php echo $search; ?>">
            </div>
            <div class="form-group" style="min-width: 150px; margin-bottom: 0;">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="min-width: 120px; margin-bottom: 0;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <?php if ($canAssignStore && !$userStoreId): ?>
            <div class="form-group" style="min-width: 150px; margin-bottom: 0;">
                <label class="form-label">Store View</label>
                <select name="view_store" class="form-control">
                    <option value="0" <?php echo $view_store_id == 0 ? 'selected' : ''; ?>>All Stores (Global)</option>
                    <?php foreach ($stores as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $view_store_id == $s['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($s['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="products.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Color</th>
                        <th>Buy Price</th>
                        <th>Sell Price</th>
                        <th>Stock</th>
                        <th>Assigned Stores</th>
                        <th>Comment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted">No products found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                            // Parse assigned store list: "StoreName::id::qty"
                            $storePills = [];
                            if (!empty($product['store_list'])) {
                                foreach (explode('||', $product['store_list']) as $entry) {
                                    if (!$entry) continue;
                                    $parts = explode('::', $entry);
                                    if (count($parts) === 3) {
                                        $storePills[] = ['name' => $parts[0], 'qty' => (int)$parts[2]];
                                    }
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?php echo sanitize($product['name']); ?>
                                    </strong>
                                    <?php if ($product['unit'] !== 'piece'): ?>
                                        <br><small class="text-muted">(
                                            <?php echo $product['unit']; ?>)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $product['barcode'] ?? '-'; ?>
                                </td>
                                <td>
                                    <?php echo sanitize($product['category_name'] ?? 'Uncategorized'); ?>
                                </td>
                                <td>
                                    <?php echo !empty($product['size_name']) ? sanitize($product['size_name']) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php echo !empty($product['color_name']) ? sanitize($product['color_name']) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php echo formatCurrency($product['buy_price']); ?>
                                </td>
                                <td><strong>
                                        <?php echo formatCurrency($product['sell_price']); ?>
                                    </strong></td>
                                <td>
                                    <?php if ($product['current_stock'] <= $product['min_stock']): ?>
                                        <span class="badge badge-danger">
                                            <?php echo $product['current_stock']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success">
                                            <?php echo $product['current_stock']; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($storePills)): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:220px;">
                                            <?php foreach ($storePills as $sp): ?>
                                                <span style="background:#eff6ff; color:#2563eb; border:1px solid #dbeafe; border-radius:999px; padding:2px 8px; font-size:0.72rem; font-weight:600; white-space:nowrap;">
                                                    <i class="fas fa-store" style="font-size:0.65rem;"></i>
                                                    <?php echo sanitize($sp['name']); ?>
                                                    <span style="color:#94a3b8;">× <?php echo $sp['qty']; ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($product['comment'])): ?>
                                        <span style="font-size:0.82rem; color:#475569;"><?php echo sanitize($product['comment']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-<?php echo $product['status'] === 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-outline" title="Print Label"
                                            onclick="printSingleLabel(<?php echo $product['id']; ?>)">
                                            <i class="fas fa-barcode"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline"
                                            onclick='editProduct(<?php echo json_encode($product); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger"
                                            onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo sanitize($product['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="productModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Product</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="productForm">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="productId" value="">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label required">Product Name</label>
                        <input type="text" name="name" id="productName" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Barcode</label>
                        <input type="text" name="barcode" id="productBarcode" class="form-control"
                            placeholder="Scan or enter barcode">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="productCategory" class="form-control">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo sanitize($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Size</label>
                        <select name="size_id" id="productSize" class="form-control">
                            <option value="">Select Size</option>
                            <?php foreach ($sizes as $sz): ?>
                                <option value="<?php echo $sz['id']; ?>">
                                    <?php echo sanitize($sz['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <select name="color_id" id="productColor" class="form-control">
                            <option value="">Select Color</option>
                            <?php foreach ($colors as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>">
                                    <?php echo sanitize($cl['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Buy Price (
                            <?php echo CURRENCY; ?>)
                        </label>
                        <input type="number" name="buy_price" id="productBuyPrice" class="form-control" step="0.01"
                            min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Sell Price (
                            <?php echo CURRENCY; ?>)
                        </label>
                        <input type="number" name="sell_price" id="productSellPrice" class="form-control" step="0.01"
                            min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Stock Quantity</label>
                        <input type="number" name="stock" id="productStock" class="form-control" min="0" required>
                    </div>

                    <?php if ($canAssignStore): ?>
                    <div class="form-group" id="storeSelectorGroup">
                        <label class="form-label required">Assign to Store</label>
                        <select name="store_id" id="productStore" class="form-control" required>
                            <option value="">Select a store...</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo ($userStoreId && $s['id'] == $userStoreId) ? 'selected' : ''; ?>>
                                    <?php echo sanitize($s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text">Choose which store this product belongs to</small>
                        <div class="invalid-feedback">Please select a store for this product.</div>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="store_id" id="productStore" value="<?php echo (int)$userStoreId; ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Min Stock Level</label>
                        <input type="number" name="min_stock" id="productMinStock" class="form-control" min="0"
                            value="10">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <select name="unit" id="productUnit" class="form-control">
                            <option value="">Select Unit</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?php echo sanitize($u['name']); ?>">
                                    <?php echo sanitize($u['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="productStatus" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Comment</label>
                        <textarea name="comment" id="productComment" class="form-control" rows="2"
                            placeholder="Optional comment for this product"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Print Labels Modal -->
<div class="modal-overlay" id="labelModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-barcode"></i> Print Product Labels</h3>
            <button class="modal-close" onclick="closeLabelModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" style="margin-bottom: 1rem;">
                Select products and quantity to print labels (Continuous Feed)
            </p>

            <div class="form-group">
                <label class="form-label">Select Products</label>
                <div
                    style="max-height: 250px; overflow-y: auto; border: 1px solid var(--gray-200); border-radius: var(--border-radius); padding: 0.5rem;">
                    <?php foreach ($products as $product): ?>
                        <label
                            style="display: flex; align-items: center; padding: 0.5rem; cursor: pointer; border-bottom: 1px solid var(--gray-100);">
                            <input type="checkbox" class="label-product-checkbox" value="<?php echo $product['id']; ?>"
                                data-name="<?php echo sanitize($product['name']); ?>" style="margin-right: 0.75rem;">
                            <span style="flex: 1;">
                                <strong><?php echo sanitize($product['name']); ?></strong>
                                <?php if ($product['barcode']): ?>
                                    <br><small class="text-muted"><?php echo $product['barcode']; ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="badge badge-primary"><?php echo formatCurrency($product['sell_price']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Sticker Width (in)</label>
                    <input type="number" id="stickerWidth" class="form-control"
                        value="<?php echo $defaultStickerWidth; ?>" step="0.0001">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Sticker Height (in)</label>
                    <input type="number" id="stickerHeight" class="form-control"
                        value="<?php echo $defaultStickerHeight; ?>" step="0.0001">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Paper Width (in)</label>
                    <input type="number" id="paperWidth" class="form-control" value="<?php echo $defaultPaperWidth; ?>"
                        step="0.0001">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Paper Height (in)</label>
                    <input type="number" id="paperHeight" class="form-control"
                        value="<?php echo $defaultPaperHeight; ?>" step="0.0001">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Labels per Product</label>
                    <input type="number" id="labelQuantity" class="form-control" value="1" min="1" max="100">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Selected Products</label>
                    <div id="selectedCount" style="font-size: 1.5rem; font-weight: bold; color: var(--primary);">0</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="selectAllProducts()">Select All</button>
            <button class="btn btn-outline" onclick="deselectAllProducts()">Deselect All</button>
            <button class="btn btn-primary" onclick="printLabels()">
                <i class="fas fa-print"></i> Print Labels
            </button>
        </div>
    </div>
</div>

<script>
    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Product' : 'Edit Product';
        document.getElementById('productModal').classList.add('active');

        if (action === 'add') {
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            if (document.getElementById('storeSelectorGroup')) {
                document.getElementById('storeSelectorGroup').style.display = 'block';
                const storeSelect = document.getElementById('productStore');
                if (storeSelect) {
                    storeSelect.required = true;
                    storeSelect.disabled = false;
                }
            }
            document.getElementById('productStock').readOnly = false;
        } else {
            if (document.getElementById('storeSelectorGroup')) {
                document.getElementById('storeSelectorGroup').style.display = 'block';
                const storeSelect = document.getElementById('productStore');
                if (storeSelect) {
                    storeSelect.required = false;
                    storeSelect.disabled = true;
                }
            }
            document.getElementById('productStock').readOnly = false;
        }
    }

    function closeModal() {
        document.getElementById('productModal').classList.remove('active');
        
        // Reset store selection validation
        const storeSelect = document.getElementById('productStore');
        if (storeSelect) {
            storeSelect.setCustomValidity('');
        }
    }
    
    // Enhanced form validation for store selection
    document.getElementById('productForm').addEventListener('submit', function(e) {
        const action = document.getElementById('formAction').value;
        if (action === 'edit') return true;

        const storeSelect = document.getElementById('productStore');
        if (!storeSelect) return true;
        
        if (storeSelect.hasAttribute('required') && !storeSelect.value) {
            e.preventDefault();
            storeSelect.setCustomValidity('Please select a store for this product');
            storeSelect.classList.add('is-invalid');
            storeSelect.reportValidity();
            return false;
        }
        
        storeSelect.setCustomValidity('');
        storeSelect.classList.remove('is-invalid');
        return true;
    });
    
    const storeSelect = document.getElementById('productStore');
    if (storeSelect && storeSelect.tagName === 'SELECT') {
        storeSelect.addEventListener('change', function() {
            if (this.value) {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
        });
    }

    function editProduct(product) {
        openModal('edit');
        document.getElementById('productId').value = product.id;
        document.getElementById('productName').value = product.name;
        document.getElementById('productBarcode').value = product.barcode || '';
        document.getElementById('productCategory').value = product.category_id || '';
        document.getElementById('productSize').value = product.size_id || '';
        document.getElementById('productColor').value = product.color_id || '';
        document.getElementById('productBuyPrice').value = product.buy_price;
        document.getElementById('productSellPrice').value = product.sell_price;
        document.getElementById('productStock').value = product.current_stock;
        document.getElementById('productMinStock').value = product.min_stock;
        document.getElementById('productUnit').value = product.unit;
        document.getElementById('productStatus').value = product.status;
        document.getElementById('productComment').value = product.comment || '';
    }

    function deleteProduct(id, name) {
        if (confirm('Are you sure you want to delete "' + name + '"?')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    // Close modal on outside click
    document.getElementById('productModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    // Label printing functions
    function openLabelModal() {
        document.getElementById('labelModal').classList.add('active');
        updateSelectedCount();
    }

    function closeLabelModal() {
        document.getElementById('labelModal').classList.remove('active');
    }

    function selectAllProducts() {
        document.querySelectorAll('.label-product-checkbox').forEach(cb => cb.checked = true);
        updateSelectedCount();
    }

    function deselectAllProducts() {
        document.querySelectorAll('.label-product-checkbox').forEach(cb => cb.checked = false);
        updateSelectedCount();
    }

    function updateSelectedCount() {
        const count = document.querySelectorAll('.label-product-checkbox:checked').length;
        document.getElementById('selectedCount').textContent = count;
    }

    // Update count when checkboxes change
    document.querySelectorAll('.label-product-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    function printLabels() {
        const selectedIds = [];
        document.querySelectorAll('.label-product-checkbox:checked').forEach(cb => {
            selectedIds.push(cb.value);
        });

        if (selectedIds.length === 0) {
            alert('Please select at least one product');
            return;
        }

        const qty = document.getElementById('labelQuantity').value || 1;
        const stickerWidth = document.getElementById('stickerWidth').value || 1.7700;
        const stickerHeight = document.getElementById('stickerHeight').value || 1.3800;
        const paperWidth = document.getElementById('paperWidth').value || 1.8000;
        const paperHeight = document.getElementById('paperHeight').value || 1.4000;

        const url = `print-labels.php?ids=${selectedIds.join(',')}&qty=${qty}&sw=${stickerWidth}&sh=${stickerHeight}&pw=${paperWidth}&ph=${paperHeight}`;
        window.open(url, '_blank', 'width=800,height=600');
    }

    function printSingleLabel(productId) {
        // Use default size or values from modal if open/available, otherwise default to user settings
        const stickerWidth = document.getElementById('stickerWidth') ? document.getElementById('stickerWidth').value : 1.7700;
        const stickerHeight = document.getElementById('stickerHeight') ? document.getElementById('stickerHeight').value : 1.3800;
        const paperWidth = document.getElementById('paperWidth') ? document.getElementById('paperWidth').value : 1.8000;
        const paperHeight = document.getElementById('paperHeight') ? document.getElementById('paperHeight').value : 1.4000;

        const qty = prompt('How many labels to print?', '1');
        if (qty && parseInt(qty) > 0) {
            const url = `print-labels.php?ids=${productId}&qty=${qty}&sw=${stickerWidth}&sh=${stickerHeight}&pw=${paperWidth}&ph=${paperHeight}`;
            window.open(url, '_blank', 'width=800,height=600');
        }
    }

    // Close label modal on outside click
    document.getElementById('labelModal').addEventListener('click', function (e) {
        if (e.target === this) closeLabelModal();
    });
</script>

<?php include 'includes/footer.php'; ?>