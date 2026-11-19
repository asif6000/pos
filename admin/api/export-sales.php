<?php
/**
 * POS System - Sales export (XLS / PDF)
 *
 * Uses the same filter/scope builder as sales.php, so an export can never contain
 * a bill the visitor is not allowed to see on screen. `ids` narrows the export to
 * the rows ticked in the list; the scope still applies.
 */

require_once '../../config/db.php';
require_once '../includes/sales-scope.php';
startSecureSession();

if (!isLoggedIn() || !hasPermission('sales')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$user = getCurrentUser();

$filter = salesFilterSql($user, $_GET);
$from = $filter['from'];
$where = $filter['where'];
$params = $filter['params'];

$ids = [];
foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $raw) {
    $raw = trim($raw);
    if ($raw !== '' && ctype_digit($raw)) {
        $ids[] = (int) $raw;
    }
}
if ($ids) {
    $where .= " AND s.id IN (" . implode(',', $ids) . ")";
}

$stmt = $db->prepare("SELECT s.invoice_number, s.created_at, s.subtotal, s.discount_amount,
        s.discount_percent, s.vat_amount, s.total, s.paid_amount, s.change_amount,
        s.payment_method, s.payment_status, s.printed,
        c.name AS customer_name, u.name AS cashier_name, st.name AS store_name,
        (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS item_qty
        $from$where
        ORDER BY s.created_at DESC, s.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$headers = ['Invoice', 'Date', 'Store', 'Customer', 'Cashier', 'Items', 'Subtotal',
    'Discount', 'Discount %', 'VAT', 'Total', 'Paid', 'Change', 'Method', 'Status', 'Printed'];

$cell = function ($v) {
    return $v === null ? '' : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$line = function ($r) {
    return [
        $r['invoice_number'],
        date('d M Y, h:i A', strtotime($r['created_at'])),
        $r['store_name'] ?: '',
        $r['customer_name'] ?: 'Walk-in',
        $r['cashier_name'] ?: '',
        (int) $r['item_qty'],
        number_format((float) $r['subtotal'], 2),
        number_format((float) $r['discount_amount'], 2),
        number_format((float) $r['discount_percent'], 2),
        number_format((float) $r['vat_amount'], 2),
        number_format((float) $r['total'], 2),
        number_format((float) $r['paid_amount'], 2),
        number_format((float) $r['change_amount'], 2),
        ucfirst($r['payment_method']),
        ucfirst($r['payment_status']),
        $r['printed'] ? 'Yes' : 'No',
    ];
};

$format = $_GET['format'] ?? 'xls';

if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sales Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', Arial, sans-serif; padding: 2rem; color: #111827; }
            h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #4f46e5; }
            .date { color: #6b7280; font-size: 0.875rem; margin-bottom: 2rem; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th { background: #4f46e5; color: white; padding: 8px 6px; text-align: left; font-size: 10px; text-transform: uppercase; }
            td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
            tr:nth-child(even) { background: #f9fafb; }
            .text-right { text-align: right; }
            @media print { body { padding: 0.5in; } @page { size: A4 landscape; margin: 0.5in; } }
        </style>
    </head>
    <body>
        <h1>Sales Report</h1>
        <p class="date">
            <?php echo $filter['filters']['date_from']; ?> to <?php echo $filter['filters']['date_to']; ?>
            &middot; <?php echo count($rows); ?> bills &middot;
            Generated <?php echo date('d M Y, h:i A'); ?>
        </p>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?>
                        <th class="<?php echo in_array($h, ['Items', 'Subtotal', 'Discount', 'Discount %', 'VAT', 'Total', 'Paid', 'Change']) ? 'text-right' : ''; ?>"><?php echo $h; ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <?php foreach ($line($r) as $v): ?><td><?php echo $cell($v); ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=sales_' . $filter['filters']['date_from'] . '_' . $filter['filters']['date_to'] . '.xls');
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC/html40">
<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Sales</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>td,th{border:1px solid #ccc;padding:6px;font-family:Arial;font-size:12px}th{background:#2563eb;color:#fff;font-weight:700}td{mso-number-format:"\@";}</style></head><body>';
echo '<table><thead><tr>';
foreach ($headers as $h) {
    echo '<th>' . $cell($h) . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    foreach ($line($r) as $v) {
        echo '<td>' . $cell($v) . '</td>';
    }
    echo '</tr>';
}
echo '</tbody></table></body></html>';
