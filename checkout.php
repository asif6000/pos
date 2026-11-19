<?php
/**
 * Checkout - creates a UddoktaPay payment for a plan and sends the browser to it.
 *
 * POST only, login only. The amount is looked up from config/plans.php on the
 * server; the plan slug and cycle are the ONLY things the browser chooses, so a
 * tampered form cannot change the price. On success we store the pending payment
 * and redirect to the gateway; the gateway later returns the buyer to
 * payment-callback.php?ref=<paymentId>&invoice_id=<gateway id>.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/subscription.php';
require_once __DIR__ . '/config/uddoktapay.php';

startSecureSession();

if (!isLoggedIn()) {
    redirect('auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('home.php#pricing');
}

$user    = getCurrentUser();
$ownerId = (int)($user['owner_id'] ?: $user['id']);
$userId  = (int)$user['id'];

// Only the people who run the shop can buy for it. A cashier must not be able to
// charge the owner's card by hitting this endpoint.
if (!hasRole(['admin', 'owner', 'manager'])) {
    redirect('home.php?pay=forbidden#pricing');
}

$slug  = (string)($_POST['plan'] ?? '');
$cycle = ($_POST['cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';

$db = getDB();
subEnsureTables($db);

$plan = subPlan($slug);
if (!$plan) {
    redirect('home.php?pay=badplan#pricing');
}

$amount = subPlanPrice($slug, $cycle);
if ($amount <= 0) {
    redirect('home.php?pay=badplan#pricing');
}

$action = subPlanAction($db, $ownerId, $slug);

$pay = subCreatePendingPayment(
    $db, $ownerId, $userId, $slug, $cycle, $action,
    $user['name'] ?: 'Shop Owner',
    $user['email'] ?: ''
);

// ref carries OUR payment id back through the gateway so the callback knows which
// pending row to credit; invoice_id is appended by the gateway on return.
$redirectUrl = subSelfUrl('payment-callback.php') . '?ref=' . $pay['id'];
$cancelUrl   = subSelfUrl('payment-cancel.php') . '?ref=' . $pay['id'];

$result = udtCreatePayment(
    $user['name'] ?: 'Shop Owner',
    $user['email'] ?: 'owner@localhost',
    $amount,
    ['payment_id' => (string)$pay['id'], 'owner_id' => (string)$ownerId, 'plan' => $slug, 'cycle' => $cycle],
    $redirectUrl,
    $redirectUrl,
    $cancelUrl
);

if ($result['ok'] && $result['payment_url']) {
    subSetPaymentUrl($db, $pay['id'], $result['payment_url']);
    header('Location: ' . $result['payment_url']);
    exit;
}

// Could not open a payment session - record why and send the buyer back.
subMarkPayment($db, $pay['id'], 'failed', null, null,
    json_encode($result['raw'] ?? ['error' => $result['error']], JSON_UNESCAPED_UNICODE));
error_log('[checkout] UddoktaPay create failed: ' . ($result['error'] ?? 'unknown'));
redirect('home.php?pay=error#pricing');
