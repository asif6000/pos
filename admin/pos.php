<?php
/**
 * POS System - Point of Sale / Billing Screen
 * Main screen for creating sales and processing payments
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'POS - Billing');

$db = getDB();
$user = getCurrentUser();

// Get settings - Filter by owner
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$user['owner_id']]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$vatPercent = (float) ($settings['vat_percent'] ?? 0);

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

// Get categories - Filter by owner
$stmt = $db->prepare("SELECT id, name FROM categories WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$user['owner_id']]);
$categories = $stmt->fetchAll();

// Get products
// Get products with store stock
$store_id = $user['store_id'] ?? 0;
if (!$store_id) {
    $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
    $stmtFallback->execute([$user['owner_id']]);
    $store_id = $stmtFallback->fetchColumn();
    if (!$store_id) {
        $stmtFallback = $db->query("SELECT id FROM stores WHERE status = 'active' LIMIT 1");
        $store_id = $stmtFallback->fetchColumn();
    }
}
$sql = "SELECT p.id, p.name, p.barcode, p.sell_price, COALESCE(ss.quantity, 0) as stock, p.category_id 
        FROM products p 
        LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ? 
        WHERE p.status = 'active' AND p.owner_id = ? AND COALESCE(ss.quantity, 0) > 0 
        ORDER BY p.name";
$stmt = $db->prepare($sql);
$stmt->execute([$store_id, $user['owner_id']]);
$products = $stmt->fetchAll();

// Get customers - Filter by owner
$stmt = $db->prepare("SELECT id, name, phone FROM customers WHERE owner_id = ? ORDER BY id ASC");
$stmt->execute([$user['owner_id']]);
$customers = $stmt->fetchAll();

// Check for Edit Mode
$editSaleId = isset($_GET['edit_sale_id']) ? (int) $_GET['edit_sale_id'] : 0;
$editSaleData = null;

if ($editSaleId) {
    // Secure edit lookup by owner_id
    $stmt = $db->prepare("SELECT s.* FROM sales s JOIN users u ON s.user_id = u.id WHERE s.id = ? AND u.owner_id = ?");
    $stmt->execute([$editSaleId, $user['owner_id']]);
    $editSale = $stmt->fetch();

    if ($editSale) {
        // Get items
        $stmtItems = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$editSaleId]);
        $editItems = $stmtItems->fetchAll();

        $editSaleData = [
            'sale' => $editSale,
            'items' => $editItems
        ];
    }
}

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

        <!-- Products Grid -->
        <div class="pos-product-grid" id="productGrid">
            <?php foreach ($products as $product): ?>
                <div class="pos-product-card" data-id="<?php echo $product['id']; ?>"
                    data-name="<?php echo sanitize($product['name']); ?>" data-price="<?php echo $product['sell_price']; ?>"
                    data-stock="<?php echo $product['stock']; ?>" data-barcode="<?php echo $product['barcode']; ?>"
                    data-category="<?php echo $product['category_id']; ?>">
                    <div class="pos-product-img">
                        <?php echo strtoupper(substr(sanitize($product['name']), 0, 1)); ?>
                    </div>
                    
                    <div class="pos-product-name">
                        <?php echo sanitize($product['name']); ?>
                    </div>
                    
                    <div class="pos-product-price">
                        <?php echo formatCurrency($product['sell_price']); ?>
                    </div>
                    
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
            </div>

            <!-- Paid Amount -->
            <div style="display: flex; gap: 0.4rem; margin-bottom: 0.4rem;">
                <input type="number" id="paidAmount" class="form-control" placeholder="Paid Amount"
                    style="flex: 1; font-size: 0.85rem; padding: 0.25rem 0.5rem; height: 30px;">
                <button class="btn btn-light" onclick="setExactAmount()"
                    style="background: #e2e8f0; border: none; padding: 0 0.5rem; font-weight: 600; font-size: 0.75rem; height: 30px;">Exact</button>
            </div>
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

<script src="<?php echo $baseUrl; ?>/assets/js/jsbarcode.min.js"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/html5-qrcode.min.js"></script>
<script>
    // Global variables
    let cart = [];
    let paymentMethod = 'cash';
    const vatPercent = <?php echo $vatPercent; ?>;
    const currency = '<?php echo CURRENCY; ?>';
    const cartStorageKey = 'pos_cart_<?php echo $user['id']; ?>_<?php echo $store_id; ?>';
    const editSaleId = <?php echo $editSaleId ?: 0; ?>;
    const editSaleData = <?php echo $editSaleData ? json_encode($editSaleData) : 'null'; ?>;
    const customersData = <?php echo json_encode($customers); ?>;

    // Initialize cart if editing
    if (editSaleData) {
        const productsMap = {};
        <?php foreach ($products as $p) {
            echo "productsMap[{$p['id']}] = " . json_encode($p) . ";\n";
        } ?>

        editSaleData.items.forEach(item => {
            const product = productsMap[item.product_id];
            if (product) {
                cart.push({
                    id: parseInt(item.product_id),
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

        // Defer main update to after functions are defined (moved to window load or executed after)
        // But since this runs inline, we need functions defined.
        // We will call updateCartDisplay() at the end of script or ensure functions are hoisted.
        // JS functions (function foo() {}) are hoisted, so it is fine.
    }

    // USB Barcode Scanner Support
    let barcodeBuffer = '';
    let lastKeyTime = 0;
    const BARCODE_TIMEOUT = 150; // Increased timeout for better reliability (ms)

    const productsData = <?php echo json_encode($products); ?>;
    
    // Ensure products data is valid
    if (!Array.isArray(productsData)) {
        console.error('Products data is not an array');
        productsData = [];
    }

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

        // Find product by barcode (case insensitive with trimming)
        const product = productsData.find(p => {
            if (!p.barcode) return false;
            const productBarcode = p.barcode.toString().toLowerCase().trim();
            const scannedBarcode = barcode.toLowerCase().trim();
            const match = productBarcode === scannedBarcode;
            if (match) {
                console.log('Barcode match found:', {productId: p.id, productName: p.name, productBarcode, scannedBarcode});
            }
            return match;
        });

        if (product) {
            // Validate product data
            if (!product.id || !product.name || !product.sell_price) {
                console.log('Invalid product data:', product);
                showScanError('Invalid product data for: ' + product.name);
                playErrorBeep();
                return;
            }
            
            console.log('Adding product to cart:', product.name);
            
            // Add to cart with exactly 1 quantity
            const newCartItem = {
                id: parseInt(product.id),
                name: product.name,
                price: parseFloat(product.sell_price),
                stock: parseInt(product.stock),
                quantity: 1
            };
            
            // Check if product already exists in cart
            const existingIndex = cart.findIndex(item => item.id === newCartItem.id);
            if (existingIndex > -1) {
                // If exists, increment quantity by 1 if stock allows
                if (cart[existingIndex].quantity < cart[existingIndex].stock) {
                    cart[existingIndex].quantity += 1;
                } else {
                    console.log('Not enough stock for: ' + product.name);
                    showScanError('Not enough stock: ' + product.name);
                    playErrorBeep();
                    return;
                }
            } else {
                // If not exists, add new item with quantity 1
                cart.push(newCartItem);
            }
            
            updateCartDisplay();

            // Visual feedback
            showScanSuccess(product.name);
            playBeep();
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
                const product = productsData.find(p => parseInt(p.id) === item.product_id);
                const stock = product ? parseInt(product.stock) : (parseInt(item.stock) || 0);
                if (stock <= 0) return;
                cart.push({
                    id: parseInt(item.product_id),
                    name: item.product_name,
                    price: parseFloat(item.unit_price),
                    stock: stock,
                    quantity: Math.min(parseInt(item.quantity) || 1, stock)
                });
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

    document.querySelectorAll('.pos-category-btn').forEach(btn => {

        btn.addEventListener('click', function () {
            document.querySelectorAll('.pos-category-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProducts();
        });
    });

    document.getElementById('productSearch').addEventListener('input', filterProducts);

    function filterProducts() {
        const search = document.getElementById('productSearch').value.toLowerCase();
        const category = document.querySelector('.pos-category-btn.active').dataset.category;
        document.querySelectorAll('.pos-product-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const barcode = (card.dataset.barcode || '').toLowerCase();
            const cardCategory = card.dataset.category;
            const matchSearch = name.includes(search) || barcode.includes(search);
            const matchCategory = category === 'all' || cardCategory === category;
            card.style.display = matchSearch && matchCategory ? 'flex' : 'none';
        });
    }

    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.addEventListener('click', function () {
            addToCart({
                id: parseInt(this.dataset.id),
                name: this.dataset.name,
                price: parseFloat(this.dataset.price),
                stock: parseInt(this.dataset.stock)
            });
        });
    });

    function addToCart(product) {
        const existing = cart.find(item => item.id === product.id);
        if (existing) {
            if (existing.quantity < product.stock) {
                existing.quantity++;
            } else {
                alert('Not enough stock!');
                return;
            }
        } else {
            cart.push({ ...product, quantity: 1 });
        }
        updateCartDisplay();
    }

    function updateQuantity(productId, change) {
        const item = cart.find(i => i.id === productId);
        if (item) {
            const newQty = item.quantity + change;
            if (newQty <= 0) {
                removeFromCart(productId);
            } else if (newQty <= item.stock) {
                item.quantity = newQty;
                updateCartDisplay();
            } else {
                alert('Not enough stock!');
            }
        }
    }

    function setQuantity(productId, qty) {
        const item = cart.find(i => i.id === productId);
        if (item) {
            qty = parseInt(qty) || 0;
            if (qty <= 0) {
                removeFromCart(productId);
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

    function removeFromCart(productId) {
        cart = cart.filter(item => item.id !== productId);
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
            cart = savedCart.filter(item => {
                const product = productsData.find(p => parseInt(p.id) === item.id);
                if (!product) return false;
                item.stock = parseInt(product.stock);
                item.price = parseFloat(product.sell_price);
                item.name = product.name;
                if (item.quantity > item.stock) item.quantity = item.stock;
                return true;
            });
        } catch (e) {}
    }

    // Call updateCartDisplay via timeout or direct call if data loaded
    if (editSaleData) {
        setTimeout(updateCartDisplay, 100);
    } else {
        loadCart();
        updateCartDisplay();
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
                </div>
                <div class="pos-cart-item-qty">
                    <button onclick="updateQuantity(${item.id}, -1)">-</button>
                    <input type="number" value="${item.quantity}" min="1" max="${item.stock}" 
                           onchange="setQuantity(${item.id}, this.value)">
                    <button onclick="updateQuantity(${item.id}, 1)">+</button>
                </div>
                <button class="pos-cart-item-remove" onclick="removeFromCart(${item.id})">
                    <i class="far fa-trash-alt"></i>
                </button>
            </div>
        `).join('');

            cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
        }

        calculateTotals();
        saveCart();
    }

    function calculateTotals() {
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

        const discountType = document.getElementById('discountType').value;
        const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;

        let discountAmount = 0;
        if (discountType === 'percent') {
            discountAmount = subtotal * (discountValue / 100);
        } else {
            discountAmount = discountValue;
        }

        // Ensure discount doesn't exceed subtotal
        if (discountAmount > subtotal) {
            discountAmount = subtotal;
        }

        const afterDiscount = subtotal - discountAmount;
        const vatAmount = afterDiscount * (vatPercent / 100);
        const total = afterDiscount + vatAmount;

        document.getElementById('subtotal').textContent = `${currency} ${subtotal.toFixed(2)}`;
        document.getElementById('discountAmount').textContent = `- ${currency} ${discountAmount.toFixed(2)}`;
        document.getElementById('vatAmount').textContent = `${currency} ${vatAmount.toFixed(2)}`;
        document.getElementById('totalAmount').textContent = `${currency} ${total.toFixed(2)}`;

        document.getElementById('checkoutBtn').disabled = cart.length === 0;

        calculateChange();
    }

    document.getElementById('discountType').addEventListener('change', calculateTotals);
    document.getElementById('discountValue').addEventListener('input', calculateTotals);
    document.getElementById('paidAmount').addEventListener('input', calculateChange);

    function calculateChange() {
        const total = parseFloat(document.getElementById('totalAmount').textContent.replace(currency, '').trim()) || 0;
        const paid = parseFloat(document.getElementById('paidAmount').value) || 0;
        const change = paid - total;

        document.getElementById('changeRow').style.display = paid > 0 ? 'flex' : 'none';
        document.getElementById('changeAmount').innerHTML = `<strong>${currency} ${Math.max(0, change).toFixed(2)}</strong>`;
    }

    function setExactAmount() {
        const total = parseFloat(document.getElementById('totalAmount').textContent.replace(currency, '').trim()) || 0;
        document.getElementById('paidAmount').value = total.toFixed(2);
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

        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

        const discountType = document.getElementById('discountType').value;
        const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;

        let discountAmount = 0;
        let discountPercent = 0;

        if (discountType === 'percent') {
            discountPercent = discountValue;
            discountAmount = subtotal * (discountPercent / 100);
        } else {
            discountAmount = discountValue;
            // Calculate equivalent percent for record keeping if needed, or just 0
            discountPercent = subtotal > 0 ? (discountAmount / subtotal * 100) : 0;
        }

        if (discountAmount > subtotal) discountAmount = subtotal;

        const afterDiscount = subtotal - discountAmount;
        const vatAmount = afterDiscount * (vatPercent / 100);
        const total = afterDiscount + vatAmount;
        const paidAmount = parseFloat(document.getElementById('paidAmount').value) || total;

        if (paidAmount < total && paymentMethod === 'cash') {
            alert('Paid amount is less than total!');
            return;
        }

        const saleData = {
            edit_sale_id: editSaleId, // ID if editing, 0 if new
            customer_id: document.getElementById('customerId').value ? parseInt(document.getElementById('customerId').value) : 0,
            items: cart.map(item => ({
                product_id: item.id,
                product_name: item.name,
                quantity: item.quantity,
                unit_price: item.price,
                total_price: item.price * item.quantity
            })),
            subtotal: subtotal,
            discount_type: discountType,
            discount_value: discountValue,
            discount_percent: discountPercent,
            discount_amount: discountAmount,
            vat_percent: vatPercent,
            vat_amount: vatAmount,
            total: total,
            paid_amount: paidAmount,
            change_amount: Math.max(0, paidAmount - total),
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
                showInvoice(result.invoice);
                cart = [];
                updateCartDisplay();
                document.getElementById('customerId').value = '';
                document.getElementById('customerSearch').value = '';
                document.getElementById('discountValue').value = 0;
                document.getElementById('paidAmount').value = '';

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

    function showInvoice(invoice) {
        const facebookUrl = `<?php echo sanitize($settings['facebook_page'] ?? 'https://www.facebook.com'); ?>`;
        const shopName = `<?php echo sanitize($settings['shop_name'] ?? 'POS System'); ?>`;
        const shopAddress = `<?php echo sanitize($settings['shop_address'] ?? ''); ?>`;
        const shopPhone = `<?php echo sanitize($settings['shop_phone'] ?? ''); ?>`;
        const receiptFooter = `<?php echo sanitize($settings['receipt_footer'] ?? 'Thank you for shopping!'); ?>`;
        const voucherTerms = `<?php echo sanitize($settings['voucher_terms'] ?? ''); ?>`;

        document.getElementById('invoiceContent').innerHTML = `
        <style>
            #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
        </style>
        <div id="printableInvoice" style="font-family: 'Hind Siliguri', monospace; font-size: 12px; width: 100%; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 1rem;">
                <h3 style="margin: 0; font-size: 16px;">${shopName}</h3>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopAddress}</p>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopPhone}</p>
                <div style="margin-top: 8px;">
                    <svg id="invoiceReturnBarcode" data-barcode="${invoice.invoice_number}"></svg>
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

            <div style="margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px; font-size: 10px;">
                <p style="margin: 0 0 4px 0; font-size: 11px; font-weight: bold;">পণ্য পরিবর্তন নীতি</p>
                <p style="margin: 0; white-space: pre-line;">ক্রয়ের তারিখ থেকে ৭ দিনের মধ্যে পণ্য পরিবর্তন করা যাবে।\nপণ্যটি অবশ্যই অব্যবহৃত, অরিজিনাল এবং রসিদসহ হতে হবে।\nকোনো নগদ টাকা ফেরত দেওয়া হবে না।\nপণ্য পরিবর্তন পণ্যের প্রাপ্যতা ও দোকানের নীতিমালার ওপর নির্ভরশীল।</p>
            </div>
            
            ${invoice.coupon_status == '1' ? `
            <div style="page-break-before: always; margin-top: 20px; border-top: 1px dashed #000; padding-top: 20px; font-family: monospace;">
                <div style="border: 2px solid #000; padding: 10px; border-radius: 8px;">
                    <h3 style="text-align: center; margin: 0 0 4px 0; font-size: 14px; text-transform: uppercase;">${invoice.coupon_title}</h3>
                    <p style="text-align: center; margin: 0 0 8px 0; font-size: 11px;">${invoice.coupon_subtitle}</p>

                    <div style="text-align: center; margin-bottom: 8px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=${encodeURIComponent(facebookUrl)}" alt="Facebook QR" style="width: 60px; height: 60px; border-radius: 4px;" />
                        <p style="margin: 2px 0 0 0; font-size: 9px;">Scan for Facebook</p>
                    </div>

                    <div style="text-align: center; font-size: 10px; border: 1px dashed #000; padding: 4px; border-radius: 4px; margin-bottom: 6px;">
                        ${invoice.coupon_prize_1} <br/> ${invoice.coupon_prize_2} <br/> ${invoice.coupon_prize_3} <br/> ${invoice.coupon_prize_4} <br/> ${invoice.coupon_prize_5}
                    </div>

                    <div style="text-align: center; font-size: 10px; margin-bottom: 4px;">${invoice.coupon_total_winners}</div>

                    <div style="font-size: 9px; text-align: center; border-bottom: 1px dashed #000; padding-bottom: 6px; margin-bottom: 8px;">
                        ${invoice.coupon_announcement}
                    </div>

                    <div style="font-size: 11px;">
                        <div style="text-align: center; margin-bottom: 6px; border: 1px solid #000; padding: 4px; font-size: 13px;">
                            SC-${invoice.invoice_number}
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
                        <div style="margin-top: 25px; text-align: right; border-top: 1px dashed #000; display: inline-block; float: right; padding-top: 4px;">Shop seal & signature</div>
                        <div style="clear: both;"></div>
                    </div>
                </div>
            </div>
            ` : ''}
        </div>
    `;
        document.getElementById('invoiceModal').classList.add('active');
        renderInvoiceBarcode();
    }

    function renderInvoiceBarcode() {
        const svg = document.getElementById('invoiceReturnBarcode');
        if (svg && typeof JsBarcode !== 'undefined' && svg.dataset.barcode) {
            try {
                JsBarcode(svg, svg.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                });
            } catch (e) {}
        }
    }

    function closeInvoiceModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    async function printInvoice() {
        let printContent = document.getElementById('printableInvoice').innerHTML;
        
        // Pre-fetch all QR images as base64 data URIs
        const qrMatches = printContent.match(/src="(https:\/\/api\.qrserver\.com[^"]+)"/g);
        if (qrMatches) {
            for (const match of qrMatches) {
                const url = match.match(/src="([^"]+)"/)[1];
                try {
                    const resp = await fetch(url);
                    const blob = await resp.blob();
                    const base64 = await new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onloadend = () => resolve(reader.result);
                        reader.readAsDataURL(blob);
                    });
                    printContent = printContent.replace(url, base64);
                } catch(e) {}
            }
        }
        
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
        <head>
            <title>Invoice</title>
            <meta charset="utf-8">
            <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hind-siliguri.css">
            <style>
                body { font-family: 'Hind Siliguri', sans-serif; font-size: 12px; margin: 0; padding: 10px; }
                #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
                #invoiceReturnBarcode { height: 50px; }
                hr { border-style: dashed; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 2px 0; }
                @media print {
                    body { margin: 0; padding: 5px; }
                    @page { margin: 5mm; }
                }
            </style>
            <script src="<?php echo $baseUrl; ?>\/assets\/js\/jsbarcode.min.js"><\/script>
        </head>
        <body>${printContent}</body>
        </html>
    `);
        printWindow.document.close();
        printWindow.onload = function() {
            const doPrint = function() {
                const barcode = printWindow.document.getElementById('invoiceReturnBarcode');
                if (barcode && typeof printWindow.JsBarcode !== 'undefined' && barcode.dataset.barcode) {
                    try {
                        printWindow.JsBarcode(barcode, barcode.dataset.barcode, {
                            format: 'CODE128',
                            width: 1.5,
                            height: 50,
                            displayValue: false
                        });
                    } catch (e) {}
                }
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
                alert('Customer added successfully!');
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