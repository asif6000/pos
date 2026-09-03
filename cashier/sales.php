<?php
/**
 * POS System - Cashier Sales View
 * View their own sales transactions
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

// Cashier can only see their own sales - no redirect needed
// (redirect removed so cashiers can access sales list)

define('PAGE_TITLE', 'My Sales');

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

$db = getDB();
$user = getCurrentUser();

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
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));

// Get only this cashier's sales
$stmt = $db->prepare("
    SELECT s.*, c.name as customer_name 
    FROM sales s 
    LEFT JOIN customers c ON s.customer_id = c.id 
    WHERE s.user_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$user['id'], $dateFrom, $dateTo]);
$sales = $stmt->fetchAll();

$totalSales = array_sum(array_column($sales, 'total'));
$totalTransactions = count($sales);

include 'includes/header.php';
?>

<!-- Summary -->
<div class="stats-grid two-col-equal" style="margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Total Sales</div>
            <div class="stat-value"><?php echo formatCurrency($totalSales); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-receipt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo $totalTransactions; ?></div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No sales found</td></tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                            <tr data-sale-id="<?php echo $sale['id']; ?>" style="background: <?php echo $sale['printed'] ? '#f0fdf4' : ''; ?>">
                                <td><strong><?php echo sanitize($sale['invoice_number']); ?>
                                            <?php if (!$sale['printed']): ?>
                                                <i class="fas fa-circle print-dot" style="color: #ef4444; font-size: 0.5rem; vertical-align: middle;" title="Not Printed"></i>
                                            <?php else: ?>
                                                <i class="fas fa-circle print-dot" style="color: #22c55e; font-size: 0.5rem; vertical-align: middle;" title="Printed"></i>
                                            <?php endif; ?></strong></td>
                                <td><?php echo date('h:i A', strtotime($sale['created_at'])); ?></td>
                                <td><?php echo sanitize($sale['customer_name'] ?? 'Walk-in'); ?></td>
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
                                <td><strong><?php echo formatCurrency($sale['total']); ?></strong></td>
                                <td><span class="badge badge-primary"><?php echo ucfirst($sale['payment_method']); ?></span></td>
                                <td><span class="badge badge-success"><?php echo ucfirst($sale['payment_status']); ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline" onclick="viewInvoice(<?php echo $sale['id']; ?>)" title="View Invoice">
                                        <i class="fas fa-eye"></i>
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
            fetch('../admin/api/mark-printed.php', {
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
            const response = await fetch('../admin/api/get-invoice.php?id=' + saleId);
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
        const subtotal    = parseFloat(invoice.subtotal    || 0);
        const discountAmt = parseFloat(invoice.discount_amount || 0);
        const vatAmt      = parseFloat(invoice.vat_amount   || 0);
        const total       = parseFloat(invoice.total        || 0);
        const paidAmt     = parseFloat(invoice.paid_amount  || 0);
        const changeAmt   = parseFloat(invoice.change_amount || 0);
        const payMethod   = (invoice.payment_method || 'Cash').toUpperCase();
        
        let discountRow = '';
        if (discountAmt > 0) {
            const discPercent = invoice.discount_percent ? ` (${invoice.discount_percent}%)` : '';
            discountRow = `
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; color: #4b5563; font-size: 13px;">
                    <span>Discount${discPercent}</span>
                    <span style="color: #10b981;">- ${currency} ${discountAmt.toFixed(2)}</span>
                </div>`;
        }

        let vatRow = '';
        if (vatAmt > 0) {
            vatRow = `
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; color: #4b5563; font-size: 13px;">
                    <span>VAT (${invoice.vat_percent || '0'}%)</span>
                    <span>${currency} ${vatAmt.toFixed(2)}</span>
                </div>`;
        }

        let itemsHtml = invoice.items.map((item, index) => `
            <tr style="border-bottom: 1px solid #f3f4f6;">
                <td style="padding: 1rem; color: #4b5563; font-size: 13px;">${index + 1}</td>
                <td style="padding: 1rem;">
                    <div style="font-weight: 600; color: #111827; font-size: 13px;">${item.product_name}</div>
                </td>
                <td style="padding: 1rem; text-align: center; color: #4b5563; font-size: 13px;">${item.quantity}</td>
                <td style="padding: 1rem; text-align: right; color: #4b5563; font-size: 13px;">${currency} ${parseFloat(item.unit_price).toFixed(2)}</td>
                <td style="padding: 1rem; text-align: right; font-weight: 600; color: #111827; font-size: 13px;">${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
            </tr>
        `).join('');

        const shopName = invoice.shop_name || 'POS System';
        const shopAddress = invoice.shop_address || 'Dhaka, Bangladesh';
        const shopPhone = invoice.shop_phone || '+880 1234 567890';
        const shopEmail = invoice.shop_email || 'info@vouser.com'; 
        const shopWeb = invoice.shop_website || 'www.vouser.com';
        const facebookUrl = invoice.facebook_page || 'https://www.facebook.com';

        document.getElementById('invoiceContent').innerHTML = `
        <style>
            #printableInvoice * { font-weight: 900 !important; color: #000 !important; }
        </style>
        <div id="printableInvoice" style="font-family: 'Hind Siliguri', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #fff; padding: 2rem; border-radius: 8px;">
            
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 3rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 40px; height: 40px; background: #4f46e5; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                    </div>
                    <div>
                        <h2 style="margin: 0; font-size: 1.5rem; font-weight: 700; color: #111827;">${shopName}</h2>
                        <p style="margin: 0; color: #4f46e5; font-size: 0.875rem; font-weight: 500;">Shopping</p>
                    </div>
                </div>
                <div style="text-align: center;">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=${encodeURIComponent(facebookUrl)}" alt="Facebook QR" style="width: 80px; height: 80px; border-radius: 4px;" />
                    <p style="margin: 4px 0 0 0; font-size: 0.65rem; color: #4b5563; font-weight: 600;">Scan for Facebook</p>
                </div>
                <div style="text-align: center; margin-top: 0.5rem;">
                    <svg id="invoiceReturnBarcode" data-barcode="${invoice.invoice_number}"></svg>
                </div>
                <div style="text-align: right;">
                    <h1 style="margin: 0; font-size: 2rem; font-weight: 800; color: #111827; letter-spacing: 1px;">INVOICE</h1>
                    <p style="margin: 0.25rem 0 0 0; color: #4f46e5; font-size: 1rem; font-weight: 600;">#${invoice.invoice_number}</p>
                </div>
            </div>

            <!-- Info Section -->
            <div style="display: flex; justify-content: space-between; margin-bottom: 2rem;">
                <!-- Bill To -->
                <div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 0.75rem; font-weight: 700; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">BILL TO</h3>
                    <p style="margin: 0 0 0.25rem 0; font-size: 1.125rem; font-weight: 600; color: #111827;">${invoice.customer_name}</p>
                    <p style="margin: 0 0 0.25rem 0; color: #4b5563; font-size: 0.875rem;">Customer ID: ${invoice.customer_id || 'Walk-in'}</p>
                </div>

                <!-- Dates & Payment -->
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 32px; height: 32px; background: #eef2ff; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #4f46e5;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </div>
                        <div>
                            <p style="margin: 0; font-size: 0.75rem; color: #6b7280; font-weight: 500;">Invoice Date</p>
                            <p style="margin: 0; font-size: 0.875rem; font-weight: 600; color: #111827;">${invoice.date ? invoice.date.split(',')[0] : ''}</p>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 32px; height: 32px; background: #eef2ff; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #4f46e5;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                        </div>
                        <div>
                            <p style="margin: 0; font-size: 0.75rem; color: #6b7280; font-weight: 500;">Payment Method</p>
                            <p style="margin: 0; font-size: 0.875rem; font-weight: 600; color: #111827;">${payMethod}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
                <thead>
                    <tr style="background: #4f46e5; color: white; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <th style="padding: 0.75rem 1rem; text-align: left; border-top-left-radius: 6px; border-bottom-left-radius: 6px;">#</th>
                        <th style="padding: 0.75rem 1rem; text-align: left;">ITEM & DESCRIPTION</th>
                        <th style="padding: 0.75rem 1rem; text-align: center;">QTY</th>
                        <th style="padding: 0.75rem 1rem; text-align: right;">UNIT PRICE</th>
                        <th style="padding: 0.75rem 1rem; text-align: right; border-top-right-radius: 6px; border-bottom-right-radius: 6px;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>

            <!-- Summary & Notes -->
            <div style="display: flex; justify-content: space-between; gap: 2rem; margin-bottom: 3rem;">
                <!-- Notes -->
                <div style="flex: 1; background: #f9fafb; padding: 1.5rem; border-radius: 8px;">
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.75rem; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.5px;">NOTES</h4>
                    <p style="margin: 0 0 1.5rem 0; font-size: 0.875rem; color: #4b5563; line-height: 1.5;">Thank you for shopping with us.<br>We hope to see you again!</p>
                    
                    <h4 style="margin: 0 0 0.5rem 0; font-size: 0.75rem; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.5px;">TERMS & CONDITIONS</h4>
                    <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.875rem; color: #4b5563; line-height: 1.5;">
                        <li>Goods once sold will not be taken back.</li>
                        <li>Warranty applicable as per company policy.</li>
                        <li>Please keep this invoice for future reference.</li>
                    </ul>
                </div>

                <!-- Totals -->
                <div style="flex: 1; max-width: 300px;">
                    <div style="padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; color: #4b5563; font-size: 13px;">
                            <span>Subtotal</span>
                            <span>${currency} ${subtotal.toFixed(2)}</span>
                        </div>
                        ${discountRow}
                        ${vatRow}
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; margin-bottom: 1rem;">
                        <span style="font-size: 1rem; font-weight: 700; color: #111827;">TOTAL</span>
                        <span style="font-size: 1.5rem; font-weight: 800; color: #4f46e5;">${currency} ${total.toFixed(2)}</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; color: #6b7280; font-size: 12px;">
                        <span>Paid Amount</span>
                        <span>${currency} ${paidAmt.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 12px;">
                        <span>Change</span>
                        <span>${currency} ${changeAmt.toFixed(2)}</span>
                    </div>
                </div>
            </div>

            <!-- Footer Contact -->
            <div style="padding-top: 2rem; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; gap: 1.5rem; font-size: 0.75rem; color: #4b5563;">
                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        ${shopPhone}
                    </span>
                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        ${shopEmail}
                    </span>
                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        ${shopWeb}
                    </span>
                    <span style="display: flex; align-items: center; gap: 0.25rem;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        ${shopAddress}
                    </span>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 1.5rem;">
                <p style="margin: 0; color: #4f46e5; font-size: 0.875rem; font-style: italic; font-weight: 500;">${invoice.receipt_footer || 'Thank you for your business!'}</p>
            ${invoice.voucher_terms ? `
            <div style="text-align: left; margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px; color: #000;">
                <p style="margin: 0 0 4px 0; font-size: 0.8rem; font-weight: bold;">Terms & Conditions</p>
                <p style="margin: 0; font-size: 0.75rem; white-space: pre-line;">${invoice.voucher_terms}</p>
            </div>
            ` : ''}

            <div style="text-align: left; margin-top: 10px; border-top: 1px dashed #000; padding-top: 8px; color: #000; font-size: 0.75rem;">
                <p style="margin: 0 0 4px 0; font-size: 0.8rem; font-weight: bold;">পণ্য পরিবর্তন নীতি</p>
                <p style="margin: 0; white-space: pre-line;">ক্রয়ের তারিখ থেকে ৭ দিনের মধ্যে পণ্য পরিবর্তন করা যাবে।\nপণ্যটি অবশ্যই অব্যবহৃত, অরিজিনাল এবং রসিদসহ হতে হবে।\nকোনো নগদ টাকা ফেরত দেওয়া হবে না।\nপণ্য পরিবর্তন পণ্যের প্রাপ্যতা ও দোকানের নীতিমালার ওপর নির্ভরশীল।</p>
            </div>
            </div>
            
            ${invoice.coupon_status == '1' ? `
            <div style="page-break-before: always; margin-top: 20px; border-top: 1px dashed #000; padding-top: 20px; font-family: sans-serif; color: #000;">
                <div style="border: 2px solid #000; padding: 10px; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div style="flex: 1;">
                            <h3 style="text-align: left; margin: 0 0 4px 0; font-weight: 900; font-size: 15px;">${invoice.coupon_title}</h3>
                            <p style="text-align: left; margin: 0; font-size: 11px; font-weight: bold;">${invoice.coupon_subtitle}</p>
                        </div>
                        <div style="text-align: right; width: 60px;">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=60x60&data=${encodeURIComponent(facebookUrl)}" alt="Facebook QR" style="width: 50px; height: 50px; border-radius: 4px;" />
                        </div>
                    </div>

                    <!-- Prizes: one line, separated by | -->
                    <div style="text-align: center; font-size: 9px; font-weight: bold; border: 1px dashed #000; padding: 4px 2px; border-radius: 4px; margin-bottom: 6px; line-height: 1.6;">
                        ${invoice.coupon_prize_1} &nbsp;|&nbsp; ${invoice.coupon_prize_2} &nbsp;|&nbsp; ${invoice.coupon_prize_3} &nbsp;|&nbsp; ${invoice.coupon_prize_4} &nbsp;|&nbsp; ${invoice.coupon_prize_5}
                    </div>

                    <div style="text-align: center; font-size: 9px; font-weight: bold; margin-bottom: 4px;">${invoice.coupon_total_winners}</div>

                    <div style="font-size: 8px; text-align: center; background: #f0f0f0; padding: 3px; border-radius: 4px; margin-bottom: 8px;">
                        ${invoice.coupon_announcement}
                    </div>

                    <!-- SC Number + Fields below -->
                    <div style="border-top: 1px dashed #000; padding-top: 8px; font-size: 11px;">
                        <div style="text-align: center; margin-bottom: 6px; border: 1px solid #000; padding: 3px; border-radius: 4px; font-weight: bold; font-size: 12px; letter-spacing: 1px;">
                            SC-${invoice.invoice_number}
                        </div>
                        <div style="display: flex; gap: 6px; margin-bottom: 6px;">
                            <div style="flex: 1;">
                                <label style="display: block; font-weight: bold; font-size: 9px;">Name</label>
                                <div style="border: 1px solid #ccc; padding: 3px 4px; border-radius: 4px; min-height: 14px;">${invoice.customer_name}</div>
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; font-weight: bold; font-size: 9px;">Mobile</label>
                                <div style="border: 1px solid #ccc; padding: 3px 4px; border-radius: 4px; min-height: 14px;">${invoice.customer_phone || ''}</div>
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; font-weight: bold; font-size: 9px;">Date</label>
                                <div style="border: 1px solid #ccc; padding: 3px 4px; border-radius: 4px; min-height: 14px;">${invoice.date || ''}</div>
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; font-size: 9px;">Shop seal &amp; signature</label>
                            <div style="border: 1px solid #ccc; padding: 3px 4px; border-radius: 4px; min-height: 28px;"></div>
                        </div>
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

    function closeModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    document.getElementById('printBtn').addEventListener('click', async () => {
        markAsPrinted();
        const content = document.getElementById('printableInvoice');
        if (!content) return;
        
        let printHTML = content.innerHTML;
        
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
    });

    document.getElementById('invoiceModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>
