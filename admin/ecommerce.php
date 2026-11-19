<?php
/**
 * POS System - My Online Shop
 *
 * Three decisions live on this page, and they are deliberately kept apart:
 *
 *   1. the shop's front-of-house details (name, delivery charge, minimum order,
 *      whether it is open right now),
 *   2. WHICH products are published online and WHICH branch fulfils them, and
 *   3. what to do with the orders customers have placed.
 *
 * Listing is its own table (ecom_listings) rather than a column on products,
 * because publishing is a per-shop decision that changes daily: a shopkeeper
 * hides tonight's stock, quotes a different price online, or moves a line to
 * another branch - none of which should touch the product record the till reads.
 *
 * An order that arrives is NOT a sale yet. Stock only moves when the shopkeeper
 * sets the order to Accepted, which writes the real sales + sale_items rows for
 * the store the customer's items came from. Setting it back to Pending, Rejected
 * or Cancelled runs the same writes in reverse, and Delete removes the order along
 * with the invoice it had become - so the order list, the Sales page and the
 * counter's stock count always say the same thing.
 */

require_once '../config/db.php';
require_once '../config/ecommerce.php';
startSecureSession();

requirePermission('ecommerce');

define('PAGE_TITLE', 'Online Shop');

$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'] ?: $currentUser['id'];

// Before anything is written: this page saves ecom_shops.cover, and a live
// database that has not seen the marketplace yet has neither the table nor the
// column. The read functions further down would create them, but a POST dies
// on its first statement before reaching them, so the request that was meant to
// fix the schema is the one that fails. Creating them here means the very first
// save is also the one that migrates.
ecommerceEnsureTables();

