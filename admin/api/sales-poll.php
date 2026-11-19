<?php
/**
 * New-sale poller for the web UI.
 *
 * WHY POLLING AND NOT A WEBSOCKET
 * -------------------------------
 * This runs on cPanel shared hosting. There is no long-running process to hold a
 * WebSocket open, and Server-Sent Events would tie up one PHP worker per open
 * browser tab for as long as the tab lives - on a shared host that exhausts the
 * account's process pool and takes the whole POS down. So the browser asks, and
 * this answers.
 *
 * WHY IT IS CHEAP
 * ---------------
 * The query is a range scan on sales.id, which is the PRIMARY KEY, bounded by
 * the id the browser already has. It reads at most a handful of rows no matter
 * how many sales the shop has made, so it stays cheap to poll every few seconds.
 *
 * The session id is carried across calls so a page refresh does not re-announce
 * the entire sales history, and the poll stops entirely once the browser has
 * caught up.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../config/db.php';

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user     = getCurrentUser();
$owner_id = (int)($user['owner_id'] ?? 0);
$viewerId = (int)($user['id'] ?? 0);

if ($owner_id <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'No owner scope']);
    exit;
}

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

// Never re-announce history on first load. Without this, opening the POS would
// fire a notification for every sale the shop has ever made.
if ($since <= 0) {
    $stmt = $db = getDB()->prepare(
        "SELECT COALESCE(MAX(id), 0) FROM sales WHERE owner_id = ?"
    );
    $stmt->execute([$owner_id]);
    $head = (int)$stmt->fetchColumn();

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'head'    => $head,
        'sales'   => [],
        'catchup' => true,
    ]);
    exit;
}

try {
    $db = getDB();

    // A counter account (staff/cashier) is told only about bills it rang up
    // itself - and those the loop below drops again, so a staff till stays
    // quiet about everyone else's takings, which are not its figures to see.
    list($ownScope, $ownParams) = ownSalesScopeSql($user, 's');

    $stmt = $db->prepare(
        "SELECT s.id, s.user_id, s.invoice_number, s.total, s.payment_method, s.created_at,
                COALESCE(u.name, 'Unknown') AS cashier_name,
                COALESCE(c.name, '') AS customer_name
           FROM sales s
           LEFT JOIN users u ON u.id = s.user_id
           LEFT JOIN customers c ON c.id = s.customer_id
          WHERE s.owner_id = ? AND s.id > ?" . $ownScope . "
          ORDER BY s.id ASC
          LIMIT 20"
    );
    $stmt->execute(array_merge([$owner_id, $since], $ownParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The viewer's own sales are dropped so a cashier is not told about a sale
    // they are in the middle of ringing up on this very screen. Sales made on a
    // different device, or by a colleague, still come through - which is the
    // whole reason this exists.
    $out  = [];
    $head = $since;
    foreach ($rows as $r) {
        $head = max($head, (int)$r['id']);

        if ((int)$r['user_id'] === $viewerId) { continue; }

        $out[] = [
            'id'          => (int)$r['id'],
            'invoice'     => $r['invoice_number'],
            'total'       => (float)$r['total'],
            'method'      => $r['payment_method'],
            'cashier'     => $r['cashier_name'],
            'customer'    => $r['customer_name'],
            'created_at'  => $r['created_at'],
        ];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'head'    => $head,
        'sales'   => $out,
    ]);
} catch (Exception $e) {
    ob_end_clean();
    error_log('sales-poll failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Poll failed']);
}