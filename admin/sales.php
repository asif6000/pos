<?php
/**
 * POS System - Sales List
 * View and manage all sales transactions
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

// Permission check: only users with 'sales' permission can view this page
if (!hasPermission('sales')) {
    setFlash('danger', 'Access denied. You do not have permission to view Sales.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Sales');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

$user = getCurrentUser();
$store_id = $user['store_id'] ?? null;

$db = getDB();

// Add printed column if missing (safe migration)
try {
    $cols = $db->query("SHOW COLUMNS FROM sales LIKE 'printed'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE sales ADD COLUMN printed TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
    }
} catch (PDOException $e) {
    // Column may already exist
}

// Filters
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));
$paymentMethod = sanitize($_GET['payment'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$filterStore = sanitize($_GET['store'] ?? '');

// Build query - Filter by store (if specific) or owner (global view)
if ($store_id) {
    $sql = "SELECT s.*, c.name as customer_name, u.name as cashier_name, st.name as store_name
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN stores st ON u.store_id = st.id
            WHERE u.store_id = ? AND DATE(s.created_at) BETWEEN ? AND ?";
    $params = [$store_id, $dateFrom, $dateTo];
} else {
    $sql = "SELECT s.*, c.name as customer_name, u.name as cashier_name, st.name as store_name
            FROM sales s 
            LEFT JOIN customers c ON s.customer_id = c.id 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN stores st ON u.store_id = st.id
            WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?";
    $params = [$user['owner_id'], $dateFrom, $dateTo];
}

if ($paymentMethod) {
    $sql .= " AND s.payment_method = ?";
    $params[] = $paymentMethod;
}

if ($filterStore && !$store_id) {
    $sql .= " AND u.store_id = ?";
    $params[] = (int)$filterStore;
}

if ($search) {
    $sql .= " AND (s.invoice_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY s.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Calculate totals
$totalSales = array_sum(array_column($sales, 'total'));
$totalTransactions = count($sales);

// Stores for filter dropdown
$stores = [];
if (!$store_id) {
    $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
    $stmt->execute([$user['owner_id']]);
    $stores = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<!-- Page Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <p class="text-muted">View all sales transactions</p>
    </div>
    <a href="pos.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Sale
    </a>
</div>

<!-- Summary Cards -->
<div class="stats-grid two-col-equal" style="margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Sales (Filtered)</div>
            <div class="stat-value">
                <?php echo formatCurrency($totalSales); ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Transactions</div>
            <div class="stat-value">
                <?php echo $totalTransactions; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
            </div>
            <?php if (!$store_id): ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Store</label>
                <select name="store" class="form-control">
                    <option value="">All Stores</option>
                    <?php foreach ($stores as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $filterStore == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($st['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Payment Method</label>
                <select name="payment" class="form-control">
                    <option value="">All Methods</option>
                    <option value="cash" <?php echo $paymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="bkash" <?php echo $paymentMethod === 'bkash' ? 'selected' : ''; ?>>bKash</option>
                    <option value="nagad" <?php echo $paymentMethod === 'nagad' ? 'selected' : ''; ?>>Nagad</option>
                    <option value="card" <?php echo $paymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Invoice or customer..."
                    value="<?php echo $search; ?>">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="sales.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </form>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Store</th>
                        <th>Customer</th>
                        <th>Cashier</th>
                        <th>Products</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">No sales found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <?php
                            // Get item count
                            $itemStmt = $db->prepare("SELECT SUM(quantity) FROM sale_items WHERE sale_id = ?");
                            $itemStmt->execute([$sale['id']]);
                            $itemCount = $itemStmt->fetchColumn();

                            // Get product names for this sale
                            $prodStmt = $db->prepare("SELECT product_name, quantity FROM sale_items WHERE sale_id = ? ORDER BY id");
                            $prodStmt->execute([$sale['id']]);
                            $saleProducts = $prodStmt->fetchAll();
                            ?>
                            <tr data-sale-id="<?php echo $sale['id']; ?>" style="background: <?php echo $sale['printed'] ? '#f0fdf4' : '#fef2f2'; ?>;">
                                <td><strong>
                                        <?php echo sanitize($sale['invoice_number']); ?>
                                        <?php if (!$sale['printed']): ?>
                                            <i class="fas fa-circle print-dot" style="color: #ef4444; font-size: 0.5rem; vertical-align: middle;" title="Not Printed"></i>
                                        <?php else: ?>
                                            <i class="fas fa-circle print-dot" style="color: #22c55e; font-size: 0.5rem; vertical-align: middle;" title="Printed"></i>
                                        <?php endif; ?>
                                    </strong></td>
                                <td>
                                    <?php echo date('d M Y, h:i A', strtotime($sale['created_at'])); ?>
                                </td>
                                <td>
                                    <?php if (!empty($sale['store_name'])): ?>
                                        <span class="badge badge-info">
                                            <i class="fas fa-store"></i> <?php echo sanitize($sale['store_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo sanitize($sale['customer_name'] ?? 'Walk-in'); ?>
                                </td>
                                <td>
                                    <?php echo sanitize($sale['cashier_name']); ?>
                                </td>
                                <td>
                                    <div style="max-width: 220px;">
                                        <?php if (empty($saleProducts)): ?>
                                            <span class="text-muted"><?php echo $itemCount; ?> items</span>
                                        <?php else: ?>
                                            <?php foreach ($saleProducts as $pi => $sp): ?>
                                                <?php if ($pi < 2): ?>
                                                    <div style="font-size:0.78rem; line-height:1.4; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                        <i class="fas fa-cube" style="color:#94a3b8;"></i>
                                                        <?php echo sanitize($sp['product_name']); ?>
                                                        <span class="text-muted">× <?php echo $sp['quantity']; ?></span>
                                                    </div>
                                                <?php elseif ($pi === 2): ?>
                                                    <div style="font-size:0.75rem; color:#94a3b8;">
                                                        + <?php echo count($saleProducts) - 2; ?> more &middot; <?php echo $itemCount; ?> items total
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php $saleDiscount = (float) ($sale['discount_amount'] ?? 0); ?>
                                    <?php if ($saleDiscount > 0): ?>
                                        <span style="color: #10b981; font-weight: 600;">
                                            -<?php echo formatCurrency($saleDiscount); ?>
                                            <?php if (!empty($sale['discount_percent'])): ?>
                                                <small style="color: #6b7280; font-weight: 400;">(<?php echo rtrim(rtrim(number_format($sale['discount_percent'], 2), '0'), '.'); ?>%)</small>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong>
                                        <?php echo formatCurrency($sale['total']); ?>
                                    </strong></td>
                                <td>
                                    <span class="badge badge-primary">
                                        <?php echo ucfirst($sale['payment_method']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span
                                        class="badge badge-<?php echo $sale['payment_status'] === 'paid' ? 'success' : ($sale['payment_status'] === 'partial' ? 'warning' : 'danger'); ?>">
                                        <?php echo ucfirst($sale['payment_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <button class="btn btn-sm btn-outline" onclick="viewInvoice(<?php echo $sale['id']; ?>)"
                                            title="View Invoice">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="pos.php?edit_sale_id=<?php echo $sale['id']; ?>" class="btn btn-sm btn-outline"
                                            title="Edit Sale">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="returns.php?invoice=<?php echo urlencode($sale['invoice_number']); ?>"
                                            class="btn btn-sm btn-outline" title="Return">
                                            <i class="fas fa-undo"></i>
                                        </a>
                                        <?php if ($user['role'] === 'admin' || hasPermission('sales_delete')): ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteSale(<?php echo $sale['id']; ?>)"
                                            title="Delete Sale">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
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

<!-- Invoice Modal -->
<div class="modal-overlay" id="invoiceModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Invoice Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="invoiceContent">
            <div class="text-center">
                <div class="spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="printBtn">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<script src="<?php echo $baseUrl; ?>/assets/js/jsbarcode.min.js"></script>
<script>
    let currentPrintSaleId = null;

    function markAsPrinted() {
        if (currentPrintSaleId) {
            fetch('api/mark-printed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: currentPrintSaleId })
            }).then(() => {
                const row = document.querySelector(`tr[data-sale-id="${currentPrintSaleId}"]`);
                if (row) {
                    row.style.background = '#f0fdf4';
                    const dot = row.querySelector('.print-dot');
                    if (dot) { dot.style.color = '#22c55e'; dot.title = 'Printed'; }
                }
            });
            currentPrintSaleId = null;
        }
    }

    async function viewInvoice(saleId) {
        currentPrintSaleId = saleId;
        document.getElementById('invoiceModal').classList.add('active');

        try {
            const response = await fetch(`api/get-invoice.php?id=${saleId}`);
            const data = await response.json();

            if (data.success) {
                displayInvoice(data.invoice);
            } else {
                document.getElementById('invoiceContent').innerHTML = '<p class="text-danger">Error loading invoice</p>';
            }
        } catch (error) {
            document.getElementById('invoiceContent').innerHTML = '<p class="text-danger">Error loading invoice</p>';
        }
    }

    function displayInvoice(invoice) {
        const currency = '<?php echo CURRENCY; ?>';
        const facebookUrl = invoice.facebook_page || 'https://www.facebook.com';

        document.getElementById('invoiceContent').innerHTML = `
        <style>
            #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
        </style>
        <div id="printableInvoice" style="font-family: 'Hind Siliguri', monospace; font-size: 12px; width: 100%; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 1rem;">
                <h3 style="margin: 0; font-size: 16px;">${invoice.shop_name || ''}</h3>
                <p style="margin: 0.25rem 0; font-size: 11px;">${invoice.shop_address || ''}</p>
                <p style="margin: 0.25rem 0; font-size: 11px;">${invoice.shop_phone || ''}</p>
                <div style="margin-top: 8px;">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=${encodeURIComponent(facebookUrl)}" alt="Facebook QR" style="width: 60px; height: 60px; border-radius: 4px;" />
                </div>
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
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.subtotal || 0).toFixed(2)}</td>
                </tr>
                ${parseFloat(invoice.discount_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Discount (${invoice.discount_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">- ${currency} ${parseFloat(invoice.discount_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                ${parseFloat(invoice.vat_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">VAT (${invoice.vat_percent}%)</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.vat_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
                <tr style="font-size: 14px; border-top: 1px dashed #000; border-bottom: 1px dashed #000;">
                    <td style="padding: 4px 0;">TOTAL</td>
                    <td style="text-align: right; padding: 4px 0;">${currency} ${parseFloat(invoice.total || 0).toFixed(2)}</td>
                </tr>
                <tr>
                    <td style="padding: 2px 0;">Paid (${(invoice.payment_method || 'Cash').toUpperCase()})</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.paid_amount || 0).toFixed(2)}</td>
                </tr>
                ${parseFloat(invoice.change_amount || 0) > 0 ? `
                <tr>
                    <td style="padding: 2px 0;">Change</td>
                    <td style="text-align: right; padding: 2px 0;">${currency} ${parseFloat(invoice.change_amount).toFixed(2)}</td>
                </tr>
                ` : ''}
            </table>
            <hr style="border-style: dashed;">
            <p style="text-align: center; font-size: 11px; margin-top: 10px;">${invoice.receipt_footer || 'Thank you for shopping!'}</p>
            ${invoice.voucher_terms ? `
            <div style="margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px;">
                <p style="margin: 0 0 4px 0; font-size: 11px; font-weight: bold;">Terms & Conditions</p>
                <p style="margin: 0; font-size: 10px; white-space: pre-line;">${invoice.voucher_terms}</p>
            </div>
            ` : ''}

            <div style="margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px; font-size: 10px;">
                <p style="margin: 0 0 4px 0; font-size: 11px; font-weight: bold;">পণ্য পরিবর্তন নীতি</p>
                <p style="margin: 0; white-space: pre-line;">ক্রয়ের তারিখ থেকে ৭ দিনের মধ্যে পণ্য পরিবর্তন করা যাবে।\nপণ্যটি অবশ্যই অব্যবহৃত, অরিজিনাল এবং রসিদসহ হতে হবে।\nকোনো নগদ টাকা ফেরত দেওয়া হবে না।\nপণ্য পরিবর্তন পণ্যের প্রাপ্যতা ও দোকানের নীতিমালার ওপর নির্ভরশীল।</p>
            </div>
            
            ${invoice.coupon_status == '1' ? `
            <div style="margin-top: 20px; border-top: 1px dashed #000; padding-top: 20px; font-family: monospace;">
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

    document.getElementById('printBtn').addEventListener('click', () => doPrint());

    async function doPrint() {
        const printContentEl = document.getElementById('printableInvoice');
        if (!printContentEl) return;

        let printHTML = printContentEl.innerHTML;
        
        // Pre-fetch all QR images as base64 data URIs
        const qrMatches = printHTML.match(/src="(https:\/\/api\.qrserver\.com[^"]+)"/g);
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
                    printHTML = printHTML.replace(url, base64);
                } catch(e) {}
            }
        }

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
        <head>
            <title>Invoice</title>
            <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hind-siliguri.css">
            <style>
                body { font-family: 'Hind Siliguri', monospace; font-size: 12px; margin: 0; padding: 10px; }
                #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
                #invoiceReturnBarcode { height: 50px; }
                hr { border-style: dashed; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 2px 0; }
            </style>
            <script src="<?php echo $baseUrl; ?>\/assets\/js\/jsbarcode.min.js"><\/script>
        </head>
        <body>${printHTML}</body>
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
                if (total === 0) { printWindow.print(); printWindow.close(); markAsPrinted(); return; }
                images.forEach(img => {
                    if (img.complete) {
                        loaded++;
                        if (loaded === total) { printWindow.print(); printWindow.close(); markAsPrinted(); }
                    } else {
                        img.onload = img.onerror = function() {
                            loaded++;
                            if (loaded === total) { printWindow.print(); printWindow.close(); markAsPrinted(); }
                        };
                    }
                });
                setTimeout(() => { printWindow.print(); printWindow.close(); markAsPrinted(); }, 5000);
            };
            const fontsReady = (printWindow.document.fonts && printWindow.document.fonts.ready) ? printWindow.document.fonts.ready : Promise.resolve();
            fontsReady.then(doPrint);
        };
    }

    async function deleteSale(saleId) {
        if (!confirm('Are you sure you want to delete this sale? This will restore stock.')) {
            return;
        }

        try {
            const response = await fetch('api/delete-sale.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: saleId })
            });

            const result = await response.json();

            if (result.success) {
                alert('Sale deleted successfully');
                window.location.reload();
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Error deleting sale');
            console.error(error);
        }
    }

    document.getElementById('invoiceModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>
