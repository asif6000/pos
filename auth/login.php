<?php
/**
 * POS System - Login Page
 * Handles user authentication
 */

require_once '../config/db.php';
startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        redirect('../admin/dashboard.php');
    } elseif ($user['role'] === 'staff') {
        redirect('../staff/dashboard.php');
    }
    redirect('../cashier/pos.php');
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, name, email, password, role, status, store_id, owner_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'active') {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['store_id'] = $user['store_id'];
                    $_SESSION['owner_id'] = $user['owner_id'];
                    $_SESSION['last_activity'] = time();

                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        redirect('../admin/dashboard.php');
                    } elseif ($user['role'] === 'staff') {
                        redirect('../staff/dashboard.php');
                    } else {
                        redirect('../cashier/pos.php');
                    }
                } else {
                    $error = 'Your account is inactive. Please contact administrator.';
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fce7f3; /* Soft pink background */
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
            width: 900px;
            max-width: 100%;
            min-height: 550px;
            overflow: hidden;
        }

        /* Left Side - Illustration */
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

        /* Memphis Abstract Shapes */
        .shape-yellow {
            position: absolute;
            top: 0;
            right: 0;
            width: 50%;
            height: 100%;
            background-color: #fde047;
            clip-path: polygon(100% 0, 100% 100%, 15% 100%, 60% 40%, 30% 0);
            z-index: 1;
        }

        .shape-blue {
            position: absolute;
            bottom: -50px;
            left: -50px;
            width: 250px;
            height: 250px;
            background-color: #93c5fd;
            border-radius: 50%;
            z-index: 1;
        }

        .shape-pink {
            position: absolute;
            bottom: -20px;
            right: 20px;
            width: 250px;
            height: 200px;
            background-color: #f43f5e;
            border-radius: 100px 120px 80px 100px;
            z-index: 2;
            transform: rotate(-15deg);
        }

        /* Decor elements */
        .decor-dots {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 2rem;
            color: #d1d5db;
            letter-spacing: 2px;
            z-index: 3;
        }

        .decor-circle {
            position: absolute;
            top: 15%;
            left: 30%;
            width: 15px;
            height: 15px;
            background-color: #f43f5e;
            border-radius: 50%;
            z-index: 3;
        }

        .decor-line {
            position: absolute;
            bottom: 30%;
            left: 30%;
            width: 40px;
            height: 4px;
            background-color: #374151;
            z-index: 3;
        }

        .illustration-content {
            position: relative;
            z-index: 10;
            background: rgba(255, 255, 255, 0.6);
            padding: 20px;
            border-radius: 16px;
            backdrop-filter: blur(5px);
            margin-bottom: 20px;
            max-width: 85%;
        }

        .illustration-content h1 {
            font-size: 2.2rem;
            color: #374151;
            margin: 0 0 0.5rem 0;
            font-weight: 700;
        }

        .illustration-content p {
            color: #6b7280;
            font-size: 1rem;
            line-height: 1.5;
            margin: 0;
        }

        /* Right Side - Form */
        .auth-form-container {
            flex: 0.9;
            padding: 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
            z-index: 10;
        }

        .auth-form-container h2 {
            font-size: 1.8rem;
            color: #374151;
            margin: 0 0 2rem 0;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.2rem;
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
            color: #374151;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
            background-color: #f9fafb;
        }

        .form-control:focus {
            outline: none;
            border-color: #f43f5e;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.1);
        }
        
        .form-control::placeholder {
            color: #9ca3af;
        }

        .form-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 0.95rem;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            font-size: 0.85rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6b7280;
            cursor: pointer;
        }

        .forgot-password {
            color: #6b7280;
            text-decoration: none;
        }

        .forgot-password:hover {
            color: #f43f5e;
        }

        .btn-primary {
            width: 100%;
            background-color: #f43f5e; /* Pink button */
            color: white;
            border: none;
            border-radius: 30px;
            padding: 14px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-primary:hover {
            background-color: #e11d48;
        }
        
        .btn-primary:active {
            transform: scale(0.98);
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: #6b7280;
        }

        .auth-footer a {
            color: #f43f5e;
            text-decoration: none;
            font-weight: 600;
        }
        
        .auth-footer a:hover {
            text-decoration: underline;
        }

        .alert-danger {
            background: #fee2e2;
            color: #ef4444;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .auth-wrapper {
                flex-direction: column;
            }
            .auth-illustration {
                display: none; /* Hide illustration on mobile */
            }
            .auth-form-container {
                padding: 2.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="auth-wrapper">
        <!-- Left Side: Abstract Illustration -->
        <div class="auth-illustration">
            <!-- Background Shapes -->
            <div class="shape-yellow"></div>
            <div class="shape-blue"></div>
            <div class="shape-pink"></div>
            
            <!-- Small decors -->
            <div class="decor-dots">...</div>
            <div class="decor-circle"></div>
            <div class="decor-line"></div>

            <div class="illustration-content">
                <h1>Welcome back!</h1>
                <p>You can sign in to access with your existing account.</p>
            </div>
        </div>

        <!-- Right Side: Form -->
        <div class="auth-form-container">
            <h2>Sign In</h2>

            <?php if ($error): ?>
                <div class="alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <i class="fas fa-user form-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="Username or email"
                        required value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="password" class="form-control"
                        placeholder="Password" required>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox"> Remember me
                    </label>
                    <a href="#" class="forgot-password">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary">
                    Sign In
                </button>
            </form>

            <div class="auth-footer">
                New here? <a href="register.php">Create an Account</a>
            </div>
        </div>
    </div>

    <div style="position: absolute; top: 30px; right: 50px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; font-family: 'Inter', sans-serif; pointer-events: none;">
        <span style="color: #9ca3af; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 2px;">Powered By</span>
        <img src="../assets/img/ava_logo.png" alt="AVA IT Solution" style="height: 45px; margin-top: 5px; pointer-events: auto;">
    </div>
</body>

</html>