$stmtStores = $db->prepare("SELECT id, name FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY id ASC");
$stmtStores->execute([$ownerId]);
$stores = $stmtStores->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'shop') {
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)($_POST['slug'] ?? ''))));
            if ($slug === '' || strlen($slug) < 3) {
                throw new Exception('A shop link needs at least 3 letters or numbers (a-z, 0-9).');
            }
            $taken = $db->prepare("SELECT owner_id FROM ecom_shops WHERE slug = ? AND owner_id <> ? LIMIT 1");
            $taken->execute([$slug, $ownerId]);
            if ($taken->fetchColumn()) {
                throw new Exception('That shop link is already used by another account.');
            }

            // Read before the write below: the page loads $shop after this block, so
            // this is the only place that still knows which file the shop had a moment
            // ago - the number needed both to clear the column and to unlink the file.
            $currentCover = '';
            try {
                $have = $db->prepare("SELECT cover FROM ecom_shops WHERE owner_id = ? LIMIT 1");
                $have->execute([$ownerId]);
                $haveCover = $have->fetchColumn();
                $currentCover = ($haveCover === false || $haveCover === null) ? '' : (string)$haveCover;
            } catch (Exception $e) {
                $currentCover = '';
            }

            $up = ecomStoreShopImage(isset($_FILES['cover']) ? $_FILES['cover'] : null);
            if ($up['error'] !== null) {
                throw new Exception($up['error']);
            }

            $db->prepare("UPDATE ecom_shops SET slug = ?, name = ?, phone = ?, address = ?, area = ?,
                                 delivery_charge = ?, free_over = ?, min_order = ?, is_open = ?
                          WHERE owner_id = ?")
              ->execute([
                  $slug,
                  sanitize($_POST['name'] ?? ''),
                  sanitize($_POST['phone'] ?? ''),
                  sanitize($_POST['address'] ?? ''),
                  sanitize($_POST['area'] ?? ''),
                  max(0, (float)($_POST['delivery_charge'] ?? 0)),
                  max(0, (float)($_POST['free_over'] ?? 0)),
                  max(0, (float)($_POST['min_order'] ?? 0)),
                  !empty($_POST['is_open']) ? 1 : 0,
                  $ownerId,
              ]);

            // The picture is its own column write, not another field in the statement
            // above: an upload that failed validation has already thrown, choosing no
            // file must leave the stored picture untouched, and the old file is only
            // unlinked once the row points at the new one.
            if ($up['path'] !== null) {
                $db->prepare("UPDATE ecom_shops SET cover = ? WHERE owner_id = ?")->execute([$up['path'], $ownerId]);
                ecomDeleteShopImage($currentCover);
            } elseif (!empty($_POST['remove_cover']) && $currentCover !== '') {
                $db->prepare("UPDATE ecom_shops SET cover = NULL WHERE owner_id = ?")->execute([$ownerId]);
                ecomDeleteShopImage($currentCover);
            }

            setFlash('success', 'Shop details saved.');
        }

        if ($action === 'listing') {
            ecommerceSetListing(
                $ownerId,
                (int)($_POST['product_id'] ?? 0),
                !empty($_POST['visible']),
                array_key_exists('store_id', $_POST) ? (int)$_POST['store_id'] : null,
                isset($_POST['price_override']) && $_POST['price_override'] !== '' ? (float)$_POST['price_override'] : null
            );
            setFlash('success', 'Listing updated.');
        }

        if ($action === 'publish_all') {
            $rows = ecommerceCatalogueRows($ownerId);
            $defaultStore = !empty($stores) ? (int)$stores[0]['id'] : null;
            $count = 0;
            foreach ($rows as $r) {
                if ((int)$r['visible'] === 1) { continue; }
                ecommerceSetListing($ownerId, (int)$r['id'], true,
                    $r['store_id'] !== null ? (int)$r['store_id'] : $defaultStore, null);
                $count++;
            }
            setFlash('success', $count . ' product(s) put online. Hide any of them with its switch.');
        }

        if ($action === 'hide_all') {
            $db->prepare("UPDATE ecom_listings SET visible = 0 WHERE owner_id = ?")->execute([$ownerId]);
            setFlash('success', 'Everything is hidden from the shop.');
        }

        if ($action === 'status') {
            $res = ecommerceSetOrderStatus(
                (int)($_POST['order_id'] ?? 0),
                $ownerId,
                (string)($_POST['status'] ?? ''),
                (int)$currentUser['id']
            );
            setFlash($res['tone'], $res['text']);
        }

        if ($action === 'delete') {
            $res = ecommerceDeleteOrder((int)($_POST['order_id'] ?? 0), $ownerId, (int)$currentUser['id']);
            setFlash('warning', 'Order ' . $res['order_no'] . ' was removed.'
                . ($res['invoice'] ? ' Its invoice ' . $res['invoice'] . ' was removed too and the stock went back.' : ''));
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        setFlash('danger', $e->getMessage());
    }
    redirect('ecommerce.php');
}

$shop = ecommerceShopSettings($ownerId);
$tab  = $_GET['tab'] ?? 'products';

$publicLink = getAppBaseUrl() . '/shop/index.php?slug=' . urlencode($shop['slug']);

$listingFilter = trim((string)($_GET['q'] ?? ''));
$onlyListed = isset($_GET['listed']) && $_GET['listed'] === '1';
$allRows = ecommerceCatalogueRows($ownerId);
$listedCount = 0;
foreach ($allRows as $r) { if ((int)$r['visible'] === 1) { $listedCount++; } }

$orderStatus = $_GET['status'] ?? 'pending';
$orders = ecommerceOrders($ownerId, $orderStatus === 'all' ? null : $orderStatus);
$pendingCount = 0;
foreach (ecommerceOrders($ownerId, 'pending') as $p) { $pendingCount++; }

include 'includes/header.php';
?>

