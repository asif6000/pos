<?php
/**
 * POS System - Login Page
 * Handles user authentication
 */

require_once '../config/db.php';
require_once '../config/auth_core.php';
require_once '../config/auth_google.php';
require_once '../config/auth_otp.php';
startSecureSession();

// Redirect if already logged in.
//
// Permission-based, the same as the successful-login branch below. It used to
// compare the role against the literals 'admin' and 'staff' and send everyone
// else to cashier/pos.php, so an account that had already signed in and then
// reopened this page was moved somewhere different from where signing in had
// put it - and one holding no POS permission was redirected into a page that
// immediately refused it.
if (isLoggedIn()) {
    $user = getCurrentUser();
    if (staffProfileExists($user)) {
        redirect('../staff/dashboard.php');
    }
    $landing = defaultLandingPage();
    if ($landing !== null) {
        redirect('../admin/' . $landing);
    }
    redirect('../admin/dashboard.php');
}

// Google sign-in is offered only when the installation has a client ID. The
// button, the nonce it will be initialised with and the audience the server will
// check are all decided from that one value, so a page cannot present a sign-in
// method the endpoint would refuse (config/auth_google.php).
$google = googleSignInPublicConfig();
$googleNonce = $google['enabled'] ? googleSignInNonce() : '';

$error = '';
$notice = '';
// Which half of the form the page redraws itself into after a POST. A wrong code
// is not a reason to throw somebody back to the password box they just abandoned.
$activePane = 'password';

// The news a redirect left behind: "account created", "password change hoise".
// Nothing on the web read this, so both messages were set by the page that did the
// work and never shown by the page that lands here - a sign-up finished into a
// silent login form, which is how "registration niley-o login hoy na" reports start.
$flash = getFlash();

