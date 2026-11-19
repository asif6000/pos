<?php
/**
 * POS System - Forgot password
 *
 * The web half of a reset flow whose service half has been built for a while:
 * authPasswordForgot() rate limits, mints and sends, /api/auth/password/forgot.php
 * has used it from the app since the day it was written, and this page is the only
 * place a shopkeeper who is standing at a browser can reach it. Until now the
 * "Forgot password?" link on the login page pointed at "#" - the feature existed
 * and nothing on the web could start it.
 *
 * The link travels by SMS to the number stored on the account, because this
 * installation has no mail transport at all. That is said on the page rather than
 * left to be discovered by an SMS that never arrives: a person who waits for an
 * email they were never going to get reports it as "reset does not work", and the
 * report does not say what was missing.
 *
 * The answer is deliberately the same sentence whether or not the identifier names
 * an account - the trade authPasswordForgot() documents. What it does not have to
 * hide is a system with no gateway configured, because that is about the
 * installation and not about the person typing.
 */

require_once '../config/db.php';
require_once '../config/auth_password.php';
startSecureSession();

// Somebody already signed in does not need a reset link, and holding one open for
// them would let a shared computer change a password on an account that is not the
// one in use here.
if (isLoggedIn()) {
    redirect('../index.php');
}

$db = getDB();
$error = '';
$notice = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trimmed, not sanitized: this is a lookup key (an email or a phone number)
    // that only ever goes into a prepared statement, and sanitize() would rewrite
    // characters in an address somebody typed correctly.
    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if ($identifier === '') {
        $error = 'Apnar email ba mobile number din.';
    } else {
        $res = authPasswordForgot($db, $identifier, array('app' => 'Smart POS'));
        if ($res['ok']) {
            $notice = 'Jodi ei identifier e kono account thake, tar mobile number e reset link '
                . 'pathano hoise. Link ta ' . max(1, (int)round(PASSWORD_RESET_TTL_SECONDS / 60))
                . ' minute cholbe ar ekbar-i kaj korbe.';
        } else {
            $error = $res['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password - POS System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo.png'), ENT_QUOTES, 'UTF-8'); ?>">
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

        .auth-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            width: 100%;
            max-width: 440px;
            padding: 2.4rem 2.2rem;
            box-sizing: border-box;
        }

        .auth-card h2 {
            font-size: 1.5rem;
            color: #374151;
            margin: 0 0 .4rem 0;
            font-weight: 700;
        }

        .auth-lede {
            font-size: .88rem;
            color: #6b7280;
            line-height: 1.55;
            margin: 0 0 1.4rem 0;
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
            font-size: .92rem;
            color: #374151;
            box-sizing: border-box;
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
            font-size: .88rem;
        }

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
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
        }

        .btn-primary:hover { background-color: #e11d48; }

        .alert-danger,
        .alert-notice {
            padding: 11px;
            border-radius: 8px;
            margin-bottom: 1.1rem;
            font-size: .88rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
        }

        .alert-danger { background: #fee2e2; color: #ef4444; }
        .alert-notice { background: #dcfce7; color: #166534; }
        .alert-notice i, .alert-danger i { margin-top: 3px; }
    </style>
</head>

<body>
    <div class="auth-card">
        <h2>Forgot password?</h2>
        <p class="auth-lede">
            Account er mobile number e ekta reset link jabe (SMS diye). Ei system e email
            pathanor byabostha nei, tai mail e kichu ashbe na.
        </p>

        <?php if ($error): ?>
            <div class="alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="alert-notice">
                <i class="fas fa-paper-plane"></i>
                <span><?php echo $notice; ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <i class="fas fa-envelope form-icon"></i>
                <input type="text" name="identifier" class="form-control"
                    placeholder="Email ba mobile number" autocomplete="username" required
                    value="<?php echo htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <button type="submit" class="btn-primary">Send reset link</button>
        </form>
    </div>
</body>

</html>
