<?php
/**
 * POS System - Cashier Products View
 * View-only product listing
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

// Cashier should only access POS page
if (hasRole('cashier')) {
    redirect('pos.php');
}

define('PAGE_TITLE', 'Products');

$db = getDB();

// Get categories - Filter by owner
$stmt = $db->prepare("SELECT id, name FROM categories WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$user['owner_id']]);
$categories = $stmt->fetchAll();

// Search and filter
$search = sanitize($_GET['search'] ?? '');
$category = (int)($_GET['category'] ?? 0);

$user = getCurrentUser();
$store_id = $_SESSION['store_id'] ?? 0;
if (!$store_id) {
    $stmtFallback = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
    $store_id = $stmtFallback->fetchColumn() ?: 1;
}

$sql = "SELECT p.*, c.name as category_name, COALESCE(ss.quantity, 0) as current_stock 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
        WHERE p.status = 'active' AND p.owner_id = ?";
$params = [$store_id, $user['owner_id']];

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category) {
    $sql .= " AND p.category_id = ?";
    $params[] = $category;
}

$sql .= " ORDER BY p.name";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

include 'includes/header.php';
?>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?php echo $search; ?>">
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
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="products.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No products found</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><strong><?php echo sanitize($product['name']); ?></strong></td>
                                <td><?php echo $product['barcode'] ?? '-'; ?></td>
                                <td><?php echo sanitize($product['category_name'] ?? 'Uncategorized'); ?></td>
                                <td><strong><?php echo formatCurrency($product['sell_price']); ?></strong></td>
                                <td>
                                    <?php if ($product['current_stock'] <= $product['min_stock']): ?>
                                        <span class="badge badge-<?php echo $product['current_stock'] == 0 ? 'danger' : 'warning'; ?>">
                                            <?php echo $product['current_stock']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?php echo $product['current_stock']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
