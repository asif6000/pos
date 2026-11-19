<?php
/**
 * POS System - Products Management
 * Add, Edit, Delete, and Search Products
 */

require_once '../config/db.php';
require_once '../config/product_variables.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Products');

$db = getDB();
$currentUser = getCurrentUser();
$error = '';
$success = '';

// Product images: make sure the column exists. appSafeDdl swallows the duplicate
// -column error, so this is a cheap no-op after the first run and lets the feature
// self-install on any environment without a separate migration step.
appSafeDdl($db, "ALTER TABLE products ADD COLUMN image VARCHAR(255) NULL");

// Who supplied the product. Nullable and unchecked at the DB level because the
// suppliers list is optional on the form - most existing products have none.
appSafeDdl($db, "ALTER TABLE products ADD COLUMN supplier_id INT NULL");

// The Size / Colour / Unit lists. Idempotent, and seeds the defaults on a shop
// that has never opened the Variables page.
productVariablesEnsureTables($db);

// Role-based access: admin and manager roles can assign products to stores
//
// The shared test, not a local spelling of it. An empty owner column describes a
// shop's own keeper as easily as the platform account, and the super-admin branches
// below (all categories, all stores, no owner filter on the product list) are
// exactly the doors that must not open for a shopkeeper. isSuperAdminUser() also
// asks whether the account owns a shop, so only the platform account gets them.
$isSuperAdmin = isSuperAdminUser($currentUser);
$canAssignStore = in_array($currentUser['role'], ['admin', 'manager'], true);

/**
 * Validate and store an uploaded product image.
 *
 * The file is accepted only if getimagesize() confirms it is a real image, and the
 * saved name is generated on the server from the detected MIME type - the browser's
 * filename and extension are never used. That closes the "rename shell.php to
 * .jpg" and "fake the Content-Type" paths. Returns ['path' => relPath|null, 'error' => msg|null];
 * a null path with no error simply means nothing was uploaded.
 */
function storeProductImage($file)
{
    $result = ['path' => null, 'error' => null];
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return $result;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Image upload failed. Please try again.';
        return $result;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $result['error'] = 'Image must be 2 MB or smaller.';
        return $result;
    }
    $info = @getimagesize($file['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if ($info === false || !isset($extMap[$info['mime'] ?? ''])) {
        $result['error'] = 'Only JPG, PNG, WEBP or GIF images are allowed.';
        return $result;
    }
    $dir = __DIR__ . '/../assets/img/products';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = 'p' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $extMap[$info['mime']];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $result['error'] = 'Could not save the image. Please try again.';
        return $result;
    }
    $result['path'] = 'assets/img/products/' . $name;
    return $result;
}

/**
 * Delete a stored product image, but only if it really lives inside the products
 * folder (resolved path is checked against the folder) so a tampered DB value can
 * never turn this into an arbitrary file delete.
 */
function deleteProductImage($relPath)
{
    if (empty($relPath)) {
        return;
    }
    $base = realpath(__DIR__ . '/../assets/img/products');
    $full = realpath(__DIR__ . '/../' . $relPath);
    if ($base && $full && strpos($full, $base) === 0 && is_file($full)) {
        @unlink($full);
    }
}

/**
 * Barcode for a product saved without one, in the shape the shop already uses:
 * the letters it prefixes its own codes with, plus the next number after the
 * highest one it has (SC2237777 -> SC2237778). A shop with no lettered barcode
 * yet takes its initials from Settings > Shop name, so even the first generated
 * code looks like it belongs to the shop.
 *
 * `barcode` is UNIQUE across every shop, so a candidate another shop already
 * holds is nudged forward until a free one is found.
 */
