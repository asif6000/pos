<?php
/**
 * POS System - Point of Sale / Billing Screen
 * Main screen for creating sales and processing payments
 */

require_once '../config/db.php';
require_once '../config/sale_split.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'POS - Billing');

$db = getDB();
$user = getCurrentUser();
$isSuper = saleIsSuperAdmin($user);

// Get settings - Filter by owner
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$user['owner_id']]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$vatPercent = (float) ($settings['vat_percent'] ?? 0);

// Get categories - Filter by owner
$stmt = $db->prepare("SELECT id, name FROM categories WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$user['owner_id']]);
$categories = $stmt->fetchAll();

/**
 * One shop's VAT rate, read the only way the sale door reads it: that shop's own
 * settings row, with no platform fallback. The screen and the door have to price a
 * sale with the same number, or the door refuses the basket as a total mismatch.
 */
function posStoreVatPercent($db, $ownerId)
{
    static $cache = [];
    $key = (int) $ownerId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $vat = 0.0;
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE owner_id = ? AND setting_key = 'vat_percent' LIMIT 1");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetchColumn();
        if ($row !== false && $row !== null && $row !== '') {
            $vat = (float) $row;
        }
    } catch (Throwable $e) {
        error_log('pos store vat read: ' . $e->getMessage());
    }
    $cache[$key] = $vat;
    return $vat;
}

