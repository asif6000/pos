<?php
// reset_admin.php
// WARNING: This script will reset the admin user password.
// Delete this file immediately after use.

require_once 'config/db.php';

echo "<h1>Admin User Reset Tool</h1>";

try {
    $db = getDB();

    // Define admin credentials
    $email = 'admin@pos.com';
    $password = 'admin123';
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Update existing user
        $stmt = $db->prepare("UPDATE users SET password = ?, role = 'admin', status = 'active' WHERE email = ?");
        $stmt->execute([$hash, $email]);
        echo "<p style='color: green;'>Success! Admin user '{$email}' updated.</p>";
    } else {
        // Create new user
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'admin', 'active')");
        $stmt->execute(['Admin', $email, $hash]);
        echo "<p style='color: green;'>Success! Admin user '{$email}' created.</p>";
    }

    echo "<p><strong>Email:</strong> {$email}<br><strong>Password:</strong> {$password}</p>";
    echo "<p><a href='auth/login.php'>Go to Login</a></p>";
    echo "<p style='color: red;'>IMPORTANT: Delete this file (reset_admin.php) from your server after successful login!</p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>