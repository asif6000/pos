<?php
/**
 * POS System - Reset password with a link that was texted
 *
 * This is where authPasswordResetUrl() sends the SMS, so it is the page that made
 * the whole forgotten-password flow reachable from a phone: without it a person
 * who tapped a reset link got a 404 in the middle of the one thing they had to
 * have help with.
 *
 * It does not sign anybody in. Changing a password because a link landed on your
 * handset is not proof that you should now have a session on it - the same reason
 * /api/auth/password/reset.php returns a message rather than a token. A successful
 * change also revokes the account's other sessions, which is what authPasswordSet()
 * has always done, so a link that was read by somebody else cannot be used again and
 * cannot leave a device signed in behind it.
 *
 * The token is looked up by its hash inside the service, so nothing here compares
 * one by hand and nothing here prints one: it is a bearer credential, and a page
 * that echoes it back into an error message would put it in browser history twice.
 */

require_once '../config/db.php';
require_once '../config/auth_password.php';
startSecureSession();

$db = getDB();
$error = '';
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));

// A reset token is 64 hex characters or it is a mistake in the link. Checking the
// shape here - and only the shape - keeps a truncated link from being charged
// against the account's five guesses per token before anybody has typed anything.
$linkUsable = (bool)preg_match('/^[0-9a-f]{64}$/i', $token);

if (!$linkUsable) {
    $error = 'Ei link ta kaj korche na: link ta kete geche, naki agei use hoye geche. '
        . 'Notun kore "Forgot password" din.';
}

if ($linkUsable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if ($confirm !== $password) {
        $error = 'Duibar ekui password likhun.';
    } else {
        $res = authPasswordResetConsume($db, $token, $password);
        if ($res['ok']) {
            setFlash('success', 'Password change hoise. Notun password diye sign in korun.');
            redirect('login.php');
        }
        $error = $res['error'];
        // The link is gone whether the answer was right or wrong: either it was
        // spent, or the account's guesses ran out. Only the new-password box can
        // come back, and only while the same link is still live.
        if (!$res['retryable']) {
            $linkUsable = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set a new password - POS System</title>
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

        .alert-danger {
            background: #fee2e2;
            color: #ef4444;
            padding: 11px;
            border-radius: 8px;
            margin-bottom: 1.1rem;
            font-size: .88rem;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
        }

        .alert-danger i { margin-top: 3px; }

        .rules {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 13px;
            font-size: .8rem;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 1.1rem;
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.3rem;
            font-size: .85rem;
            color: #6b7280;
        }

        .auth-footer a {
            color: #f43f5e;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer a:hover { text-decoration: underline; }
    </style>
</head>

<body>
    <div class="auth-card">
        <?php if ($linkUsable): ?>
            <h2>Set a new password</h2>
            <p class="auth-lede">
                Ei link ta apnar mobile e asche, tai ekhon notun password dile-i hobe. Password
                change howar sathe sathe account er onno sob jayga theke logout hoye jabe.
            </p>

            <?php if ($error): ?>
                <div class="alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <div class="rules">
                Komyopakkhe <?php echo AUTH_PASSWORD_MIN_LENGTH; ?> character, beshi howa
                <?php echo AUTH_PASSWORD_MAX_LENGTH; ?> porjonto.
            </div>

            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="password" class="form-control"
                        placeholder="Notun password" autocomplete="new-password" required
                        minlength="<?php echo AUTH_PASSWORD_MIN_LENGTH; ?>"
                        maxlength="<?php echo AUTH_PASSWORD_MAX_LENGTH; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="confirm_password" class="form-control"
                        placeholder="Abar likhun" autocomplete="new-password" required
                        minlength="<?php echo AUTH_PASSWORD_MIN_LENGTH; ?>"
                        maxlength="<?php echo AUTH_PASSWORD_MAX_LENGTH; ?>">
                </div>

                <button type="submit" class="btn-primary">Change password</button>
            </form>

            <div class="auth-footer">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Sign in e phire jan</a>
            </div>
        <?php else: ?>
            <h2>Link ta cholche na</h2>

            <div class="alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo $error; ?></span>
            </div>

            <p class="auth-lede">
                Ekta link only ekbar ar <?php echo max(1, (int)round(PASSWORD_RESET_TTL_SECONDS / 60)); ?>
                minute cholbe. Notun ekta link er jonno niche er button din.
            </p>

            <a href="forgot-password.php" class="btn-primary"
                style="display:block;text-align:center;text-decoration:none;">Notun link chokun</a>

            <div class="auth-footer">
                <a href="login.php">Sign in page</a>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>
