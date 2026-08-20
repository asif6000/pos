import re

with open('cashier/pos.php', 'r', encoding='utf-8', errors='replace') as f:
    content = f.read()

# Find the start of showInvoice
start_marker = '    function showInvoice(invoice) {'
end_marker = '    function renderInvoiceBarcode()'

start_idx = content.find(start_marker)
end_idx = content.find(end_marker)

if start_idx == -1:
    print("ERROR: Cannot find start of showInvoice")
    exit(1)
if end_idx == -1:
    print("ERROR: Cannot find end marker renderInvoiceBarcode")
    exit(1)

replacement = r"""    function showInvoice(invoice) {
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
        <div id="printableInvoice" style="font-family: monospace; font-size: 12px; width: 100%; max-width: 300px; margin: 0 auto;">
            <div style="text-align: center; margin-bottom: 1rem;">
                <div style="margin-bottom: 8px;">
                    <svg id="invoiceReturnBarcode" data-barcode="${invoice.invoice_number}"></svg>
                    <p style="margin: 4px 0 0 0; font-size: 10px;">Scan for Return</p>
                </div>
                <h3 style="margin: 0; font-size: 16px;">${shopName}</h3>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopAddress}</p>
                <p style="margin: 0.25rem 0; font-size: 11px;">${shopPhone}</p>
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

    """

new_content = content[:start_idx] + replacement + content[end_idx:]

with open('cashier/pos.php', 'w', encoding='utf-8') as f:
    f.write(new_content)

print("SUCCESS: showInvoice function replaced in cashier/pos.php")
print(f"Start index: {start_idx}, End index: {end_idx}")
