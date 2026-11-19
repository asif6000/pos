<?php
/**
 * Payment callback - UddoktaPay returns the buyer here after they pay.
 *
 * The gateway appends ?invoice_id=... to the redirect_url we gave it (which
 * already carries ?ref=<our payment id>). We re-verify that invoice against the
 * gateway with our secret API key - the query string alone is never trusted -
 * then credit the subscription and mark the payment paid.
 *
 * Idempotent: a row already marked paid just re-renders success without
 * re-applying, so a double redirect cannot extend a plan twice.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/subscription.php';
require_once __DIR__ . '/config/uddoktapay.php';

startSecureSession();

$db = getDB();
subEnsureTables($db);

$ref = (int)($_GET['ref'] ?? 0);
$pay = $ref > 0 ? subFindPayment($db, $ref) : null;

/** Render a small standalone result page and stop. */
function payPage($tone, $title, $lines, $ctaHref, $ctaLabel)
{
    $bg     = $tone === 'ok' ? '#ecfdf5' : ($tone === 'warn' ? '#fffbeb' : '#fef2f2');
    $border = $tone === 'ok' ? '#059669' : ($tone === 'warn' ? '#d97706' : '#e11d48');
    $text   = '#1f2937';
    $lis    = '';
    foreach ($lines as $l) { $lis .= '<li>' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</li>'; }
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeCta   = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $safeHref  = htmlspecialchars($ctaHref, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $safeTitle . '</title></head>'
       . '<body style="margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:' . $text . '">'
       . '<div style="max-width:520px;margin:60px auto;padding:0 16px">'
       . '<div style="background:#fff;border-radius:16px;padding:28px;border-top:6px solid ' . $border . ';box-shadow:0 6px 24px rgba(0,0,0,.08)">'
       . '<div style="width:56px;height:56px;border-radius:50%;background:' . $bg . ';display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:14px">'
       . ($tone === 'ok' ? '✅' : ($tone === 'warn' ? '⏳' : '⚠️')) . '</div>'
       . '<h1 style="font-size:22px;margin:0 0 10px">' . $safeTitle . '</h1>'
       . '<ul style="margin:0 0 20px;padding-left:18px;line-height:1.7;font-size:15px">' . $lis . '</ul>'
       . '<a href="' . $safeHref . '" style="display:inline-block;background:#e11d48;color:#fff;text-decoration:none;padding:13px 22px;border-radius:10px;font-weight:700">' . $safeCta . '</a>'
       . '</div></div></body></html>';
    exit;
}

$back = subSelfUrl('home.php') . '#pricing';
$dash = subSelfUrl('admin/index.php');

if (!$pay) {
    payPage('bad', 'Payment not found', ['We could not match this payment to a request.', 'If money was deducted, contact support with your transaction details.'], $back, 'Back to plans');
}

// Already credited - just show success again.
if ($pay['status'] === 'paid') {
    $cur = subGetCurrent($db, $pay['owner_id']);
    $planName = ucfirst($pay['plan_slug']);
    $exp = $cur ? date('d M Y', strtotime($cur['expires_at'])) : '—';
    payPage('ok', 'Payment already confirmed', ['Plan: ' . $planName . ' (' . $pay['billing_cycle'] . ')', 'Valid until: ' . $exp], $dash, 'Go to dashboard');
}

// Find the gateway invoice id (it may use a slightly different key name).
$invoiceId = null;
foreach (['invoice_id', 'invoice', 'payment_id', 'trx', 'transaction_id'] as $k) {
    if (!empty($_GET[$k])) { $invoiceId = (string)$_GET[$k]; break; }
}

if (!$invoiceId) {
    // Returned without an invoice id (buyer closed early, or a param name we do
    // not know). Leave the row pending; do not guess.
    subMarkPayment($db, $pay['id'], 'pending', null, null,
        json_encode(['callback_query' => $_GET], JSON_UNESCAPED_UNICODE));
    payPage('warn', 'Payment not confirmed yet',
        ['We did not receive a payment confirmation.', 'If you completed the payment it may take a moment - reload your plans page, or contact support if it was deducted.'],
        $back, 'Back to plans');
}

$verify = udtVerifyPayment($invoiceId);
$payloadJson = json_encode(['invoice_id' => $invoiceId, 'callback_query' => $_GET, 'verify' => $verify['raw']], JSON_UNESCAPED_UNICODE);

$expected = (float)$pay['amount'];

if (!$verify['ok'] || !$verify['paid']) {
    subMarkPayment($db, $pay['id'], 'failed', $invoiceId, $verify['txn'], $payloadJson);
    payPage('bad', 'Payment not completed',
        ['The gateway did not confirm this payment as paid.', 'Nothing was charged to your plan. You can try again.'],
        $back, 'Try again');
}

// Amount must match what we asked for - blocks a cheap invoice being replayed
// against an expensive pending row.
if ($verify['amount'] !== null && abs($verify['amount'] - $expected) > 0.01) {
    subMarkPayment($db, $pay['id'], 'failed', $invoiceId, $verify['txn'], $payloadJson);
    error_log('[callback] amount mismatch pay#' . $pay['id'] . ' expected=' . $expected . ' got=' . $verify['amount']);
    payPage('bad', 'Payment amount mismatch',
        ['The confirmed amount does not match this order.', 'Please contact support - your plan was not changed.'],
        $back, 'Back to plans');
}

// Credit it. Transaction so the payment row and the subscription cannot diverge.
try {
    $db->beginTransaction();
    subMarkPayment($db, $pay['id'], 'paid', $invoiceId, $verify['txn'], $payloadJson, date('Y-m-d H:i:s'));
    $fresh = subFindPayment($db, $pay['id']);
    $sub = subApplyPaid($db, $fresh);
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log('[callback] apply failed pay#' . $pay['id'] . ': ' . $e->getMessage());
    payPage('bad', 'Payment received, plan not applied',
        ['Your payment was confirmed but we could not update the plan automatically.', 'Please contact support with invoice ' . $invoiceId . '.'],
        $back, 'Back to plans');
}

$planName = subPlan($sub['plan_slug'])['name'] ?? ucfirst($sub['plan_slug']);
$exp = date('d M Y', strtotime($sub['expires_at']));
$cyc = $sub['billing_cycle'] === 'yearly' ? 'বার্ষিক' : 'মাসিক';
payPage('ok', 'পেমেন্ট সফল হয়েছে! 🎉',
    ['প্ল্যান: ' . $planName . ' (' . $cyc . ')',
     'মেয়াদ: ' . $exp . ' পর্যন্ত',
     'ইনভয়েস: ' . $invoiceId],
    $dash, 'ড্যাশবোর্ডে যান');