// Get products
// The stores this till may sell from, and the stock of each.
//
// A shop's own staff see their own stores. The platform Super Admin sees every
// shop's, because selling from any store is what they are for - and each of those
// products still belongs to the shop that owns the store it sits in, which is what
// the line below asks the database for rather than what the person typing happens
// to own. The sale, its money and its stock movements all land on that shop.
$posStores = [];
if ($isSuper) {
    $stmt = $db->query("SELECT id, name, owner_id FROM stores WHERE status = 'active' ORDER BY name");
} else {
    $stmt = $db->prepare("SELECT id, name, owner_id FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$user['owner_id']]);
}
$posStores = $stmt->fetchAll();

// This till's own store stays in the list even when it has later been marked
// inactive: it is the shelf the shop has always sold from, and dropping it here
// would empty their grid. Nobody else's inactive store is offered.
$sessionStoreId = (int) ($user['store_id'] ?? 0);
if (!$isSuper && $sessionStoreId > 0) {
    $listed = array_map('intval', array_column($posStores, 'id'));
    if (!in_array($sessionStoreId, $listed, true)) {
        $stmtOwn = $db->prepare("SELECT id, name, owner_id FROM stores WHERE id = ? AND (owner_id = ? OR owner_id IS NULL)");
        $stmtOwn->execute([$sessionStoreId, $user['owner_id']]);
        $ownStore = $stmtOwn->fetch();
        if ($ownStore) {
            $posStores[] = $ownStore;
        }
    }
}

/**
 * sales.store_id is read below, so the column has to be settled first - the same
 * call the sale door makes, which only writes when the database lacks it.
 */
$storeReady = ensureSaleStoreColumns();

/**
 * A sale being corrected (?edit_sale_id=).
 *
 * Read the way admin/api/process-sale.php reads it, because the two have to agree
 * about which store the shelf and the stock belong to: an old row that carries no
 * store_id is the store the cashier who made it stood in, which is where the
 * reports file it too. Getting that wrong here would restore the sale's stock into
 * whichever store the person editing happens to be standing in today.
 *
 * A sale that is not this account's is dropped back to a new sale rather than
 * half-shown - the door refuses the post anyway, and a pre-filled cart the cashier
 * cannot save is a worse answer than an empty one.
 */
$editSaleId = (int) ($_GET['edit_sale_id'] ?? 0);
$editSale = null;
$editItems = [];
$editStoreId = 0;
if ($editSaleId > 0) {
    $editSql = "SELECT s.id, s.user_id, s.customer_id, s.invoice_number, s.created_at,
                       s.subtotal, s.discount_percent, s.discount_amount,
                       s.vat_percent, s.vat_amount, s.total, s.paid_amount,
                       s.payment_method, s.payment_status, s.owner_id"
         . ($storeReady ? ", s.store_id" : "") . "
                FROM sales s WHERE s.id = ?";
    $stmtEdit = $db->prepare($editSql);
    $stmtEdit->execute([$editSaleId]);
    $editSale = $stmtEdit->fetch() ?: null;

    if ($editSale) {
        $editOwner = (int) ($editSale['owner_id'] ?? 0);
        $editStoreId = (int) ($editSale['store_id'] ?? 0);
        if ($editOwner <= 0 || $editStoreId <= 0) {
            $stmtMaker = $db->prepare("SELECT owner_id, store_id FROM users WHERE id = ?");
            $stmtMaker->execute([(int) $editSale['user_id']]);
            $maker = $stmtMaker->fetch();
            if ($editOwner <= 0) {
                $editOwner = (int) ($maker['owner_id'] ?? 0);
            }
            if ($editStoreId <= 0) {
                $editStoreId = (int) ($maker['store_id'] ?? 0);
            }
        }

        $mayEdit = $isSuper || $editOwner === (int) $user['owner_id'];
        if (!$mayEdit) {
            $editSale = null;
            $editSaleId = 0;
            $editStoreId = 0;
        } else {
            $stmtItems = $db->prepare("SELECT product_id, product_name, quantity, unit_price, total_price
                                       FROM sale_items WHERE sale_id = ? ORDER BY id");
            $stmtItems->execute([$editSaleId]);
            $editItems = $stmtItems->fetchAll();

            // The shelf the sale came out of has to be on the till, or the lines
            // being corrected have no products to correct against. A shop only ever
            // gets its own store back; the Super Admin gets the store the row names.
            if ($editStoreId > 0 && !in_array($editStoreId, array_map('intval', array_column($posStores, 'id')), true)) {
                $stmtE = $db->prepare($isSuper
                    ? "SELECT id, name, owner_id FROM stores WHERE id = ?"
                    : "SELECT id, name, owner_id FROM stores WHERE id = ? AND (owner_id = ? OR owner_id IS NULL)");
                $stmtE->execute($isSuper ? [$editStoreId] : [$editStoreId, $user['owner_id']]);
                $editStoreRow = $stmtE->fetch();
                if ($editStoreRow) {
                    $posStores[] = $editStoreRow;
                }
            }
        }
    }
}

// Whose shop a sale out of each of these stores is filed under, and at what rate:
// the two decisions config/sale_split.php and the sale door make, worked out here
// only so the number on the screen is the number the door books. A store that
// belongs to nobody is the shop that owns the till, which for the platform Super
// Admin is nobody at all - the door then refuses the line, and the grid below never
// lists a store it would refuse.
foreach ($posStores as $si => $posStore) {
    $saleOwner = (int) $posStore['owner_id'] > 0 ? (int) $posStore['owner_id'] : (int) $user['owner_id'];
    $posStores[$si]['sale_owner_id'] = $saleOwner;
    $posStores[$si]['vat_percent']   = $saleOwner === (int) $user['owner_id']
        ? $vatPercent
        : posStoreVatPercent($db, $saleOwner);
}

$multiStore = $isSuper || count($posStores) > 1;

// A Super Admin filtering by shop sees store names chosen by eight different
// shopkeepers, so the shop each belongs to goes on the chip. Their own staff do not
// need it - every store on their till is already theirs.
if ($multiStore && !empty($posStores)) {
    $ownerIds = array_values(array_unique(array_column($posStores, 'sale_owner_id')));
    $shopNames = [];
    if (!empty($ownerIds)) {
        $in = implode(',', array_map('intval', $ownerIds));
        $stmtShops = $db->query("SELECT owner_id, setting_value FROM settings
                                 WHERE setting_key = 'shop_name' AND owner_id IN ($in)");
        foreach ($stmtShops->fetchAll() as $shopRow) {
            $shopNames[(int) $shopRow['owner_id']] = $shopRow['setting_value'];
        }
    }
    foreach ($posStores as $si => $posStore) {
        $posStores[$si]['shop_name'] = $shopNames[(int) $posStore['sale_owner_id']] ?? '';
    }
}

// How a store is written on a card and on a cart line: its own name, and for the
// platform Super Admin the shop it belongs to as well, because "Main Shop" means
// nothing when it is one of eight shops' "Main Shop".
$storeLabels = [];
foreach ($posStores as $posStore) {
    $label = $posStore['name'];
    if ($isSuper && !empty($posStore['shop_name'])) {
        $label .= ' · ' . $posStore['shop_name'];
    }
    $storeLabels[(int) $posStore['id']] = $label;
}
$storesById = [];
foreach ($posStores as $posStore) {
    $storesById[(int) $posStore['id']] = $posStore;
}

// The same list the chips use, for the browser: each store's label and the VAT rate
// its own shop charges. The till prices a basket with these, and the door re-prices
// it from the settings of the same shops - which is why both numbers are read the
// same way rather than one being typed in.
$storesForJs = [];
foreach ($posStores as $posStore) {
    $storesForJs[] = [
        'id'          => (int) $posStore['id'],
        'name'        => $posStore['name'],
        'label'       => $storeLabels[(int) $posStore['id']],
        'vat_percent' => (float) $posStore['vat_percent'],
    ];
}

// The store a line falls back to when it names none: this till's own, or - for a
// shop with several - the first of them. A Super Admin with more than one store has
// no store of their own to fall back to, so every line they add names one.
$store_id = in_array($sessionStoreId, array_map('intval', array_column($posStores, 'id')), true)
    ? $sessionStoreId
    : 0;
if ($store_id <= 0 && !empty($posStores)) {
    $store_id = (int) $posStores[0]['id'];
    if ($isSuper && $multiStore) {
        $store_id = 0;
    }
}
$defaultStore = null;
foreach ($posStores as $posStore) {
    if ((int) $posStore['id'] === (int) $store_id) {
        $defaultStore = $posStore;
        break;
    }
}

// A sale being corrected opens on the shelf it was rung up from, whatever the
// person editing is standing in today - and that store is in the list above
// precisely so its products are here to be corrected.
if ($editSaleId > 0 && $editStoreId > 0) {
    foreach ($posStores as $posStore) {
        if ((int) $posStore['id'] === $editStoreId) {
            $store_id = $editStoreId;
            $defaultStore = $posStore;
            break;
        }
    }
}

if ($multiStore) {
    // One row per (product, store) pair that has stock, so a basket can hold the same
    // product from two shelves and each line says which one it came from.
    $sql = "SELECT p.id, p.name, p.barcode, p.sell_price, p.image, p.category_id,
                   s.id AS store_id, s.name AS store_name, ss.quantity AS stock
            FROM products p
            JOIN store_stocks ss ON ss.product_id = p.id AND ss.quantity > 0
            JOIN stores s ON s.id = ss.store_id
            WHERE p.status = 'active'
              AND s.id IN (" . implode(',', array_map('intval', array_column($posStores, 'id'))) . ")
              AND (p.owner_id = s.owner_id OR p.owner_id IS NULL) ";
    $args = [];
    if (!$isSuper) {
        $sql .= " AND p.owner_id = ?";
        $args[] = $user['owner_id'];
    }
    $sql .= " ORDER BY p.name, s.name";
    $stmt = $db->prepare($sql);
    $stmt->execute($args);
    $products = $stmt->fetchAll();
} else {
    $sql = "SELECT p.id, p.name, p.barcode, p.sell_price, p.image, COALESCE(ss.quantity, 0) as stock, p.category_id 
            FROM products p 
            LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ? 
            WHERE p.status = 'active' AND p.owner_id = ? AND COALESCE(ss.quantity, 0) > 0 
            ORDER BY p.name";
    $stmt = $db->prepare($sql);
    $stmt->execute([$store_id, $user['owner_id']]);
    $products = $stmt->fetchAll();
    foreach ($products as $pi => $product) {
        $products[$pi]['store_id']   = (int) $store_id;
        $products[$pi]['store_name'] = $defaultStore['name'] ?? '';
    }
}

// One label per row, from the same list the chips use, so a card and the line it
// adds to the cart always say the same thing about where the goods sit.
foreach ($products as $pi => $product) {
    $rowStore = (int) $product['store_id'];
    $products[$pi]['store_id']    = $rowStore;
    $products[$pi]['store_label'] = $storeLabels[$rowStore] ?? ($product['store_name'] ?? '');
}

// What the till prefills when a sale is being corrected. The store it belongs to
// travels with it, because the door reads that one line back and the cart has to
// show the shelf the goods came off - not whichever one this person is on today.
$editSaleData = null;
if ($editSaleId > 0 && $editSale) {
    $editSaleData = [
        'sale'       => $editSale,
        'items'      => $editItems,
        'store_id'   => $editStoreId,
        'store_name' => $storeLabels[$editStoreId] ?? ($editSale['store_name'] ?? ''),
    ];
}

// Get customers - Filter by owner
$stmt = $db->prepare("SELECT id, name, phone FROM customers WHERE owner_id = ? ORDER BY id ASC");
$stmt->execute([$user['owner_id']]);
$customers = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    /* Modern POS UI Variables */
    :root {
        --pos-bg: #f8fafc;
        --pos-card: #ffffff;
        --pos-primary: #4f46e5;
        --pos-primary-hover: #4338ca;
        --pos-text: #1e293b;
        --pos-text-muted: #64748b;
        --pos-border: #e2e8f0;
        --pos-radius: 12px;
        --pos-radius-lg: 16px;
        --pos-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --pos-shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --pos-shadow-hover: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    }

    body {
        background-color: var(--pos-bg);
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    .pos-page .content {
        padding: 1.5rem;
        height: calc(100vh - var(--header-height));
        overflow: hidden;
        background-color: var(--pos-bg);
    }

    .pos-container {
        height: 100%;
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 1.5rem;
    }

    .pos-products {
        background: var(--pos-card);
        border-radius: var(--pos-radius-lg);
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        height: 100%;
        box-shadow: var(--pos-shadow);
        border: 1px solid var(--pos-border);
    }

    .pos-product-grid {
        flex: 1;
        overflow-y: auto;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 0.8rem;
        padding-right: 0.5rem;
        margin-top: 0.5rem;
        align-content: start;
        align-items: start;
    }

    /* Product Card override from style.css */
    .pos-product-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 0.75rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
        min-height: 190px;
        position: relative;
        overflow: hidden;
    }

    .pos-product-card:hover, .pos-product-card.active-card {
        background: #e8f5e9;
        border-color: #10b981;
    }

    .pos-product-img {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        margin: 0.5rem auto;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: bold;
        color: #94a3b8;
    }

    .pos-product-name {
        font-size: 0.8rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 0.25rem;
        line-height: 1.2;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        flex: 1;
    }

    .pos-product-price {
        font-size: 0.85rem;
        font-weight: 700;
        color: #10b981;
        margin-bottom: 0.5rem;
    }

    .pos-product-stock {
        display: none;
    }

    .pos-add-btn {
        background: #10b981;
        border: none;
        border-radius: 99px;
        color: white;
        padding: 0.35rem;
        display: flex;
        align-items: center;
        width: 100%;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: auto;
    }
    
    .pos-product-card:hover .pos-add-btn {
        background: #059669;
    }
    
    .pos-add-icon {
        background: rgba(255, 255, 255, 0.3);
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
    }
    
    .pos-add-text {
        flex: 1;
        text-align: center;
        font-weight: 600;
        font-size: 0.8rem;
        margin-right: 24px;
    }

    /* Search Bar styling */
    .pos-search-wrapper {
        position: relative;
        margin-bottom: 1.25rem;
    }
    
    .pos-search-wrapper input {
        width: 100%;
        padding: 0.875rem 6.5rem 0.875rem 2.75rem;
        border: 2px solid var(--pos-border);
        border-radius: var(--pos-radius);
        font-size: 1rem;
        transition: all 0.2s ease;
        background: #f8fafc;
        color: var(--pos-text);
    }
    
    .pos-search-wrapper input:focus {
        border-color: var(--pos-primary);
        background: var(--pos-card);
        outline: none;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
    }
    
    .pos-search-wrapper i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--pos-text-muted);
        font-size: 1.1rem;
    }

    /* Categories styling */
    .pos-categories {
        display: flex;
        gap: 0.75rem;
        overflow-x: auto;
        padding-bottom: 0.5rem;
        margin-bottom: 0.5rem;
    }
    
    .pos-categories::-webkit-scrollbar {
        height: 4px;
    }
    .pos-categories::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .pos-category-btn {
        padding: 0.6rem 1.25rem;
        border: 1px solid var(--pos-border);
        background: var(--pos-bg);
        border-radius: 99px;
        white-space: nowrap;
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--pos-text-muted);
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .pos-category-btn:hover {
        background: #e2e8f0;
        color: var(--pos-text);
    }

    .pos-category-btn.active {
        background: var(--pos-primary);
        border-color: var(--pos-primary);
        color: white;
        box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.3);
    }

    /* Which shop's shelf a product sits on. The same chip as a category, in its own
       colour, so the two filters do not read as one choice. */
    .pos-store-btn {
        background: #fffbeb;
        border-color: #fde68a;
        color: #92400e;
    }

    .pos-store-btn:hover {
        background: #fef3c7;
        color: #7c2d12;
    }

    .pos-store-btn.active {
        background: #b45309;
        border-color: #b45309;
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(180, 83, 9, 0.3);
    }

    .pos-store-hint {
        font-size: 0.75rem;
        color: var(--pos-text-muted);
        margin: -0.25rem 0 0.5rem;
    }

    .pos-product-store {
        align-self: flex-start;
        background: #f1f5f9;
        color: var(--pos-text-muted);
        border-radius: 99px;
        padding: 0.1rem 0.45rem;
        font-size: 0.65rem;
        margin-top: 0.15rem;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .pos-cart-item-store {
        font-size: 0.65rem;
        color: #b45309;
        font-weight: 600;
    }

    /* One till, several shops' goods: the tender is said per shop, because the cash
       physically goes into different tills and each shop's bill is its own sale. */
    .pos-store-payments {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
        margin-bottom: 0.4rem;
        max-height: 150px;
        overflow-y: auto;
    }

    .pos-store-payment-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.78rem;
    }

    .pos-store-payment-row .pos-store-payment-name {
        flex: 1;
        color: #92400e;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .pos-store-payment-row input {
        width: 92px;
        text-align: right;
        font-size: 0.8rem;
        padding: 0.2rem 0.4rem;
        height: 28px;
    }

    .pos-store-payment-row .pos-store-payment-total {
        width: 78px;
        text-align: right;
        color: var(--pos-text-muted);
    }

    /* Two bills from one basket: each stacked under the other, each sendable on its
       own, because the customer's receipt belongs to the shop whose goods it lists. */
    .invoice-bill + .invoice-bill {
        border-top: 2px dashed #94a3b8;
        margin-top: 1rem;
        padding-top: 0.75rem;
    }

    .invoice-bill-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.75rem;
        color: #92400e;
        background: #fffbeb;
        border-radius: 6px;
        padding: 0.3rem 0.5rem;
        margin-bottom: 0.5rem;
    }

    .invoice-bill-bar button {
        border: 1px solid #fde68a;
        background: #fff;
        color: #92400e;
        border-radius: 99px;
        padding: 0.15rem 0.5rem;
        font-size: 0.68rem;
        cursor: pointer;
    }

    .invoice-bill-bar button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Cart Section styling */
    .pos-cart {
        background: var(--pos-card);
        border-radius: var(--pos-radius-lg);
        box-shadow: var(--pos-shadow-lg);
        display: flex;
        flex-direction: column;
        height: 100%;
        border: 1px solid var(--pos-border);
        overflow: hidden;
    }

    .pos-cart-header {
        padding: 0.75rem 1rem;
        background: var(--pos-bg);
        border-bottom: 1px solid var(--pos-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pos-cart-title {
        font-weight: 700;
        font-size: 1.15rem;
        color: var(--pos-text);
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .pos-cart-count {
        background: var(--pos-primary);
        color: white;
        font-size: 0.8rem;
        padding: 0.2rem 0.6rem;
        border-radius: 99px;
    }

    .pos-cart-items {
        flex: 1;
        overflow-y: auto;
        padding: 1rem;
    }

    /* Cart Item styling */
    .pos-cart-item {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1rem 0;
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 0.75rem;
    }
    
    .pos-cart-item:last-child {
        border-bottom: none;
    }

    .pos-cart-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        font-weight: bold;
        color: #94a3b8;
        flex-shrink: 0;
    }

    .pos-cart-item-info {
        display: flex;
        flex-direction: column;
        justify-content: center;
        flex: 1;
    }

    .pos-cart-item-name {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.85rem;
        margin-bottom: 0.2rem;
    }

    .pos-cart-item-price {
        font-weight: 600;
        color: #64748b;
        font-size: 0.8rem;
    }

    .pos-cart-item-qty {
        display: flex;
        align-items: center;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        overflow: hidden;
        height: 28px;
    }

    .pos-cart-item-qty button {
        width: 24px;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: none;
        cursor: pointer;
        color: #64748b;
        transition: all 0.2s;
        font-size: 0.9rem;
    }

    .pos-cart-item-qty button:hover {
        background: #f1f5f9;
    }

    .pos-cart-item-qty input {
        width: 32px;
        height: 100%;
        border: none;
        border-left: 1px solid #e2e8f0;
        border-right: 1px solid #e2e8f0;
        text-align: center;
        font-weight: 600;
        font-size: 0.85rem;
        color: #1e293b;
        -moz-appearance: textfield;
        background: transparent;
    }
    
    .pos-cart-item-qty input::-webkit-outer-spin-button,
    .pos-cart-item-qty input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .pos-cart-item-remove {
        color: #ef4444;
        background: transparent;
        border: 1px solid #fca5a5;
        width: 28px;
        height: 28px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .pos-cart-item-remove:hover {
        background: #fef2f2;
    }

    .pos-cart-footer {
        padding: 0.5rem;
        background: var(--pos-bg);
        border-top: 1px solid var(--pos-border);
        overflow-y: auto;
    }

    .pos-cart-summary {
        background: white;
        padding: 0.25rem 0.5rem;
        border-radius: var(--pos-radius);
        border: 1px solid var(--pos-border);
        margin-bottom: 0.4rem;
    }

    .pos-cart-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.15rem;
        font-size: 0.75rem;
        color: var(--pos-text-muted);
    }

    .pos-cart-row:last-child {
        margin-bottom: 0;
    }

    .pos-cart-row.total {
        border-top: 1px dashed var(--pos-border);
        padding-top: 0.25rem;
        margin-top: 0.25rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--pos-text);
    }

    .pos-payment-methods {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.2rem;
        margin-bottom: 0.4rem;
    }

    .pos-payment-btn {
        padding: 0.3rem 0.2rem;
        border: 1px solid var(--pos-border);
        background: white;
        border-radius: 8px;
        font-size: 0.65rem;
        font-weight: 500;
        color: var(--pos-text-muted);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.1rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .pos-payment-btn i {
        font-size: 0.8rem;
    }

    .pos-payment-btn:hover {
        background: #f1f5f9;
        color: var(--pos-primary);
        border-color: #cbd5e1;
    }

    .pos-payment-btn.active {
        background: #eff6ff;
        border-color: #3b82f6;
        color: #2563eb;
    }

    .pos-checkout-btn {
        width: 100%;
        padding: 0.5rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        border-radius: var(--pos-radius);
        font-size: 0.85rem;
        font-weight: 600;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.3rem;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);
    }

    .pos-checkout-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(16, 185, 129, 0.3);
    }
    
    .pos-checkout-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    /* Barcode Scanner Toast Notifications */
    .barcode-toast {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        padding: 1rem 2rem;
        border-radius: var(--pos-radius-lg);
        font-weight: 600;
        z-index: 9999;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: var(--pos-shadow-lg);
        animation: slideDown 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .barcode-toast.success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }

    .barcode-toast.error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
    }

    .barcode-toast i {
        font-size: 1.25rem;
    }

    .barcode-toast.fade-out {
        opacity: 0;
        transform: translateX(-50%) translateY(-20px);
        transition: all 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    }

    /* Scanner indicator */
    .pos-search-wrapper::after {
        content: '📷 Scanner Ready';
        position: absolute;
        right: 52px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.75rem;
        color: var(--pos-text-muted);
        padding: 0.3rem 0.6rem;
        background: #f1f5f9;
        border-radius: 6px;
        transition: all 0.2s ease;
        font-weight: 500;
    }
    
    .pos-search-wrapper.scanner-active::after {
        content: '🔍 Scanning...';
        background: var(--pos-primary);
        color: white;
        animation: pulse 1s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; }
        100% { opacity: 1; }
    }

    /* Camera scan button */
    .pos-camera-btn {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        border: none;
        border-radius: 8px;
        background: var(--pos-primary);
        color: #fff;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        transition: all 0.2s ease;
        z-index: 2;
    }

    .pos-camera-btn:hover {
        background: #4338ca;
    }

    .pos-camera-btn.active {
        background: #e11d48;
    }

    /* Customer Search Dropdown */
    .customer-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1000;
        background: var(--pos-card);
        border: 1px solid var(--pos-border);
        border-radius: var(--pos-radius);
        box-shadow: var(--pos-shadow-lg);
        max-height: 220px;
        overflow-y: auto;
        margin-top: 6px;
    }
    .customer-dropdown-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.2s;
    }
    .customer-dropdown-item:last-child {
        border-bottom: none;
    }
    .customer-dropdown-item:hover,
    .customer-dropdown-item.active {
        background: #f8fafc;
    }
    .customer-dropdown-item i {
        color: var(--pos-text-muted);
        width: 16px;
        text-align: center;
    }
    .customer-dropdown-item .customer-name {
        font-weight: 600;
        color: var(--pos-text);
    }
    .customer-dropdown-item .customer-phone {
        color: var(--pos-text-muted);
        margin-left: auto;
        font-size: 0.8rem;
        background: #f1f5f9;
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
    }
    .customer-dropdown-empty {
        padding: 1rem;
        font-size: 0.9rem;
        color: var(--pos-text-muted);
        text-align: center;
    }
    
    /* Scrollbar for POS */
    ::-webkit-scrollbar {
        width: 6px;
    }
    ::-webkit-scrollbar-track {
        background: transparent;
    }
    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Responsive Design for smaller screens */
    @media (max-width: 1024px) {
        .pos-container {
            grid-template-columns: 1fr 320px;
            gap: 1rem;
        }
    }
    
    @media (max-width: 768px) {
        .pos-container {
            grid-template-columns: 1fr;
            height: auto;
            min-height: 100vh;
        }
        
        .pos-page .content {
            height: auto;
            overflow: visible;
            padding: 1rem;
        }
        
        .pos-product-grid {
            grid-template-columns: repeat(auto-fill, minmax(95px, 1fr));
            gap: 0.6rem;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .pos-product-card {
            min-height: 100px;
            padding: 0.6rem;
        }
        
        .pos-product-name {
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        .pos-product-price {
            font-size: 0.9rem;
        }
        
        .pos-product-stock {
            padding: 0.15rem 0.4rem;
            font-size: 0.65rem;
        }
        
        .pos-cart {
            margin-top: 1rem;
        }
    }
</style>

<div class="pos-container">
    <!-- Products Section -->
    <div class="pos-products">
        <!-- Search -->
        <div class="pos-search">
            <div class="pos-search-wrapper">
                <i class="fas fa-search"></i>
                <input type="text" id="productSearch" class="form-control"
                    placeholder="Search by name or scan barcode..." autofocus>
                <button type="button" class="pos-camera-btn" id="cameraScanBtn" title="Scan barcode with camera">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
        </div>

        <!-- Categories -->
        <div class="pos-categories">
            <button class="pos-category-btn active" data-category="all">All</button>
            <?php foreach ($categories as $cat): ?>
                <button class="pos-category-btn" data-category="<?php echo $cat['id']; ?>">
                    <?php echo sanitize($cat['name']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($multiStore): ?>
        <!-- Which shop's shelf. Picking one is how a barcode that exists in two
             stores is told apart; "All stores" is the list every shop's stock. -->
        <div class="pos-categories">
            <button class="pos-category-btn pos-store-btn active" data-store="all">All stores</button>
            <?php foreach ($posStores as $posStore): ?>
                <button class="pos-category-btn pos-store-btn" data-store="<?php echo (int) $posStore['id']; ?>"
                        title="<?php echo sanitize($posStore['shop_name'] ?: $posStore['name']); ?>">
                    <?php echo sanitize($posStore['name']); ?><?php echo $isSuper && !empty($posStore['shop_name']) ? ' · ' . sanitize($posStore['shop_name']) : ''; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Products Grid -->
        <div class="pos-product-grid" id="productGrid">
            <?php foreach ($products as $product): ?>
                <div class="pos-product-card" data-id="<?php echo $product['id']; ?>"
                    data-name="<?php echo sanitize($product['name']); ?>" data-price="<?php echo $product['sell_price']; ?>"
                    data-stock="<?php echo $product['stock']; ?>" data-barcode="<?php echo $product['barcode']; ?>"
                    data-category="<?php echo $product['category_id']; ?>"
                    data-store="<?php echo (int) $product['store_id']; ?>">
                    <div class="pos-product-img">
                        <?php if (!empty($product['image'])): ?>
                            <img src="<?php echo htmlspecialchars(assetUrl($product['image']), ENT_QUOTES, 'UTF-8'); ?>" alt=""
                                style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                        <?php else: ?>
                            <?php echo strtoupper(substr(sanitize($product['name']), 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="pos-product-name">
                        <?php echo sanitize($product['name']); ?>
                    </div>
                    
                    <div class="pos-product-price">
                        <?php echo formatCurrency($product['sell_price']); ?>
                    </div>

                    <?php if ($multiStore): ?>
                        <div class="pos-product-store" title="Ei store-r stock theke bikri hobea">
                            <?php echo sanitize($storeLabels[(int) $product['store_id']] ?? $product['store_name']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="pos-product-stock <?php echo $product['stock'] <= 10 ? 'low' : ''; ?>">
                        Stock: <?php echo $product['stock']; ?>
                    </div>

                    <button class="pos-add-btn">
                        <div class="pos-add-icon"><i class="fas fa-plus"></i></div>
                        <div class="pos-add-text">ADD</div>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Cart Section -->
    <div class="pos-cart" id="cartPanel">
        <div class="pos-cart-header">
            <div class="pos-cart-title">
                <i class="fas fa-shopping-cart"></i>
                Shopping Cart
                <span class="pos-cart-count" id="cartCount">0</span>
            </div>
            <button class="btn btn-sm btn-outline" onclick="clearCart()" title="Clear Cart">
                <i class="fas fa-trash"></i>
            </button>
        </div>

        <div class="pos-cart-items" id="cartItems">
            <div class="pos-cart-empty">
                <i class="fas fa-shopping-cart"
                    style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                <p>Your cart is empty</p>
                <p class="text-muted" style="font-size: 0.875rem;">Click on products to add them</p>
            </div>
        </div>

        <div class="pos-cart-footer">
            <!-- Customer Selection -->
            <div class="form-group" style="margin-bottom: 0.4rem; display: flex; gap: 0.4rem; align-items: center;">
                <div class="customer-search-wrap" style="position: relative; flex: 1;">
                    <input type="text" id="customerSearch" class="form-control" placeholder="Search customer..." autocomplete="off" oninput="filterCustomers()" onfocus="filterCustomers()">
                    <input type="hidden" id="customerId" value="">
                    <div id="customerDropdown" class="customer-dropdown" style="display: none;"></div>
                </div>
                <button type="button" class="btn btn-primary" style="padding: 0.2rem 0.5rem;" onclick="openCustomerModal()" title="Add New Customer">
                    <i class="fas fa-user-plus" style="font-size: 0.8rem;"></i>
                </button>
            </div>

            <!-- Summary -->
            <div class="pos-cart-summary">
                <div class="pos-cart-row">
                    <span>Subtotal</span>
                    <span id="subtotal">
                        <?php echo CURRENCY; ?> 0.00
                    </span>
                </div>
                <div class="pos-cart-row">
                    <span>Discount</span>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <select id="discountType" class="form-control"
                            style="padding: 0.25rem; width: 70px; height: 30px; font-size: 0.8rem;">
                            <option value="percent">%</option>
                            <option value="fixed">Fixed</option>
                        </select>
                        <input type="number" id="discountValue" class="form-control"
                            style="width: 70px; padding: 0.25rem; text-align: right; height: 30px;" value="0" min="0">
                    </div>
                </div>
                <div class="pos-cart-row">
                    <span>Discount Amount</span>
                    <span id="discountAmount">-
                        <?php echo CURRENCY; ?> 0.00
                    </span>
                </div>
                <div class="pos-cart-row">
                    <span>VAT (
                        <?php echo $vatPercent; ?>%)
                    </span>
                    <span id="vatAmount">
                        <?php echo CURRENCY; ?> 0.00
                    </span>
                </div>
                <div class="pos-cart-row total">
                    <span>Total</span>
                    <span id="totalAmount">
                        <?php echo CURRENCY; ?> 0.00
                    </span>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="pos-payment-methods">
                <button class="pos-payment-btn active" data-method="cash">
                    <i class="fas fa-money-bill"></i> Cash
                </button>
                <button class="pos-payment-btn" data-method="bkash">
                    <i class="fas fa-mobile-alt"></i> bKash
                </button>
                <button class="pos-payment-btn" data-method="nagad">
                    <i class="fas fa-mobile-alt"></i> Nagad
                </button>
                <button class="pos-payment-btn" data-method="rocket">
                    <i class="fas fa-mobile-alt"></i> Rocket
                </button>
                <button class="pos-payment-btn" data-method="card">
                    <i class="fas fa-credit-card"></i> Card
                </button>
                <button class="pos-payment-btn" data-method="bank">
                    <i class="fas fa-university"></i> Bank
                </button>
                <button class="pos-payment-btn" data-method="lottery">
                    <i class="fas fa-ticket"></i> Lottery Sale
                </button>
            </div>

            <!-- Paid Amount. One input for one shop's bill; when a basket spans two
                 shops each shop's tender is typed beside its own total, because the
                 change is counted per bill and the cash lands in two tills. -->
            <div id="singlePaymentRow" style="display: flex; gap: 0.4rem; margin-bottom: 0.4rem;">
                <input type="number" id="paidAmount" class="form-control" placeholder="Paid Amount"
                    style="flex: 1; font-size: 0.85rem; padding: 0.25rem 0.5rem; height: 30px;">
                <button class="btn btn-light" onclick="setExactAmount()"
                    style="background: #e2e8f0; border: none; padding: 0 0.5rem; font-weight: 600; font-size: 0.75rem; height: 30px;">Exact</button>
            </div>
            <div class="pos-store-payments" id="storePaymentRows" style="display: none; margin-bottom: 0.4rem;"></div>
            <div class="pos-cart-row" id="changeRow" style="display: none; margin-bottom: 0.75rem;">
                <span><strong>Change</strong></span>
                <span id="changeAmount" class="text-success"><strong>
                        <?php echo CURRENCY; ?> 0.00
                    </strong></span>
            </div>

            <!-- Checkout Button -->
            <button class="btn btn-success pos-checkout-btn" id="checkoutBtn" onclick="processCheckout()" disabled>
                <i class="fas fa-check-circle"></i>
                Complete Sale
            </button>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal-overlay" id="invoiceModal">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title">Invoice</h3>
            <button class="modal-close" onclick="closeInvoiceModal()">&times;</button>
        </div>
        <div class="modal-body" id="invoiceContent">
            <!-- Invoice will be inserted here -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeInvoiceModal()">Close</button>
            <button class="btn btn-sms" id="invoiceSmsBtn" onclick="sendInvoiceSms()"
                    style="background:#4b5563;color:#fff;border:none;">
                <i class="fas fa-comment-sms"></i> SMS
            </button>
            <button class="btn btn-primary" onclick="printInvoice()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- Camera Scan Modal -->
<div class="modal-overlay" id="cameraScanModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-camera"></i> Scan Barcode</h3>
            <button class="modal-close" onclick="closeCameraScan()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="cameraScanReader" style="width: 100%;"></div>
            <p style="text-align: center; margin-top: 10px; color: #6b7280; font-size: 0.85rem;">Point the camera at the product barcode</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCameraScan()">Close</button>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('assets/js/qrcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('assets/js/html5-qrcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
    // Global variables
    let cart = [];
    let paymentMethod = 'cash';
    let currentBasket = null;
    const currency = '<?php echo CURRENCY; ?>';
    // This shop's own rate. A one-shop basket is priced with it, exactly as before;
    // a basket that spans shops is priced per shop, from storesData below.
    const vatPercent = <?php echo $vatPercent; ?>;
    const multiStore = <?php echo $multiStore ? 'true' : 'false'; ?>;
    const storesData = <?php echo json_encode($storesForJs); ?>;
    const cartStorageKey = 'pos_cart_<?php echo $user['id']; ?>_<?php echo $store_id; ?>';
    const editSaleId = <?php echo $editSaleId ?: 0; ?>;
    const editSaleData = <?php echo $editSaleData ? json_encode($editSaleData) : 'null'; ?>;
    const customersData = <?php echo json_encode($customers); ?>;
    const productsData = <?php echo json_encode($products); ?>;

    const storesById = {};
    storesData.forEach(s => { storesById[parseInt(s.id)] = s; });

    // A product stocked in two shops is two rows here, so a cart line is only
    // itself once its shelf is named with it. Everything that finds a line by id
    // alone would merge two shops' stock of the same shirt into one line - and the
    // server books each shelf separately.
    function cartKey(storeId, productId) {
        return parseInt(storeId) + ':' + parseInt(productId);
    }

    function storeLabel(storeId) {
        const s = storesById[parseInt(storeId)];
        return s ? s.label : '';
    }

    function storeVatPercent(storeId) {
        const s = storesById[parseInt(storeId)];
        return s ? parseFloat(s.vat_percent) : vatPercent;
    }

    /**
     * One product row into one cart line, shelf included.
     *
     * The shape is written in one place because a line that forgets its store is a
     * line the server cannot book: two shops' stock of the same product would merge
     * into one row and the deduction would land on whichever of them got asked
     * first.
     */
    function productToCartLine(p) {
        const storeId = parseInt(p.store_id) || 0;
        return {
            key: cartKey(storeId, p.id),
            id: parseInt(p.id),
            store: storeId,
            storeName: storeLabel(storeId),
            name: p.name,
            price: parseFloat(p.sell_price),
            stock: parseInt(p.stock)
        };
    }

    /**
     * One token per basket, so a double tap cannot write the sale twice.
     *
     * The server keeps the first one it sees and replays that receipt for any post
     * carrying the same token, which is why a fresh one is taken as soon as a sale
     * is saved - reusing the old token would silently return the finished receipt
     * instead of ringing up the next customer.
     */
    function newSaleToken() {
        return 'pos-<?php echo (int) $user['id']; ?>-' + Date.now().toString(36)
            + '-' + Math.random().toString(36).slice(2, 10);
    }
    let saleToken = newSaleToken();

    // Initialize cart if editing
    if (editSaleData) {
        const editStore = parseInt(editSaleData.store_id) || 0;
        const productsMap = {};
        productsData.forEach(p => {
            if (!editStore || parseInt(p.store_id) === editStore) {
                productsMap[cartKey(p.store_id, p.id)] = p;
            }
        });

        editSaleData.items.forEach(item => {
            const product = productsMap[cartKey(editStore, item.product_id)];
            if (product) {
                cart.push({
                    key: cartKey(editStore, item.product_id),
                    id: parseInt(item.product_id),
                    store: editStore,
                    storeName: editSaleData.store_name || storeLabel(editStore),
                    name: item.product_name,
                    price: parseFloat(item.unit_price),
                    stock: parseInt(product.stock) + parseInt(item.quantity),
                    quantity: parseInt(item.quantity)
                });
            }
        });

        document.getElementById('customerId').value = editSaleData.sale.customer_id || '';
        const editCustomer = customersData.find(c => c.id == editSaleData.sale.customer_id);
        if (editCustomer) {
            document.getElementById('customerSearch').value = editCustomer.name;
        }

        if (parseFloat(editSaleData.sale.discount_amount) > 0 && parseFloat(editSaleData.sale.discount_percent) == 0) {
            document.getElementById('discountType').value = 'fixed';
            document.getElementById('discountValue').value = editSaleData.sale.discount_amount;
        } else {
            document.getElementById('discountType').value = 'percent';
            document.getElementById('discountValue').value = editSaleData.sale.discount_percent;
        }

        paymentMethod = editSaleData.sale.payment_method;
        document.querySelectorAll('.pos-payment-btn').forEach(btn => {
            if (btn.dataset.method === paymentMethod) {
                btn.click();
            }
        });

        // The chips open on the shelf the sale was rung up from, so the grid the
        // cashier sees is the grid the lines came out of.
        if (multiStore && editStore) {
            selectStoreChip(editStore);
        }
    }

    // USB Barcode Scanner Support
    let barcodeBuffer = '';
    let lastKeyTime = 0;
    const BARCODE_TIMEOUT = 150; // Increased timeout for better reliability (ms)

    // Customer Search
    const customerSearchInput = document.getElementById('customerSearch');
    const customerDropdown = document.getElementById('customerDropdown');

    function filterCustomers() {
        const q = customerSearchInput.value.trim().toLowerCase();
        const qDigits = q.replace(/\D/g, '');
        const results = customersData.filter(c => {
            if (!q) return true;
            if (c.name && c.name.toLowerCase().includes(q)) return true;
            const phoneDigits = (c.phone || '').replace(/\D/g, '');
            if (qDigits && phoneDigits && phoneDigits.includes(qDigits)) return true;
            return false;
        });

        if (!results.length) {
            customerDropdown.innerHTML = '<div class="customer-dropdown-empty">No customers found</div>';
        } else {
            customerDropdown.innerHTML = results.map(c => `
                <div class="customer-dropdown-item" onclick="selectCustomer(${c.id})">
                    <i class="fas fa-user"></i>
                    <span class="customer-name">${escapeHtml(c.name)}</span>
                    ${c.phone ? `<span class="customer-phone">${escapeHtml(c.phone)}</span>` : ''}
                </div>
            `).join('');
        }
        customerDropdown.style.display = 'block';
    }

    function selectCustomer(id) {
        const customer = customersData.find(c => c.id == id);
        if (!customer) return;
        document.getElementById('customerId').value = id;
        customerSearchInput.value = customer.name;
        customerDropdown.style.display = 'none';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.customer-search-wrap')) {
            customerDropdown.style.display = 'none';
        }
    });


    document.addEventListener('keydown', function (e) {
        const now = Date.now();
        const searchInput = document.getElementById('productSearch');
        const searchWrapper = document.querySelector('.pos-search-wrapper');
        const isNewScan = (now - lastKeyTime) > BARCODE_TIMEOUT;
        lastKeyTime = now;

        if (document.activeElement.tagName === 'INPUT' &&
            document.activeElement !== searchInput &&
            document.activeElement.type !== 'hidden') {
            return;
        }

        if (e.key === 'Enter') {
            let barcode = barcodeBuffer.trim();
            barcodeBuffer = '';
            searchWrapper.classList.remove('scanner-active');

            if (barcode.length < 3) {
                barcode = searchInput.value.trim();
            }

            if (barcode.length >= 3) {
                e.preventDefault();
                searchWrapper.classList.add('scanner-active'); // Show scanning state
                handleBarcodeScanned(barcode);
                searchInput.value = '';
                // Remove active state after delay
                setTimeout(() => {
                    searchWrapper.classList.remove('scanner-active');
                }, 1000);
            }
            return;
        }

        if (isNewScan) {
            barcodeBuffer = '';
            searchWrapper.classList.remove('scanner-active'); // Remove active state
        }

        // Build barcode buffer (alphanumeric and common barcode characters)
        if (e.key.length === 1 && /[a-zA-Z0-9\-*+#]/.test(e.key)) {
            barcodeBuffer += e.key;
            searchWrapper.classList.add('scanner-active'); // Show scanning state
            if (document.activeElement !== searchInput) {
                searchInput.value = barcodeBuffer;
                searchInput.focus();
            }
        }
    });

    function handleBarcodeScanned(barcode) {
        console.log('Barcode scanned:', barcode);
        console.log('Products data length:', productsData.length);
        
        // Validate barcode length
        if (!barcode || barcode.length < 3) {
            console.log('Invalid barcode length:', barcode.length);
            showScanError('Invalid barcode: ' + barcode);
            playErrorBeep();
            return;
        }

        // Invoice barcode (INV-...) - load that sale's items into the cart as a repeat sale
        if (/^inv-/i.test(barcode.trim())) {
            loadInvoiceToCart(barcode.trim());
            return;
        }

        // Debug: Log sample product barcodes
        if (productsData.length > 0) {
            console.log('Sample product barcodes:', productsData.slice(0, 3).map(p => ({id: p.id, name: p.name, barcode: p.barcode})));
        }

        // Find product by barcode (case insensitive with trimming).
        //
        // The same barcode can sit on two shops' shelves, and the two are two
        // different sales with two different stock counts. A store picked above
        // decides it; an undecided scan that matches more than one shelf is refused
        // and says why, rather than quietly selling off a shelf nobody chose.
        const scannedBarcode = barcode.toLowerCase().trim();
        const matches = productsData.filter(p => p.barcode
            && String(p.barcode).toLowerCase().trim() === scannedBarcode);
        const chosenStore = activeStoreId();
        const product = matches.length === 1
            ? matches[0]
            : (matches.find(p => chosenStore && parseInt(p.store_id) === chosenStore) || null);

        if (product) {
            // Validate product data
            if (!product.id || !product.name || !product.sell_price) {
                console.log('Invalid product data:', product);
                showScanError('Invalid product data for: ' + product.name);
                playErrorBeep();
                return;
            }
            
            console.log('Adding product to cart:', product.name);
            
            // Check if this shelf's product already exists in cart
            const key = cartKey(product.store_id, product.id);
            const existing = cart.find(item => item.key === key);
            if (existing) {
                // If exists, increment quantity by 1 if stock allows
                if (existing.quantity < existing.stock) {
                    existing.quantity += 1;
                } else {
                    console.log('Not enough stock for: ' + product.name);
                    showScanError('Not enough stock: ' + product.name);
                    playErrorBeep();
                    return;
                }
            } else {
                // If not exists, add new item with quantity 1
                cart.push(Object.assign(productToCartLine(product), { quantity: 1 }));
            }
            
            updateCartDisplay();

            // Visual feedback
            showScanSuccess(product.name + (multiStore ? ' (' + storeLabel(product.store_id) + ')' : ''));
            playBeep();
        } else if (matches.length > 1) {
            showScanError('Ei barcode ' + matches.length + ' ta store-r shelf-e ache - age ekta store khun.');
            playErrorBeep();
        } else {
            // Product not found
            console.log('Product not found for barcode:', barcode);
            showScanError('Product not found: ' + barcode);
            playErrorBeep();
        }

        // Reset search
        filterProducts();
    }

    async function loadInvoiceToCart(invoiceNumber) {
        try {
            const response = await fetch(`api/get-sale-by-invoice.php?invoice=${encodeURIComponent(invoiceNumber)}`);
            const data = await response.json();

            if (!data.success) {
                console.log('Invoice not found:', invoiceNumber, data.message);
                showScanError(data.message || 'Invoice not found: ' + invoiceNumber);
                playErrorBeep();
                return;
            }

            if (cart.length > 0 && !confirm(`Load invoice ${data.invoice_number}?\nThis will replace the current ${cart.length} item(s) in the cart.`)) {
                return;
            }

            cart = [];
            let loadedCount = 0;

            data.items.forEach(item => {
                // Repeat sale: the goods come off the shelf the original bill went
                // out of, not off whichever shelf this till is standing on.
                const lineStore = parseInt(item.store_id) || parseInt(data.store_id) || 0;
                const product = productsData.find(p => parseInt(p.id) === item.product_id
                    && (!lineStore || parseInt(p.store_id) === lineStore));
                const stock = product ? parseInt(product.stock) : (parseInt(item.stock) || 0);
                const storeId = product ? parseInt(product.store_id) : lineStore;
                if (stock <= 0) return;
                cart.push(Object.assign(productToCartLine({
                    id: item.product_id,
                    store_id: storeId,
                    sell_price: item.unit_price,
                    stock: stock,
                    name: item.product_name
                }), { quantity: Math.min(parseInt(item.quantity) || 1, stock) }));
                loadedCount++;
            });

            if (loadedCount === 0) {
                showScanError('No in-stock items from invoice ' + data.invoice_number);
                playErrorBeep();
                return;
            }

            updateCartDisplay();
            showScanSuccess(`Loaded ${loadedCount} item(s) from ${data.invoice_number}`);
            playBeep();
        } catch (error) {
            console.error('Error loading invoice:', error);
            showScanError('Error loading invoice: ' + invoiceNumber);
            playErrorBeep();
        }
    }

    // Removed duplicate event handler to prevent double processing - handled in main keydown event

    function showScanSuccess(productName) {
        const toast = document.createElement('div');
        toast.className = 'barcode-toast success';
        toast.innerHTML = `<i class="fas fa-check-circle"></i> Added: ${productName}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    function showScanError(message) {
        const toast = document.createElement('div');
        toast.className = 'barcode-toast error';
        toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function playBeep() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 1200;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.3;
            oscillator.start();
            setTimeout(() => oscillator.stop(), 100);
        } catch (e) { console.log('Audio not supported'); }
    }

    function playErrorBeep() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 400;
            oscillator.type = 'sine';
            gainNode.gain.value = 0.3;
            oscillator.start();
            setTimeout(() => oscillator.stop(), 300);
        } catch (e) { console.log('Audio not supported'); }
    }

    // Camera barcode scanner
    let html5QrScanner = null;

    function openCameraScan() {
        const modal = document.getElementById('cameraScanModal');
        const reader = document.getElementById('cameraScanReader');
        if (!modal || !reader) return;
        modal.classList.add('active');
        reader.innerHTML = '';
        if (typeof Html5Qrcode === 'undefined') {
            showScanError('Camera scanner library failed to load');
            modal.classList.remove('active');
            return;
        }
        try {
            html5QrScanner = new Html5Qrcode('cameraScanReader');
            html5QrScanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    qrbox: { width: 250, height: 150 }
                },
                function (decodedText) {
                    closeCameraScan();
                    handleBarcodeScanned(decodedText.trim());
                },
                function () {}
            ).catch(function (err) {
                showScanError('Camera error: ' + (err && err.message ? err.message : err));
                modal.classList.remove('active');
            });
        } catch (e) {
            showScanError('Camera error: ' + e.message);
            modal.classList.remove('active');
        }
    }

    function closeCameraScan() {
        const modal = document.getElementById('cameraScanModal');
        if (modal) modal.classList.remove('active');
        if (html5QrScanner) {
            try { html5QrScanner.stop().then(function () { html5QrScanner.clear(); }).catch(function () {}); } catch (e) {}
            html5QrScanner = null;
        }
    }

    const cameraScanBtn = document.getElementById('cameraScanBtn');
    if (cameraScanBtn) {
        cameraScanBtn.addEventListener('click', openCameraScan);
    }

    // The two chip rows share the .pos-category-btn look but decide different
    // things, so each keeps its own active state. Marking a store chip with the
    // category handler used to clear every category chip and leave
    // filterProducts() reading a dataset that had no category in it - the grid
    // then hid itself.
    const categoryChips = document.querySelectorAll('.pos-category-btn:not(.pos-store-btn)');
    categoryChips.forEach(btn => {
        btn.addEventListener('click', function () {
            categoryChips.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProducts();
        });
    });

    const storeChips = document.querySelectorAll('.pos-store-btn');

    function selectStoreChip(storeId) {
        let hit = null;
        document.querySelectorAll('.pos-store-btn').forEach(btn => {
            const on = parseInt(btn.dataset.store) === parseInt(storeId);
            btn.classList.toggle('active', on);
            if (on) hit = btn;
        });
        if (!hit) return;
        filterProducts();
    }

    storeChips.forEach(btn => {
        btn.addEventListener('click', function () {
            storeChips.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProducts();
        });
    });

    // 0 means "every shelf", which is what "All stores" says. It is also the answer
    // on a single-shop till, where there is only one shelf to choose from.
    function activeStoreId() {
        const btn = document.querySelector('.pos-store-btn.active');
        if (!btn || btn.dataset.store === 'all') return 0;
        return parseInt(btn.dataset.store) || 0;
    }

    document.getElementById('productSearch').addEventListener('input', filterProducts);

    function filterProducts() {
        const search = document.getElementById('productSearch').value.toLowerCase();
        const catBtn = document.querySelector('.pos-category-btn:not(.pos-store-btn).active');
        const category = catBtn ? catBtn.dataset.category : 'all';
        const store = activeStoreId();
        document.querySelectorAll('.pos-product-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const barcode = (card.dataset.barcode || '').toLowerCase();
            const cardCategory = card.dataset.category;
            const matchSearch = name.includes(search) || barcode.includes(search);
            const matchCategory = category === 'all' || cardCategory === category;
            const matchStore = !store || parseInt(card.dataset.store) === store;
            card.style.display = matchSearch && matchCategory && matchStore ? 'flex' : 'none';
        });
    }

    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.addEventListener('click', function () {
            addToCart(productToCartLine({
                id: this.dataset.id,
                store_id: this.dataset.store,
                name: this.dataset.name,
                sell_price: this.dataset.price,
                stock: this.dataset.stock
            }));
        });
    });

    function addToCart(line) {
        const existing = cart.find(item => item.key === line.key);
        if (existing) {
            if (existing.quantity < line.stock) {
                existing.quantity++;
            } else {
                alert('Not enough stock' + (multiStore ? ' at ' + line.storeName : '') + '!');
                return;
            }
        } else {
            cart.push(Object.assign({}, line, { quantity: 1 }));
        }
        updateCartDisplay();
    }

    // Cart lines are found by their key (shelf + product), never by product id
    // alone: two shops can both have ten pieces of the same shirt, and the two
    // counts are different numbers that must not be edited together.
    function updateQuantity(key, change) {
        const item = cart.find(i => i.key === key);
        if (item) {
            const newQty = item.quantity + change;
            if (newQty <= 0) {
                removeFromCart(key);
            } else if (newQty <= item.stock) {
                item.quantity = newQty;
                updateCartDisplay();
            } else {
                alert('Not enough stock' + (multiStore ? ' at ' + item.storeName : '') + '!');
            }
        }
    }

    function setQuantity(key, qty) {
        const item = cart.find(i => i.key === key);
        if (item) {
            qty = parseInt(qty) || 0;
            if (qty <= 0) {
                removeFromCart(key);
            } else if (qty <= item.stock) {
                item.quantity = qty;
                updateCartDisplay();
            } else {
                alert('Not enough stock!');
                item.quantity = item.stock;
                updateCartDisplay();
            }
        }
    }

    function removeFromCart(key) {
        cart = cart.filter(item => item.key !== key);
        updateCartDisplay();
    }

    function clearCart() {
        if (cart.length > 0 && confirm('Clear all items from cart?')) {
            cart = [];
            updateCartDisplay();
        }
    }

    function saveCart() {
        try {
            localStorage.setItem(cartStorageKey, JSON.stringify(cart));
        } catch (e) {}
    }

    function loadCart() {
        if (editSaleId) return;
        try {
            const saved = localStorage.getItem(cartStorageKey);
            if (!saved) return;
            const savedCart = JSON.parse(saved);
            if (!Array.isArray(savedCart)) return;
            cart = savedCart.map(item => {
                const storeId = parseInt(item.store) || 0;
                // Stock, price and name are re-read from the shelf: the browser's
                // copy is from whenever this tab was last open, and a sale must be
                // booked against what the shelf holds now.
                const product = productsData.find(p => parseInt(p.id) === parseInt(item.id)
                        && (!storeId || parseInt(p.store_id) === storeId))
                    || productsData.find(p => parseInt(p.id) === parseInt(item.id));
                if (!product) return null;
                const line = productToCartLine(product);
                line.quantity = Math.min(parseInt(item.quantity) || 1, line.stock);
                return line;
            }).filter(Boolean);
        } catch (e) {}
    }



    function updateCartDisplay() {
        const cartItems = document.getElementById('cartItems');
        const cartCount = document.getElementById('cartCount');

        if (cart.length === 0) {
            cartItems.innerHTML = `
            <div class="pos-cart-empty">
                <i class="fas fa-shopping-cart" style="font-size: 3rem; color: var(--gray-300); margin-bottom: 1rem;"></i>
                <p>Your cart is empty</p>
                <p class="text-muted" style="font-size: 0.875rem;">Click on products to add them</p>
            </div>
        `;
            cartCount.textContent = '0';
        } else {
            cartItems.innerHTML = cart.map(item => `
            <div class="pos-cart-item">
                <div class="pos-cart-img">${item.name.charAt(0).toUpperCase()}</div>
                <div class="pos-cart-item-info">
                    <div class="pos-cart-item-name">${item.name}</div>
                    <div class="pos-cart-item-price">${currency} ${item.price.toFixed(2)}</div>
                    ${multiStore ? `<div class="pos-cart-item-store">${escapeHtml(item.storeName || '')}</div>` : ''}
                </div>
                <div class="pos-cart-item-qty">
                    <button onclick="updateQuantity('${item.key}', -1)">-</button>
                    <input type="number" value="${item.quantity}" min="1" max="${item.stock}" 
                           onchange="setQuantity('${item.key}', this.value)">
                    <button onclick="updateQuantity('${item.key}', 1)">+</button>
                </div>
                <button class="pos-cart-item-remove" onclick="removeFromCart('${item.key}')">
                    <i class="far fa-trash-alt"></i>
                </button>
            </div>
        `).join('');

            cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
        }

        calculateTotals();
        saveCart();
    }

    function round2(v) {
        return Math.round(((Number(v) || 0) + Number.EPSILON) * 100) / 100;
    }

    /**
     * Split an amount across shares so the parts add back to the whole.
     *
     * The poisha difference goes to the largest part, which is config/sale_split.php's
     * saleShareMoney() rule. Copying it here is the only way the till's total and the
     * booked total come out of the same arithmetic; if they differ by more than half a
     * poisha the door refuses the basket outright.
     */
    function shareMoney(amount, shares) {
        if (shares.length === 0) return [];
        if (shares.length === 1) return [round2(amount)];

        const parts = shares.map(s => round2(amount * s));
        let used = 0;
        parts.forEach(p => { used += p; });
        used = round2(used);

        let at = 0;
        parts.forEach((p, i) => { if (p >= parts[at]) at = i; });
        parts[at] = round2(parts[at] + (amount - used));
        return parts;
    }

    /**
     * The basket, split by shelf and priced the way the door prices it.
     *
     * Each line is rounded on its own and each shop is charged its own VAT rate on
     * its own discounted share, because that is what admin/api/process-sale.php does
     * from the settings of the shop that owns the store - not one rate across the
     * whole basket, which would bill shop B at shop A's rate.
     */
    function storeBreakdown() {
        const byStore = {};
        cart.forEach(item => {
            const storeId = parseInt(item.store) || 0;
            if (!byStore[storeId]) {
                byStore[storeId] = {
                    store: storeId,
                    name: storeLabel(storeId) || item.storeName || '',
                    vat_percent: storeVatPercent(storeId),
                    subtotal: 0
                };
            }
            byStore[storeId].subtotal += round2(item.price * item.quantity);
        });

        const groups = Object.keys(byStore)
            .map(k => byStore[k])
            .sort((a, b) => a.store - b.store);
        groups.forEach(g => { g.subtotal = round2(g.subtotal); });

        const subtotal = round2(groups.reduce((s, g) => s + g.subtotal, 0));

        const discountType = document.getElementById('discountType').value;
        const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;

        let discount = discountType === 'percent' ? subtotal * (discountValue / 100) : discountValue;
        if (discount < 0) discount = 0;
        if (discount > subtotal) discount = subtotal;
        discount = round2(discount);

        const discounts = shareMoney(discount, groups.map(g => (subtotal > 0 ? g.subtotal / subtotal : 0)));

        let vat = 0;
        let total = 0;
        groups.forEach((g, i) => {
            g.discount = discounts[i];
            g.discount_percent = g.subtotal > 0 ? round2(discounts[i] / g.subtotal * 100) : 0;
            const afterDiscount = round2(g.subtotal - g.discount);
            g.vat_amount = round2(afterDiscount * (g.vat_percent / 100));
            g.total = round2(afterDiscount + g.vat_amount);
            vat = round2(vat + g.vat_amount);
            total = round2(total + g.total);
        });

        return {
            groups: groups,
            subtotal: subtotal,
            discount: discount,
            discount_type: discountType,
            discount_value: discountValue,
            vat: vat,
            total: total
        };
    }

    function calculateTotals() {
        currentBasket = storeBreakdown();
        const b = currentBasket;

        document.getElementById('subtotal').textContent = `${currency} ${b.subtotal.toFixed(2)}`;
        document.getElementById('discountAmount').textContent = `- ${currency} ${b.discount.toFixed(2)}`;
        document.getElementById('vatAmount').textContent = `${currency} ${b.vat.toFixed(2)}`;
        document.getElementById('totalAmount').textContent = `${currency} ${b.total.toFixed(2)}`;

        document.getElementById('checkoutBtn').disabled = cart.length === 0;

        renderStorePayments(b);
        calculateChange();
    }

    document.getElementById('discountType').addEventListener('change', calculateTotals);
    document.getElementById('discountValue').addEventListener('input', calculateTotals);
    document.getElementById('paidAmount').addEventListener('input', calculateChange);

    // Initialize cart once all functions and listeners are ready
    if (editSaleData) {
        setTimeout(updateCartDisplay, 100);
    } else {
        loadCart();
        updateCartDisplay();
    }

    /**
     * One tender per shop when a basket spans two of them.
     *
     * The cash physically goes into different tills and one shop's change cannot come
     * out of another's drawer, so the door asks for a payment per store instead of
     * guessing by splitting one number. An empty box is that shop's own total at
     * checkout - paid in full being the normal thing at a counter.
     */
    function renderStorePayments(b) {
        const box = document.getElementById('storePaymentRows');
        const single = document.getElementById('singlePaymentRow');
        if (!box || !single) return;

        if (b.groups.length < 2) {
            box.innerHTML = '';
            box.style.display = 'none';
            single.style.display = 'flex';
            return;
        }

        const typed = {};
        box.querySelectorAll('input[data-store-payment]').forEach(inp => {
            typed[inp.dataset.storePayment] = inp.value;
        });

        single.style.display = 'none';
        box.style.display = 'block';
        box.innerHTML = b.groups.map(g => `
            <div class="pos-store-payment-row">
                <span class="pos-store-payment-name">${escapeHtml(g.name)}</span>
                <span class="pos-store-payment-total">${currency} ${g.total.toFixed(2)}</span>
                <input type="number" class="form-control" min="0" step="0.01"
                       placeholder="Paid" data-store-payment="${g.store}"
                       value="${typed[g.store] !== undefined ? escapeHtml(typed[g.store]) : ''}"
                       oninput="calculateChange()">
            </div>
        `).join('');
    }

    function tenderedTotals() {
        const b = currentBasket || storeBreakdown();
        const rows = [];

        if (b.groups.length > 1) {
            const inputs = {};
            document.querySelectorAll('#storePaymentRows input[data-store-payment]').forEach(inp => {
                inputs[inp.dataset.storePayment] = parseFloat(inp.value) || 0;
            });
            b.groups.forEach(g => {
                const paid = inputs[g.store] || 0;
                rows.push({ store: g.store, name: g.name, total: g.total, paid: paid,
                            change: Math.max(0, round2(paid - g.total)) });
            });
            return { basket: b, rows: rows, multi: true };
        }

        const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
        rows.push({ store: b.groups.length ? b.groups[0].store : 0, name: '', total: b.total, paid: paid,
                    change: Math.max(0, round2(paid - b.total)) });
        return { basket: b, rows: rows, multi: false };
    }

    function calculateChange() {
        const t = tenderedTotals();
        const change = t.rows.reduce((s, r) => s + r.change, 0);
        const anyPaid = t.rows.some(r => r.paid > 0);

        document.getElementById('changeRow').style.display = anyPaid ? 'flex' : 'none';
        document.getElementById('changeAmount').innerHTML = `<strong>${currency} ${round2(change).toFixed(2)}</strong>`;
    }

    function setExactAmount() {
        const b = currentBasket || storeBreakdown();
        if (b.groups.length > 1) {
            b.groups.forEach(g => {
                const inp = document.querySelector(`#storePaymentRows input[data-store-payment="${g.store}"]`);
                if (inp) inp.value = g.total.toFixed(2);
            });
        } else {
            document.getElementById('paidAmount').value = b.total.toFixed(2);
        }
        calculateChange();
    }

    // Payment method selection
    document.querySelectorAll('.pos-payment-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.pos-payment-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            paymentMethod = this.dataset.method;
        });
    });

    // Process checkout
    async function processCheckout() {
        if (cart.length === 0) {
            alert('Cart is empty!');
            return;
        }

        const b = storeBreakdown();
        const t = tenderedTotals();

        // Cash short on a bill is the one mistake a till cannot absorb quietly:
        // which drawer the difference belongs to is decided by whose goods they
        // are, so the shop is named rather than the total.
        if (paymentMethod === 'cash') {
            const short = t.rows.filter(r => r.paid > 0 && r.paid < r.total);
            if (t.multi && short.length) {
                alert(short.map(r => r.name + ': ' + currency + ' '
                    + (r.total - r.paid).toFixed(2) + ' kom.').join('\n'));
                return;
            }
            if (!t.multi && t.rows[0].paid > 0 && t.rows[0].paid < t.rows[0].total) {
                alert('Paid amount is less than total!');
                return;
            }
        }

        const storePayments = {};
        t.rows.forEach(r => { storePayments[r.store] = r.paid > 0 ? r.paid : r.total; });

        const saleData = {
            edit_sale_id: editSaleId, // ID if editing, 0 if new
            // Same token on every retry of this basket; a fresh one is taken as
            // soon as it is booked. This is what stops one double tap writing two
            // sales and two sets of stock movements.
            idempotency_key: saleToken,
            customer_id: document.getElementById('customerId').value ? parseInt(document.getElementById('customerId').value) : 0,
            // Each line names the shelf it came off, which is what the server books
            // the money, the stock and the report against - not whoever typed it.
            items: cart.map(item => ({
                product_id: item.id,
                product_name: item.name,
                quantity: item.quantity,
                unit_price: item.price,
                total_price: round2(item.price * item.quantity),
                store_id: item.store
            })),
            subtotal: b.subtotal,
            discount_type: b.discount_type,
            discount_value: b.discount_value,
            discount_percent: b.discount_type === 'percent'
                ? b.discount_value
                : (b.subtotal > 0 ? round2(b.discount / b.subtotal * 100) : 0),
            discount_amount: b.discount,
            vat_percent: b.groups.length === 1 ? b.groups[0].vat_percent : vatPercent,
            vat_amount: b.vat,
            total: b.total,
            paid_amount: t.multi ? 0 : (t.rows[0].paid || b.total),
            store_payments: t.multi ? storePayments : undefined,
            change_amount: round2(t.rows.reduce((s, r) => s + r.change, 0)),
            payment_method: paymentMethod
        };

        document.getElementById('checkoutBtn').disabled = true;
        document.getElementById('checkoutBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const response = await fetch('api/process-sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(saleData)
            });

            const result = await response.json();

            if (result.success) {
                // One basket can be several bills: each shop's own sale, with its own
                // invoice number, its own payment and its own stock movements.
                // result.invoices is that list; result.invoice is its first, so a
                // single-shop sale still gets the one bill it always got from the
                // same field.
                const list = (Array.isArray(result.invoices) && result.invoices.length)
                    ? result.invoices
                    : [result.invoice || {}];
                // The sale id is copied onto the invoice if the API did not. The
                // send button posts it back, and a missing one reached it as
                // "sale_id: undefined", which the server cannot read
                // - the cashier then saw "Invoice number bujha jay ni" with no idea
                // why, because the failure is invisible until someone sends.
                list.forEach(inv => {
                    if (inv.id == null && result.sale_id != null) inv.id = result.sale_id;
                });

                showInvoices(list);
                cart = [];
                updateCartDisplay();
                document.getElementById('customerId').value = '';
                document.getElementById('customerSearch').value = '';
                document.getElementById('discountValue').value = 0;
                document.getElementById('paidAmount').value = '';
                document.querySelectorAll('#storePaymentRows input').forEach(inp => { inp.value = ''; });

                // This basket is booked, so its token is spent. Reusing it would make
                // the next customer's sale a replay of the receipt just printed.
                saleToken = newSaleToken();

                // Hand the receipts over by themselves, as Settings > Invoice Delivery
                // asks. Fired after the cart has been cleared and the receipt is
                // on screen, so the cashier is never made to wait for a phone
                // network before they can start the next sale.
                const fallbackNotify = result.notify || {};
                list.forEach(inv => autoDeliverInvoice(inv.notify || fallbackNotify, inv));

                // The sale is saved; the paper copy is the staff app's job. A browser
                // cannot open the printer's TCP port, so only the app's injected
                // bridge answers here, and only the app decides whether Auto Print is
                // on. Every failure is the app's to show: a printer that is switched
                // off must never turn a completed sale into an error, and an edit is
                // not a new sale to print.
                if (!editSaleId && window.LanPrinter && typeof window.LanPrinter.printAuto === 'function') {
                    list.forEach(inv => {
                        try {
                            window.LanPrinter.printAuto(inv, { jobId: String(inv.id || ''), source: 'pos' })
                                .catch(() => {});
                        } catch (e) {
                            console.warn('LAN printer:', e);
                        }
                    });
                }

                // If editing, maybe redirect back to sales list or just refresh to clear edit mode?
                // For now, reload to clear cart and edit mode
                if (editSaleId) {
                    alert('Sale updated successfully!');
                    window.location.href = 'sales.php';
                } else {
                    // Refresh product stock local data if needed, or just keep going
                    // Reloading is safest to update stock data from server
                    // But showInvoice modal needs to be seen first.
                    // The modal has a close button that reloads.
                }

            } else {
                alert('Error: ' + result.message);
                document.getElementById('checkoutBtn').disabled = false;
                document.getElementById('checkoutBtn').innerHTML = '<i class="fas fa-check-circle"></i> Complete Sale';
            }
        } catch (error) {
            alert('Error processing sale. Please try again.');
            console.error(error);
            document.getElementById('checkoutBtn').disabled = false;
            document.getElementById('checkoutBtn').innerHTML = '<i class="fas fa-check-circle"></i> Complete Sale';
        }
    }

    /**
     * The coupon QR, drawn in the page as an inline SVG.
     *
     * It used to be an <img> pointing at api.qrserver.com, which left an empty
     * box wherever that request did not finish in time: a print preview that
     * snapshots the page straight away, or a shop PC with no internet at all.
     * Drawing it here means the preview, the printout and the slip all carry
     * the same code and none of them need the network.
     */
    function couponQrSvg(url, size) {
        if (!url || typeof qrcode === 'undefined') return '';
        try {
            const qr = qrcode(0, 'M');
            qr.addData(url);
            qr.make();
            return qr.createSvgTag({ cellSize: 4, margin: 1, scalable: true })
                .replace('<svg ', '<svg style="width:' + size + 'px;height:' + size + 'px;" ');
        } catch (e) {
            return '';
        }
    }

    function invoiceBillHtml(invoice, idx, multi) {
        // The till page's own letterhead. It is only a fallback now: one basket can
        // span two shops, and the second bill cannot be printed under the shop the
        // cashier happens to be logged into.
        const dFacebookUrl = `<?php echo sanitize($settings['facebook_page'] ?? 'https://www.facebook.com'); ?>`;
        // Controlled from Settings > Invoice. Defaults to on when absent, which
        // is how the receipt behaved before the setting existed.
        const dShowQr = <?php
            $qrOn = ($settings['invoice_show_qr'] ?? '1');
            echo !($qrOn === '0' || $qrOn === 0 || $qrOn === '') ? 'true' : 'false';
        ?>;
        const dShopName = `<?php echo sanitize($settings['shop_name'] ?? 'POS System'); ?>`;
        const dShopAddress = `<?php echo sanitize($settings['shop_address'] ?? ''); ?>`;
        const dShopPhone = `<?php echo sanitize($settings['shop_phone'] ?? ''); ?>`;
        const dReceiptFooter = `<?php echo sanitize($settings['receipt_footer'] ?? 'Thank you for shopping!'); ?>`;
        const dVoucherTerms = `<?php echo sanitize($settings['voucher_terms'] ?? ''); ?>`;

        // A bill that names its own shop wins; an older reply that does not still
        // prints exactly the way it used to.
        const facebookUrl = invoice.facebook_url || dFacebookUrl;
        const showQr = (invoice.show_qr == null) ? dShowQr : (invoice.show_qr === 1 || invoice.show_qr === true);
        const shopName = invoice.shop_name || dShopName;
        const shopAddress = (invoice.shop_address == null) ? dShopAddress : invoice.shop_address;
        const shopPhone = (invoice.shop_phone == null) ? dShopPhone : invoice.shop_phone;
        const receiptFooter = invoice.receipt_footer || dReceiptFooter;
        const voucherTerms = (invoice.voucher_terms == null) ? dVoucherTerms : invoice.voucher_terms;

        return `
        <div class="invoice-bill">
            ${multi ? `
            <div class="invoice-bill-bar">
                <span>${escapeHtml(invoice.store_name || invoice.shop_name || '')}</span>
                <span>
                    <button type="button" onclick="sendInvoiceSms(${idx}, this)">SMS</button>
                </span>
            </div>` : ''}
        <div class="printable-invoice" style="font-family: 'Hind Siliguri', monospace; font-size: 12px; width: 100%; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 1rem;">
                <h3 style="margin: 0; font-size: 16px;">${shopName}</h3>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopAddress}</p>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopPhone}</p>
                <div style="margin-top: 8px;">
                    <svg class="invoice-return-barcode" data-barcode="${invoice.invoice_number}"></svg>
                </div>
            </div>
            <hr style="border-style: dashed;">
            <p style="margin: 2px 0;"><strong>Invoice:</strong> ${invoice.invoice_number}</p>
            <p style="margin: 2px 0;"><strong>Date:</strong> ${invoice.date}</p>
            <p style="margin: 2px 0;"><strong>Customer:</strong> ${invoice.customer_name}</p>
            <p style="margin: 2px 0;"><strong>Cashier:</strong> ${invoice.cashier}</p>
            <hr style="border-style: dashed;">
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; border-bottom: 1px dashed #000; padding-bottom: 4px;">Item</th>
                        <th style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 4px;">Qty</th>
                        <th style="text-align: right; border-bottom: 1px dashed #000; padding-bottom: 4px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${invoice.items.map(item => `
                        <tr>
                            <td style="padding: 4px 0;">${item.product_name}</td>
                            <td style="text-align: center; padding: 4px 0;">${item.quantity}</td>
                            <td style="text-align: right; padding: 4px 0;">${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <hr style="border-style: dashed;">
            <table style="width: 100%; font-size: 12px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 2px 0;">Subtotal</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.subtotal).toFixed(2)}</td>
                </tr>
                ${invoice.discount_amount > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Discount (${invoice.discount_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">- ${currency} ${parseFloat(invoice.discount_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                ${invoice.vat_amount > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">VAT (${invoice.vat_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.vat_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                <tr style="font-weight: bold; font-size: 14px; border-top: 1px dashed #000; border-bottom: 1px dashed #000;">
                    <td style="padding: 4px 0;">TOTAL</td>
                    <td style="text-align: right; padding: 4px 0;">${currency} ${parseFloat(invoice.total).toFixed(2)}</td>
                </tr>
                <tr>
                    <td style="padding: 2px 0;">Paid (${invoice.payment_method.toUpperCase()})</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.paid_amount).toFixed(2)}</td>
                </tr>
                ${invoice.change_amount > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Change</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.change_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
            </table>
            <hr style="border-style: dashed;">
            <p style="text-align: center; font-size: 11px; margin-top: 10px;">${receiptFooter}</p>
            ${voucherTerms ? `
            <div style="margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px;">
                <p style="margin: 0 0 4px 0; font-size: 11px; font-weight: bold;">Terms & Conditions</p>
                <p style="margin: 0; font-size: 10px; white-space: pre-line;">${voucherTerms}</p>
            </div>
            ` : ''}

            
            
            ${invoice.coupon_status == '1' ? (() => {
                const totalQty = invoice.items.reduce((sum, item) => sum + parseInt(item.quantity || 1), 0);

                // Build the N slips (one per qty)
                let slips = '';
                for (let i = 1; i <= totalQty; i++) {
                    slips += `
                    <div style="margin-top: 10px; border: 1px solid #000; padding: 6px; border-radius: 4px; font-size: 11px; page-break-inside: avoid;">
                        <div style="text-align: center; margin-bottom: 6px; border: 1px solid #000; padding: 4px; font-size: 13px; font-weight: bold;">
                            SC-${invoice.invoice_number}${totalQty > 1 ? '-' + i : ''}
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Name:</span> <span>${invoice.customer_name}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Mobile:</span> <span>${invoice.customer_phone || ''}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span>Date:</span> <span>${invoice.date || ''}</span>
                        </div>
                        <div style="clear: both;"></div>
                    </div>`;
                }

                // ONE coupon header + all slips
                return `
            <div style="page-break-before: always; margin-top: 20px; border-top: 1px dashed #000; padding-top: 20px; font-family: monospace;">
                <div style="border: 2px solid #000; padding: 10px; border-radius: 8px;">
                    <h3 style="text-align: center; margin: 0 0 4px 0; font-size: 14px; text-transform: uppercase;">${invoice.coupon_title}</h3>
                    <p style="text-align: center; margin: 0 0 8px 0; font-size: 11px;">${invoice.coupon_subtitle}</p>
                    <div style="text-align: center; margin-bottom: 8px;">
                        ${showQr ? `<div style="display: flex; justify-content: center;">${couponQrSvg(facebookUrl, 60)}</div>
                        <p style="margin: 2px 0 0 0; font-size: 9px;">Scan for Facebook</p>` : ''}
                    </div>
                    <div style="text-align: center; font-size: 10px; border: 1px dashed #000; padding: 4px; border-radius: 4px; margin-bottom: 6px;">
                        ${invoice.coupon_prize_1} <br/> ${invoice.coupon_prize_2} <br/> ${invoice.coupon_prize_3} <br/> ${invoice.coupon_prize_4} <br/> ${invoice.coupon_prize_5}
                    </div>
                    <div style="text-align: center; font-size: 10px; margin-bottom: 4px;">${invoice.coupon_total_winners}</div>
                    <div style="font-size: 9px; text-align: center; border-bottom: 1px dashed #000; padding-bottom: 6px; margin-bottom: 8px;">
                        ${invoice.coupon_announcement}
                    </div>
                    ${slips}
                </div>
            </div>`;
            })() : ''}
        </div>
        </div>`;
    }

    /**
     * Put every bill this basket came out of on screen, one under the other.
     *
     * A sale that touched two shops is two sales on the server - two invoice
     * numbers, two payments, two sets of stock movements - and a customer who gets
     * one receipt for it is a customer with no proof of what they paid at the
     * second shop. So the modal prints each bill with its own shop's letterhead.
     */
    function showInvoices(list) {
        const bills = (Array.isArray(list) ? list : [list]).filter(Boolean);
        if (!bills.length) return;
        const multi = bills.length > 1;

        document.getElementById('invoiceContent').innerHTML = `
        <style>
            .printable-invoice * { font-weight: 900 !important; color: #000 !important; }
        </style>` + bills.map((inv, idx) => invoiceBillHtml(inv, idx, multi)).join('');

        document.getElementById('invoiceModal').classList.add('active');
        renderInvoiceBarcode();

        shownInvoices = bills;
        shownInvoice = bills[0];

        // Without an id the send button cannot name the sale, and it would
        // post a null and be told the invoice number was unreadable - which reads
        // like the cashier's mistake. Say what is actually wrong instead.
        const smsBtn = document.getElementById('invoiceSmsBtn');
        // Two bills and one footer button means the button can only ever
        // mean one of them, so each bill carries its own and this one steps
        // aside rather than sending the wrong shop's receipt.
        if (smsBtn) smsBtn.style.display = multi ? 'none' : '';
        const sendable = !!(shownInvoice && shownInvoice.id);
        if (smsBtn) smsBtn.disabled = !sendable;
    }

    /**
     * The invoices currently on screen, kept so the SMS button knows
     * which sale it is talking about. Held as data rather than re-read out of
     * the modal's HTML, because parsing a rendered receipt back into numbers is how
     * a button ends up sending the wrong one. There is more than one of them now:
     * a basket that spans two shops is one bill per shop.
     */
    let shownInvoices = [];
    let shownInvoice = null;

    function renderInvoiceBarcode() {
        if (typeof JsBarcode === 'undefined') return;
        document.querySelectorAll('.invoice-return-barcode').forEach(svg => {
            if (!svg.dataset.barcode) return;
            try {
                JsBarcode(svg, svg.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                });
            } catch (e) {}
        });
    }

    function closeInvoiceModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    /**
     * Send the receipt without being asked, per Settings > Invoice Delivery.
     *
     * Silent by design. An automatic send that popped a dialog would interrupt
     * the counter on every single sale, and one that failed loudly would train
     * the cashier to dismiss it without reading - so a receipt that genuinely
     * could not go is left to the manual buttons on the receipt, which are still
     * there and still work.
     *
     * @param {object} n  the notify block the sale API returns
     * @param {object} inv  the bill this send is about - one per shop, not one per basket
     */
    function autoDeliverInvoice(n, inv) {
        const invoice = inv || shownInvoice;
        if (!n || !n.auto) return;
        if (n.is_edit) return;                 // a corrected sale must not resend
        if (!invoice || !invoice.id) return;

        const phone = (invoice.customer_phone || '').trim();
        if (!phone) return;                    // nothing to send to, and no prompting on auto

        if (n.sms) {
            deliverInvoiceRequest('sms', phone, true, invoice);
        }
    }

    /**
     * The one request both buttons and the automatic send go through.
     *
     * Split out so a manual press and an automatic one report identically. The
     * manual version is what showed "SMS pathano jay ni" with nothing behind it:
     * a PHP fatal came back as a 500 with an HTML body, res.json() threw, and the
     * catch had nothing but the word "failed" to offer. The body is read as text
     * now and parsed by hand, so a non-JSON answer is reported for what it is.
     */
    async function deliverInvoiceRequest(kind, phone, quiet, inv) {
        const invoice = inv || shownInvoice;
        const api = 'api/invoice-sms.php';
        const label = 'SMS';

        let res, text;
        try {
            res = await fetch(api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sale_id: invoice.id, phone: phone })
            });
            text = await res.text();
        } catch (err) {
            if (!quiet) alert(label + ' pathano jay ni. Internet connection dekhe nin.');
            return { ok: false, reason: 'network' };
        }

        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            if (!quiet) {
                alert(label + ' pathano jay ni.\n\nServer er reply:\n' + text.slice(0, 300));
            }
            return { ok: false, reason: 'bad response', http: res.status };
        }

        if (result.success) {
            if (!quiet) {
                if (result.manual) {
                    showInvoiceSmsText(result.text, result.message);
                } else {
                    alert(result.message || (label + ' chole geche.'));
                }
            }
            return { ok: true, result: result };
        }

        if (!quiet) {
            if (result.text) {
                showInvoiceSmsText(result.text, result.message);
            } else {
                alert(result.message || (label + ' pathano jay ni.'));
            }
        }
        return { ok: false, reason: result.message };
    }

    /**
     * Text the cashier can copy when the SMS gateway is not configured.
     *
     * The endpoint hands the message back whenever it could not send it itself,
     * which is the normal state of a shop that has not configured a gateway yet.
     * Rather than telling the cashier "failed" and leaving the feature unusable
     * until someone configures a provider, the text is put on screen already
     * selected, because the phone is already in their other hand.
     */
    function showInvoiceSmsText(text, message) {
        if (message) alert(message);
        const box = document.createElement('textarea');
        box.value = text || '';
        box.readOnly = true;
        box.style.cssText = 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);'
            + 'width:min(420px,90vw);height:140px;z-index:99999;padding:12px;'
            + 'font-family:monospace;font-size:12px;border:1px solid #ccc;border-radius:8px';
        document.body.appendChild(box);
        box.focus();
        box.select();
        document.getElementById('invoiceSmsCopyBtn')?.remove();
        const done = document.createElement('button');
        done.id = 'invoiceSmsCopyBtn';
        done.textContent = 'Copyed - Close';
        done.style.cssText = 'position:fixed;left:50%;top:calc(50% + 90px);transform:translateX(-50%);'
            + 'z-index:99999;padding:8px 16px;border:0;border-radius:6px;background:#111;color:#fff';
        done.onclick = () => { box.remove(); done.remove(); };
        document.body.appendChild(done);
    }

    /**
     * Send the invoice receipt by SMS.
     *
     * Manual on purpose. An SMS costs money per message, so this is a button the
     * cashier presses rather than something that fires on every sale - the shop
     * decides who gets a text copy.
     */
    async function sendInvoiceSms(idx, btnArg) {
        const invoice = (idx == null) ? shownInvoice : shownInvoices[idx];
        if (!invoice) return;
        if (!invoice.id) {
            alert('Ei invoice er number nai, tai pathano jay na.');
            return;
        }

        let phone = (invoice.customer_phone || '').trim();
        if (!phone) {
            phone = (prompt('Ei customer er kono number nai.\nSMS e pathate chay number ta likhun:') || '').trim();
            if (!phone) return;
        }

        const btn = btnArg || document.getElementById('invoiceSmsBtn');
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        // The request and the reporting of it live in deliverInvoiceRequest, so a
        // manual press and the automatic send fail in exactly the same way. The
        // body is read as text there and parsed by hand, which is what lets a PHP
        // error page be shown as the reason instead of being swallowed into a
        // bare "could not send".
        const done = await deliverInvoiceRequest('sms', phone, false, invoice);

        btn.disabled = false;
        if (done.ok && !done.result.manual) {
            btn.innerHTML = '<i class="fas fa-check"></i> Sent';
            setTimeout(() => {
                if (document.getElementById('invoiceModal').classList.contains('active')) {
                    closeInvoiceModal();
                }
            }, 1200);
        } else {
            btn.innerHTML = original;
        }
    }

    async function printInvoice() {
        // Every bill the basket produced, in one print run: a customer who bought
        // across two shops takes both receipts home, not only the first one drawn.
        const bills = Array.from(document.querySelectorAll('#invoiceContent .printable-invoice'));
        if (!bills.length) return;
        let printContent = bills.map(bill => bill.outerHTML)
            .join('<div style="page-break-after: always;"></div>');

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
        <head>
            <title>Invoice</title>
            <meta charset="utf-8">
            <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
            <style>
                body { font-family: 'Hind Siliguri', sans-serif; font-size: 12px; margin: 0; padding: 10px; }
                .printable-invoice * { font-weight: 900 !important; color: #000 !important; }
                .invoice-return-barcode { height: 50px; }
                hr { border-style: dashed; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 2px 0; }
                @media print {
                    body { margin: 0; padding: 5px; }
                    @page { margin: 5mm; }
                }
            </style>
            <script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"><\/script>
        </head>
        <body>${printContent}</body>
        </html>
    `);
        printWindow.document.close();
        printWindow.onload = function() {
            const doPrint = function() {
                printWindow.document.querySelectorAll('.invoice-return-barcode').forEach(barcode => {
                    if (!barcode.dataset.barcode || typeof printWindow.JsBarcode === 'undefined') return;
                    try {
                        printWindow.JsBarcode(barcode, barcode.dataset.barcode, {
                            format: 'CODE128',
                            width: 1.5,
                            height: 50,
                            displayValue: false
                        });
                    } catch (e) {}
                });
                const images = printWindow.document.querySelectorAll('img');
                let loaded = 0;
                const total = images.length;
                if (total === 0) { printWindow.print(); printWindow.close(); return; }
                images.forEach(img => {
                    if (img.complete) {
                        loaded++;
                        if (loaded === total) { printWindow.print(); printWindow.close(); }
                    } else {
                        img.onload = img.onerror = function() {
                            loaded++;
                            if (loaded === total) { printWindow.print(); printWindow.close(); }
                        };
                    }
                });
                setTimeout(() => { printWindow.print(); printWindow.close(); }, 5000);
            };
            const fontsReady = (printWindow.document.fonts && printWindow.document.fonts.ready) ? printWindow.document.fonts.ready : Promise.resolve();
            fontsReady.then(doPrint);
        };
    }

    // Mobile cart toggle
    document.querySelector('.pos-cart-header').addEventListener('click', function () {
        document.getElementById('cartPanel').classList.toggle('open');
    });

    // Create Customer Functions
    function openCustomerModal() {
        document.getElementById('createCustomerModal').classList.add('active');
    }

    function closeCustomerModal() {
        document.getElementById('createCustomerModal').classList.remove('active');
        document.getElementById('createCustomerForm').reset();
    }

    async function submitCustomerForm(e) {
        e.preventDefault();
        const form = document.getElementById('createCustomerForm');
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

        try {
            const formData = new FormData(form);
            const response = await fetch('api/create-customer.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                // Add to customers data and select it
                customersData.push(result.customer);
                selectCustomer(result.customer.id);

                closeCustomerModal();
            } else {
                alert(result.message || 'Error adding customer');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Save Customer';
        }
    }
</script>

<!-- Create Customer Modal -->
<div class="modal-overlay" id="createCustomerModal">
    <div class="modal" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-user-plus"></i> Add New Customer</h3>
            <button class="modal-close" onclick="closeCustomerModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createCustomerForm" onsubmit="submitCustomerForm(event)">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group" style="margin-top: 10px;">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
                <div class="form-group" style="margin-top: 10px;">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="form-group" style="margin-top: 10px;">
                    <label>Address</label>
                    <textarea name="address" class="form-control" rows="2"></textarea>
                </div>
                <div class="modal-footer" style="margin-top: 1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeCustomerModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>