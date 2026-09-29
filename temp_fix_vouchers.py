from pathlib import Path
import re

root = Path(r'c:\xampp1\htdocs\pos\pos')

# Fix admin/voucher-settings.php to redirect
voucher_settings = root / 'admin' / 'voucher-settings.php'
voucher_settings.write_text("""<?php
require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

redirect('dashboard.php');
exit;
""", encoding='utf-8')

# Remove leftover voucher placeholder from cashier/pos.php
cashier_pos = root / 'cashier' / 'pos.php'
text = cashier_pos.read_text(encoding='utf-8')
text = text.replace('            <!-- Appended Vouchers -->\n            ${vouchersHtml}\n', '')
cashier_pos.write_text(text, encoding='utf-8')

# Ensure cashier/sales.php no voucher print button or functions
cashier_sales = root / 'cashier' / 'sales.php'
text = cashier_sales.read_text(encoding='utf-8')
text = text.replace(
    '            <button class="btn btn-primary" onclick="printInvoice()">\n                <i class="fas fa-print"></i> Print\n            </button>\n            <button class="btn btn-success" onclick="printVouchers(currentSaleId)">\n                <i class="fas fa-gift"></i> Print Vouchers\n            </button>\n',
    '            <button class="btn btn-primary" onclick="printInvoice()">\n                <i class="fas fa-print"></i> Print\n            </button>\n'
)
text = text.replace('    let currentSaleId = null;\n\n', '')
text = text.replace('            ${generateVoucherHtml(invoice)}\n', '')
text = re.sub(r'(?s)    function generateVoucherHtml\(invoice\) \{.*?\n    \}\n\n', '', text)
text = re.sub(r'(?s)    async function printVouchers\(saleId\) \{.*?\n    \}\n\n', '', text)
text = text.replace('    function closeModal() {\n        document.getElementById(\'invoiceModal\').classList.remove(\'active\');\n    }\n\n', '    function closeModal() {\n        document.getElementById(\'invoiceModal\').classList.remove(\'active\');\n    }\n\n')

cashier_sales.write_text(text, encoding='utf-8')

# No-op migrate_vouchers.php
migrate_vouchers = root / 'migrate_vouchers.php'
migrate_vouchers.write_text("""<?php
echo '<h1>Voucher migration removed: voucher feature disabled.</h1>';
?>
""", encoding='utf-8')

print('temp_fix_vouchers.py applied')
