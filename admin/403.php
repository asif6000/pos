<?php
/**
 * POS System - Access Denied (403)
 * Shown when the logged-in user's plan does not grant a module,
 * or when their subscription has expired.
 */
require_once '../config/db.php';
startSecureSession();

// Try to detect whether this is a plan issue or an expired subscription
$reason = 'plan'; // default
if (isLoggedIn() && !isSuperAdmin()) {
    $modules = getUserPlanModules();
    if ($modules === null) {
        $reason = 'no_subscription';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #fce7f3;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            padding: 20px;
        }
        .box {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(0,0,0,.08);
            max-width: 480px;
            width: 100%;
            text-align: center;
            padding: 3rem 2.5rem;
        }
        .icon { font-size: 3.5rem; color: #f43f5e; margin-bottom: 1.2rem; line-height: 1; }
        h1 { margin: 0 0 .5rem; color: #111827; font-size: 1.7rem; font-weight: 700; }
        p { color: #6b7280; margin: 0 0 1.75rem; font-size: .95rem; line-height: 1.65; }
        .btn {
            display: inline-block;
            background: #f43f5e;
            color: #fff;
            text-decoration: none;
            padding: .7rem 1.5rem;
            border-radius: 30px;
            font-weight: 600;
            font-size: .9rem;
            transition: background .2s;
            margin: .3rem;
        }
        .btn:hover { background: #e11d48; }
        .btn.secondary { background: #64748b; }
        .btn.secondary:hover { background: #475569; }
        .badge {
            display: inline-block;
            background: #fee2e2;
            color: #dc2626;
            font-size: .75rem;
            font-weight: 700;
            padding: .25rem .75rem;
            border-radius: 999px;
            margin-bottom: 1.25rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>

        <?php if ($reason === 'no_subscription'): ?>
            <div class="badge">No Active Subscription</div>
            <h1>Subscription Required</h1>
            <p>You don't have an active subscription plan. Please purchase or renew a plan to access this feature. Your data is safe and will be available once you reactivate.</p>
            <a class="btn" href="subscription.php">View Subscription Plans</a>
            <a class="btn secondary" href="dashboard.php">Go to Dashboard</a>
        <?php else: ?>
            <div class="badge">Access Denied</div>
            <h1>Feature Not Available</h1>
            <p>This module is not included in your current plan. Please upgrade your subscription to unlock this feature, or contact your administrator.</p>
            <a class="btn" href="subscription.php">Upgrade Plan</a>
            <a class="btn secondary" href="dashboard.php">Go to Dashboard</a>
        <?php endif; ?>

        <?php if (isLoggedIn()): ?>
            <br>
            <a class="btn secondary" style="margin-top:.5rem;" href="../logout.php">Logout</a>
        <?php else: ?>
            <br>
            <a class="btn secondary" style="margin-top:.5rem;" href="../auth/login.php">Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