function productAutoBarcode($db, $productId, $ownerId)
{
    $prefix = '';
    $highest = 0;

    $stmt = $db->prepare("SELECT barcode FROM products
                          WHERE owner_id = ? AND barcode REGEXP '^[A-Za-z]+[0-9]+$'");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (!preg_match('/^([A-Za-z]+)([0-9]+)$/', $code, $m)) {
            continue;
        }
        if ($prefix === '' || (int) $m[2] > $highest) {
            $prefix = strtoupper($m[1]);
            $highest = (int) $m[2];
        }
    }

    if ($prefix === '') {
        $stmt = $db->prepare("SELECT setting_value FROM settings
                              WHERE setting_key = 'shop_name' AND owner_id = ? LIMIT 1");
        $stmt->execute([$ownerId]);
        $words = preg_split('/[^A-Za-z0-9]+/', (string) $stmt->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($words, 0, 3) as $word) {
            $prefix .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        if ($prefix === '') {
            $prefix = 'P';
        }
    }

    $chk = $db->prepare("SELECT id FROM products WHERE barcode = ? AND id != ?");
    $tries = 0;
    for ($n = ($highest > 0 ? $highest : (int) $productId) + 1; $tries < 500; $n++, $tries++) {
        $candidate = $prefix . $n;
        $chk->execute([$candidate, (int) $productId]);
        if (!$chk->fetchColumn()) {
            return $candidate;
        }
    }

    // The whole run was taken; a random number behind the same prefix is still
    // scannable, and the UNIQUE index keeps it honest.
    return $prefix . random_int(1000000, 9999999);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $barcode = sanitize($_POST['barcode'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $buy_price = (float) ($_POST['buy_price'] ?? 0);
        $sell_price = (float) ($_POST['sell_price'] ?? 0);
        $stock = (int) ($_POST['stock'] ?? 0);
        $min_stock = (int) ($_POST['min_stock'] ?? 10);
        $unit = sanitize($_POST['unit'] ?? '') ?: 'piece';
        $comment = sanitize($_POST['comment'] ?? '');
        $status = sanitize($_POST['status'] ?? 'active');
        $supplier_id = (int) ($_POST['supplier_id'] ?? 0);

        // Size / Colour etc. Keyed by variable id; 0 means "left blank", which is
        // allowed for every one of them.
        $rawSelections = $_POST['variable'] ?? [];
        $selections = [];
        if (is_array($rawSelections)) {
            foreach ($rawSelections as $varId => $valueId) {
                $selections[(int) $varId] = (int) $valueId;
            }
        }

        $postOwnerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];

        // Suppliers belong to a shop; only accept one this owner actually has.
        $stmt_supplier_ok = false;
        if ($supplier_id) {
            $chk = $db->prepare("SELECT id FROM suppliers WHERE id = ? AND owner_id = ? AND status = 'active'");
            $chk->execute([$supplier_id, $postOwnerId]);
            $stmt_supplier_ok = (bool) $chk->fetchColumn();
        }

        // Validation
        if (empty($name)) {
            $error = 'Product name is required.';
        } elseif ($sell_price <= 0) {
            $error = 'Sell price must be greater than 0.';
        } elseif ($supplier_id && !$stmt_supplier_ok) {
            // A supplier id that is not this shop's is either stale or crafted -
            // store nothing rather than silently linking to another owner's row.
            $supplier_id = 0;
        } else {
            // Optional image upload is validated and stored before the transaction
            // opens, so a rejected file never leaves a half-written product behind.
            $newImagePath = null;
            $uploaded = storeProductImage($_FILES['image'] ?? null);
            $removeImage = (($_POST['remove_image'] ?? '') === '1');

            if ($uploaded['error']) {
                $error = $uploaded['error'];
            } else {
            $newImagePath = $uploaded['path'];
            try {
                if ($action === 'add') {
                    $ownerId = !empty($currentUser['owner_id']) ? (int)$currentUser['owner_id'] : (int)$currentUser['id'];
                    $db->beginTransaction();
                    $stmt = $db->prepare("INSERT INTO products (name, barcode, category_id, buy_price, sell_price, stock, min_stock, unit, comment, status, owner_id, image, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $barcode ?: null, $category_id ?: null, $buy_price, $sell_price, $stock, $min_stock, $unit, $comment ?: null, $status, $ownerId, $newImagePath ?: null, $supplier_id ?: null]);
                    $newProductId = $db->lastInsertId();
                    $barcodeWasAuto = ($barcode === '');
                    if ($barcodeWasAuto) {
                        $barcode = productAutoBarcode($db, (int) $newProductId, $ownerId);
                        $stmt = $db->prepare("UPDATE products SET barcode = ? WHERE id = ?");
                        $stmt->execute([$barcode, $newProductId]);
                    }
                    saveProductVariableMap($db, (int)$newProductId, $selections);

                    // Determine Store ID (admin can assign; super admin can pick any store, sub-admin only their own)
                    if ($canAssignStore) {
                        $target_store_id = (int)($_POST['store_id'] ?? 0);
                        if (!$target_store_id) {
                            // Fallback: super admin -> first active store, sub-admin -> first of their stores
                            if ($isSuperAdmin) {
                                $stmt = $db->query("SELECT id FROM stores WHERE status = 'active' ORDER BY id ASC LIMIT 1");
                                $target_store_id = $stmt->fetchColumn();
                            } else {
                                $stmt = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY id ASC LIMIT 1");
                                $stmt->execute([$ownerId]);
                                $target_store_id = $stmt->fetchColumn();
                            }
                            if (!$target_store_id) {
                                $stmt = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
                                $target_store_id = $stmt->fetchColumn();
                            }
                        }
                        // Sub-admin: ensure the chosen store belongs to them
                        if (!$isSuperAdmin && $target_store_id) {
                            $stmt = $db->prepare("SELECT id FROM stores WHERE id = ? AND owner_id = ?");
                            $stmt->execute([$target_store_id, $ownerId]);
                            if (!$stmt->fetchColumn()) {
                                throw new Exception('You can only assign products to stores you created.');
                            }
                        }
                    } else {
                        // Cashier/Staff: always use their own assigned store (validate it still exists)
                        $target_store_id = (int)($currentUser['store_id'] ?? 0);
                        if ($target_store_id) {
                            $stmt = $db->prepare("SELECT id FROM stores WHERE id = ? AND status = 'active'");
                            $stmt->execute([$target_store_id]);
                            if (!$stmt->fetchColumn()) {
                                $target_store_id = 0;
                            }
                        }
                    }
                    if (!$target_store_id) {
                        throw new Exception('No active store found. Please create a store first.');
                    }
                    // Insert initial stock into store_stocks
                    $stmt = $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$target_store_id, $newProductId, $stock]);

                    // Add to stock history
                    $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, note, user_id) VALUES (?, ?, 'purchase', 'Initial stock on creation', ?)");
                    $stmt->execute([$newProductId, $stock, $currentUser['id']]);

                    $db->commit();
                    $success = $barcodeWasAuto
                        ? 'Product added successfully! Barcode ' . $barcode . ' was generated automatically.'
                        : 'Product added successfully!';
                } else {
                    // Work out the final image: a fresh upload wins, else "remove"
                    // clears it, else the existing file is kept.
                    $cur = $db->prepare("SELECT image, barcode FROM products WHERE id = ?");
                    $cur->execute([$id]);
                    $current = $cur->fetch();
                    $currentImage = $current['image'] ?: null;
                    if ($newImagePath) {
                        $finalImage = $newImagePath;
                    } elseif ($removeImage) {
                        $finalImage = null;
                    } else {
                        $finalImage = $currentImage;
                    }

                    // A blank barcode field means "I did not touch it", not "delete it" -
                    // keep what the product already has, and give it one if it never had.
                    $finalBarcode = $barcode !== '' ? $barcode : trim((string) ($current['barcode'] ?? ''));
                    $barcodeWasAuto = false;
                    if ($finalBarcode === '') {
                        $finalBarcode = productAutoBarcode($db, $id, $postOwnerId);
                        $barcodeWasAuto = true;
                    }

                    $db->beginTransaction();
                    $stmt = $db->prepare("UPDATE products SET name=?, barcode=?, category_id=?, buy_price=?, sell_price=?, stock=?, min_stock=?, unit=?, comment=?, status=?, image=?, supplier_id=? WHERE id=?");
                    $stmt->execute([$name, $finalBarcode, $category_id ?: null, $buy_price, $sell_price, $stock, $min_stock, $unit, $comment ?: null, $status, $finalImage, $supplier_id ?: null, $id]);
                    saveProductVariableMap($db, (int)$id, $selections);

                    $stmt = $db->prepare("UPDATE store_stocks SET quantity = ? WHERE product_id = ?");
                    $stmt->execute([$stock, $id]);

                    $db->commit();
                    if ($currentImage && $currentImage !== $finalImage) {
                        deleteProductImage($currentImage);
                    }
                    $success = $barcodeWasAuto
                        ? 'Product updated successfully! Barcode ' . $finalBarcode . ' was generated automatically.'
                        : 'Product updated successfully!';
                }
                } catch (Exception $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $error = 'Barcode already exists.';
                    } else {
                        $error = 'Error: ' . $e->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $db->beginTransaction();
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            $db->prepare("DELETE FROM store_stocks WHERE product_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM product_variable_map WHERE product_id = ?")->execute([$id]);
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
    // Non-super-admin (e.g. manager): a picked store must belong to their own
    // shop, otherwise the Store View silently falls back to the global list.
    if ($view_store_id > 0 && !$isSuperAdmin) {
        $stmt = $db->prepare("SELECT id FROM stores WHERE id = ? AND owner_id = ?");
        $stmt->execute([$view_store_id, $ownerId]);
        if (!$stmt->fetchColumn()) {
            $view_store_id = 0;
        }
    }
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
    $from = " FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN store_stocks ss ON p.id = ss.product_id
            LEFT JOIN stores st ON ss.store_id = st.id";
    $stockCol = "COALESCE(SUM(ss.quantity), 0)";
    $params = [];
} else {
    $from = " FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
            LEFT JOIN stores st ON ss.store_id = st.id";
    $stockCol = "COALESCE(ss.quantity, 0)";
    $params = [$scope_store_id];
}

$where = " WHERE 1=1";
if (!$isSuperAdmin) {
    $where .= " AND p.owner_id = ?";
    $params[] = $ownerId;
}

if ($search) {
    $where .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($category) {
    $where .= " AND p.category_id = ?";
    $params[] = $category;
}

if ($status_filter) {
    $where .= " AND p.status = ?";
    $params[] = $status_filter;
}

// Sorting. Only these keys are ever interpolated into ORDER BY, so nothing the
// visitor sends can reach the SQL itself.
$sortable = [
    'name' => 'p.name',
    'barcode' => 'p.barcode',
    'category' => 'c.name',
    'buy_price' => 'p.buy_price',
    'sell_price' => 'p.sell_price',
    'stock' => 'current_stock',
    'status' => 'p.status',
];
$sort = isset($_GET['sort']) && isset($sortable[$_GET['sort']]) ? $_GET['sort'] : '';
$dir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// One page of the list, plus the total the footer reports.
$perPage = 20;
$stmt = $db->prepare("SELECT COUNT(DISTINCT p.id)$from$where");
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.*, c.name as category_name, $stockCol as current_stock,
        GROUP_CONCAT(DISTINCT CONCAT(st.name, '::', ss.store_id, '::', ss.quantity) SEPARATOR '||') as store_list
        $from$where
        GROUP BY p.id
        ORDER BY " . ($sort ? $sortable[$sort] . ' ' . strtoupper($dir) . ', ' : '') . "p.created_at DESC, p.id DESC
        LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

/** Rebuild a products.php URL with the current filters plus these overrides. */
function productQuery($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) { unset($q[$k]); }
    }
    return 'products.php' . ($q ? '?' . http_build_query($q) : '');
}

/** A sortable column header. $key is already whitelisted in $sortable. */
$thSort = function ($key, $label) use ($sort, $dir) {
    $active = $sort === $key;
    $next = ($active && $dir === 'asc') ? 'desc' : 'asc';
    ?>
    <th class="sortable<?php echo $active ? ' active' : ''; ?>">
        <a href="<?php echo htmlspecialchars(productQuery(['sort' => $key, 'dir' => $next, 'page' => 1])); ?>">
            <?php echo $label; ?>
            <i class="fas fa-caret-<?php echo $active && $dir === 'desc' ? 'down' : 'up'; ?> caret"></i>
        </a>
    </th>
    <?php
};

// Category pills pick a colour from the name, so the same category always
// looks the same from row to row.
$catPalette = [
    ['#e8f1ff', '#1d4ed8'], ['#f1ecff', '#6d28d9'], ['#e6f9f0', '#047857'],
    ['#fff4e0', '#b45309'], ['#ffeaf1', '#be185d'], ['#e4f8fb', '#0e7490'],
];
$catColor = function ($name) use ($catPalette) {
    return $catPalette[crc32($name) % count($catPalette)];
};

// Get stores for dropdowns - Super admin: all stores; Sub admin: only stores they created; Cashier/Staff: their own
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$stores = $stmt->fetchAll();

// Variables (Size / Colour / Unit + anything the shop adds) for the Add/Edit form.
$productVariables = getProductVariables($db);
$unitOptions = getUnitVariableValues($db);

// Suppliers are optional on a product - a shop can sell without naming who supplied it.
if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, name FROM suppliers WHERE status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
}
$suppliers = $stmt->fetchAll();

// Existing choices, in one query for the whole list (editProduct() reads them from JSON).
$varMapByProduct = [];
if ($products && $productVariables) {
    $ids = implode(',', array_map('intval', array_column($products, 'id')));
    $in = $productVariables ? implode(',', array_map('intval', array_column($productVariables, 'id'))) : '0';
    $rows = $db->query("SELECT product_id, variable_id, value_id FROM product_variable_map
                        WHERE product_id IN ($ids) AND variable_id IN ($in)")->fetchAll();
    foreach ($rows as $r) {
        $varMapByProduct[(int) $r['product_id']][(int) $r['variable_id']] = (int) $r['value_id'];
    }
}

// The Print Labels modal opens pre-filled with whatever the Barcode Settings
// page saved, falling back to the sizes print-labels.php itself assumes.
$labelSettings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $s) {
    $labelSettings[$s['setting_key']] = $s['setting_value'];
}
$defaultStickerWidth = $labelSettings['barcode_sticker_width'] ?? 1.7700;
$defaultStickerHeight = $labelSettings['barcode_sticker_height'] ?? 1.3800;
$defaultPaperWidth = $labelSettings['barcode_paper_width'] ?? 1.8000;
$defaultPaperHeight = $labelSettings['barcode_paper_height'] ?? 1.4000;

include 'includes/header.php';
?>

<style>
    /* ── Page hero ────────────────────────────────────────────────────────── */
    .pg-hero {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        background: linear-gradient(115deg, #dbeafe 0%, #eef4ff 45%, #f7fbff 100%);
        border: 1px solid #e0eaff; border-radius: 18px;
        padding: 1.1rem 1.4rem; margin-bottom: 1.25rem;
    }
    .pg-hero-ico {
        width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 1.15rem;
    }
    .pg-hero-txt { flex: 1 1 220px; min-width: 0; }
    .pg-hero-txt h2 { margin: 0; font-size: 1.3rem; font-weight: 800; color: #0f172a; }
    .pg-hero-txt p { margin: .15rem 0 0; font-size: .82rem; color: #475569; }
    .pg-hero-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    html[data-theme="dark"] .pg-hero { background: linear-gradient(115deg, #16233f 0%, #111a2e 100%); border-color: #1e2a44; }
    html[data-theme="dark"] .pg-hero-txt h2 { color: var(--gray-900); }
    html[data-theme="dark"] .pg-hero-txt p { color: var(--gray-500); }

    /* ── Filter card ──────────────────────────────────────────────────────── */
    .flt-card {
        background: var(--surface); border: 1px solid var(--gray-200);
        border-radius: 16px; padding: 1.15rem 1.3rem; margin-bottom: 1.25rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
    }
    .flt-grid { display: grid; grid-template-columns: minmax(220px, 2fr) 1fr 1fr 1fr auto; gap: .9rem; align-items: end; }
    .flt-actions { display: flex; gap: .6rem; }
    .search-wrap { position: relative; display: block; }
    .search-wrap > i { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); font-size: .78rem; color: var(--gray-400); pointer-events: none; }
    .search-wrap input { padding-left: 2.2rem; }
    @media (max-width: 1100px) { .flt-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 560px) { .flt-grid { grid-template-columns: 1fr; } .flt-actions .btn { flex: 1; } }

    /* ── Table ────────────────────────────────────────────────────────────── */
    .sortable a { display: inline-flex; align-items: center; gap: .35rem; color: inherit; text-decoration: none; }
    .sortable a:hover { color: #2563eb; text-decoration: none; }
    .sortable .caret { font-size: .68rem; opacity: .4; }
    .sortable.active .caret { opacity: 1; color: #2563eb; }
    .p-cell { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .p-thumb, .p-ph { width: 40px; height: 40px; border-radius: 9px; flex-shrink: 0; }
    .p-thumb { object-fit: cover; border: 1px solid var(--gray-200); }
    .p-ph { display: flex; align-items: center; justify-content: center; background: var(--gray-100); color: var(--gray-400); }
    .p-name { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .p-name small { font-size: .72rem; }
    .p-barcode { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: var(--gray-600); }
    .pill {
        display: inline-flex; align-items: center; gap: .3rem;
        font-size: .72rem; font-weight: 700; padding: .25rem .6rem;
        border-radius: 999px; white-space: nowrap;
    }
    .pill.stock-ok { background: #e6f9f0; color: #047857; }
    .pill.stock-low { background: #fff4e0; color: #b45309; }
    .pill.stock-out { background: #fdeaea; color: #b91c1c; }
    .pill.pill-on { background: #e6f9f0; color: #047857; }
    .pill.pill-off { background: #fff4e0; color: #b45309; }
    .pill.pill-store { background: #e8f1ff; color: #1d4ed8; }
    .pill.pill-store i { font-size: .62rem; }
    .pill.pill-store .qty { color: #64748b; font-weight: 600; }
    .store-list { display: flex; flex-wrap: wrap; gap: 4px; max-width: 220px; }
    .act {
        width: 32px; height: 32px; border: none; border-radius: 9px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; font-size: .78rem;
        transition: transform .15s ease, filter .15s ease;
    }
    .act:hover { transform: translateY(-1px); filter: brightness(1.05); }
    .act.ghost { background: var(--gray-50); border: 1px solid var(--gray-200); color: var(--gray-600); }
    .act.main { background: #2563eb; color: #fff; }
    .act.danger { background: #ef4444; color: #fff; }

    /* ── Footer + pager ───────────────────────────────────────────────────── */
    .tbl-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        flex-wrap: wrap; padding: .85rem 1.25rem;
        border-top: 1px solid var(--gray-100);
        font-size: .8rem; color: var(--gray-500);
    }
    .pager { display: flex; align-items: center; gap: .35rem; }
    .pg {
        min-width: 30px; height: 30px; padding: 0 .45rem; border-radius: 8px;
        border: 1px solid var(--gray-200); background: var(--surface); color: var(--gray-600);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .76rem; font-weight: 700; text-decoration: none;
    }
    .pg:hover { border-color: #bfdbfe; color: #2563eb; text-decoration: none; }
    .pg.cur { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pg.off { opacity: .4; pointer-events: none; }
    .pg-dots { color: var(--gray-400); font-size: .8rem; padding: 0 .1rem; }
</style>

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
<div class="pg-hero">
    <span class="pg-hero-ico"><i class="fas fa-box-open"></i></span>
    <div class="pg-hero-txt">
        <h2>Products &amp; Inventory</h2>
        <p>Manage your products and inventory</p>
    </div>
    <div class="pg-hero-actions">
        <button class="btn btn-outline" onclick="openLabelModal()">
            <i class="fas fa-print"></i> Print Labels
        </button>
        <button class="btn btn-primary" onclick="openModal('add')">
            <i class="fas fa-plus"></i> Add Product
        </button>
    </div>
</div>

<!-- Filters -->
<div class="flt-card">
    <form method="GET" class="flt-grid">
        <input type="hidden" name="sort" value="<?php echo $sort; ?>">
        <input type="hidden" name="dir" value="<?php echo $dir; ?>">
        <div class="form-group flt-search" style="margin-bottom: 0;">
            <label class="form-label">Search</label>
            <span class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control"
                    placeholder="Enter Product name / SKU / Scan bar code" value="<?php echo $search; ?>">
            </span>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
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
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>

        <?php if ($canAssignStore && !$userStoreId): ?>
        <div class="form-group" style="margin-bottom: 0;">
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

        <div class="flt-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="products.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <?php
                        $thSort('name', 'Product');
                        $thSort('barcode', 'Barcode');
                        $thSort('category', 'Category');
                        $thSort('buy_price', 'Buy Price');
                        $thSort('sell_price', 'Sell Price');
                        $thSort('stock', 'Stock');
                        ?>
                        <th>Assigned Stores</th>
                        <th>Comment</th>
                        <?php $thSort('status', 'Status'); ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted">No products found</td>
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
                                    <div class="p-cell">
                                        <?php if (!empty($product['image'])): ?>
                                            <img class="p-thumb" src="<?php echo assetUrl($product['image']); ?>" alt="">
                                        <?php else: ?>
                                            <span class="p-ph"><i class="fas fa-box"></i></span>
                                        <?php endif; ?>
                                        <span class="p-name">
                                            <strong><?php echo sanitize($product['name']); ?></strong>
                                            <?php if ($product['unit'] !== 'piece'): ?>
                                                <small class="text-muted">(
                                                    <?php echo $product['unit']; ?>)
                                                </small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="p-barcode">
                                    <?php echo $product['barcode'] ?: '-'; ?>
                                </td>
                                <td>
                                    <?php
                                    $catName = $product['category_name'] ?? '';
                                    $cc = $catColor($catName ?: 'Uncategorized');
                                    ?>
                                    <span class="pill" style="background:<?php echo $cc[0]; ?>; color:<?php echo $cc[1]; ?>;">
                                        <?php echo sanitize($catName ?: 'Uncategorized'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo formatCurrency($product['buy_price']); ?>
                                </td>
                                <td><strong>
                                        <?php echo formatCurrency($product['sell_price']); ?>
                                    </strong></td>
                                <td>
                                    <?php
                                    $qty = (float)$product['current_stock'];
                                    $min = (float)$product['min_stock'];
                                    $stockState = $qty <= 0 ? 'out' : ($qty <= $min ? 'low' : 'ok');
                                    ?>
                                    <span class="pill stock-<?php echo $stockState; ?>">
                                        <?php echo number_format($qty); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (empty($storePills)): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <div class="store-list">
                                            <?php foreach ($storePills as $sp): ?>
                                                <span class="pill pill-store">
                                                    <i class="fas fa-store"></i>
                                                    <?php echo sanitize($sp['name']); ?>
                                                    <span class="qty">&times; <?php echo $sp['qty']; ?></span>
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
                                    <span class="pill <?php echo $product['status'] === 'active' ? 'pill-on' : 'pill-off'; ?>">
                                        <?php echo ucfirst($product['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="act ghost" title="Print Label"
                                            onclick="printSingleLabel(<?php echo $product['id']; ?>)">
                                            <i class="fas fa-barcode"></i>
                                        </button>
                                        <button class="act main" title="Edit"
                                            onclick='editProduct(<?php echo json_encode(array_merge($product, [
                                                'image_url' => !empty($product['image']) ? assetUrl($product['image']) : '',
                                                'variables' => $varMapByProduct[(int) $product['id']] ?? new stdClass(),
                                            ])); ?>)'>
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <button class="act danger" title="Delete"
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
    <div class="tbl-foot">
        <span>
            <?php if ($totalFiltered === 0): ?>
                No products match these filters
            <?php else: ?>
                Showing <?php echo $offset + 1; ?> to <?php echo min($page * $perPage, $totalFiltered); ?>
                of <?php echo $totalFiltered; ?> products
            <?php endif; ?>
        </span>
        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <a class="pg <?php echo $page <= 1 ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(productQuery(['page' => max(1, $page - 1)])); ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                // A window of pages around the current one, never more than 7 links.
                $from = max(1, min($page - 3, $totalPages - 6));
                $to = min($totalPages, max($page + 3, 7));
                ?>
                <?php if ($from > 1): ?>
                    <a class="pg" href="<?php echo htmlspecialchars(productQuery(['page' => 1])); ?>">1</a>
                    <?php if ($from > 2): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $from; $i <= $to; $i++): ?>
                    <a class="pg <?php echo $i === $page ? 'cur' : ''; ?>"
                        href="<?php echo htmlspecialchars(productQuery(['page' => $i])); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($to < $totalPages): ?>
                    <?php if ($to < $totalPages - 1): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                    <a class="pg" href="<?php echo htmlspecialchars(productQuery(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                <a class="pg <?php echo $page >= $totalPages ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(productQuery(['page' => min($totalPages, $page + 1)])); ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="productModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Product</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" id="productForm" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="productId" value="">

                <div class="cols-2" style="gap: 1rem;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label required">Product Name</label>
                        <input type="text" name="name" id="productName" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Barcode</label>
                        <input type="text" name="barcode" id="productBarcode" class="form-control"
                            placeholder="Scan or type a barcode (blank = auto-generate)">
                        <small class="form-text">Leave blank and a barcode is generated for you; a scanned or typed
                            code is kept exactly as entered.</small>
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
                            <option value="">Not set</option>
                            <?php if ($unitOptions): ?>
                                <?php foreach ($unitOptions as $uo): ?>
                                    <option value="<?php echo sanitize($uo['value']); ?>">
                                        <?php echo sanitize($uo['value']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach (['piece', 'kg', 'gram', 'liter', 'pack', 'box', 'dozen'] as $u): ?>
                                    <option value="<?php echo $u; ?>"><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <small class="form-text">Edit the list on the Variables page</small>
                    </div>

                    <?php foreach ($productVariables as $pv): ?>
                        <?php if ($pv['slug'] === 'unit') { continue; } ?>
                        <div class="form-group">
                            <label class="form-label"><?php echo sanitize($pv['name']); ?></label>
                            <select name="variable[<?php echo (int) $pv['id']; ?>]"
                                id="productVar<?php echo (int) $pv['id']; ?>" class="form-control">
                                <option value="">Not set</option>
                                <?php foreach ($pv['values'] as $vv): ?>
                                    <option value="<?php echo (int) $vv['id']; ?>">
                                        <?php echo sanitize($vv['value']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="productSupplier" class="form-control">
                            <option value="">None (optional)</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo (int) $sup['id']; ?>">
                                    <?php echo sanitize($sup['name']); ?>
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

                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Product Image</label>
                        <input type="file" name="image" id="productImage" class="form-control"
                            accept="image/jpeg,image/png,image/webp,image/gif">
                        <small class="form-text">JPG, PNG, WEBP or GIF &middot; max 2 MB</small>
                        <div id="imagePreviewWrap" style="display:none; margin-top:.6rem; align-items:center; gap:.75rem;">
                            <img id="imagePreview" alt="Product image"
                                style="width:64px; height:64px; object-fit:cover; border-radius:8px; border:1px solid var(--gray-200, #e2e8f0);">
                            <label id="removeImageWrap" style="display:none; font-size:.82rem; align-items:center; gap:.35rem; cursor:pointer;">
                                <input type="checkbox" name="remove_image" id="removeImage" value="1"> Remove current image
                            </label>
                        </div>
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

            <div class="cols-2" style="gap: 1rem; margin-top: 1rem;">
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

            <div class="cols-2" style="gap: 1rem; margin-top: 1rem;">
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

            <div class="cols-2" style="gap: 1rem; margin-top: 1rem;">
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
            resetImageField();
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
        document.getElementById('productBuyPrice').value = product.buy_price;
        document.getElementById('productSellPrice').value = product.sell_price;
        document.getElementById('productStock').value = product.current_stock;
        document.getElementById('productMinStock').value = product.min_stock;
        const unitSel = document.getElementById('productUnit');
        unitSel.querySelectorAll('option[data-legacy]').forEach(function (o) { o.remove(); });
        const wantUnit = product.unit || '';
        // A unit saved before the Variables list existed (or renamed since) is not
        // in the dropdown. Offering it anyway keeps an edit from silently rewriting
        // it to the first option.
        if (wantUnit && !Array.from(unitSel.options).some(function (o) { return o.value === wantUnit; })) {
            const opt = document.createElement('option');
            opt.value = wantUnit;
            opt.textContent = wantUnit;
            opt.dataset.legacy = '1';
            unitSel.appendChild(opt);
        }
        unitSel.value = wantUnit;
        document.getElementById('productSupplier').value = product.supplier_id || '';
        document.querySelectorAll('#productForm select[id^="productVar"]').forEach(function (sel) {
            const varId = sel.id.replace('productVar', '');
            sel.value = product.variables && product.variables[varId] ? String(product.variables[varId]) : '';
        });
        document.getElementById('productStatus').value = product.status;
        document.getElementById('productComment').value = product.comment || '';

        // Show the stored image (with a remove option) so the cashier sees what is
        // already there; the file input itself stays empty until a new file is chosen.
        const wrap = document.getElementById('imagePreviewWrap');
        const prev = document.getElementById('imagePreview');
        const rmWrap = document.getElementById('removeImageWrap');
        document.getElementById('removeImage').checked = false;
        if (product.image_url) {
            prev.src = product.image_url;
            prev.style.display = 'block';
            rmWrap.style.display = 'flex';
            wrap.style.display = 'flex';
        } else {
            wrap.style.display = 'none';
        }
    }

    function resetImageField() {
        const img = document.getElementById('productImage');
        if (img) img.value = '';
        const rm = document.getElementById('removeImage');
        if (rm) rm.checked = false;
        const wrap = document.getElementById('imagePreviewWrap');
        if (wrap) wrap.style.display = 'none';
        const rmWrap = document.getElementById('removeImageWrap');
        if (rmWrap) rmWrap.style.display = 'none';
    }

    // Live-preview a newly chosen file before saving.
    document.getElementById('productImage').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        const prev = document.getElementById('imagePreview');
        prev.src = URL.createObjectURL(file);
        prev.style.display = 'block';
        document.getElementById('removeImageWrap').style.display = 'none';
        document.getElementById('removeImage').checked = false;
        document.getElementById('imagePreviewWrap').style.display = 'flex';
    });

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