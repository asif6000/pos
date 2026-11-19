<?php
/**
 * POS System - Lottery Sales
 *
 * Lottery is a payment method, not a separate kind of bill, so this list is the
 * normal Sales list with its payment filter pinned. One page keeps the tenant
 * scope, KPIs, export and invoice actions in step with sales.php - a second
 * copy would drift the moment one of them gained a filter.
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('sales')) {
    setFlash('danger', 'Access denied. You do not have permission to view Sales.');
    redirect('dashboard.php');
}

header('Location: sales.php?payment=lottery');
exit;
