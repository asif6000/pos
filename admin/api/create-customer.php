<?php
/**
 * API - Create Customer
 * Handles AJAX requests to create a new customer from the POS screen
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

$name = sanitize($_POST['name'] ?? '');
$rawPhone = sanitize($_POST['phone'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$address = sanitize($_POST['address'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit;
}

// Stored in the international form - 8801712345678 - and it was stored exactly
// as typed before, so the same customer could exist twice: once as 01712345678
// from this screen and once as 8801712345678 from the Marketing page, which has
// always normalised. One form means the campaign audience and
// the receipt link all agree on what the number is, and a search finds the
// customer whichever way the number was typed.
$phone = marketingNormalizePhone($rawPhone);
if ($phone === '' && trim($rawPhone) !== '') {
    // Something with letters in it. Refused rather than silently stored, because
    // a customer saved with an unusable number is invisible to every send path.
    echo json_encode(['success' => false, 'message' => 'Phone number ta bujha jay ni.']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, owner_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $phone, $email ?: null, $address, $currentUser['owner_id']]);
    $newCustomerId = $db->lastInsertId();

    // Sync to Google Contacts if enabled
    require_once '../../config/google_contacts.php';
    $googleSync = syncCustomerToGoogleContacts([
        'id'      => $newCustomerId,
        'name'    => $name,
        'phone'   => $phone,
        'email'   => $email,
        'address' => $address
    ], $currentUser['owner_id']);

    $message = 'Customer created successfully';
    if (!empty($googleSync['success'])) {
        $message .= ' & synced to Google Contacts';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'google_synced' => !empty($googleSync['success']),
        'customer' => [
            'id' => $newCustomerId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
