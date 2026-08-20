<?php
/**
 * Debug multiple product sale issue
 * Visit: http://localhost/pos/pos/pos/debug_sale.php
 * DELETE after use.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config/db.php';
startSecureSession();

$db = getDB();
$userId  = $_SESSION['user_id']  ?? null;
$ownerId = $_SESSION['owner_id'] ?? null;

// Get store
$store_id = $_SESSION['store_id'] ?? 0;
if (!$store_id) {
    $store_id = $db->prepare("SELECT id FROM stores WHERE status='active' AND owner_id=? LIMIT 1");
    $store_id->execute([$ownerId]);
    $store_id = $store_id->fetchColumn();
}

// Get 3 products with stock
$products = $db->prepare("
    SELECT p.id, p.name, p.sell_price, ss.quantity as stock
    FROM products p
    JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
    WHERE p.status='active' AND ss.quantity > 0 AND p.owner_id = ?
    LIMIT 3
");
$products->execute([$store_id, $ownerId]);
$prods = $products->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug Multi-Product Sale</title>
    <style>
        body{font-family:monospace;padding:2rem;background:#f8fafc;}
        .box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:1.5rem;max-width:900px;margin-bottom:1rem;}
        pre{background:#1e1e1e;color:#d4d4d4;padding:1rem;border-radius:6px;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto;}
        .ok{color:#10b981;font-weight:bold;}
        .err{color:#ef4444;font-weight:bold;}
        .warn{color:#f59e0b;}
        button{background:#4f46e5;color:white;border:none;padding:0.5rem 1.2rem;border-radius:6px;cursor:pointer;font-size:14px;margin:4px;}
        table{border-collapse:collapse;width:100%;}
        td,th{border:1px solid #e5e7eb;padding:6px 10px;font-size:13px;}
    </style>
</head>
<body>

<div class="box">
    <h2>Session Info</h2>
    <table>
        <tr><td>user_id</td><td><?= $userId ?? '<span class="err">NOT LOGGED IN</span>' ?></td></tr>
        <tr><td>owner_id</td><td><?= $ownerId ?? '<span class="warn">NULL</span>' ?></td></tr>
        <tr><td>store_id (session)</td><td><?= ($_SESSION['store_id'] ?? '<span class="warn">NULL - using fallback: '.$store_id.'</span>') ?></td></tr>
    </table>
</div>

<div class="box">
    <h2>Available Products for Test</h2>
    <?php if(empty($prods)): ?>
        <p class="err">No products with stock found in store_stocks! Run fix_all.php first.</p>
    <?php else: ?>
    <table>
        <tr><th>ID</th><th>Name</th><th>Price</th><th>Stock</th></tr>
        <?php foreach($prods as $p): ?>
        <tr><td><?=$p['id']?></td><td><?=$p['name']?></td><td><?=$p['sell_price']?></td><td><?=$p['stock']?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="box">
    <h2>Test Sale with Multiple Products</h2>
    <button onclick="testSingle()">Test 1 Product</button>
    <button onclick="testMultiple()">Test <?= count($prods) ?> Products Together</button>
    <div id="status" style="margin:10px 0;font-size:14px;"></div>
    <pre id="output">Click a button...</pre>
</div>

<script>
const products = <?= json_encode($prods) ?>;

async function sendSale(items) {
    const subtotal = items.reduce((s,i) => s + i.total_price, 0);
    const payload = {
        edit_sale_id: 0,
        customer_id: 1,
        items: items,
        subtotal: subtotal,
        discount_type: 'percent',
        discount_value: 0,
        discount_percent: 0,
        discount_amount: 0,
        vat_percent: 0,
        vat_amount: 0,
        total: subtotal,
        paid_amount: subtotal,
        change_amount: 0,
        payment_method: 'cash'
    };

    document.getElementById('output').textContent = 'Sending:\n' + JSON.stringify(payload, null, 2);
    document.getElementById('status').innerHTML = '<span class="warn">⏳ Processing...</span>';

    try {
        const resp = await fetch('admin/api/process-sale.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });

        const raw = await resp.text();
        document.getElementById('output').textContent =
            'HTTP Status: ' + resp.status + '\n\nRaw Response:\n' + raw;

        try {
            const json = JSON.parse(raw);
            if (json.success) {
                document.getElementById('status').innerHTML =
                    '<span class="ok">✅ SUCCESS! Invoice: ' + json.invoice.invoice_number + '</span>';
            } else {
                document.getElementById('status').innerHTML =
                    '<span class="err">❌ API Error: ' + json.message + '</span>';
            }
        } catch(e) {
            document.getElementById('status').innerHTML =
                '<span class="err">❌ JSON Parse Failed — PHP outputting garbage before JSON</span>';
        }
    } catch(e) {
        document.getElementById('status').innerHTML = '<span class="err">❌ Fetch Error: ' + e.message + '</span>';
    }
}

function testSingle() {
    if (!products.length) { alert('No products!'); return; }
    const p = products[0];
    sendSale([{
        product_id: p.id,
        product_name: p.name,
        quantity: 1,
        unit_price: parseFloat(p.sell_price),
        total_price: parseFloat(p.sell_price)
    }]);
}

function testMultiple() {
    if (products.length < 2) { alert('Need at least 2 products with stock!'); return; }
    const items = products.map(p => ({
        product_id: p.id,
        product_name: p.name,
        quantity: 1,
        unit_price: parseFloat(p.sell_price),
        total_price: parseFloat(p.sell_price)
    }));
    sendSale(items);
}
</script>

</body>
</html>
