<?php
/**
 * POS System - Store-Wise Product Stock Details
 *
 * The drill-down behind the Store-Wise Stock Summary on stock.php: one row per
 * product held by a store, with the quantity read straight from store_stocks -
 * the same ledger every movement writes to. Nothing is cached, so a purchase,
 * sale, return, exchange, transfer or manual adjustment is visible here on the
 * next load.
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Store Stock Details');

$db = getDB();
$currentUser = getCurrentUser();

$ownerId = (int)$currentUser['owner_id'];
$isSuper = isSuperAdminUser($currentUser);
$userStoreId = $currentUser['store_id'];

// Every active store this account may read.
if ($isSuper) {
    $stores = $db->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name")->fetchAll();
} else {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
    $stores = $stmt->fetchAll();
}
$storeIds = array_map('intval', array_column($stores, 'id'));

// A store-bound account always reads its own store. Otherwise a requested store
// is honoured only when it is one of the shops above.
$store_id = (int)($_GET['store_id'] ?? 0);
if ($userStoreId) {
    $store_id = (int)$userStoreId;
} elseif (!in_array($store_id, $storeIds, true)) {
    $store_id = 0;
}

$search = sanitize($_GET['search'] ?? '');

// One query for the whole page. The product join filters out rows left behind
// by an inactive product, and the owner filter keeps one shop's stock out of
// another shop's screen even if a stale row exists in store_stocks.
$sql = "SELECT ss.store_id, ss.quantity, st.name AS store_name,
               p.id AS product_id, p.name AS product_name, p.barcode, p.unit,
               p.min_stock, p.buy_price
        FROM store_stocks ss
        JOIN stores st ON st.id = ss.store_id
        JOIN products p ON p.id = ss.product_id
        WHERE st.status = 'active' AND p.status = 'active'";
$params = [];

if (!$isSuper) {
    $sql .= " AND st.owner_id = ? AND p.owner_id = ?";
    $params[] = $ownerId;
    $params[] = $ownerId;
}
if ($store_id) {
    $sql .= " AND ss.store_id = ?";
    $params[] = $store_id;
}
if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY st.name ASC, p.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Variation labels (Size, Colour, ...) for every product on the page, in one
// query. Unit is skipped because the quantity cell already shows it. The tables
// may not exist on an install that has never opened the Variables page.
$variations = [];
if ($rows
    && appTableExists($db, 'product_variable_map')
    && appTableExists($db, 'product_variables')
    && appTableExists($db, 'product_variable_values')) {
    $productIds = implode(',', array_map('intval', array_unique(array_column($rows, 'product_id'))));
    $vrows = $db->query("SELECT m.product_id, v.name AS variable_name, v.slug, vv.value
        FROM product_variable_map m
        JOIN product_variables v ON v.id = m.variable_id
        JOIN product_variable_values vv ON vv.id = m.value_id
        WHERE m.product_id IN ($productIds)
        ORDER BY v.sort_order ASC, v.name ASC")->fetchAll();
    foreach ($vrows as $v) {
        if ($v['slug'] === 'unit') { continue; }
        $variations[(int)$v['product_id']][] = $v['variable_name'] . ': ' . $v['value'];
    }
}

// Totals for the rows on screen; no extra round trip.
$totalRows = count($rows);
$totalQty = 0;
$totalValue = 0.0;
foreach ($rows as $r) {
    $totalQty += (int)$r['quantity'];
    $totalValue += (float)$r['quantity'] * (float)$r['buy_price'];
}

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<div class="page-toolbar">
    <div>
        <h2 style="margin-bottom: 0.5rem;">Store-Wise Product Stock</h2>
        <p class="text-muted">Available pieces held by each store, read live from the stock ledger.</p>
    </div>
    <a href="stock.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Stock</a>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-boxes"></i></div>
        <div class="stat-content">
            <div class="stat-label">Stock Records</div>
            <div class="stat-value"><?php echo number_format($totalRows); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-cubes"></i></div>
        <div class="stat-content">
            <div class="stat-label">Available Quantity</div>
            <div class="stat-value"><?php echo number_format($totalQty); ?> pcs</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow"><i class="fas fa-coins"></i></div>
        <div class="stat-content">
            <div class="stat-label">Stock Value</div>
            <div class="stat-value"><?php echo formatCurrency($totalValue); ?></div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom: 0;">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Product name or SKU / barcode..." value="<?php echo $search; ?>">
            </div>

            <?php if (!$userStoreId): ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Store</label>
                <select name="store_id" class="form-control">
                    <option value="0" <?php echo $store_id === 0 ? 'selected' : ''; ?>>All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo (int)$store['id']; ?>" <?php echo $store_id === (int)$store['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($store['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
            <a href="store-stock-details.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>SKU / Barcode</th>
                    <th>Variation</th>
                    <th>Available Quantity</th>
                    <th>Store Name</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No stock records found</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $qty = (int)$row['quantity'];
                        $unit = $row['unit'] ? $row['unit'] : 'pcs';
                        $variationText = isset($variations[(int)$row['product_id']])
                            ? implode(', ', $variations[(int)$row['product_id']])
                            : '';
                        ?>
                        <tr>
                            <td><strong><?php echo sanitize($row['product_name']); ?></strong></td>
                            <td><?php echo $row['barcode'] ? sanitize($row['barcode']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td><?php echo $variationText !== '' ? sanitize($variationText) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $qty > 0 ? 'success' : ($qty === 0 ? 'danger' : 'warning'); ?>">
                                    <?php echo number_format($qty); ?> <?php echo sanitize($unit); ?>
                                </span>
                            </td>
                            <td><?php echo sanitize($row['store_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
