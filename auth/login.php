<?php
/**
 * User / Tenant Login Page
 * For business owners (role='user'), cashiers, and staff.
 * Super-admins must use /admin/login.php instead.
 */

require_once '../config/db.php';
startSecureSession();

// Already logged in?
if (isLoggedIn()) {
    $u = getCurrentUser();
    if (isSuperAdmin()) {
        // Super-admin accidentally hit this page → send to admin portal
        redirect('../admin/dashboard.php');
    }
    if ($u['role'] === 'admin') {
        redirect('../admin/dashboard.php');
    }
    if ($u['role'] === 'staff') {
        redirect('../staff/dashboard.php');
    }
    redirect('../admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email এবং Password দিন।';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT id, name, email, password, role, status, store_id, owner_id
                 FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {

                // Block true super-admin from this portal
                $isTrueSuperAdmin = ($user['role'] === 'admin') && empty($user['owner_id']);
                if ($isTrueSuperAdmin) {
                    $error = 'Super Admin login করতে <a href="../admin/login.php" style="color:#f43f5e;font-weight:600;">Admin Portal</a> ব্যবহার করুন।';
                } elseif ($user['status'] !== 'active' && $user['role'] !== 'staff') {
                    $error = 'আপনার account inactive। Administrator-এর সাথে যোগাযোগ করুন।';
                } else {
                    // ---------- Set session ----------
                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['user_name']     = $user['name'];
                    $_SESSION['user_email']    = $user['email'];
                    $_SESSION['user_role']     = $user['role'];
                    $_SESSION['store_id']      = $user['store_id'];
                    // owner_id: for tenant admins this equals their own id
                    // For super admin it is NULL (but they can't reach this portal)
                    $_SESSION['owner_id']      = $user['owner_id'] ?: $user['id'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_type']    = 'user';

                    $ownerId = $user['owner_id'] ?: $user['id'];

                    // Check subscription state
                    $isPending = false;
                    $hasActive = false;
                    try {
                        $subStmt = $db->prepare(
                            "SELECT status, end_date FROM subscriptions
                             WHERE owner_id = ?
                             ORDER BY id DESC LIMIT 1"
                        );
                        $subStmt->execute([$ownerId]);
                        $sub = $subStmt->fetch();
                        if ($sub) {
                            $isPending = ($sub['status'] === 'pending');
                            $hasActive = ($sub['status'] === 'active')
                                && ($sub['end_date'] === null || $sub['end_date'] >= date('Y-m-d'));
                        }
                    } catch (Exception $e) {}

                    // ---------- Redirect ----------
                    if ($user['role'] === 'staff') {
                        // Check staff table
                        $hasStaff = false;
                        try {
                            $chk = $db->prepare("SELECT 1 FROM staff WHERE user_id = ? LIMIT 1");
                            $chk->execute([$user['id']]);
                            $hasStaff = (bool)$chk->fetch();
                        } catch (Exception $e) {}
                        redirect($hasStaff ? '../staff/dashboard.php' : '../admin/dashboard.php');
                    }

                    if ($isPending) {
                        setFlash('info', 'আপনার payment review-এ আছে। Admin approve করলে সব features পাবেন।');
                        redirect('../admin/subscription.php');
                    }

                    if ($hasActive || $user['role'] === 'admin') {
                        redirect('../admin/dashboard.php');
                    }

                    // No active subscription
                    setFlash('warning', 'আপনার কোনো active subscription নেই। একটি plan কিনুন।');
                    redirect('../admin/subscription.php');
                }
            } else {
                $error = 'Email বা Password ভুল।';
            }
        } catch (PDOException $e) {
            $error = 'একটি সমস্যা হয়েছে। আবার চেষ্টা করুন।';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Login — POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background-color: #fce7f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
        }
        .auth-wrapper {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            display: flex;
            width: 900px;
            max-width: 100%;
            min-height: 550px;
            overflow: hidden;
        }
        .auth-illustration {
            flex: 1.1;
            background-color: #ffffff;
            position: relative;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
            border-right: 1px solid #f3f4f6;
        }
        .shape-yellow {
            position: absolute; top: 0; right: 0;
            width: 50%; height: 100%;
            background-color: #fde047;
            clip-path: polygon(100% 0, 100% 100%, 15% 100%, 60% 40%, 30% 0);
            z-index: 1;
        }
        .shape-blue {
            position: absolute; bottom: -50px; left: -50px;
            width: 250px; height: 250px;
            background-color: #93c5fd; border-radius: 50%; z-index: 1;
        }
        .shape-pink {
            position: absolute; bottom: -20px; right: 20px;
            width: 250px; height: 200px;
            background-color: #f43f5e;
            border-radius: 100px 120px 80px 100px;
            z-index: 2; transform: rotate(-15deg);
        }
        .decor-dots { position: absolute; top: 20px; left: 20px; font-size: 2rem; color: #d1d5db; letter-spacing: 2px; z-index: 3; }
        .decor-circle { position: absolute; top: 15%; left: 30%; width: 15px; height: 15px; background-color: #f43f5e; border-radius: 50%; z-index: 3; }
        .decor-line { position: absolute; bottom: 30%; left: 30%; width: 40px; height: 4px; background-color: #374151; z-index: 3; }
        .illustration-content {
            position: relative; z-index: 10;
            background: rgba(255,255,255,0.6);
            padding: 20px; border-radius: 16px;
            backdrop-filter: blur(5px);
            margin-bottom: 20px; max-width: 85%;
        }
        .illustration-content h1 { font-size: 2.2rem; color: #374151; margin: 0 0 0.5rem 0; font-weight: 700; }
        .illustration-content p { color: #6b7280; font-size: 1rem; line-height: 1.5; margin: 0; }
        .auth-form-container {
            flex: 0.9; padding: 3.5rem;
            display: flex; flex-direction: column; justify-content: center;
            background: #ffffff; z-index: 10;
        }
        .auth-form-container h2 { font-size: 1.8rem; color: #374151; margin: 0 0 0.4rem 0; font-weight: 700; }
        .auth-form-container .subtitle { color: #9ca3af; font-size: 0.85rem; margin-bottom: 1.75rem; }
        .form-group { margin-bottom: 1.2rem; position: relative; }
        .form-control {
            width: 100%; padding: 12px 12px 12px 40px;
            border: 1px solid #e5e7eb; border-radius: 8px;
            font-size: 0.95rem; color: #374151;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #f9fafb; font-family: inherit;
        }
        .form-control:focus {
            outline: none; border-color: #f43f5e;
            background-color: #fff; box-shadow: 0 0 0 3px rgba(244,63,94,0.1);
        }
        .form-control::placeholder { color: #9ca3af; }
        .form-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.95rem; }
        .form-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; font-size: 0.85rem; }
        .remember-me { display: flex; align-items: center; gap: 0.5rem; color: #6b7280; cursor: pointer; }
        .btn-primary {
            width: 100%; background-color: #f43f5e; color: white; border: none;
            border-radius: 30px; padding: 14px; font-size: 1.05rem;
            font-weight: 600; cursor: pointer; transition: background-color 0.2s, transform 0.1s;
            font-family: inherit;
        }
        .btn-primary:hover { background-color: #e11d48; }
        .btn-primary:active { transform: scale(0.98); }
        .auth-footer { text-align: center; margin-top: 1.5rem; font-size: 0.85rem; color: #6b7280; }
        .auth-footer a { color: #f43f5e; text-decoration: none; font-weight: 600; }
        .auth-footer a:hover { text-decoration: underline; }
        .alert-danger {
            background: #fee2e2; color: #ef4444;
            padding: 12px; border-radius: 8px; margin-bottom: 1.5rem;
            font-size: 0.9rem; display: flex; align-items: flex-start; gap: 8px; line-height: 1.5;
        }
        .alert-danger a { color: #f43f5e; }
        @media (max-width: 768px) {
            .auth-wrapper { flex-direction: column; }
            .auth-illustration { display: none; }
            .auth-form-container { padding: 2.5rem; }
        }
        @media (max-width: 480px) {
            body { padding: 10px; }
            .auth-wrapper { border-radius: 8px; min-height: auto; }
            .auth-form-container { padding: 1.5rem; }
            .auth-form-container h2 { font-size: 1.4rem; }
            .btn-primary { padding: 12px; font-size: 0.95rem; }
        }
    </style>
</head>
<body>

<div class="auth-wrapper">
    <!-- Left: Illustration -->
    <div class="auth-illustration">
        <div class="shape-yellow"></div>
        <div class="shape-blue"></div>
        <div class="shape-pink"></div>
        <div class="decor-dots">...</div>
        <div class="decor-circle"></div>
        <div class="decor-line"></div>
        <div class="illustration-content">
            <h1>স্বাগতম!</h1>
            <p>আপনার business account দিয়ে sign in করুন এবং POS পরিচালনা করুন।</p>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="auth-form-container">
        <h2>User Login</h2>
        <p class="subtitle">Business Owner / Cashier / Staff</p>

        <?php if ($error): ?>
            <div class="alert-danger">
                <i class="fas fa-exclamation-circle" style="margin-top:2px;flex-shrink:0;"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flash = getFlash()): ?>
            <div class="alert-danger" style="background:#<?php echo $flash['type']==='info'?'e0f2fe;color:#0369a1':($flash['type']==='warning'?'fef9c3;color:#92400e':'fee2e2;color:#ef4444'); ?>;">
                <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;"></i>
                <span><?php echo htmlspecialchars($flash['message']); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-envelope form-icon"></i>
                <input type="email" name="email" class="form-control"
                    placeholder="আপনার Email"
                    required
                    value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
            </div>
            <div class="form-group">
                <i class="fas fa-lock form-icon"></i>
                <input type="password" name="password" class="form-control"
                    placeholder="Password" required>
            </div>
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox"> মনে রাখুন
                </label>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login করুন
            </button>
        </form>

        <div class="auth-footer">
            নতুন account? <a href="register.php">এখানে Register করুন</a>
        </div>
    </div>
</div>

<!-- Powered By -->
<div style="position:fixed;top:24px;right:36px;z-index:10;display:flex;flex-direction:column;align-items:flex-end;gap:3px;pointer-events:none;">
    <span style="color:#9ca3af;font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:3px;">Powered By</span>
    <img src="../assets/img/ava_logo.png" alt="AVA IT Solution" style="height:40px;pointer-events:auto;">
</div>

</body>
</html>
