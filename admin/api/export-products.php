<?php
require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'];

$store_id = $_SESSION['store_id'] ?? 0;
if (!$store_id) {
    $stmt = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
    $stmt->execute([$ownerId]);
    $store_id = $stmt->fetchColumn() ?: 0;
}

$stmt = $db->prepare("
    SELECT p.id, p.name, p.barcode, c.name as category, 
           p.buy_price, p.sell_price, COALESCE(ss.quantity, 0) as stock,
           p.min_stock, p.unit, p.status, p.created_at
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
    WHERE p.owner_id = ?
    ORDER BY p.name
");
$stmt->execute([$store_id, $ownerId]);
$products = $stmt->fetchAll();

$format = $_GET['format'] ?? 'xls';

if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=products_' . date('Y-m-d') . '.xls');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Products</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
    <style>td,th{border:1px solid #ccc;padding:6px;font-family:Arial;font-size:12px}th{background:#4f46e5;color:#fff;font-weight:700}td{mso-number-format:"\@";}</style></head><body>';
    echo '<table><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Barcode</th><th>Category</th><th>Buy Price</th><th>Sell Price</th><th>Stock</th><th>Min Stock</th><th>Unit</th><th>Status</th><th>Created</th>';
    echo '</tr></thead><tbody>';
    foreach ($products as $p) {
        echo '<tr>';
        echo '<td>' . $p['id'] . '</td>';
        echo '<td>' . htmlspecialchars($p['name']) . '</td>';
        echo '<td>' . htmlspecialchars($p['barcode']) . '</td>';
        echo '<td>' . htmlspecialchars($p['category']) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($p['buy_price'], 2) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($p['sell_price'], 2) . '</td>';
        echo '<td>' . $p['stock'] . '</td>';
        echo '<td>' . $p['min_stock'] . '</td>';
        echo '<td>' . htmlspecialchars($p['unit']) . '</td>';
        echo '<td>' . $p['status'] . '</td>';
        echo '<td>' . $p['created_at'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
} elseif ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Products Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', Arial, sans-serif; padding: 2rem; color: #111827; }
            h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #4f46e5; }
            .date { color: #6b7280; font-size: 0.875rem; margin-bottom: 2rem; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; }
            th { background: #4f46e5; color: white; padding: 8px 6px; text-align: left; font-size: 11px; text-transform: uppercase; }
            td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
            tr:nth-child(even) { background: #f9fafb; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            @media print { body { padding: 0.5in; } @page { size: A4 landscape; margin: 0.5in; } }
        </style>
    </head>
    <body>
        <h1>Products Report</h1>
        <p class="date">Generated: <?php echo date('d M Y, h:i A'); ?></p>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Barcode</th>
                    <th>Category</th>
                    <th class="text-right">Buy Price</th>
                    <th class="text-right">Sell Price</th>
                    <th class="text-center">Stock</th>
                    <th class="text-center">Min Stock</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?php echo $p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['name']); ?></td>
                    <td><?php echo htmlspecialchars($p['barcode']); ?></td>
                    <td><?php echo htmlspecialchars($p['category']); ?></td>
                    <td class="text-right"><?php echo number_format($p['buy_price'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($p['sell_price'], 2); ?></td>
                    <td class="text-center"><?php echo $p['stock']; ?></td>
                    <td class="text-center"><?php echo $p['min_stock']; ?></td>
                    <td><?php echo htmlspecialchars($p['unit']); ?></td>
                    <td><?php echo $p['status']; ?></td>
                    <td><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>window.print();</script>
    </body>
    </html>
    <?php
}
