<?php
/**
 * Permission Version Polling Endpoint
 *
 * Called by the frontend every 30 seconds (via app.js) to detect when the
 * SaaS admin has changed a plan or role permission.
 *
 * Returns:
 *   {
 *     "authenticated": true|false,
 *     "permissions_version":  "N",   // bumped when role_permissions change
 *     "plan_modules_version": "N",   // bumped when subscription_plans.modules change
 *     "plan_name":  "Pro",           // current active plan name (or null)
 *     "sub_status": "active"         // subscription status
 *   }
 *
 * The JS stores the versions it received at page load.  When either version
 * increments the browser shows a non-intrusive toast and auto-reloads after
 * 3 seconds so the user gets the updated sidebar / routes / access.
 *
 * Security: read-only, no side effects, no sensitive data exposed.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['authenticated' => false]);
    exit;
}

// Fetch both version counters in one query
$versions = getPermissionVersions();

// Resolve current plan name + subscription status for the user
$planName  = null;
$subStatus = 'none';

if (!isSuperAdmin()) {
    $user    = getCurrentUser();
    $ownerId = !empty($user['owner_id']) ? (int)$user['owner_id'] : (int)$user['id'];
    if ($ownerId) {
        try {
            $db   = getDB();
            $stmt = $db->prepare("
                SELECT s.status, sp.name AS plan_name
                FROM subscriptions s
                JOIN subscription_plans sp ON sp.id = s.plan_id
                WHERE s.owner_id = ?
                ORDER BY s.id DESC
                LIMIT 1
            ");
            $stmt->execute([$ownerId]);
            $row = $stmt->fetch();
            if ($row) {
                $planName  = $row['plan_name'];
                $subStatus = $row['status'];
            }
        } catch (Exception $e) { /* non-critical */ }
    }
} else {
    $planName  = 'Super Admin';
    $subStatus = 'active';
}

echo json_encode([
    'authenticated'         => true,
    'permissions_version'   => $versions['permissions_version'],
    'plan_modules_version'  => $versions['plan_modules_version'],
    'plan_name'             => $planName,
    'sub_status'            => $subStatus,
]);
