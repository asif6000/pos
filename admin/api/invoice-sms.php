<?php
/**
 * API - Send a sale's invoice receipt by SMS.
 *
 * The whole receipt travels as text: shop name, invoice number, date, the items,
 * the total and what was paid. No link is included - the text already says
 * everything a link would open, and the URL is the line that pushes a normal
 * basket into a second charged segment.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
// marketing.php is required in its own right, not left to another file to pull
// in. sms_gateway.php only requires db.php, and this endpoint calls
// marketingNormalizePhone() and getMarketingSettings(), which live in
// marketing.php - so without this line both were undefined and the page died
// with a fatal error, which the browser reported to the POS as "SMS pathano
// jay ni" and nothing else.
require_once __DIR__ . '/../../config/marketing.php';
require_once __DIR__ . '/../../config/sms_gateway.php';
// Sends the message and records that it went out. There is no credit check any
// more - SMS is not sold by the pack - so this file's only job is the send plus
// the usage row every count on the Marketing page is read back from.
require_once __DIR__ . '/../../config/sms_usage.php';
require_once __DIR__ . '/../../config/invoice_token.php';
require_once __DIR__ . '/../../config/invoice_image.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$saleId = (int)($input['sale_id'] ?? $input['id'] ?? 0);
if ($saleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invoice number bujha jay ni.']);
    exit;
}

try {
    $db = getDB();
    $currentUser = getCurrentUser();
    $ownerId = $currentUser['owner_id'];
} catch (Throwable $e) {
    error_log('invoice-sms: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server ekhon pares na.']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT s.id, s.invoice_number, s.total, s.created_at,
               COALESCE(NULLIF(TRIM(c.phone), ''), '') AS num,
               c.name AS customer_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.id = ? AND s.owner_id = ?");
    $stmt->execute([$saleId, $ownerId]);
    $sale = $stmt->fetch();
} catch (Throwable $e) {
    error_log('invoice-sms read: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Invoice ta pora jay ni.']);
    exit;
}

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Ei invoice-ta nai.']);
    exit;
}

// A counter account (staff/cashier) texts only the receipts of its own bills -
// another counter's invoice is their record, and this door leads straight to it.
if (isOwnSalesOnlyUser($currentUser)) {
    $stmt = $db->prepare("SELECT 1 FROM sales WHERE id = ? AND user_id = ?");
    $stmt->execute([$saleId, (int) $currentUser['id']]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Ei invoice apnar nijer bikri na.']);
        exit;
    }
}

// The number typed at the counter wins over the stored one, for the same reason
// the receipt is typed at all: a walk-in has no customer record.
$phone = trim((string)($input['phone'] ?? ''));
if ($phone === '') {
    $phone = trim((string)($sale['num'] ?? ''));
}

$digits = marketingNormalizePhone($phone);
if ($digits === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Ei invoice-ta kono customer number nai, tai SMS e pathano jay na. Numbar ta diye chalan -',
    ]);
    exit;
}

// Two different setting families, and mixing them up is why the shop's name has
// been missing from this message.
//
// getMarketingSettings() reads the settings table filtered to keys LIKE
// 'marketing_%', because that is all it is for. shop_name, invoice_sms_*,
// facebook_page and coupon_raffle_url are ordinary shop settings and are not in
// that result at all - every one of them read as absent, so the receipt arrived
// with no shop name on it, the promo block permanently off and the item limit
// stuck at 8 no matter what the shop had saved. Nothing
// threw, and a setting that quietly reads as "off" is the hardest kind to notice.
//
// invoiceLoadSale() already reads the same table for this owner with no filter,
// and the printed receipt and the invoice image are both built from it. So the
// shop's own settings come from there, and nothing marketing-specific is
// consulted on this path.

// The full receipt, not a summary. Both this and the printed receipt read the
// same rows through invoiceLoadSale(), so the two cannot disagree about what was
// bought.
$data = invoiceLoadSale($db, $saleId, $ownerId);
if (empty($data['ok'])) {
    echo json_encode(['success' => false, 'message' => $data['error'] ?? 'Invoice pora jay ni.']);
    exit;
}
$settings = $data['settings'];

$shopName = trim((string)($settings['shop_name'] ?? ''));

// The Facebook page and the raffle link, on the shop's own setting.
//
// Off by default for the promo block, because a receipt with items is already
// the thing that decides how many segments this costs, and the shop pays per
// segment. It is a setting rather than a constant so the choice belongs to
// whoever owns the bill.
$promo = [];
$promoOn = ($settings['invoice_sms_promo'] ?? '0');
$promoOn = !($promoOn === '0' || $promoOn === 0 || $promoOn === '');
if ($promoOn) {
    $raffle = trim((string)($settings['coupon_raffle_url'] ?? ''));
    $page   = trim((string)($settings['facebook_page'] ?? ''));
    // The raffle link wins: it is the specific thing worth a tap. The page is the
    // fallback, and if neither is set there is nothing to say.
    $promo = [
        'label' => $raffle !== '' ? 'Follow & watch the live raffle draw' : 'Follow us',
        'url'   => $raffle !== '' ? $raffle : $page,
    ];
}

// No invoice link in this message. The receipt the customer is sent already
// lists the items and the total, and a URL is what tips that over into a second
// charged segment - so it is not offered as a setting any more.
$msg = invoiceSmsText([
    'shop_name'      => $shopName,
    'invoice_number' => (string)$data['sale']['invoice_number'],
    'date_label'     => date('d M Y, h:i A', strtotime((string)($data['sale']['created_at'] ?: 'now')) ?: time()),
    'items'          => $data['items'],
    'total'          => (float)$data['sale']['total'],
    'paid'           => (float)$data['sale']['paid_amount'],
    'paid_label'     => (string)($data['sale']['payment_method'] ?? ''),
    'change'         => (float)$data['sale']['change_amount'],
    'footer'         => (string)($settings['receipt_footer'] ?? ''),
    'promo'          => $promo,
    'max_items'      => (int)($settings['invoice_sms_max_items'] ?? 8),
]);

// Which way this invoice should travel. Read once, here, so the answer cannot
// differ between the attempt and the message the cashier is shown.
//
// Both spellings that remain send over the SMS gateway; 'auto' is simply the
// default. A value saved on this setting back when a second channel existed is
// normalised here too, so those shops' receipts keep going out.
$channel = strtolower(trim((string)($settings['invoice_sms_channel'] ?? 'auto')));
if (!in_array($channel, ['auto', 'sms'], true)) {
    $channel = 'auto';
}

// The reason delivery did not happen, kept as a list in the shape the response
// was always built on.
$problems = [];
$delivered = null;

// Sent, and checked properly. A provider that is unconfigured comes back as
// 'manual', which is not a failure - the POS shows the text so the cashier can
// send it from the phone, which is how a shop with no gateway configured still
// gets the feature.
try {
    $res = smsSendAndLog($db, $ownerId, $digits, $msg['text'], [
        'segments' => (int)$msg['segments'],
        'msg_type' => 'invoice',
        'ref_type' => 'sale',
        'ref_id'   => (string)$saleId,
        'store_id' => $currentUser['store_id'] ?? null,
        'user_id'  => $currentUser['id'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('invoice-sms: ' . $e->getMessage());
    $res = ['ok' => false, 'error' => 'SMS gateway kaj korchilo na. Settings > SMS e gateway ta check korun.'];
}

if (!empty($res['ok'])) {
    $delivered = ['channel' => 'sms', 'manual' => !empty($res['manual'])];
} else {
    $problems[] = $res['error'] ?? 'SMS pathano jay ni.';
}

if ($delivered === null) {
    echo json_encode([
        'success' => false,
        // Both reasons, not just the first. One of them is usually the one the
        // shop can act on, and the other is what sent them looking in the wrong
        // place.
        'message' => implode(' | ', $problems),
        'text'    => $msg['text'],
    ]);
    exit;
}

echo json_encode([
    'success'      => true,
    // manual means the gateway handed the text back for the cashier to send from
    // the phone.
    'manual'       => !empty($delivered['manual']),
    'channel'      => $delivered['channel'],
    'to'           => $digits,
    'text'         => $msg['text'],
    'segments'     => $msg['segments'],
    'items_shown'  => $msg['items_shown'],
    'items_total'  => $msg['items_total'],
    'truncated'    => $msg['truncated'],
    'message'      => invoiceSmsResultMessage($msg, !empty($delivered['manual'])),
]);
exit;

/**
 * What the cashier is told, in words that name the cost.
 *
 * A receipt with items is billed per segment, and a shop that does not know that
 * will find out on the bill. When the list was cut short, that is said too -
 * silently stopping after eight items would read as a receipt for eight.
 */
function invoiceSmsResultMessage(array $msg, $manual)
{
    if ($manual) {
        return 'SMS gateway configure nai - ei text-ta phone er SMS app e chalan:';
    }

    $parts = [];
    $parts[] = 'SMS chole geche.';

    if ($msg['segments'] > 1) {
        $parts[] = $msg['segments'] . ' segment lagbe, mane ' . $msg['segments'] . ' gun charge.';
    }
    if (!empty($msg['truncated'])) {
        $left = $msg['items_total'] - $msg['items_shown'];
        $parts[] = $msg['items_shown'] . ' ta item dekhaychi, ar ' . $left
                 . ' ta chara gobe. Settings e item sonlimit barano jay.';
    }
    return implode(' ', $parts);
}
