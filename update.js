const fs = require('fs');

const cashierPosPath = 'c:/xampp1/htdocs/pos/pos/cashier/pos.php';
const adminPosPath = 'c:/xampp1/htdocs/pos/pos/admin/pos.php';
const adminSalesPath = 'c:/xampp1/htdocs/pos/pos/admin/sales.php';
const cashierSalesPath = 'c:/xampp1/htdocs/pos/pos/cashier/sales.php';

const cashierPosContent = fs.readFileSync(cashierPosPath, 'utf-8');

// Extract showInvoice from cashier/pos.php
const showInvoiceRegex = /function showInvoice\(invoice\) \{[\s\S]*?\n    \}(?=\n\n    function closeInvoiceModal)/;
const match = cashierPosContent.match(showInvoiceRegex);

if (!match) {
    console.error("Could not find showInvoice in cashier/pos.php");
    process.exit(1);
}

const replacementContent = match[0];
const displayReplacement = replacementContent.replace('function showInvoice(invoice) {', 'function displayInvoice(invoice) {');

// Update admin/pos.php
let adminPosContent = fs.readFileSync(adminPosPath, 'utf-8');
const adminMatch = adminPosContent.match(/function showInvoice\(invoice\) \{[\s\S]*?\n    \}(?=\n\n    function closeInvoiceModal)/);
if (adminMatch) {
    adminPosContent = adminPosContent.replace(adminMatch[0], replacementContent);
    fs.writeFileSync(adminPosPath, adminPosContent);
    console.log("Updated admin/pos.php");
}

// Update admin/sales.php
let adminSalesContent = fs.readFileSync(adminSalesPath, 'utf-8');
const adminSalesMatch = adminSalesContent.match(/function displayInvoice\(invoice\) \{[\s\S]*?\n    \}(?=\n\n    function printInvoice)/);
if (adminSalesMatch) {
    adminSalesContent = adminSalesContent.replace(adminSalesMatch[0], displayReplacement);
    fs.writeFileSync(adminSalesPath, adminSalesContent);
    console.log("Updated admin/sales.php");
}

// Update cashier/sales.php
let cashierSalesContent = fs.readFileSync(cashierSalesPath, 'utf-8');
const cashierSalesMatch = cashierSalesContent.match(/function displayInvoice\(invoice\) \{[\s\S]*?\n    \}(?=\n\n    function printInvoice)/);
if (cashierSalesMatch) {
    cashierSalesContent = cashierSalesContent.replace(cashierSalesMatch[0], displayReplacement);
    fs.writeFileSync(cashierSalesPath, cashierSalesContent);
    console.log("Updated cashier/sales.php");
}

console.log("Done");
