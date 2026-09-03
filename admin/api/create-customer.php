<?php
/**
 * API - Create Customer
 * Handles AJAX requests to create a new customer from the POS screen
 */

header('Content-Type: application/json');
require_once '../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasPermission('customers')) {
    echo json_encode(['success' => false, 'message' => 'Forbidden: Your plan does not include this feature.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$db = getDB();
$currentUser = getCurrentUser();

$name = sanitize($_POST['name'] ?? '');
$phone = sanitize($_POST['phone'] ?? '');
$email = sanitize($_POST['email'] ?? '');
$address = sanitize($_POST['address'] ?? '');

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, owner_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $phone, $email ?: null, $address, $currentUser['owner_id']]);
    $newCustomerId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Customer created successfully',
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
