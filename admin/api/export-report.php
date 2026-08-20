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

$reportType = sanitize($_GET['type'] ?? 'daily');
$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = sanitize($_GET['date_to'] ?? date('Y-m-d'));

switch ($reportType) {
    case 'monthly':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        break;
    case 'yearly':
        $dateFrom = date('Y-01-01');
        $dateTo = date('Y-12-31');
        break;
}

$sales = $db->prepare("
    SELECT s.invoice_number, s.created_at, c.name as customer,
           u.name as cashier, s.subtotal, s.discount_amount,
           s.vat_amount, s.total, s.paid_amount, s.payment_method, s.payment_status
    FROM sales s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC
");
$sales->execute([$ownerId, $dateFrom, $dateTo]);
$salesData = $sales->fetchAll();

$summary = $db->prepare("
    SELECT COUNT(s.id) as total_transactions,
           COALESCE(SUM(s.total), 0) as total_sales,
           COALESCE(SUM(s.discount_amount), 0) as total_discount,
           COALESCE(SUM(s.vat_amount), 0) as total_vat,
           COALESCE(AVG(s.total), 0) as avg_sale
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE u.owner_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
");
$summary->execute([$ownerId, $dateFrom, $dateTo]);
$summaryData = $summary->fetch();

$format = $_GET['format'] ?? 'xls';

if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=report_' . $dateFrom . '_to_' . $dateTo . '.xls');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
    <style>td,th{border:1px solid #ccc;padding:6px;font-family:Arial;font-size:12px}th{background:#4f46e5;color:#fff;font-weight:700}</style></head><body>';
    
    echo '<h3>Sales Report: ' . $dateFrom . ' to ' . $dateTo . '</h3>';
    echo '<table>';
    echo '<tr><th>Total Transactions</th><td>' . $summaryData['total_transactions'] . '</td></tr>';
    echo '<tr><th>Total Sales</th><td>' . number_format($summaryData['total_sales'], 2) . '</td></tr>';
    echo '<tr><th>Total Discount</th><td>' . number_format($summaryData['total_discount'], 2) . '</td></tr>';
    echo '<tr><th>Total VAT</th><td>' . number_format($summaryData['total_vat'], 2) . '</td></tr>';
    echo '<tr><th>Avg Sale</th><td>' . number_format($summaryData['avg_sale'], 2) . '</td></tr>';
    echo '</table><br><br>';
    
    echo '<table><thead><tr>';
    echo '<th>Invoice</th><th>Date</th><th>Customer</th><th>Cashier</th>';
    echo '<th>Subtotal</th><th>Discount</th><th>VAT</th><th>Total</th><th>Paid</th><th>Payment</th><th>Status</th>';
    echo '</tr></thead><tbody>';
    foreach ($salesData as $s) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($s['invoice_number']) . '</td>';
        echo '<td>' . $s['created_at'] . '</td>';
        echo '<td>' . htmlspecialchars($s['customer'] ?? 'Walk-in') . '</td>';
        echo '<td>' . htmlspecialchars($s['cashier']) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($s['subtotal'], 2) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($s['discount_amount'], 2) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($s['vat_amount'], 2) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($s['total'], 2) . '</td>';
        echo '<td style="mso-number-format:\#\,\#\#0\.00">' . number_format($s['paid_amount'], 2) . '</td>';
        echo '<td>' . ucfirst($s['payment_method']) . '</td>';
        echo '<td>' . ucfirst($s['payment_status']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
} elseif ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sales Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', Arial, sans-serif; padding: 2rem; color: #111827; }
            h1 { font-size: 1.5rem; color: #4f46e5; margin-bottom: 0.25rem; }
            h2 { font-size: 1rem; color: #6b7280; font-weight: 400; margin-bottom: 2rem; }
            .summary { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
            .summary-item { background: #f9fafb; padding: 1rem; border-radius: 8px; flex: 1; min-width: 120px; border: 1px solid #e5e7eb; }
            .summary-item .label { font-size: 0.75rem; color: #6b7280; text-transform: uppercase; }
            .summary-item .value { font-size: 1.25rem; font-weight: 700; color: #111827; margin-top: 0.25rem; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th { background: #4f46e5; color: white; padding: 8px 6px; text-align: left; font-size: 10px; text-transform: uppercase; }
            td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
            tr:nth-child(even) { background: #f9fafb; }
            .text-right { text-align: right; }
            @media print { body { padding: 0.5in; } @page { size: A4 landscape; margin: 0.3in; } }
        </style>
    </head>
    <body>
        <h1>Sales Report</h1>
        <h2><?php echo $dateFrom; ?> to <?php echo $dateTo; ?></h2>
        
        <div class="summary">
            <div class="summary-item">
                <div class="label">Transactions</div>
                <div class="value"><?php echo $summaryData['total_transactions']; ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Total Sales</div>
                <div class="value"><?php echo number_format($summaryData['total_sales'], 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Discount</div>
                <div class="value"><?php echo number_format($summaryData['total_discount'], 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">VAT</div>
                <div class="value"><?php echo number_format($summaryData['total_vat'], 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="label">Avg Sale</div>
                <div class="value"><?php echo number_format($summaryData['avg_sale'], 2); ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Cashier</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right">Discount</th>
                    <th class="text-right">VAT</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Paid</th>
                    <th>Method</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($salesData as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['invoice_number']); ?></td>
                    <td><?php echo date('d M y', strtotime($s['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($s['customer'] ?? 'Walk-in'); ?></td>
                    <td><?php echo htmlspecialchars($s['cashier']); ?></td>
                    <td class="text-right"><?php echo number_format($s['subtotal'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($s['discount_amount'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($s['vat_amount'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($s['total'], 2); ?></td>
                    <td class="text-right"><?php echo number_format($s['paid_amount'], 2); ?></td>
                    <td><?php echo ucfirst($s['payment_method']); ?></td>
                    <td><?php echo ucfirst($s['payment_status']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>window.print();</script>
    </body>
    </html>
    <?php
}
