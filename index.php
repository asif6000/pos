<?php
/**
 * POS System - Main Entry Point
 * Redirects to appropriate page based on login status and role
 */

require_once 'config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('landing.php');
}

$user = getCurrentUser();

if (isSuperAdmin()) {
    redirect('admin/dashboard.php');
} elseif ($user['role'] === 'admin') {
    redirect('admin/dashboard.php');
} elseif ($user['role'] === 'staff') {
    redirect('staff/dashboard.php');
} else {
    redirect('admin/dashboard.php');
}
?>