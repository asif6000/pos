<?php
/**
 * POS System - Main Entry Point
 * Redirects to appropriate page based on login status and role
 */

require_once 'config/db.php';
startSecureSession();

// Check if user is logged in
if (!isLoggedIn()) {
    redirect('landing.php');
}

// Redirect based on role
$user = getCurrentUser();
if ($user['role'] === 'admin') {
    redirect('admin/dashboard.php');
} elseif ($user['role'] === 'staff') {
    redirect('staff/dashboard.php');
} else {
    redirect('cashier/pos.php');
}
?>