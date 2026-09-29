<?php
/**
 * API - Check whether one saved customer number is on WhatsApp
 *
 * Called after a customer is saved, never during the save. The check is a round
 * trip to WhatsApp, so running it inline would make the POS wait on it for no
 * good reason - the sale does not care whether the customer has WhatsApp, only
 * a later campaign does.
 *
 * The answer is cached on the customer row, so a number is probed once and the
 * marketing module can read the result without touching WhatsApp again.
 *
 * POST customer_id
 *   -> { success, has_whatsapp: true|false|null, checked_at }
 *
 * has_whatsapp is null both when the number is unusable and when the check
 * itself could not run. They are told apart by `error`, but the stored value
 * stays null in both cases so nothing downstream mistakes a bridge outage for a
 * customer without WhatsApp.
 */

header('Content-Type: application/json');
require_once '../../config/db.php';
require_once '../../config/marketing.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'] ?? $currentUser['id'];

$customerId = (int)($_POST['customer_id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'customer_id is required']);
    exit;
}

$stmt = $db->prepare("SELECT id, name, phone FROM customers WHERE id = ? AND owner_id = ?");
$stmt->execute([$customerId, $ownerId]);
$customer = $stmt->fetch();

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
    exit;
}

/** Store the outcome. NULL means "still unknown", so leave the timestamp off too. */
function saveWhatsappCheck($db, $customerId, $hasWhatsapp)
{
    if ($hasWhatsapp === null) {
        $db->prepare("UPDATE customers SET has_whatsapp = NULL WHERE id = ?")->execute([$customerId]);
        return;
    }
    $db->prepare("UPDATE customers
                  SET has_whatsapp = ?, whatsapp_checked_at = NOW()
                  WHERE id = ?")->execute([$hasWhatsapp ? 1 : 0, $customerId]);
}

$phone = marketingNormalizePhone($customer['phone'] ?? '');

if ($phone === '') {
    saveWhatsappCheck($db, $customerId, null);
    echo json_encode([
        'success'      => true,
        'has_whatsapp' => null,
        'message'      => 'No usable phone number to check.',
    ]);
    exit;
}

$check = marketingCheckWhatsApp($ownerId, $phone);

if (!$check['ok']) {
    // The bridge could not answer. Leave the row unchecked so a later attempt
    // can still succeed, and say plainly that nothing was learned.
    saveWhatsappCheck($db, $customerId, null);
    echo json_encode([
        'success'      => true,
        'has_whatsapp' => null,
        'message'      => $check['error'] ?: 'WhatsApp bridge is not reachable.',
    ]);
    exit;
}

saveWhatsappCheck($db, $customerId, $check['exists']);

echo json_encode([
    'success'      => true,
    'has_whatsapp' => $check['exists'],
    'phone'        => marketingFormatPhone($phone),
    'message'      => $check['exists']
        ? 'WhatsApp number found.'
        : 'This number is not on WhatsApp.',
]);
