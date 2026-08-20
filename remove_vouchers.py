from pathlib import Path
import re

root = Path(r'c:\xampp1\htdocs\pos\pos')
files = {
    'admin_sales': root / 'admin' / 'sales.php',
    'cashier_pos': root / 'cashier' / 'pos.php',
    'cashier_sales': root / 'cashier' / 'sales.php',
    'admin_pos': root / 'admin' / 'pos.php',
    'voucher_settings': root / 'admin' / 'voucher-settings.php',
    'vouchers': root / 'admin' / 'vouchers.php',
    'db_sql': root / 'config' / 'database.sql',
    'migrate_all': root / 'migrate_all.php',
    'migrate_vouchers': root / 'migrate_vouchers.php'
}

for key, path in files.items():
    if not path.exists():
        raise FileNotFoundError(f'{key} file not found: {path}')

# admin/sales.php
path = files['admin_sales']
text = path.read_text(encoding='utf-8')
old = '''                                        <button class="btn btn-sm btn-outline"
                                            onclick="printInvoice(<?php echo $sale['id']; ?>)" title="Print">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline"
                                            onclick="printVouchers(<?php echo $sale['id']; ?>)" title="Print Vouchers">
                                            <i class="fas fa-gift"></i>
                                        </button>
                                        <a href="returns.php?invoice=<?php echo urlencode($sale['invoice_number']); ?>"
                                            class="btn btn-sm btn-outline" title="Return">
'''
new = '''                                        <button class="btn btn-sm btn-outline"
                                            onclick="printInvoice(<?php echo $sale['id']; ?>)" title="Print">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <a href="returns.php?invoice=<?php echo urlencode($sale['invoice_number']); ?>"
                                            class="btn btn-sm btn-outline" title="Return">
'''
text = text.replace(old, new)
old = '''            <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="printBtn">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-success" onclick="printVouchers(currentSaleId)">
                <i class="fas fa-gift"></i> Print Vouchers
            </button>
        </div>
    </div>
</div>
'''
new = '''            <button class="btn btn-secondary" onclick="closeModal()">Close</button>
            <button class="btn btn-primary" id="printBtn">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>
'''
text = text.replace(old, new)
old = '''    let currentSaleId = null;

    async function viewInvoice(saleId) {
        currentSaleId = saleId;
        document.getElementById('invoiceModal').classList.add('active');
'''
new = '''    async function viewInvoice(saleId) {
        document.getElementById('invoiceModal').classList.add('active');
'''
text = text.replace(old, new)
text = text.replace('            ${generateVoucherHtml(invoice)}\n', '')
pattern = re.compile(r'    function generateVoucherHtml\(invoice\) \{.*?\n    \}\n\n    async function printVouchers\(saleId\) \{.*?\n    \}\n\n    function printInvoice\(', re.S)
text, count = pattern.subn('    function printInvoice(', text)
if count == 0:
    raise SystemExit('admin_sales voucher functions not removed')
path.write_text(text, encoding='utf-8')

# cashier/sales.php
path = files['cashier_sales']
text = path.read_text(encoding='utf-8')
old = '''            <button class="btn btn-primary" onclick="printInvoice()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-success" onclick="printVouchers(currentSaleId)">
                <i class="fas fa-gift"></i> Print Vouchers
            </button>
        </div>
    </div>
</div>
'''
new = '''            <button class="btn btn-primary" onclick="printInvoice()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>
'''
text = text.replace(old, new)
text = text.replace('            ${generateVoucherHtml(invoice)}\n', '')
pattern = re.compile(r'    function generateVoucherHtml\(invoice\) \{.*?\n    \}\n\n    async function printVouchers\(saleId\) \{.*?\n    \}\n\n    async function getInvoice\(', re.S)
text, count = pattern.subn('    async function getInvoice(', text)
if count == 0:
    raise SystemExit('cashier_sales voucher functions not removed')
path.write_text(text, encoding='utf-8')

# cashier/pos.php
path = files['cashier_pos']
text = path.read_text(encoding='utf-8')
old = '''            <button class="btn btn-primary" onclick="printInvoice()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-success" onclick="printVoucherOnly()">
                <i class="fas fa-gift"></i> Print Voucher
            </button>
        </div>
    </div>
</div>
'''
new = '''            <button class="btn btn-primary" onclick="printInvoice()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
</div>
'''
text = text.replace(old, new)
start = text.find('        // Generate Vouchers HTML from server data\n')
if start != -1:
    end = text.find('            <!-- Appended Vouchers -->\n            ${vouchersHtml}\n        </div>\n    `;\n', start)
    if end != -1:
        text = text[:start] + text[end + len('            <!-- Appended Vouchers -->\n            ${vouchersHtml}\n        </div>\n    `;\n'):]
pattern = re.compile(r'    function printVoucherOnly\(\) \{.*?\n    \}\n', re.S)
text, count = pattern.subn('', text)
if count == 0:
    raise SystemExit('cashier_pos printVoucherOnly not removed')
path.write_text(text, encoding='utf-8')

# admin/pos.php stray block
path = files['admin_pos']
text = path.read_text(encoding='utf-8')
old = '''    function closeInvoiceModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }
        const content = document.getElementById('printableInvoice');
        if (!content) return;
        const voucherDiv = content.querySelector('.voucher-print');
        if (!voucherDiv) return alert('No voucher found');
        const w = voucherDiv.dataset.width || 80;
        const h = voucherDiv.dataset.height || 50;
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
        <html>
        <head>
            <title>Voucher</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Inter', sans-serif; background: #fff; padding: 0; width: ${w}mm; margin: 0 auto; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                @media print { body { width: ${w}mm; } @page { size: ${w}mm ${h}mm; margin: 0; } }
            </style>
        </head>
        <body>${voucherDiv.outerHTML}</body>
        </html>
        `);
        printWindow.document.close();
        setTimeout(() => { printWindow.print(); printWindow.close(); }, 500);
    }

    function printInvoice() {
'''
new = '''    function closeInvoiceModal() {
        document.getElementById('invoiceModal').classList.remove('active');
    }

    function printInvoice() {
'''
text = text.replace(old, new)
path.write_text(text, encoding='utf-8')

# voucher pages redirect
for key in ['voucher_settings', 'vouchers']:
    path = files[key]
    path.write_text("<?php\nheader('Location: index.php');\nexit;\n", encoding='utf-8')

# remove voucher schema from database.sql
path = files['db_sql']
text = path.read_text(encoding='utf-8')
start = text.find('-- 15. Vouchers Table')
if start != -1:
    end = text.find('-- 16. Return Items Table', start)
    if end != -1:
        text = text[:start] + text[end:]
path.write_text(text, encoding='utf-8')

# remove voucher migration and defaults from migrate_all.php
path = files['migrate_all']
text = path.read_text(encoding='utf-8')
start = text.find('    // 1. Create vouchers table if not exists')
if start != -1:
    end = text.find('    // 2. Ensure settings table has required defaults', start)
    if end != -1:
        text = text[:start] + text[end:]
text = re.sub(r"\s*        'voucher_prefix' => 'VCH',\n        'voucher_validity_days' => '30',\n        'voucher_enabled' => '1',\n        'voucher_terms' => \".*?\",\n        'voucher_color' => '#4f46e5',\n        'voucher_print_width' => '80',\n        'voucher_print_height' => '50'\n", '', text, flags=re.S)
path.write_text(text, encoding='utf-8')

# replace migrate_vouchers.php
path = files['migrate_vouchers']
path.write_text("<?php\necho '<h1>Voucher migration removed: voucher feature disabled.</h1>';\n", encoding='utf-8')
print('DONE')
