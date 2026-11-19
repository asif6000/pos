<?php
/**
 * Cancel callback - the buyer backed out of the UddoktaPay page.
 * Marks the pending payment cancelled and sends them back to the plans.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/subscription.php';
require_once __DIR__ . '/config/uddoktapay.php';

startSecureSession();

$db = getDB();
subEnsureTables($db);

$ref = (int)($_GET['ref'] ?? 0);
$pay = $ref > 0 ? subFindPayment($db, $ref) : null;

if ($pay && $pay['status'] === 'pending') {
    subMarkPayment($db, $pay['id'], 'cancelled', null, null,
        json_encode(['cancelled_query' => $_GET], JSON_UNESCAPED_UNICODE));
}

$back = subSelfUrl('home.php') . '?pay=cancelled#pricing';
header('Location: ' . $back);
exit;
