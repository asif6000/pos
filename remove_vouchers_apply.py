from pathlib import Path
import re

root = Path(__file__).resolve().parent

# cashier/sales.php cleanup
sales_path = root / 'cashier' / 'sales.php'
text = sales_path.read_text(encoding='utf-8')
text = text.replace(
    '            <button class="btn btn-success" onclick="printVouchers(currentSaleId)">\n                <i class="fas fa-gift"></i> Print Vouchers\n            </button>\n',
    ''
)
text = text.replace('            ${generateVoucherHtml(invoice)}\n', '')
text = re.sub(r'\n    function generateVoucherHtml\(invoice\) \{.*?\n    \}\n', '\n', text, flags=re.S)
text = re.sub(r'\n    async function printVouchers\(saleId\) \{.*?\n    \}\n', '\n', text, flags=re.S)
sales_path.write_text(text, encoding='utf-8')

# cashier/pos.php cleanup
pos_path = root / 'cashier' / 'pos.php'
text = pos_path.read_text(encoding='utf-8')
text = text.replace(
    '            <button class="btn btn-success" onclick="printVoucherOnly()">\n                <i class="fas fa-gift"></i> Print Voucher\n            </button>\n',
    ''
)
text = re.sub(
    r"\n\s*// Generate Vouchers HTML from server data\n\s*let vouchersHtml = ''\n.*?<!-- Appended Vouchers -->\n\s*\$\{vouchersHtml\}\n\s*\}\n    `;",
    '\n        document.getElementById(\'invoiceContent\').innerHTML = `',
    text,
    flags=re.S
)
text = re.sub(r'\n    function printVoucherOnly\(\) \{.*?\n    \}\n', '\n', text, flags=re.S)
pos_path.write_text(text, encoding='utf-8')

# disable admin voucher pages
redirect_php = """<?php
require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

redirect('dashboard.php');
exit;
"""
for page in ['admin/voucher-settings.php', 'admin/vouchers.php']:
    (root / page).write_text(redirect_php, encoding='utf-8')

# remove voucher schema from database.sql
schema_path = root / 'config' / 'database.sql'
schema_text = schema_path.read_text(encoding='utf-8')
schema_text = re.sub(
    r'-- 15\. Vouchers Table\nCREATE TABLE IF NOT EXISTS vouchers \(.*?\) ENGINE=InnoDB;\n\n',
    '',
    schema_text,
    flags=re.S
)
schema_path.write_text(schema_text, encoding='utf-8')

# replace migrate_vouchers.php with no-op
(root / 'migrate_vouchers.php').write_text("<?php\necho '<h1>Voucher migration removed: voucher feature disabled.</h1>';?>\n", encoding='utf-8')

print('Cleanup applied')
