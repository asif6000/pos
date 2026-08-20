<?php
/**
 * POS System - Registration Page
 * Handles new user registration
 */

require_once '../config/db.php';
startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('../index.php');
}

// Registration is always open for admin accounts

$error = '';
$success = '';

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $business_name = sanitize($_POST['business_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($name) || empty($email) || empty($business_name) || empty($password)) {
        $error = 'All fields are required, including Business Name.';
    } elseif (strlen($name) < 2) {
        $error = 'Name must be at least 2 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $db = getDB();

            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'Email address already registered.';
            } else {
                $db->beginTransaction();

                // Create user (omit subscription columns for database compatibility)
                // Super admin: role='admin', owner_id = NULL, store_id = NULL (full access)
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    "INSERT INTO users (name, email, password, role, status) 
                     VALUES (?, ?, ?, 'admin', 'active')"
                );
                $stmt->execute([$name, $email, $hashedPassword]);

                $ownerId = $db->lastInsertId();

                // Create store owned by the new super admin
                $stmtStore = $db->prepare("INSERT INTO stores (name, status, owner_id) VALUES (?, 'active', ?)");
                $stmtStore->execute([$business_name, $ownerId]);
                $storeId = $db->lastInsertId();

                $db->commit();

                // Set success message and redirect to login
                setFlash('success', 'Account created successfully! Please sign in with your credentials.');
                redirect('login.php');
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'An error occurred. Please try again: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fce7f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }

        .auth-wrapper {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            display: flex;
            width: 960px;
            max-width: 100%;
            min-height: 600px;
            overflow: hidden;
        }

        /* Left Side - Illustration */
        .auth-illustration {
            flex: 1;
            background-color: #ffffff;
            position: relative;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: hidden;
            border-right: 1px solid #f3f4f6;
        }

        /* Memphis Abstract Shapes */
        .shape-yellow {
            position: absolute;
            top: 0; right: 0;
            width: 50%; height: 100%;
            background-color: #fde047;
            clip-path: polygon(100% 0, 100% 100%, 15% 100%, 60% 40%, 30% 0);
            z-index: 1;
        }

        .shape-blue {
            position: absolute;
            bottom: -50px; left: -50px;
            width: 250px; height: 250px;
            background-color: #93c5fd;
            border-radius: 50%;
            z-index: 1;
        }

        .shape-pink {
            position: absolute;
            bottom: -20px; right: 20px;
            width: 250px; height: 200px;
            background-color: #f43f5e;
            border-radius: 100px 120px 80px 100px;
            z-index: 2;
            transform: rotate(-15deg);
        }

        .decor-dots {
            position: absolute;
            top: 20px; left: 20px;
            font-size: 2rem;
            color: #d1d5db;
            letter-spacing: 2px;
            z-index: 3;
        }

        .decor-circle {
            position: absolute;
            top: 15%; left: 30%;
            width: 15px; height: 15px;
            background-color: #f43f5e;
            border-radius: 50%;
            z-index: 3;
        }

        .decor-line {
            position: absolute;
            bottom: 30%; left: 30%;
            width: 40px; height: 4px;
            background-color: #374151;
            z-index: 3;
        }

        .illustration-content {
            position: relative;
            z-index: 10;
            background: rgba(255, 255, 255, 0.65);
            padding: 22px;
            border-radius: 16px;
            backdrop-filter: blur(5px);
            margin-bottom: 20px;
            max-width: 85%;
        }

        .illustration-content h1 {
            font-size: 2rem;
            color: #374151;
            margin: 0 0 0.6rem 0;
            font-weight: 700;
        }

        .illustration-content p {
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.5;
            margin: 0;
        }

        .illustration-features {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 16px;
            max-width: 85%;
        }

        .illus-feature {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.55);
            backdrop-filter: blur(4px);
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
        }

        .illus-feature i {
            color: #f43f5e;
            font-size: 0.9rem;
        }

        /* Right Side - Form */
        .auth-form-container {
            flex: 1.1;
            padding: 2.8rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .auth-form-container h2 {
            font-size: 1.7rem;
            color: #374151;
            margin: 0 0 1.5rem 0;
            font-weight: 700;
        }

        .form-group {
            margin-bottom: 1rem;
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 11px 12px 11px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.92rem;
            color: #374151;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #f9fafb;
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #f43f5e;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.1);
        }

        .form-control::placeholder { color: #9ca3af; }

        .form-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 0.88rem;
        }

        .free-badge {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .free-badge i { margin-top: 2px; font-size: 0.85rem; }

        .btn-primary {
            width: 100%;
            background-color: #f43f5e;
            color: white;
            border: none;
            border-radius: 30px;
            padding: 13px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
        }

        .btn-primary:hover { background-color: #e11d48; }
        .btn-primary:active { transform: scale(0.98); }

        .auth-footer {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .auth-footer a {
            color: #f43f5e;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer a:hover { text-decoration: underline; }

        .alert-danger {
            background: #fee2e2;
            color: #ef4444;
            padding: 11px;
            border-radius: 8px;
            margin-bottom: 1.2rem;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .auth-wrapper { flex-direction: column; }
            .auth-illustration { display: none; }
            .auth-form-container { padding: 2rem; }
        }
    </style>
</head>

<body>
    <div class="auth-wrapper">
        <!-- Left Side: Abstract Illustration -->
        <div class="auth-illustration">
            <div class="shape-yellow"></div>
            <div class="shape-blue"></div>
            <div class="shape-pink"></div>
            <div class="decor-dots">...</div>
            <div class="decor-circle"></div>
            <div class="decor-line"></div>

            <div class="illustration-content">
                <h1>Join us today!</h1>
                <p>Create your free account and start managing your business smarter.</p>
            </div>

            <div class="illustration-features">
                <div class="illus-feature">
                    <i class="fas fa-check-circle"></i> Free forever — no subscription
                </div>
                <div class="illus-feature">
                    <i class="fas fa-store"></i> Unlimited store branches
                </div>
                <div class="illus-feature">
                    <i class="fas fa-boxes"></i> Full inventory & sales management
                </div>
            </div>
        </div>

        <!-- Right Side: Form -->
        <div class="auth-form-container">
            <h2>Create Account</h2>

            <?php if ($error): ?>
                <div class="alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <i class="fas fa-user form-icon"></i>
                    <input type="text" name="name" class="form-control" placeholder="Full Name"
                        required value="<?php echo isset($_POST['name']) ? sanitize($_POST['name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-store form-icon"></i>
                    <input type="text" name="business_name" class="form-control" placeholder="Business / Store Name"
                        required value="<?php echo isset($_POST['business_name']) ? sanitize($_POST['business_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-envelope form-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="Email Address"
                        required value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="password" class="form-control"
                        placeholder="Password (min 6 characters)" required>
                </div>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="confirm_password" class="form-control"
                        placeholder="Confirm Password" required>
                </div>

                <div class="free-badge">
                    <i class="fas fa-gift"></i>
                    <span><strong>Free Account</strong> — Get immediate access to all POS, inventory, and store features.</span>
                </div>

                <button type="submit" class="btn-primary">
                    Create Account
                </button>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="login.php">Sign in here</a>
            </div>
        </div>
    </div>

    <!-- Powered by -->
    <div style="position: absolute; top: 30px; right: 50px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; font-family: 'Inter', sans-serif; pointer-events: none;">
        <span style="color: #9ca3af; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 3px;">Powered By</span>
        <img src="../assets/img/ava_logo.png" alt="AVA IT Solution" style="height: 40px; pointer-events: auto;">
    </div>
</body>

</html>