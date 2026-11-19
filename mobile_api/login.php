<?php
/**
 * Mobile API - Staff Login
 *
 * Verifies the password and opens a session the rest of the mobile API can
 * recognise. The token it returns is that session's id, sent back as
 * "Authorization: Bearer <token>" on every later call - see config/mobile_auth.php
 * for why the API is keyed on the web session instead of its own token table.
 */

require_once '../config/mobile_auth.php';
mobileCors();

$db = getDB();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['username']) || !isset($data['password'])) {
    echo json_encode(['success' => false, 'message' => 'Email and password required']);
    exit;
}

$email    = trim($data['username']); // app sends 'username' field, which is actually the email
$password = trim($data['password']);

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] !== 'active') {
            echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact administrator.']);
            exit;
        }

        $session = mobileIssueSession($user);
        $effectiveOwnerId = $user['owner_id'] ?: $user['id'];

        echo json_encode([
            'success'    => true,
            'token'      => $session['token'],
            'is_admin'   => $session['is_admin'],
            'plan'       => $session['plan'],
            'permissions'=> $session['permissions'],
            'user' => [
                'id'       => (int)$user['id'],
                'email'    => $user['email'],
                'name'     => $user['name'],
                'role'     => $user['role'],
                'store_id' => $user['store_id'] ?? null,
                'owner_id' => (int)$effectiveOwnerId
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
