<?php
/**
 * API - Ask WhatsApp about one customer, without holding up the save.
 *
 * WHY THIS IS SEPARATE
 * --------------------
 * The check needs the bridge, and the bridge is on the shop PC while this request
 * is on a shared host. So the answer cannot be known at the moment the customer is
 * saved - the only options are to wait, or to come back for it.
 *
 * Waiting was what this used to do, inside the save itself, for twenty seconds.
 * That is wrong on a shared host for three reasons: the cashier watches a spinner
 * for twenty seconds on every customer they add, the host's own execution limit
 * can cut the request off before it answers, and when that happens the customer
 * is saved with no badge and nobody is told why.
 *
 * So the save returns at once and the page asks afterwards. The cashier is never
 * blocked, a slow bridge cannot cost a customer their badge, and the answer
 * arrives when it is genuinely ready.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/marketing.php';
require_once __DIR__ . '/../../config/wa_agent.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$customerId = (int)($input['customer_id'] ?? $input['id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Customer bujha jay ni.']);
    exit;
}

try {
    $db = getDB();
    $currentUser = getCurrentUser();
    $ownerId = $currentUser['owner_id'];
} catch (Throwable $e) {
    error_log('customer-wa-check: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server ekhon pares na.']);
    exit;
}

/** The badge as it stands right now, with the three states kept apart. */
function customerWaState($db, $customerId, $ownerId)
{
    $st = $db->prepare("SELECT phone, has_whatsapp, whatsapp_checked_at
                        FROM customers WHERE id = ? AND owner_id = ?");
    $st->execute([$customerId, $ownerId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return [
        'phone'     => (string)($row['phone'] ?? ''),
        // true / false / null - "never checked" is not "not on WhatsApp", and
        // collapsing the two would tell a shopkeeper to give up on a customer who
        // is perfectly reachable.
        'has_wa'    => $row['has_whatsapp'] === null ? null : ((int)$row['has_whatsapp'] === 1),
        'checked_at' => $row['whatsapp_checked_at'],
    ];
}

$before = customerWaState($db, $customerId, $ownerId);
if (!$before) {
    echo json_encode(['success' => false, 'message' => 'Ei customer-ta nai.']);
    exit;
}

// Nothing to ask about.
if (trim($before['phone']) === '') {
    echo json_encode([
        'success'   => true,
        'has_wa'    => null,
        'reason'    => 'no number',
        'message'   => 'Ei customer er kono number nai.',
    ]);
    exit;
}

$res = waAgentCheckCustomer($db, $ownerId, $customerId);
$after = customerWaState($db, $customerId, $ownerId);

// Read the badge back off the row rather than trusting the return value, so what
// the page shows is what was actually stored.
$hasWa = $after['has_wa'] ?? null;

$message = 'ok';
if (!$res['ok']) {
    // A bridge that is offline is an ordinary state, not an error to shout about.
    $message = ($res['reason'] === 'bridge not connected')
        ? 'WhatsApp bridge connected nai - number check hoye ni.'
        : 'WhatsApp check hoye ni (' . ($res['reason'] ?: 'unknown') . ').';
}

echo json_encode([
    'success' => true,
    'has_wa'  => $hasWa,
    'message' => $message,
    // Worth saying out loud, because it is the whole point: the number either has
    // WhatsApp or it has not, and the shop should be told which.
    'text'    => $hasWa === true
        ? 'Ei number e WhatsApp ache'
        : ($hasWa === false ? 'Ei number e WhatsApp nai' : 'WhatsApp check hoye ni'),
]);
exit;