<style>
    /* Shop details: as many columns as the width allows, one column on a phone. */
    .ecom-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 0.2rem 1rem;
    }
    .ecom-open {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0;
    }
    /* The listing row is one form with four controls that have to stay on one line
       on a desktop and fold onto two lines on a phone - flex-wrap does that without
       a media query, and the table's own mobile card layout stacks the row itself. */
    .listing-form { margin: 0; }
    .listing-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        align-items: center;
    }
    .listing-grid .form-control { width: auto; min-width: 120px; }
    .listing-grid input[name="price_override"] { max-width: 120px; }
    .listing-online { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; }
    .filter-inline { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; }
    .filter-inline label { display: flex; gap: 0.35rem; align-items: center; font-size: 0.85rem; }
    /* The shop picture spans the whole grid - a 92px square squeezed between two
       text inputs reads as a mistake, and on a phone it is the only row left. */
    .ecom-cover { grid-column: 1 / -1; }
    .ecom-cover-row { display: flex; flex-wrap: wrap; gap: 0.8rem; align-items: center; }
    .ecom-cover-img,
    .ecom-cover-none {
        width: 92px; height: 92px; flex: 0 0 auto;
        border-radius: 14px; object-fit: cover;
        border: 1px solid var(--gray-100);
    }
    .ecom-cover-none {
        display: flex; align-items: center; justify-content: center;
        border-style: dashed; background: var(--gray-100);
        font-size: 1.7rem; color: var(--gray-500);
    }
    .ecom-cover-side { flex: 1 1 240px; min-width: 0; }
    .ecom-cover-remove { display: flex; gap: 0.4rem; align-items: center; font-size: 0.85rem; margin-top: 0.4rem; }
    .ecom-cover-note { font-size: 0.78rem; color: var(--gray-500); margin: 0.4rem 0 0; }
    /* An order row carries two forms now, so they stack instead of fighting for
       one line inside a table cell that is already narrow on a phone. */
    .ecom-status-form,
    .ecom-del-form { margin: 0; }
    .ecom-status-row { display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center; }
    .ecom-status-row .form-control { width: auto; flex: 1 1 150px; min-width: 150px; }
    .ecom-del-form { margin-top: 0.4rem; }