/**
 * The account or accounts a typed identifier points at: authFindUsersByIdentifier()
 * in config/auth_otp.php.
 *
 * Email first, because an address is unique in this table and one row is the
 * answer. Failing that, the text is read as a mobile number and looked for in every
 * form a number has ever been stored in - the local 01..., the 8801... the SMS
 * gateway wants, and the ten digits without the trunk 0 - because legacy rows hold
 * whatever somebody typed on the Users page, and a lookup that recognised only one
 * spelling would tell a shopkeeper their number is not on the system when it is
 * merely stored oddly.
 *
 * More than one row is a real possibility and is handled as one rather than quietly
 * taking the first: with a password it does not matter (only the right account's
 * hash will verify), but with a code it decides who gets in, so the ambiguity has to
 * be resolved by a human on the Users page.
 *
 * This page and /api/auth/login.php resolve an identifier through the same function
 * on purpose. Two copies of that rule always disagree in some corner eventually, and
 * the disagreement shows up as a person whose number works for a code and is refused
 * as "no such account" for a password.
 */

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string)($_POST['mode'] ?? 'password');
    $identifier = $_POST['email'] ?? '';
    $db = getDB();

    if ($mode === 'otp_send' || $mode === 'otp_verify') {
        $activePane = 'otp';
    }

    $pending = (isset($_SESSION['login_otp_pending']) && is_array($_SESSION['login_otp_pending']))
        ? $_SESSION['login_otp_pending'] : null;
    // The code's own life is kept in the database; this copy of it only decides
    // whether the pane asks for a code or offers to send one. Asking for a code
    // that expired ten minutes ago, in a form that will never accept it, is the
    // kind of dead end that gets reported as "OTP does not work".
    if ($pending && (time() - (int)($pending['at'] ?? 0)) > OTP_TTL_SECONDS + 30) {
        $pending = null;
        unset($_SESSION['login_otp_pending']);
    }

    if ($mode === 'otp_send') {
        unset($_SESSION['login_otp_pending']);
        $phone = otpNormalizePhone($identifier);
        $rows = $phone === '' ? array() : authFindUsersByIdentifier($db, $phone);

        if ($phone === '') {
            $error = 'OTP dite apnar mobile number ta din (01XXXXXXXXX).';
        } elseif (count($rows) === 0) {
            $error = 'Ei number kono account e nei. Admin ke bolun number ta apnar account e jog korun.';
        } elseif (count($rows) > 1) {
            $error = 'Ei number ta ekaikekta account e ache. Admin ke bolun seta thik korben, tarpor code din.';
        } elseif ($rows[0]['status'] !== 'active') {
            $error = 'Apnar account inactive. Please contact administrator.';
        } else {
            $account = $rows[0];
            $res = otpSendCode($db, otpLoginSenderOwner($db, $account), $phone, 'login',
                array('user_id' => $account['id'], 'app' => 'Smart POS'));
            if ($res['ok']) {
                $_SESSION['login_otp_pending'] = array('phone' => $phone, 'at' => time());
                $notice = 'OTP pathano hoise ' . otpMaskPhone($phone) . ' e. '
                    . round(OTP_TTL_SECONDS / 60) . ' min er moddhe code ta din.';
            } else {
                $error = $res['error'];
            }
        }
    } elseif ($mode === 'otp_verify') {
        if (!$pending) {
            $error = 'OTP request ta ar nei. Agey notun kore code din.';
        } else {
            $chk = otpConsume($db, 'login', $pending['phone'], $_POST['otp_code'] ?? '');
            if (!$chk['ok']) {
                if (!$chk['retryable']) {
                    unset($_SESSION['login_otp_pending']);
                }
                $error = $chk['error'];
            } else {
                // The number the spent code was sent to, and the account found from
                // it again here - not from whatever is in the box now. Between the
                // two posts the typed text means nothing; the answered SMS does.
                $rows = authFindUsersByIdentifier($db, $chk['phone']);
                $account = (count($rows) === 1) ? $rows[0] : null;
                if (!$account || $account['status'] !== 'active') {
                    $error = 'Code ta thik chilo, kintu ei number er account ta nite pari nai. '
                        . 'Admin ke janun.';
                } else {
                    authWebSessionFromUser($account);
                    // Answering the code IS the proof of ownership, so the number
                    // becomes verified at the moment it is first used to sign in.
                    // That is what lets a shop whose number was typed by an admin -
                    // and never checked - start using it, without a second trip
                    // through Settings.
                    otpMarkUserPhone($db, $account['id'], $chk['phone']);
                    authAudit('login', true, $account['id'], otpMaskPhone($chk['phone']),
                        'Signed in with an OTP code');
                    unset($_SESSION['login_otp_pending']);
                    redirect('../' . authWebLanding($account));
                }
            }
        }
    } elseif ($identifier === '' || (string)($_POST['password'] ?? '') === '') {
        $error = 'Please enter both email and password.';
    } else {
        try {
            // Every account the identifier could mean, then the one whose password
            // actually verifies. For an email that is one row and behaves exactly
            // as it always did; for a number shared by two rows it is the password
            // that says which account is being opened.
            $user = null;
            foreach (authFindUsersByIdentifier($db, $identifier) as $row) {
                if (password_verify((string)$_POST['password'], $row['password'])) {
                    $user = $row;
                    break;
                }
            }

            if ($user && $user['status'] === 'active') {
                // The shared session writer, so that an account signing in here
                // holds exactly the session an account signing in with Google or
                // with a code holds. The keys below were written by hand for
                // years, and every new sign-in method that copied them by eye
                // was one missed key away from a dashboard that opens and a
                // menu that refuses.
                authWebSessionFromUser($user);
                authAudit('login', true, $user['id'], $user['email'], 'Signed in with a password');

                // Where to land, decided by permission rather than by role name.
                //
                // This used to compare the role against the literals 'admin' and
                // 'staff', so a manager, an owner or any custom role fell through
                // to cashier/pos.php however much it had been granted, and a role
                // called 'staff' landed on a page that errors unless the account
                // also has a staff record. The Roles page and the menu disagreed
                // because they were reading different things.
                //
                // A real staff member still gets their own page first - it is a
                // personal view, not a menu - but only if a staff record exists.
                redirect('../' . authWebLanding($user));
            } elseif ($user) {
                authAudit('login', false, $user['id'], $user['email'], 'Inactive account');
                $error = 'Your account is inactive. Please contact administrator.';
            } else {
                authAudit('login', false, null, (string)$identifier, 'Wrong password or no such account');
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}

// The OTP pane asks for a code only while one is actually waiting to be typed, so
// a visitor who reloads the page mid-code gets the box for the code they have, and
// one who arrives an hour later gets the button that sends a new one.
$otpPending = null;
if (isset($_SESSION['login_otp_pending']) && is_array($_SESSION['login_otp_pending'])
    && (time() - (int)($_SESSION['login_otp_pending']['at'] ?? 0)) <= OTP_TTL_SECONDS + 30) {
    $otpPending = $_SESSION['login_otp_pending'];
    $activePane = 'otp';
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo.png'), ENT_QUOTES, 'UTF-8'); ?>">
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

        .alert-danger a {
            color: #b91c1c;
            font-weight: 600;
        }

        /* A send that worked: the same block as the error, in the same place, so
           the eye goes to one spot for news of either kind. */
        .alert-notice {
            background: #dcfce7;
            color: #166534;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Password and code are two ways through one door, so they are two tabs
           over one field rather than two forms competing for the same box. */
        .auth-tabs {
            display: flex;
            gap: .4rem;
            margin-bottom: 1.2rem;
        }

        .auth-tab {
            flex: 1;
            padding: .6rem .4rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            color: #6b7280;
            font-family: inherit;
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
        }

        .auth-tab.active {
            background: #fff1f2;
            border-color: #f43f5e;
            color: #e11d48;
        }

        .otp-hint {
            color: #6b7280;
            font-size: .85rem;
            margin: 0 0 1rem 0;
        }

        .btn-quiet {
            width: 100%;
            margin-top: .6rem;
            background: none;
            border: none;
            color: #6b7280;
            font-family: inherit;
            font-size: .85rem;
            text-decoration: underline;
            cursor: pointer;
        }

        /* Google sign-in ---------------------------------------------- */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: .8rem;
            margin: 1.4rem 0 1rem;
            color: #9ca3af;
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: #e5e7eb;
        }

        .google-btn {
            display: flex;
            justify-content: center;
            min-height: 40px;
        }

        /* Google draws its own button into this box; a stuck-out label keeps the
           row from collapsing to nothing while the script loads. */
        .google-btn-pending {
            font-size: .85rem;
            color: #9ca3af;
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
                <div class="alert-danger" id="authAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="alert-notice">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $notice; ?></span>
                </div>
            <?php elseif ($flash && $flash['type'] === 'success'): ?>
                <div class="alert-notice">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php elseif ($flash): ?>
                <div class="alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <div class="auth-tabs">
                <button type="button" class="auth-tab<?php echo $activePane === 'password' ? ' active' : ''; ?>"
                    data-pane="password"><i class="fas fa-lock"></i> Password</button>
                <button type="button" class="auth-tab<?php echo $activePane === 'otp' ? ' active' : ''; ?>"
                    data-pane="otp"><i class="fas fa-mobile-alt"></i> OTP code</button>
            </div>

            <form method="POST">
                <div class="form-group">
                    <i class="fas fa-user form-icon"></i>
                    <input type="text" name="email" class="form-control"
                        placeholder="Email or phone number" autocomplete="username" required
                        value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>">
                </div>

                <?php // One form, two panes. The inactive pane's inputs are disabled
                      // as well as hidden, so a submission carries only the half the
                      // visitor is actually using - and its required fields are not
                      // the ones blocking the button they pressed. ?>
                <div class="auth-pane" data-pane="password"<?php echo $activePane === 'password' ? '' : ' hidden'; ?>>
                    <div class="form-group">
                        <i class="fas fa-lock form-icon"></i>
                        <input type="password" name="password" class="form-control" placeholder="Password"
                            autocomplete="current-password" <?php echo $activePane === 'password' ? 'required' : 'disabled'; ?>>
                    </div>

                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" <?php echo $activePane === 'password' ? '' : 'disabled'; ?>> Remember me
                        </label>
                        <a href="forgot-password.php" class="forgot-password">Forgot password?</a>
                    </div>

                    <button type="submit" name="mode" value="password" class="btn-primary">
                        Sign In
                    </button>
                </div>

                <div class="auth-pane" data-pane="otp"<?php echo $activePane === 'otp' ? '' : ' hidden'; ?>>
                    <?php if ($otpPending): ?>
                        <p class="otp-hint">
                            Code geche <strong><?php echo sanitize(otpMaskPhone($otpPending['phone'])); ?></strong>
                            e. 6 digit code ta niche likhun.
                        </p>
                        <div class="form-group">
                            <i class="fas fa-key form-icon"></i>
                            <input type="text" name="otp_code" class="form-control" placeholder="6 digit code"
                                maxlength="6" inputmode="numeric" autocomplete="one-time-code" required>
                        </div>
                        <button type="submit" name="mode" value="otp_verify" class="btn-primary">
                            Verify &amp; Sign In
                        </button>
                        <button type="submit" name="mode" value="otp_send" class="btn-quiet">
                            Code ashe nai - notun OTP pathan
                        </button>
                    <?php else: ?>
                        <p class="otp-hint">
                            Upore apnar mobile number likhun (01XXXXXXXXX). Amra ekta 6 digit code pathabo,
                            seta dilei sign in hoye jaben.
                        </p>
                        <button type="submit" name="mode" value="otp_send" class="btn-primary">
                            Send OTP
                        </button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($google['enabled']): ?>
                <div class="auth-divider">or</div>
                <div class="google-btn" id="googleSignInButton">
                    <span class="google-btn-pending" id="googleSignInPending">Loading Google sign-in&hellip;</span>
                </div>
            <?php endif; ?>

            <div class="auth-footer">
                New here?
                <a href="register.php">Create an account</a>
            </div>
        </div>
    </div>

    <script>
    (function () {
        // The tabs are a display switch and nothing else: both halves post the
        // same form, and what the button meant is decided on the server. The
        // starting pane is the one PHP chose (an error or a sent code keeps the
        // visitor on the tab they were using), and show() re-applies it so the
        // hidden and disabled states cannot drift from what was rendered.
        function show(name) {
            var panes = document.querySelectorAll('.auth-pane');
            for (var i = 0; i < panes.length; i++) {
                var on = panes[i].getAttribute('data-pane') === name;
                panes[i].hidden = !on;
                // Buttons as well as inputs: with both pane buttons live, pressing
                // Enter in the identifier box while the OTP tab is shown submitted
                // the first button in the page, which is mode=password.
                var fields = panes[i].querySelectorAll('input,button');
                for (var j = 0; j < fields.length; j++) {
                    fields[j].disabled = !on;
                }
            }
            var tabs = document.querySelectorAll('.auth-tab');
            for (var k = 0; k < tabs.length; k++) {
                tabs[k].className = 'auth-tab'
                    + (tabs[k].getAttribute('data-pane') === name ? ' active' : '');
            }
        }

        var tabs = document.querySelectorAll('.auth-tab');
        for (var t = 0; t < tabs.length; t++) {
            tabs[t].addEventListener('click', function () {
                show(this.getAttribute('data-pane'));
            });
        }

        show(<?php echo json_encode($activePane); ?>);
    })();
    </script>

    <?php if ($google['enabled']): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
    (function () {
        var clientId = <?php echo json_encode($google['client_id']); ?>;
        var endpoint = <?php echo json_encode(rtrim(appBasePath(), '/') . '/api/auth/google.php'); ?>;
        var nonce = <?php echo json_encode($googleNonce); ?>;
        var box = null;

        // The message appears where the password form's message appears, so a
        // visitor who cannot sign in with Google reads one thing in one place
        // rather than a second block of text further down the page.
        function notify(message, link) {
            if (!box) {
                box = document.getElementById('authAlert');
            }
            if (!box) {
                box = document.createElement('div');
                box.className = 'alert-danger';
                box.id = 'authAlert';
                var form = document.querySelector('.auth-form-container form');
                form.parentNode.insertBefore(box, form);
            }
            box.textContent = '';
            var icon = document.createElement('i');
            icon.className = 'fas fa-exclamation-circle';
            box.appendChild(icon);
            var span = document.createElement('span');
            span.textContent = message;
            box.appendChild(span);
            if (link && link.href) {
                var a = document.createElement('a');
                a.href = link.href;
                a.textContent = link.label;
                box.appendChild(a);
            }
            box.style.display = 'flex';
        }

        function initGoogle() {
            if (!window.google || !google.accounts || !google.accounts.id) {
                setTimeout(initGoogle, 400);
                return;
            }
            var holder = document.getElementById('googleSignInButton');
            if (!holder) { return; }
            var pending = document.getElementById('googleSignInPending');
            if (pending) { pending.remove(); }
            google.accounts.id.initialize({
                client_id: clientId,
                nonce: nonce,
                callback: function (resp) { send(resp.credential); }
            });
            google.accounts.id.renderButton(holder, {
                theme: 'outline', size: 'large', text: 'signin_with', shape: 'pill'
            });
        }

        function send(credential) {
            var body = new URLSearchParams();
            body.set('credential', credential);
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            }).then(function (r) {
                return r.json().catch(function () { return {}; });
            }).then(function (data) {
                // Google signs the nonce into the token and the server spends it,
                // so a second attempt needs a value the new token can carry. Without
                // this the button works once per page load and then refuses.
                if (data.nonce && data.nonce !== nonce) {
                    nonce = data.nonce;
                    initGoogle();
                }
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                notify(data.error || 'Google sign-in did not complete.',
                       data.register_url ? { href: data.register_url, label: ' Create an account' } : null);
            }).catch(function () {
                notify('Google sign-in could not be reached. Check your connection and try again.', null);
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initGoogle);
        } else {
            initGoogle();
        }
    })();
    </script>
    <?php endif; ?>

    <div style="position: absolute; top: 30px; right: 50px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; font-family: 'Inter', sans-serif; pointer-events: none;">
        <span style="color: #9ca3af; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 2px;">Powered By</span>
        <img src="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="AVA IT Solution" style="height: 45px; margin-top: 5px; pointer-events: auto;">
    </div>
</body>

</html>
