<?php
/**
 * Admin Login Page - Modern Redesign
 * Only for the SaaS super-admin (role = 'admin', owner_id IS NULL).
 * Tenant admins and users must use /auth/login.php instead.
 */

require_once '../config/db.php';
startSecureSession();

if (isLoggedIn()) {
    $u = getCurrentUser();
    if (isSuperAdmin()) {
        redirect('dashboard.php');
    }
    redirect('../auth/login.php');
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
                 FROM users
                 WHERE email = ? AND role = 'admin' AND owner_id IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $isTrueSuperAdmin = empty($user['owner_id']);

                if (!$isTrueSuperAdmin) {
                    $error = 'এই page শুধু Super Admin-এর জন্য। Business account হলে <a href="../auth/login.php" style="color:#818cf8;">User Login</a> ব্যবহার করুন।';
                } elseif ($user['status'] !== 'active') {
                    $error = 'আপনার account inactive।';
                } else {
                    $_SESSION['user_id']       = $user['id'];
                    $_SESSION['user_name']     = $user['name'];
                    $_SESSION['user_email']    = $user['email'];
                    $_SESSION['user_role']     = $user['role'];
                    $_SESSION['store_id']      = $user['store_id'];
                    $_SESSION['owner_id']      = null;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_type']    = 'admin';

                    redirect('dashboard.php');
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
    <title>Admin Login — POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #6366f1;
            --accent: #8b5cf6;
            --bg: #0f172a;
        }

        @keyframes float { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-10px) } }
        @keyframes spin-slow { from { transform: rotate(0deg) } to { transform: rotate(360deg) } }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(30px) } to { opacity: 1; transform: translateY(0) } }
        @keyframes gradient-shift { 0% { background-position: 0% 50% } 50% { background-position: 100% 50% } 100% { background-position: 0% 50% } }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(99,102,241,0.4); }
            70% { box-shadow: 0 0 0 20px rgba(99,102,241,0); }
            100% { box-shadow: 0 0 0 0 rgba(99,102,241,0); }
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Hind Siliguri', sans-serif;
            background: var(--bg);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Animated gradient background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                linear-gradient(135deg, #0f172a 0%, #1e1b4b 30%, #312e81 55%, #1e1b4b 80%, #0f172a 100%);
            background-size: 200% 200%;
            animation: gradient-shift 15s ease infinite;
            z-index: -2;
        }

        /* Floating orbs */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(40px);
            z-index: -1;
            opacity: 0.5;
            animation: float 8s ease-in-out infinite;
            background: radial-gradient(circle, rgba(99,102,241,0.4), transparent 70%);
        }

        .orb-1 { width: 400px; height: 400px; top: -100px; left: -100px; }
        .orb-2 { width: 350px; height: 350px; bottom: -80px; right: -80px; animation-delay: 2s; background: radial-gradient(circle, rgba(139,92,246,0.35), transparent 70%); }
        .orb-3 { width: 250px; height: 250px; top: 50%; right: 15%; animation-delay: 4s; background: radial-gradient(circle, rgba(34,211,238,0.2), transparent 70%); }

        /* Grid pattern overlay */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(99,102,241,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99,102,241,0.04) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: -1;
            pointer-events: none;
        }

        .wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 460px;
            animation: fadeUp 0.7s ease forwards;
        }

        /* Card */
        .card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(30px);
            -webkit-backdrop-filter: blur(30px);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 24px;
            padding: 2.8rem 2.5rem 2rem;
            box-shadow: 0 30px 60px rgba(0,0,0,0.5);
            position: relative;
        }

        /* Gradient border top */
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 40px; right: 40px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent), #22d3ee, var(--primary));
            background-size: 300% 100%;
            animation: gradient-shift 3s ease infinite;
            border-radius: 0 0 3px 3px;
        }

        .card h2 {
            margin: 0 0 0.3rem;
            color: #f1f5f9;
            font-size: 1.7rem;
            font-weight: 800;
        }
        .card .subtitle {
            color: #94a3b8;
            font-size: 0.88rem;
            margin-bottom: 2rem;
            line-height: 1.5;
        }

        /* Alert */
        .alert {
            background: rgba(239,68,68,0.15);
            border: 1px solid rgba(239,68,68,0.35);
            color: #fca5a5;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
            animation: fadeUp 0.4s ease;
        }
        .alert a { color: #f43f5e; }

        /* Form fields */
        .form-group {
            margin-bottom: 1.3rem;
            position: relative;
        }
        .form-label {
            display: block;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.5rem;
        }
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6366f1;
            font-size: 0.9rem;
            pointer-events: none;
            transition: 0.3s;
        }
        .form-control {
            width: 100%;
            padding: 14px 14px 14px 44px;
            background: rgba(15,23,42,0.6);
            border: 1.5px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            color: #f1f5f9;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.3s, box-shadow 0.3s, background 0.3s;
            outline: none;
        }
        .form-control::placeholder { color: #475569; }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.2);
            background: rgba(15,23,42,0.9);
        }
        .form-control:focus + .input-icon,
        .input-wrap:focus-within .input-icon { color: #a5b4fc; }

        /* Submit button */
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            background-size: 200% 200%;
            animation: gradient-shift 3s ease infinite;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(79,70,229,0.4);
            position: relative;
            overflow: hidden;
        }
        .btn-submit::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        .btn-submit:hover::after { left: 100%; }
        .btn-submit:hover  { transform: translateY(-2px); box-shadow: 0 12px 35px rgba(79,70,229,0.5); }
        .btn-submit:active { transform: scale(0.98); }

        /* Footer link */
        .footer-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.82rem;
            color: #64748b;
        }
        .footer-link a {
            color: #a5b4fc;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .footer-link a:hover { color: #c7d2fe; text-decoration: underline; }

        /* Security note */
        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 1.5rem;
            font-size: 0.72rem;
            color: #475569;
        }
        .security-note i { color: #22c55e; font-size: 0.8rem; }

        /* Powered by */
        .powered {
            position: fixed;
            top: 24px;
            right: 36px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            z-index: 10;
            animation: fadeUp 0.8s ease 0.3s both;
        }
        .powered span {
            color: #64748b;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        .powered img {
            height: 38px;
            filter: drop-shadow(0 0 10px rgba(99,102,241,0.3));
            transition: transform 0.3s;
        }
        .powered img:hover { transform: scale(1.05); }

        /* Back to home */
        .back-home {
            position: fixed;
            top: 28px;
            left: 36px;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            animation: fadeUp 0.8s ease 0.5s both;
        }
        .back-home i {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        .back-home:hover { color: #fff; }
        .back-home:hover i { transform: translateX(-3px); background: rgba(99,102,241,0.2); border-color: rgba(99,102,241,0.4); }

        @media (max-width: 600px) {
            .card { padding: 2.2rem 1.5rem 1.8rem; }
            .powered { right: 20px; }
            .back-home { left: 20px; }
            .powered span { display: none; }
            .back-home span { display: none; }
        }
    </style>
</head>
<body>

<!-- Animated background orbs -->
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<div class="powered">
    <span>Powered By</span>
    <img src="../assets/img/ava_logo.png" alt="AVA IT Solution">
</div>

<a href="../landing.php" class="back-home">
    <i class="fas fa-arrow-left"></i> <span>Back to Home</span>
</a>

<div class="wrapper">

    <div class="card">
        <h2>Sign In</h2>
        <p class="subtitle">এই পেজটি শুধুমাত্র Super Admin-এর জন্য। Business owner হলে User Login ব্যবহার করুন।</p>

        <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-triangle" style="margin-top:2px;flex-shrink:0;"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="form-group">
                <label class="form-label">Admin Email</label>
                <div class="input-wrap">
                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        placeholder="admin@example.com"
                        required
                        value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>"
                        autocomplete="username">
                    <i class="fas fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-wrap">
                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password">
                    <i class="fas fa-lock input-icon"></i>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> Admin Login
            </button>
        </form>

        <div class="footer-link">
            Business owner? <a href="../auth/login.php">User Login এখানে</a>
        </div>

        <div class="security-note">
            <i class="fas fa-lock"></i> Secure admin portal — unauthorized access is prohibited
        </div>
    </div>

</div>

</body>
</html>
