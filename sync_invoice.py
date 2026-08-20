import re

with open('admin/pos.php', 'r', encoding='utf-8') as f:
    admin_content = f.read()

with open('cashier/pos.php', 'r', encoding='utf-8') as f:
    cashier_content = f.read()

# Extract displayInvoice from admin/pos.php
match_admin = re.search(r'function displayInvoice\(invoice\) \{.*?\n    \}(?=\n\n    async function printInvoice)', admin_content, re.DOTALL)
if not match_admin:
    print("Could not find displayInvoice in admin/pos.php")
    exit(1)
admin_func = match_admin.group(0)

# Replace in cashier/pos.php
match_cashier = re.search(r'function displayInvoice\(invoice\) \{.*?\n    \}(?=\n\n    async function printInvoice)', cashier_content, re.DOTALL)
if not match_cashier:
    print("Could not find displayInvoice in cashier/pos.php")
    exit(1)

new_cashier_content = cashier_content[:match_cashier.start()] + admin_func + cashier_content[match_cashier.end():]

with open('cashier/pos.php', 'w', encoding='utf-8') as f:
    f.write(new_cashier_content)

print("Successfully synced displayInvoice from admin to cashier.")
