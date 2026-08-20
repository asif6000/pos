<?php
/**
 * Direct test of process-sale.php
 * Visit: http://localhost/pos/pos/pos/test_process_sale.php
 * DELETE after use.
 */
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test Process Sale</title>
    <style>
        body { font-family: monospace; padding: 2rem; background: #f8fafc; }
        .box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem; max-width: 900px; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 13px; white-space: pre-wrap; word-break: break-all; }
        .ok { color: #10b981; font-weight: bold; }
        .err { color: #ef4444; font-weight: bold; }
        button { background: #4f46e5; color: white; border: none; padding: 0.6rem 1.5rem; border-radius: 6px; cursor: pointer; font-size: 14px; margin-right: 10px; }
        .info { color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
<div class="box">
    <h2>Process Sale — Live Test</h2>
    <p class="info">Session: user_id=<?php echo $_SESSION['user_id'] ?? '<b style="color:red">NOT LOGGED IN</b>'; ?>, store_id=<?php echo $_SESSION['store_id'] ?? 'NULL'; ?>, owner_id=<?php echo $_SESSION['owner_id'] ?? 'NULL'; ?></p>

    <?php if (empty($_SESSION['user_id'])): ?>
        <p style="color:red;font-weight:bold;">⚠ You are not logged in! <a href="auth/login.php">Login first</a>, then come back here.</p>
    <?php else: ?>
    <p>This will send a test sale request and show the raw response from <code>api/process-sale.php</code>.</p>
    <button onclick="runTest()">▶ Run Test Sale</button>
    <button onclick="runRawTest()">▶ Show Raw Response (no JSON parse)</button>
    <br><br>
    <div id="status"></div>
    <pre id="output">Click a button above to test...</pre>

    <script>
    async function runTest() {
        document.getElementById('status').innerHTML = '<span style="color:#f59e0b">⏳ Testing...</span>';
        document.getElementById('output').textContent = '';

        const saleData = {
            edit_sale_id: 0,
            customer_id: 1,
            items: [{
                product_id: <?php
                    require_once 'config/db.php';
                    $db = getDB();
                    $ownerId = $_SESSION['owner_id'] ?? null;
                    // get first available product in store_stocks
                    $p = null;
                    try {
                        if ($ownerId) {
                            $p = $db->prepare("SELECT p.id, p.name, p.sell_price FROM products p JOIN store_stocks ss ON p.id = ss.product_id WHERE p.owner_id = ? AND ss.quantity > 0 LIMIT 1");
                            $p->execute([$ownerId]);
                        } else {
                            $p = $db->query("SELECT p.id, p.name, p.sell_price FROM products p JOIN store_stocks ss ON p.id = ss.product_id WHERE ss.quantity > 0 LIMIT 1");
                        }
                        $prod = $p ? $p->fetch() : null;
                    } catch (Exception $e) {
                        $prod = null;
                    }
                    if ($prod) {
                        echo (int)$prod['id'];
                    } else {
                        // Try any product
                        try {
                            $anyProd = $db->query("SELECT id, name, sell_price FROM products WHERE status='active' LIMIT 1")->fetch();
                            echo $anyProd ? (int)$anyProd['id'] : 1;
                        } catch (Exception $e) { echo 1; }
                    }
                ?>,
                product_name: "<?php echo $prod['name'] ?? 'Test Product'; ?>",
                quantity: 1,
                unit_price: <?php echo $prod['sell_price'] ?? 10.00; ?>,
                total_price: <?php echo $prod['sell_price'] ?? 10.00; ?>
            }],
            subtotal: <?php echo $prod['sell_price'] ?? 10.00; ?>,
            discount_type: 'percent',
            discount_value: 0,
            discount_percent: 0,
            discount_amount: 0,
            vat_percent: 0,
            vat_amount: 0,
            total: <?php echo $prod['sell_price'] ?? 10.00; ?>,
            paid_amount: <?php echo $prod['sell_price'] ?? 10.00; ?>,
            change_amount: 0,
            payment_method: 'cash'
        };

        try {
            const response = await fetch('admin/api/process-sale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(saleData)
            });

            const rawText = await response.text();
            document.getElementById('output').textContent = 'HTTP Status: ' + response.status + '\n\nRaw Response:\n' + rawText;

            try {
                const json = JSON.parse(rawText);
                if (json.success) {
                    document.getElementById('status').innerHTML = '<span class="ok">✅ SUCCESS! Sale processed. Invoice: ' + json.invoice.invoice_number + '</span>';
                } else {
                    document.getElementById('status').innerHTML = '<span class="err">❌ API Error: ' + json.message + '</span>';
                }
            } catch(e) {
                document.getElementById('status').innerHTML = '<span class="err">❌ JSON PARSE FAILED — PHP is outputting non-JSON content (see raw response below)</span>';
            }
        } catch(e) {
            document.getElementById('status').innerHTML = '<span class="err">❌ Fetch failed: ' + e.message + '</span>';
            document.getElementById('output').textContent = e.toString();
        }
    }

    async function runRawTest() {
        document.getElementById('status').innerHTML = '<span style="color:#f59e0b">⏳ Fetching raw...</span>';
        try {
            const response = await fetch('admin/api/process-sale.php', {
                method: 'GET'
            });
            const text = await response.text();
            document.getElementById('output').textContent = 'HTTP ' + response.status + '\n' + text;
            document.getElementById('status').innerHTML = '<span class="ok">Done (GET request - shows headers/errors only)</span>';
        } catch(e) {
            document.getElementById('output').textContent = e.toString();
        }
    }
    </script>
    <?php endif; ?>
</div>
</body>
</html>
