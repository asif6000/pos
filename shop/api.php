<?php
/**
 * The public e-commerce API - the only door a customer app or web storefront
 * knocks on.
 *
 * WHY THERE IS NO LOGIN HERE
 * --------------------------
 * A buyer on a shop's page is not an account in this system; they are a phone
 * number and an address. So nothing here is authenticated, which in turn means
 * two rules are load-bearing: prices and stock are always re-read from the
 * database (config/ecommerce.php), and the only thing an unauthenticated caller
 * can learn is what the shop already chose to publish.
 *
 *   GET/POST api.php?action=catalogue&slug=<shop>     -> shop + listed products
 *   GET/POST api.php?action=shops                     -> open shops to browse
 *   GET/POST api.php?action=browse&q=&category=&sort= -> products across all shops
 *   POST      api.php?action=order&slug=<shop>        -> place a cash-on-delivery order
 *   GET/POST  api.php?action=status&order_no=&phone=  -> one order's status
 *
 * A status lookup needs BOTH the order number and the customer's phone, because
 * an order number alone (ECM-000012) is guessable and would hand a stranger
 * someone's name, address and bill.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ecommerce.php';

function ecomOut($payload, $code = 200)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_REQUEST['action'] ?? '');
$slug   = isset($_REQUEST['slug']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string)$_REQUEST['slug'])) : '';

try {
    if ($action === 'shops') {
        ecommerceEnsureTables();
        $rows = getDB()->query(
            "SELECT s.slug, s.name, s.phone, s.area, s.is_open, s.cover,
                    (SELECT COUNT(*) FROM ecom_listings l WHERE l.owner_id = s.owner_id AND l.visible = 1) AS products
             FROM ecom_shops s
             ORDER BY s.is_open DESC, s.name ASC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        // One query above rather than a per-shop lookup in the loop: this list can
        // carry 200 shops and a customer's home screen waits on it.
        $base = rtrim(getAppBaseUrl(), '/');
        foreach ($rows as &$row) {
            // Only the picture the shopkeeper chose. A shop that never picked one
            // gets null and the app draws a plain name tile - standing in another
            // shop's product photo made the card look like a demo.
            $pic = trim((string)($row['cover'] ?? ''));
            $row['cover_url'] = ($pic !== '') ? $base . '/' . ltrim($pic, '/') : null;
            $row['products'] = (int)$row['products'];
        }
        unset($row);
        ecomOut(['success' => true, 'shops' => $rows]);
    }

    if ($action === 'catalogue') {
        if ($slug === '') { ecomOut(['success' => false, 'message' => 'Shop slug required'], 400); }
        $data = ecommercePublicCatalogue($slug);
        if ($data === null) { ecomOut(['success' => false, 'message' => 'Shop not found'], 404); }
        ecomOut(['success' => true] + $data);
    }

    if ($action === 'browse') {
        ecommerceEnsureTables();
        // Whitelisted rather than passed through: the sort name is interpolated into
        // ORDER BY, so an unexpected value must fall back to the default, not travel.
        $sort = (string)($_REQUEST['sort'] ?? 'popular');
        if (!in_array($sort, ['popular', 'newest', 'price_low', 'price_high', 'name'], true)) {
            $sort = 'popular';
        }
        $data = ecommerceBrowse([
            'q'        => (string)($_REQUEST['q'] ?? ''),
            'category' => (string)($_REQUEST['category'] ?? ''),
            'sort'     => $sort,
            'limit'    => (int)($_REQUEST['limit'] ?? 60),
            'offset'   => (int)($_REQUEST['offset'] ?? 0),
        ]);
        ecomOut(['success' => true] + $data);
    }

    if ($action === 'order') {
        if ($slug === '') { ecomOut(['success' => false, 'message' => 'Shop slug required'], 400); }

        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) { $body = $_POST; }

        // Accepted as {product_id: qty} or as [{id, qty}, ...] - the app and a web
        // cart naturally build different shapes and neither should be forced to
        // reshape just to satisfy this file.
        $lines = [];
        if (isset($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                if (is_array($item) && isset($item['id'])) { $lines[(int)$item['id']] = isset($item['qty']) ? (int)$item['qty'] : 1; }
            }
        } elseif (isset($body['lines']) && is_array($body['lines'])) {
            foreach ($body['lines'] as $id => $qty) { $lines[(int)$id] = (int)$qty; }
        }

        $result = ecommercePlaceOrder($slug, $lines, [
            'name'    => (string)($body['name'] ?? ''),
            'phone'   => (string)($body['phone'] ?? ''),
            'address' => (string)($body['address'] ?? ''),
            'note'    => (string)($body['note'] ?? ''),
        ]);

        ecomOut(['success' => true, 'order' => $result,
                 'message' => 'Order placed. The shop will call you to confirm.']);
    }

    if ($action === 'status') {
        $orderNo = strtoupper(trim((string)($_REQUEST['order_no'] ?? '')));
        $phone   = preg_replace('/[^0-9]/', '', (string)($_REQUEST['phone'] ?? ''));
        if ($orderNo === '' || strlen($phone) < 10) {
            ecomOut(['success' => false, 'message' => 'Order number and phone required'], 400);
        }
        ecommerceEnsureTables();
        $stmt = getDB()->prepare(
            "SELECT order_no, status, items_total, delivery_charge, total, store_id, created_at
             FROM ecom_orders WHERE order_no = ? AND customer_phone = ? LIMIT 1"
        );
        $stmt->execute([$orderNo, $phone]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) { ecomOut(['success' => false, 'message' => 'No such order for that number'], 404); }

        $idStmt = getDB()->prepare("SELECT id FROM ecom_orders WHERE order_no = ? AND customer_phone = ? LIMIT 1");
        $idStmt->execute([$orderNo, $phone]);
        $order['items'] = ecommerceOrderItems((int)$idStmt->fetchColumn());

        ecomOut(['success' => true, 'order' => $order]);
    }

    ecomOut(['success' => false, 'message' => 'Unknown action'], 400);

} catch (Exception $e) {
    // The message is written for the person reading it in an app, so it goes out
    // as-is; nothing in this path echoes a query or a table name back.
    ecomOut(['success' => false, 'message' => $e->getMessage()], 422);
}
