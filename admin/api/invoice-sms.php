<?php
/**
 * API - Send a sale's invoice link by SMS.
 *
 * The SMS is short on purpose: a shop name, the invoice number, the date, the
 * total and a link. A full itemised receipt over SMS is four charged messages
 * to say something a link says in one.
 *
 * The link is the same receipt the WhatsApp image is built from, and it opens
 * invoice-view.php, which is the only unauthenticated page in the project and is
 * guarded by a 128-bit token per sale.
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
// wa_agent.php is what the WhatsApp fallback below queues through, and it needs
// marketing.php above it. Required in its own right for the same reason
// marketing.php is: leaving a function to be pulled in by whichever file happens
// to include it first is how a page ends up calling something that was never
// loaded, and the fatal that follows reaches the POS as "SMS pathano jay ni".
require_once __DIR__ . '/../../config/wa_agent.php';
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

// The number typed at the counter wins over the stored one, for the same reason
// the WhatsApp button asks: a walk-in has no customer record.
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

// Without the column there is no link to send, and a plain "failed" would leave
// the shop guessing. The column is created on demand by invoiceEnsureToken(),
// which needs DDL rights; where the host denies them this says so plainly
// rather than failing later inside a provider call.
try {
    $token = invoiceEnsureToken($db, $saleId, $ownerId);
} catch (Throwable $e) {
    error_log('invoice-sms: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Invoice link banano jay ni. Database ta dekhe bolen.']);
    exit;
}
if ($token === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Invoice link banano jay ni. Database e DDL permission nai.',
    ]);
    exit;
}

$url = invoicePublicUrl($token);
if ($url === '') {
    echo json_encode(['success' => false, 'message' => 'Invoice link banano jay ni.']);
    exit;
}

// Two different setting families, and mixing them up is why the shop's name has
// been missing from this message.
//
// getMarketingSettings() reads the settings table filtered to keys LIKE
// 'marketing_%', because that is all it is for. shop_name, invoice_sms_*,
// facebook_page and coupon_raffle_url are ordinary shop settings and are not in
// that result at all - every one of them read as absent, so the receipt arrived
// with no shop name on it, the promo block permanently off, the link permanently
// off and the item limit stuck at 8 no matter what the shop had saved. Nothing
// threw, and a setting that quietly reads as "off" is the hardest kind to notice.
//
// invoiceLoadSale() already reads the same table for this owner with no filter,
// and the printed receipt and the invoice image are both built from it. So the
// shop's own settings come from there, and the marketing settings are used only
// for what they actually own: the bridge.
try {
    $marketing = getMarketingSettings($ownerId);
} catch (Throwable $e) {
    error_log('invoice-sms: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'SMS settings pora jay ni.']);
    exit;
}

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

// The link is off unless the shop asked for it. With the item list in the
// message the receipt stands on its own, and the link is what tips a normal
// basket over one charged segment.
$linkOn = ($settings['invoice_sms_link'] ?? '0');
$linkOn = !($linkOn === '0' || $linkOn === 0 || $linkOn === '');

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
    'link'           => $linkOn ? $url : '',
    'promo'          => $promo,
    'max_items'      => (int)($settings['invoice_sms_max_items'] ?? 8),
]);

// Which way this invoice should travel. Read once, here, so the answer cannot
// differ between the attempt and the message the cashier is shown.
//
// 'auto'       - try SMS, and if the gateway will not take it, send the same
//                text over WhatsApp instead. The default, and the reason a shop
//                whose provider rejects the server IP still reaches its
//                customers.
// 'whatsapp'   - never spend an SMS credit on this; WhatsApp only.
// 'sms'        - the old behaviour exactly, gateway failure and all.
$channel = strtolower(trim((string)($settings['invoice_sms_channel'] ?? 'auto')));
if (!in_array($channel, ['auto', 'whatsapp', 'sms'], true)) {
    $channel = 'auto';
}

// Every reason delivery did not happen, in the order they were tried. Held rather
// than reported at the first failure, because "SMS fail korlo" on its own sent the
// shop to the SMS settings when the fault was the provider's IP whitelist - and
// the WhatsApp fallback that would have worked never got the chance to run.
$problems = [];
$delivered = null;

// Sent first, and checked properly. A provider that is unconfigured comes back as
// 'manual', which is not a failure - the POS shows the text so the cashier can
// send it from the phone, which is how a shop with no gateway configured still
// gets the feature.
if ($channel !== 'whatsapp') {
    try {
        $res = marketingSendSms($ownerId, $digits, $msg['text']);
    } catch (Throwable $e) {
        error_log('invoice-sms: ' . $e->getMessage());
        $res = ['ok' => false, 'error' => 'SMS gateway kaj korchilo na. Settings > SMS e gateway ta check korun.'];
    }

    if (!empty($res['ok'])) {
        $delivered = ['channel' => 'sms', 'manual' => !empty($res['manual'])];
    } else {
        $problems[] = $res['error'] ?? 'SMS pathano jay ni.';
    }
}

// The WhatsApp attempt, and the fallback. Same text, same customer, the shop's own
// bridge - so it costs nothing per message and does not care that the server IP
// is not on a provider's whitelist, which is the failure that blocks it today.
//
// $marketing, not $settings: the bridge mode and the agent token are marketing_*
// keys, so handing this the shop's own settings would read as "agent mode off"
// for a shop that has it switched on.
if ($delivered === null) {
    $wa = invoiceDeliverViaWhatsApp($ownerId, $marketing, $digits, $msg['text']);
    if (!empty($wa['ok'])) {
        $delivered = ['channel' => 'whatsapp', 'manual' => false];
    } else {
        $problems[] = $wa['error'];
    }
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

$why = '';
if ($delivered['channel'] === 'whatsapp') {
    $why = ($channel === 'whatsapp')
        ? 'WhatsApp e queue te dhaka holo - bridge poshtay pathiye debe.'
        : 'SMS gateway pathate parche na (' . ($problems[0] ?? 'gateway problem')
          . '), tarpor WhatsApp e pathiye dilam.';
}

echo json_encode([
    'success'      => true,
    // manual means the gateway handed the text back for the cashier to send from
    // the phone. A WhatsApp send is queued, not manual: it needs no tapping.
    'manual'       => !empty($delivered['manual']),
    'channel'      => $delivered['channel'],
    'to'           => $digits,
    'link'         => $linkOn ? $url : '',
    'text'         => $msg['text'],
    'segments'     => $msg['segments'],
    'items_shown'  => $msg['items_shown'],
    'items_total'  => $msg['items_total'],
    'truncated'    => $msg['truncated'],
    'message'      => $why !== '' ? $why : invoiceSmsResultMessage($msg, !empty($delivered['manual'])),
]);
exit;

/**
 * Hand the invoice text to the shop's own WhatsApp bridge.
 *
 * Queued, not sent: the WhatsApp session lives on the shop PC beside the bridge,
 * so the job is written here and the agent picks it up on its next poll. That is
 * the same arrangement the invoice image uses, which is why there is one queue
 * and not two.
 *
 * Every refusal is a sentence the shop can act on. "not connected" is the state
 * this is in most often, and it is worth saying so plainly: a fallback that
 * fails quietly is worse than no fallback, because the cashier was told the
 * invoice was on its way.
 *
 * @return array{ok:bool, error:string}
 */
function invoiceDeliverViaWhatsApp($ownerId, $marketing, $digits, $text)
{
    if (!waAgentModeEnabled($marketing)) {
        return ['ok' => false, 'error' => 'WhatsApp bridge agent mode e nai (Settings > WhatsApp Bridge).'];
    }

    $status = marketingAgentStatus($ownerId, $marketing);
    if (empty($status['connected'])) {
        $hint = trim((string)($status['hint'] ?? ''));
        return ['ok' => false, 'error' => $hint !== '' ? $hint : 'WhatsApp connect nai. QR scan kore link korun.'];
    }

    $put = waAgentEnqueue($ownerId, 'send', $digits, $text);
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => $put['error'] ?? 'WhatsApp queue e pathano jay ni.'];
    }

    return ['ok' => true, 'error' => ''];
}

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
