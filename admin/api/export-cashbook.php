<?php
/**
 * POS System - Cashbook ledger export (XLS / PDF)
 *
 * Uses the same filter/scope builder as cashbook.php, so an export can never
 * carry a movement the visitor is not allowed to see on screen - or one the
 * KPI cards are not counting.
 */

require_once '../../config/db.php';
require_once '../../config/cashbook.php';
require_once '../includes/cashbook-scope.php';
startSecureSession();

if (!isLoggedIn() || !hasPermission('cashbook')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];

ensureCashbookModuleTables();

$filter = cashbookFilterSql($user, $_GET);
$stmt = $db->prepare("SELECT ce.*, cb.name AS book_name
                      {$filter['from']}{$filter['where']}
                      ORDER BY ce.created_at ASC, ce.id ASC");
$stmt->execute($filter['params']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = [];
if (appTableExists($db, 'expense_categories')) {
    $stmt = $db->prepare("SELECT id, name FROM expense_categories WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $categories[(int)$c['id']] = $c['name'];
    }
}

$headers = ['Date', 'Cashbook', 'Type', 'Category', 'Description', 'Reference', 'Amount', 'Status'];

$cell = function ($v) {
    return $v === null ? '' : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$line = function ($r) use ($categories) {
    return [
        date('Y-m-d H:i', strtotime(($r['entry_date'] ?: substr($r['created_at'], 0, 10))
            . ' ' . substr($r['created_at'], 11, 5))),
        $r['book_name'] ?: 'Unassigned',
        $r['type'] === 'cash_in' ? 'Cash In' : 'Cash Out',
        cashbookCategoryLabel($r, $categories),
        $r['note'],
        $r['reference_no'] ?: $r['invoice_no'],
        number_format((float)$r['amount'], 2),
        (int)$r['is_void'] === 1 ? 'Voided' : 'Recorded',
    ];
};

$range = $filter['filters']['date_from'] . '_' . $filter['filters']['date_to'];

if (($_GET['format'] ?? 'xls') === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Cashbook Ledger</title>
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
        <h1>Cashbook Ledger</h1>
        <p class="date">
            <?php echo $filter['filters']['date_from']; ?> to <?php echo $filter['filters']['date_to']; ?>
            &middot; <?php echo count($rows); ?> movements &middot;
            Generated <?php echo date('d M Y, h:i A'); ?>
        </p>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?>
                        <th class="<?php echo $h === 'Amount' ? 'text-right' : ''; ?>"><?php echo $h; ?></th>
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
header('Content-Disposition: attachment; filename=cashbook_' . $range . '.xls');
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC/html40">
<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Cashbook</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
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