</style>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<div class="page-toolbar">
    <div>
        <h2 style="margin-bottom: 0.5rem;">My Online Shop</h2>
        <p class="text-muted">
            <?php echo $listedCount; ?> of <?php echo count($allRows); ?> product(s) are online
            <?php if ($pendingCount): ?>
                &middot; <strong><?php echo $pendingCount; ?></strong> order(s) waiting
            <?php endif; ?>
        </p>
    </div>
    <div class="table-actions">
        <a class="btn btn-outline" href="<?php echo htmlspecialchars($publicLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
            <i class="fas fa-store"></i> Open shop page
        </a>
        <button class="btn btn-primary" onclick="document.getElementById('shopForm').submit()">
            <i class="fas fa-check"></i> Save shop details
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Shop front</h3></div>
    <div class="card-body">
        <form id="shopForm" method="post" enctype="multipart/form-data" class="ecom-grid">
            <input type="hidden" name="action" value="shop">

            <div class="form-group ecom-cover">
                <label class="form-label">Shop image</label>
                <div class="ecom-cover-row">
                    <?php /* Both boxes are always in the page, only one is shown, so
                        ticking "Remove the current image" has somewhere to put the
                        placeholder back without another round trip. */ ?>
                    <div id="coverNone" class="ecom-cover-none" <?php echo !empty($shop['cover']) ? 'style="display:none;"' : ''; ?>>
                        <i class="fas fa-store"></i>
                    </div>
                    <img id="coverPreview" class="ecom-cover-img" alt=""
                         <?php echo !empty($shop['cover'])
                             ? 'src="' . htmlspecialchars(assetUrl($shop['cover']), ENT_QUOTES, 'UTF-8') . '"'
                             : 'style="display:none;"'; ?>>
                    <div class="ecom-cover-side">
                        <input type="file" name="cover" id="coverFile" class="form-control"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                        <?php if (!empty($shop['cover'])): ?>
                            <label class="ecom-cover-remove">
                                <input type="checkbox" name="remove_cover" id="coverRemove" value="1">
                                <span>Remove the current image</span>
                            </label>
                        <?php endif; ?>
                        <p class="ecom-cover-note">
                            JPG, PNG, WEBP or GIF, up to 2 MB. Customers see this picture on the shop's card
                            in the Marketplace app and at the top of the shop's page.
                        </p>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Shop name</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars((string)$shop['name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Phone shown to customers</label>
                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars((string)$shop['phone'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Shop link (letters and numbers)</label>
                <input type="text" name="slug" class="form-control" value="<?php echo htmlspecialchars((string)$shop['slug'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Delivery area</label>
                <input type="text" name="area" class="form-control" value="<?php echo htmlspecialchars((string)$shop['area'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Mirpur, 10 km">
            </div>
            <div class="form-group">
                <label class="form-label">Delivery charge (৳)</label>
                <input type="number" step="0.01" min="0" name="delivery_charge" class="form-control" value="<?php echo htmlspecialchars((string)$shop['delivery_charge'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Free delivery over (৳, 0 = never)</label>
                <input type="number" step="0.01" min="0" name="free_over" class="form-control" value="<?php echo htmlspecialchars((string)$shop['free_over'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Minimum order (৳)</label>
                <input type="number" step="0.01" min="0" name="min_order" class="form-control" value="<?php echo htmlspecialchars((string)$shop['min_order'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars((string)$shop['address'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Orders</label>
                <label class="ecom-open">
                    <input type="checkbox" name="is_open" value="1" <?php echo $shop['is_open'] ? 'checked' : ''; ?>>
                    <span>Shop is open for orders right now</span>
                </label>
            </div>
        </form>
        <p class="text-muted" style="margin-top:.6rem;font-size:.85rem;">
            Customers reach this shop at
            <a href="<?php echo htmlspecialchars($publicLink, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($publicLink, ENT_QUOTES, 'UTF-8'); ?></a>
        </p>
    </div>
</div>

<div class="card" style="margin-top:1rem;">
    <div class="card-body" style="padding:0.8rem 1rem;">
        <div class="table-actions">
            <a class="btn btn-sm btn-outline <?php echo $tab === 'products' ? 'btn-primary' : ''; ?>" href="ecommerce.php?tab=products"><i class="fas fa-box"></i> Products online</a>
            <a class="btn btn-sm btn-outline <?php echo $tab === 'orders' ? 'btn-primary' : ''; ?>" href="ecommerce.php?tab=orders"><i class="fas fa-bag-shopping"></i> Orders <?php echo $pendingCount ? '<span class="badge badge-warning">' . $pendingCount . '</span>' : ''; ?></a>
        </div>
    </div>
</div>

<?php if ($tab === 'orders'): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header">
            <h3 class="card-title">Orders</h3>
            <div class="table-actions">
                <?php foreach (['pending', 'accepted', 'delivered', 'all'] as $s): ?>
                    <a class="btn btn-sm btn-outline <?php echo $orderStatus === $s ? 'btn-primary' : ''; ?>"
                       href="ecommerce.php?tab=orders&status=<?php echo $s; ?>"><?php echo ucfirst($s); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Branch</th><th>Total</th><th>Status</th><th>Placed</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="7" class="text-center">No orders here yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o):
                    $items = ecommerceOrderItems((int)$o['id']); ?>
                    <tr>
                        <td><strong><?php echo sanitize($o['order_no']); ?></strong>
                            <div class="text-muted" style="font-size:.8rem;">
                                <?php echo count($items); ?> line(s):
                                <?php foreach ($items as $i => $it): ?>
                                    <?php echo sanitize($it['product_name']); ?> &times;<?php echo (int)$it['quantity']; ?><?php echo $i < count($items) - 1 ? ', ' : ''; ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?php echo sanitize((string)$o['customer_name']); ?>
                            <div class="text-muted" style="font-size:.8rem;"><?php echo sanitize((string)$o['customer_phone']); ?>
                            <?php echo $o['address'] ? '<br>' . sanitize((string)$o['address']) : ''; ?></div>
                        </td>
                        <td><?php echo $o['store_name'] ? sanitize($o['store_name']) : '<span class="text-muted">main branch</span>'; ?></td>
                        <td>৳<?php echo number_format((float)$o['total'], 2); ?>
                            <div class="text-muted" style="font-size:.8rem;">items ৳<?php echo number_format((float)$o['items_total'], 2); ?> + delivery ৳<?php echo number_format((float)$o['delivery_charge'], 2); ?></div>
                        </td>
                        <td><span class="badge badge-<?php echo $o['status'] === 'pending' ? 'warning' : ($o['status'] === 'rejected' || $o['status'] === 'cancelled' ? 'danger' : 'success'); ?>"><?php echo ucfirst($o['status']); ?></span>
                            <?php if (!empty($o['sale_id'])): ?>
                                <div class="text-muted" style="font-size:.8rem;">
                                    <?php if (!empty($o['invoice_number'])): ?>
                                        <a href="sales.php?search=<?php echo urlencode((string)$o['invoice_number']); ?>">
                                            <?php echo sanitize($o['invoice_number']); ?></a>
                                    <?php else: ?>
                                        billed
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d M Y h:i A', strtotime($o['created_at'])); ?></td>
                        <td>
                            <div class="table-actions">
                                <?php /* One select instead of the old row of forward-only
                                    buttons: a wrong Accept has to be undoable from the same
                                    place that made it, and the server runs the matching
                                    POS write (or reversal) for whatever is chosen. */ ?>
                                <form method="post" class="ecom-status-form">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                    <div class="ecom-status-row">
                                        <select name="status" class="form-control ecom-status"
                                                data-from="<?php echo sanitize($o['status']); ?>"
                                                data-invoice="<?php echo sanitize((string)($o['invoice_number'] ?? '')); ?>"
                                                aria-label="Status for <?php echo sanitize($o['order_no']); ?>">
                                            <?php foreach (['pending', 'accepted', 'delivered', 'rejected', 'cancelled'] as $st): ?>
                                                <option value="<?php echo $st; ?>" <?php echo $o['status'] === $st ? 'selected' : ''; ?>>
                                                    <?php echo ucfirst($st); ?><?php echo $st === 'accepted' && $o['status'] === 'pending' ? ' (bill + reduce stock)' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save</button>
                                    </div>
                                </form>
                                <form method="post" class="ecom-del-form">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                    <input type="hidden" name="order_no" value="<?php echo sanitize($o['order_no']); ?>">
                                    <input type="hidden" name="billed" value="<?php echo !empty($o['sale_id']) ? '1' : ''; ?>">
                                    <button class="btn btn-sm btn-outline"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header">
            <h3 class="card-title">Products</h3>
            <div class="table-actions">
                <form method="get" class="filter-inline">
                    <input type="hidden" name="tab" value="products">
                    <input type="search" name="q" class="form-control" style="width:180px;" value="<?php echo htmlspecialchars($listingFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search product">
                    <label><input type="checkbox" name="listed" value="1" <?php echo $onlyListed ? 'checked' : ''; ?>> online only</label>
                    <button class="btn btn-sm btn-outline"><i class="fas fa-filter"></i></button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="publish_all">
                    <button class="btn btn-sm btn-outline"><i class="fas fa-cloud-arrow-up"></i> Put all online</button>
                </form>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="hide_all">
                    <button class="btn btn-sm btn-outline" onclick="return confirm('Hide every product from the shop page?')"><i class="fas fa-eye-slash"></i> Hide all</button>
                </form>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="table">
                <thead>
                    <tr><th>Product</th><th>POS price</th><th>Branch stock</th><th>Online listing</th></tr>
                </thead>
                <tbody>
                <?php $shown = 0; foreach ($allRows as $r):
                    if ($onlyListed && (int)$r['visible'] !== 1) { continue; }
                    if ($listingFilter !== '' && stripos($r['name'], $listingFilter) === false && stripos((string)$r['barcode'], $listingFilter) === false) { continue; }
                    $shown++;
                    $qty = (float)$r['store_qty'] > 0 ? (int)$r['store_qty'] : (int)$r['stock'];
                    ?>
                    <tr>
                        <td><strong><?php echo sanitize($r['name']); ?></strong>
                            <div class="text-muted" style="font-size:.8rem;"><?php echo $r['category_name'] ? sanitize($r['category_name']) : 'no category'; ?></div>
                        </td>
                        <td>৳<?php echo number_format((float)$r['sell_price'], 2); ?></td>
                        <td><?php echo $qty; ?> <?php echo sanitize((string)$r['unit']); ?></td>
                        <td>
                            <!-- One form per row, entirely inside this cell. A <form>
                                 wrapped around the <td> elements is not valid table
                                 markup, and the browser lifts it out of the table,
                                 which is how a row's inputs end up on the page header. -->
                            <form method="post" class="listing-form">
                                <input type="hidden" name="action" value="listing">
                                <input type="hidden" name="product_id" value="<?php echo (int)$r['id']; ?>">
                                <div class="listing-grid">
                                    <label class="listing-online">
                                        <input type="checkbox" name="visible" value="1" <?php echo (int)$r['visible'] === 1 ? 'checked' : ''; ?>>
                                        <span>online</span>
                                    </label>
                                    <select name="store_id" class="form-control">
                                        <option value="0">shared stock</option>
                                        <?php foreach ($stores as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$r['store_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo sanitize($s['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="number" step="0.01" min="0" name="price_override" class="form-control"
                                           placeholder="৳<?php echo number_format((float)$r['sell_price'], 2); ?>"
                                           title="Leave blank to use the POS price"
                                           value="<?php echo $r['price_override'] !== null ? htmlspecialchars((string)$r['price_override'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                                    <button class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                    <tr><td colspan="4" class="text-center">Nothing matches. Add products on the Products page first.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    /*
        Show the chosen picture before it is saved, the way the product form does:
        a shopkeeper picking a banner from a phone gallery has no other way to tell
        the file they meant apart from its name. FileReader works on a local file the
        visitor just selected, so nothing is uploaded until the form is submitted.
    */
    (function () {
        var file = document.getElementById('coverFile');
        var preview = document.getElementById('coverPreview');
        var placeholder = document.getElementById('coverNone');
        var remove = document.getElementById('coverRemove');
        if (!file || !preview) { return; }

        function showImage(src) {
            preview.src = src;
            preview.style.display = 'block';
            if (placeholder) { placeholder.style.display = 'none'; }
        }
        function showPlaceholder() {
            preview.style.display = 'none';
            if (placeholder) { placeholder.style.display = 'flex'; }
        }

        file.addEventListener('change', function () {
            if (!file.files || !file.files.length) { return; }
            // A fresh choice outranks "remove the current image"; leaving the tick set
            // would tell the server to clear the column it has just been handed a file for.
            if (remove) { remove.checked = false; }
            var reader = new FileReader();
            reader.onload = function (e) { showImage(e.target.result); };
            reader.readAsDataURL(file.files[0]);
        });

        if (remove) {
            remove.addEventListener('change', function () {
                if (file.files && file.files.length) { return; }
                if (remove.checked) { showPlaceholder(); } else { showImage(preview.src); }
            });
        }
    })();

    /*
        A status change can now move stock and print or destroy an invoice, so the
        two directions that cost something are confirmed first. The page cannot know
        what the server will do, so these conditions mirror
        ecommerceSetOrderStatus(): pending -> billed writes a sale, billed -> anything
        else reverses one.
    */
    (function () {
        var billed = ['accepted', 'delivered'];

        function isBilled(s) { return billed.indexOf(s) !== -1; }

        document.querySelectorAll('.ecom-status-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var sel = form.querySelector('.ecom-status');
                if (!sel) { return; }
                var from = sel.getAttribute('data-from') || '';
                var to = sel.value;
                if (from === to) { return; }

                var msg = null;
                if (from === 'pending' && isBilled(to)) {
                    msg = 'Bill this order in the POS now? The items will be sold from the branch stock.';
                } else if (isBilled(from) && !isBilled(to)) {
                    var inv = sel.getAttribute('data-invoice');
                    msg = 'The stock goes back to the counter'
                        + (inv ? ' and invoice ' + inv + ' is removed' : '')
                        + '. Continue?';
                }
                if (msg && !confirm(msg)) { e.preventDefault(); }
            });
        });

        document.querySelectorAll('.ecom-del-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var no = form.querySelector('input[name="order_no"]');
                var was = form.querySelector('input[name="billed"]');
                var msg = 'Delete ' + (no && no.value ? no.value : 'this order') + '? ';
                msg += (was && was.value)
                    ? 'Its POS invoice will be removed and the items returned to stock. This cannot be undone.'
                    : 'This cannot be undone.';
                if (!confirm(msg)) { e.preventDefault(); }
            });
        });
    })();
</script>
