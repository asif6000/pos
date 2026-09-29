<?php
/**
 * Public invoice view - one sale, by link, no account.
 *
 * Reached by the link in an SMS receipt. Deliberately the only file in the
 * project that shows a sale to somebody who has not logged in, which is why it
 * is written the way it is:
 *
 *   - it finds the sale by token and by nothing else, so it cannot be walked;
 *   - a token that is not found, and a request with no token at all, produce the
 *     same short answer, so the page cannot be used to tell "wrong link" from
 *     "no such invoice" and start guessing;
 *   - it never prints a sale id, a customer id, a phone number in full, or the
 *     cashier's name, because a link that is forwarded or left in a chat is a
 *     link other people can see;
 *   - it says noindex, and sends the same as a header, so a receipt does not end
 *     up in a search result.
 *
 * The receipt itself is built by invoiceBuildHtml(), the same function the
 * WhatsApp image uses, so the page a customer opens and the picture they were
 * sent can never disagree about what they bought.
 */
// Set once this page has decided what to show. The shutdown handler reads it.
$GLOBALS['invoice_view_answered'] = false;
$GLOBALS['invoice_view_why'] = 'not answered';

// The includes are checked rather than assumed.
//
// A partial upload - the page present but config/invoice_image.php missing, or an
// older copy of it - makes the require fatal, and the shutdown handler below turns
// that into the same "link is not working" page as a bad token. Which is why the
// reason is named here: from the browser a deployment gap and an expired link are
// indistinguishable, and without this the shop is told to "ask for it again" when
// what is actually wrong is a file that never got uploaded.
$missingFile = '';
foreach ([
    'config/db.php',
    'config/invoice_token.php',
    'config/invoice_image.php',
] as $required) {
    if (!is_file(__DIR__ . '/' . $required)) {
        $missingFile = $required;
        break;
    }
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/invoice_token.php';
require_once __DIR__ . '/config/invoice_image.php';

// A public page has no business starting a session, and the session cookie would
// only be sent here for nothing.
@ini_set('session.use_cookies', '0');
@ini_set('display_errors', '0');
error_reporting(0);

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Referrer-Policy: no-referrer', true);
header('Cache-Control: no-store', true);
header('Content-Type: text/html; charset=utf-8');

if ($missingFile !== '') {
    // Logged inside invoicePublicSorry(); setting the reason is all this needs.
    $GLOBALS['invoice_view_why'] = 'missing file: ' . $missingFile;
    invoicePublicSorry();
}

// getDB() calls die() when the database is unreachable, and die() cannot be
// caught - a try/catch around it does nothing. On this page that message would go
// straight to a stranger holding a link, telling them the shop's database is
// down. So everything from here on is buffered, and the buffer is thrown away if
// the script ends without having answered, which turns any such failure into the
// same short "link is not working" page as a bad token.
ob_start();

register_shutdown_function(function () {
    if (!empty($GLOBALS['invoice_view_answered'])) {
        return;
    }
    // Reached when something ended the script without a decision of its own: a
    // fatal error, getDB()'s die(), a memory limit. The reason is whatever the
    // last line of the page set, or a note that it was never reached - which
    // points straight at a missing column or a partial upload.
    error_log('invoice-view: ' . $GLOBALS['invoice_view_why'] . ' (ended without answering)');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    }
    echo invoicePublicSorryBody();
});

/**
 * The one thing this page ever says when it will not show a sale.
 *
 * Deliberately vague and identical in every case, including a database that is
 * down. A page that says "no such invoice" and a page that says "database error"
 * together are enough to tell a fuzzer which tokens are real.
 */
function invoicePublicSorryBody()
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>Invoice not available</title>'
       . '<style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f4f5f7;color:#333;'
       . 'margin:0;padding:40px 20px;display:flex;justify-content:center}'
       . '.box{background:#fff;max-width:380px;padding:28px 24px;border-radius:10px;'
       . 'box-shadow:0 1px 3px rgba(0,0,0,.12);text-align:center}'
       . 'h1{font-size:17px;margin:0 0 8px}.p{font-size:14px;color:#666;line-height:1.5;margin:0}'
       . '</style></head><body><div class="box">'
       . '<h1>Invoice link is not working</h1>'
       . '<p class="p">This link may have been sent for an invoice that is no longer available, '
       . 'or it may have been copied incompletely. Please ask the shop to send it again.</p>'
       . '</div></body></html>';
}

/**
 * The one thing this page ever says when it will not show a sale.
 *
 * Deliberately vague and identical in every case, including a database that is
 * down. A page that says "no such invoice" and a page that says "database error"
 * together are enough to tell a fuzzer which tokens are real. The reason goes to
 * the server log instead, which is the only place it is useful.
 */
function invoicePublicSorry($why = '')
{
    $GLOBALS['invoice_view_answered'] = true;
    if ($why !== '' && empty($GLOBALS['invoice_view_why'])) {
        $GLOBALS['invoice_view_why'] = $why;
    }
    error_log('invoice-view: ' . $GLOBALS['invoice_view_why']);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo invoicePublicSorryBody();
    exit;
}

// Only ever 32 hex characters, whatever arrived. Anything else is not a token
// this page was ever going to have issued.
$token = isset($_GET['t']) && is_string($_GET['t'])
    ? trim((string)preg_replace('/[^a-fA-F0-9]/', '', $_GET['t']))
    : '';
if (strlen($token) !== 32) {
    invoicePublicSorry('bad token length: ' . strlen($token));
}

try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.created_at, s.subtotal, s.discount_percent,
               s.discount_amount, s.vat_percent, s.vat_amount, s.total,
               s.paid_amount, s.change_amount, s.payment_method, s.customer_id,
               c.name AS customer_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.invoice_token = ?
        LIMIT 1");
    $stmt->execute([$token]);
    $sale = $stmt->fetch();
} catch (Throwable $e) {
    error_log('invoice-view: ' . $e->getMessage());
    invoicePublicSorry();
}

if (!$sale) {
    invoicePublicSorry('no sale for that token');
}

// The owner id is needed to read this shop's settings for the receipt header, and
// it is the one place a public page has to reach past the sale row.
$stmt = $db->prepare("SELECT owner_id FROM sales WHERE id = ?");
$stmt->execute([(int)$sale['id']]);
$ownerId = (int)$stmt->fetchColumn();

$items = [];
$stmt = $db->prepare("SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id ASC");
$stmt->execute([(int)$sale['id']]);
$items = $stmt->fetchAll();

// The page shows the receipt and nothing that identifies the buyer further than
// the name already printed on the paper one. The phone is not shown at all: the
// link is the proof it is meant to be opened by, and a forwarded link that also
// carried a number would be a forwarded number.
$built = invoiceBuildHtml($db, (int)$sale['id'], $ownerId, '');
if (empty($built['ok']) || empty($built['html'])) {
    invoicePublicSorry();
}

// invoiceBuildHtml() returns a complete document, because it is drawn to a PNG
// by the bridge. The page needs the same receipt with a page's worth of
// furniture around it, so the document is unwrapped rather than the markup
// rebuilt - two descriptions of a receipt would drift, and the customer would be
// shown one thing while the shop sent another.
$inner = preg_replace('#\A.*<body[^>]*>(.*)</body>.*\z#s', '$1', $built['html']);
if (!is_string($inner) || trim($inner) === '') {
    invoicePublicSorry();
}
$style = '';
if (preg_match('#<style>(.*?)</style>#s', $built['html'], $m)) {
    $style = $m[1];
}

// The stylesheet in the receipt fixes the body to 340px, which is right for a
// phone and wasteful on a page. Only the width is relaxed here; the sizes that
// make the receipt legible are untouched.
$style .= 'body{width:auto;max-width:420px;background:#f4f5f7;padding:18px 12px}'
        . '.wrap{background:#fff;border-radius:10px;padding:18px 16px;box-shadow:0 1px 3px rgba(0,0,0,.12)}'
        . '.note{font-size:12px;color:#666;text-align:center;margin-top:14px;line-height:1.5}';

$shopName = '';
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE owner_id = ? AND setting_key = 'shop_name'");
    $stmt->execute([$ownerId]);
    $shopName = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $_) {
    // The page title is not worth failing over; the receipt is already built.
    $shopName = '';
}

// The one and only path that shows a sale. Marked before the first byte is
// written, so the shutdown handler above stays quiet on the success path.
$GLOBALS['invoice_view_answered'] = true;

echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width, initial-scale=1">'
   . '<meta name="robots" content="noindex, nofollow">'
   . '<title>' . invoiceEscape($shopName !== '' ? $shopName : 'Invoice') . ' - Invoice</title>'
   . '<style>' . $style . '</style></head><body><div class="wrap">'
   . $inner
   . '<div class="note">Thank you for shopping.</div>'
   . '</div></body></html>';

