<?php
/**
 * POS System - Registration Page
 * Handles new user registration
 */

require_once '../config/db.php';
require_once '../config/auth_google.php';
require_once '../config/auth_otp.php';
require_once '../config/auth_password.php';
startSecureSession();

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('../index.php');
}

// A connection is taken here, before the guard below, because the guard needs one
// and $db was previously only opened inside the try further down - which made the
// guard a fatal and closed the hole only by crashing the page.
$db = getDB();

// Self-registration is OFF unless a setting turns it on.
//
// It used to be unconditional here, and what it created was role='admin' with
// owner_id = NULL - the full-access combination - plus a store. Linked from the
// login page, so not even hidden. Anyone who could reach the URL could make
// themselves an administrator of a shop they do not own.
//
// A shop adds people from Admin -> Users, which is where the role actually gets
// chosen; that is the path that should be used.
//
// Read without an owner scope on purpose: nobody is signed in on this page, so
// there is no owner to scope by, and this is a single-shop install. Any row set
// to 1 is taken as "the shop has deliberately opened registration".
$selfReg = '0';
try {
    $v = $db->query("SELECT setting_value FROM settings
                      WHERE setting_key = 'self_registration' ORDER BY id LIMIT 1")->fetchColumn();
    $selfReg = ($v === false || $v === null || $v === '') ? '0' : (string)$v;
} catch (Exception $e) {
    $selfReg = '0';   // if the setting cannot be read, closed is the safe answer
}

// Initialised before the guard fills it in. The first attempt set $error and then
// wiped it on the next line.
$error = '';
$success = '';

// A Google identity that reached this page from the login form.
//
// The login endpoint will not invent an account out of a Google token - that is
// how a button becomes a way to become an administrator of a shop nobody owns -
// but it also will not lose what Google proved. It stores the verified name and
// address in the session, and this page starts from them. Nothing here is taken
// from the URL or from a form field: an account's email has to come from the
// credential, not from whatever the visitor typed into the address bar.
$googlePending = googleSignInPending();

if ($selfReg !== '1') {
    $error = 'Registration is closed on this system. Ask an administrator to create your account.';
    if ($googlePending !== null) {
        // Said plainly, because a person who just signed in with Google and landed
        // here has to be told why the button did not finish the job.
        $error = 'Google confirmed ' . sanitize($googlePending['email'])
               . ', but this system does not accept new registrations. Ask an administrator to create your account.';
    }
}

// ── The two steps of signing up ──────────────────────────────────────────────
//
// 'details' asks for a code, 'code' spends one and creates the account, 'resend'
// sends the same number another one, 'cancel' drops the waiting details and gives
// the form back.
//
// What the visitor typed is kept between the two posts in the session and in
// nothing the browser holds, so the second half of the form cannot be POSTed on
// its own: an account appears only for somebody who answered a code sent to the
// number they gave. The password is kept as its bcrypt hash and never in the
// clear, so a session file that somebody else can read still does not hold one.
$step = (string)($_POST['step'] ?? 'details');
$notice = '';

$pending = null;
if (isset($_SESSION['register_pending']) && is_array($_SESSION['register_pending'])
    && (time() - (int)($_SESSION['register_pending']['at'] ?? 0)) <= OTP_TTL_SECONDS + 30) {
    $pending = $_SESSION['register_pending'];
}
if ($pending === null) {
    unset($_SESSION['register_pending']);
}

/**
 * The account, its store and its trial - the three things that have always been
 * created together, in the one transaction that keeps them that way.
 *
 * Called only after a code for the number was spent, and it writes no phone
 * column: the caller does that with otpMarkUserPhone() afterwards, because that
 * helper may create tables of its own, and a CREATE TABLE inside a transaction
 * commits it in MySQL - which would end the account creation half-done.
 *
 * @return int the new account's id, which is also its own owner_id
 */
function registerCreateShop($db, array $pending)
{
    require_once __DIR__ . '/../config/subscription.php';
    subEnsureTables($db);

    $db->beginTransaction();

    // A self-registered account owns its own shop, so it is stored the same way a
    // real owner is. Leaving owner_id NULL made every signup look like the platform
    // super admin to any check of the form empty($owner_id).
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, status)
                          VALUES (?, ?, ?, 'admin', 'active')");
    $stmt->execute(array($pending['name'], $pending['email'], $pending['password_hash']));
    $ownerId = (int)$db->lastInsertId();
    $db->prepare("UPDATE users SET owner_id = ? WHERE id = ?")->execute(array($ownerId, $ownerId));

    $stmtStore = $db->prepare("INSERT INTO stores (name, status, owner_id) VALUES (?, 'active', ?)");
    $stmtStore->execute(array($pending['business_name'], $ownerId));

    // Point the account at its own store so a fresh signup lands on the
    // single-store dashboard rather than the global multi-store view.
    $db->prepare("UPDATE users SET store_id = ? WHERE id = ?")
        ->execute(array((int)$db->lastInsertId(), $ownerId));

    subGrantTrial($db, $ownerId, $ownerId);

    $db->commit();
    return $ownerId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    if ($step === 'cancel') {
        // Only the code request is thrown away; the form redraws from $_POST, so
        // the person who decided to change their number does not retype the rest.
        $pending = null;
        unset($_SESSION['register_pending']);
        $step = '';
    }

    if ($step === 'details') {
        $name     = sanitize($_POST['name'] ?? '');
        $email    = sanitize($_POST['email'] ?? '');
        $business = sanitize($_POST['business_name'] ?? '');
        $phone    = otpNormalizePhone($_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($googlePending !== null) {
            // The address Google verified wins, whatever this POST says, so the
            // account that adopts the Google identity can only ever be the one that
            // belongs to it. The field is read-only in the form for the same reason.
            $email = $googlePending['email'];
            if ($name === '') {
                $name = sanitize($googlePending['name']);
            }
        }

        if ($selfReg !== '1') {
            // Already reported above; do not fall through to a code.
            $error = 'Registration is closed on this system.';
        } elseif ($name === '' || $email === '' || $business === '' || $password === ''
                  || trim((string)($_POST['phone'] ?? '')) === '') {
            $error = 'All fields are required, including the mobile number.';
        } elseif ($phone === '') {
            $error = 'Ekta thik mobile number din (01XXXXXXXXX).';
        } elseif (strlen($name) < 2) {
            $error = 'Name must be at least 2 characters.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (count($policyErrors = authPasswordPolicyErrors($password)) > 0) {
            // The same rule the reset page, the settings page and the Users page
            // apply, read from the one place they all read it from. Signup used to
            // accept six and the reset page refused to hand back a password
            // shorter than eight, so an account made here could get in and could
            // never be repaired.
            $error = $policyErrors[0];
        } elseif ($password !== (string)($_POST['confirm_password'] ?? '')) {
            $error = 'Passwords do not match.';
        } elseif (empty($_POST['terms'])) {
            // The checkbox is required in the browser as well, but a POST can be made
            // without ever loading the page - and an agreement that only exists in the
            // interface is not an agreement.
            $error = 'Please read and accept the Terms & Conditions and Privacy Policy to continue.';
        } else {
            try {
                $taken = otpPhoneTaken($db, $phone);
                $stmt  = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute(array($email));

                if ($taken !== null) {
                    // Said as plainly as the email one below. A number already on the
                    // system is somebody's login, and this form cannot tell whether it
                    // is also the visitor's - only the code on that handset can, and
                    // that is the other account's code to spend, not this one's.
                    $error = 'Ei mobile number ta agey onno ekta account e use hoise. '
                           . 'Onno number din, ba admin er sathe jogajog korun.';
                } elseif ($stmt->fetch()) {
                    $error = 'Email address already registered.';
                } else {
                    $senderOwner = otpPlatformSenderOwner($db);
                    $res = otpSendCode($db, $senderOwner, $phone, 'register',
                        array('app' => 'Smart POS'));
                    if ($res['ok']) {
                        $_SESSION['register_pending'] = array(
                            'name'          => $name,
                            'email'         => $email,
                            'business_name' => $business,
                            'password_hash' => authHashPassword($password),
                            'phone'         => $phone,
                            'at'            => time(),
                        );
                        $pending = $_SESSION['register_pending'];
                        $notice = 'OTP pathano hoise ' . otpMaskPhone($phone) . ' e. '
                            . round(OTP_TTL_SECONDS / 60) . ' min er moddhe code ta din.';
                    } elseif ($senderOwner <= 0) {
                        // The service's own message says "set up SMS on the Marketing
                        // & SMS page", which is advice for a shop that exists. This
                        // visitor has no account to log into, so the reason is named
                        // as what it is: nobody has told this installation which SMS
                        // account pays for a signup code.
                        $error = 'Ei system e notun signup er OTP pathanor jonno kono SMS account '
                               . 'set kora hoyni. Administrator ke janun '
                               . '(system_settings e otp_sms_owner_id).';
                    } else {
                        $error = $res['error'];
                    }
                }
            } catch (Throwable $e) {
                error_log('register details: ' . $e->getMessage());
                $error = 'An error occurred. Please try again.';
            }
        }
    } elseif ($step === 'resend') {
        if ($pending === null) {
            $error = 'OTP request ta ar nei. Upore notun kore details din.';
        } else {
            $res = otpSendCode($db, otpPlatformSenderOwner($db), $pending['phone'], 'register',
                array('app' => 'Smart POS'));
            if ($res['ok']) {
                // The details wait as long as the newest code does, not as long as
                // the first one did.
                $pending['at'] = time();
                $_SESSION['register_pending'] = $pending;
                $notice = 'Abar OTP geche ' . otpMaskPhone($pending['phone']) . ' e. '
                    . round(OTP_TTL_SECONDS / 60) . ' min er moddhe code ta din.';
            } else {
                $error = $res['error'];
            }
        }
    } elseif ($step === 'code') {
        if ($pending === null) {
            $error = 'OTP request ta ar nei. Upore notun kore details din.';
        } else {
            $chk = otpConsume($db, 'register', $pending['phone'], $_POST['otp_code'] ?? '');
            if (!$chk['ok']) {
                if (!$chk['retryable']) {
                    unset($_SESSION['register_pending']);
                    $pending = null;
                }
                $error = $chk['error'];
            } else {
                // The number the spent code went to, not the one in the form: the
                // answered SMS is what was proved, and blessing anything else would
                // be the whole point of the exercise undone.
                $phone = $chk['phone'];
                try {
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute(array($pending['email']));
                    $emailTaken = (bool)$stmt->fetch();
                    $taken = otpPhoneTaken($db, $phone);

                    if ($emailTaken || $taken !== null) {
                        // Either can happen in the five minutes a code is live, and
                        // neither is a reason to hand somebody an account that shares
                        // an address or a handset with somebody else.
                        unset($_SESSION['register_pending']);
                        $pending = null;
                        $error = $taken !== null
                            ? 'Ei mobile number ta ekhon onno ekta account e niye neya hoise. '
                              . 'Details abar din.'
                            : 'Email address ta ekhon onno ekta account e niye neya hoise. '
                              . 'Details abar din.';
                    } else {
                        $ownerId = registerCreateShop($db, $pending);
                        otpMarkUserPhone($db, $ownerId, $phone);

                        // The Google identity that brought them here now belongs to
                        // the account they just made. After the commit on purpose: a
                        // Google column a host refuses to create must not roll back a
                        // registration that has already succeeded.
                        if ($googlePending !== null) {
                            googleSignInAttachToNewUser($ownerId);
                        }

                        authAudit('register', true, $ownerId, $pending['email'],
                            'Signed up with a verified phone number');
                        unset($_SESSION['register_pending']);
                        setFlash('success', 'Account created successfully! Your 7-day free trial '
                            . 'has started. Please sign in.');
                        redirect('login.php');
                    }
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    // The old code printed the database's own sentence to the
                    // visitor. It said what went wrong to everybody, including the
                    // person who just got a new shop out of a form.
                    error_log('register create: ' . $e->getMessage());
                    $error = 'An error occurred. Please try again.';
                }
            }
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

        /* Consent is the one field on this form that must not be pre-filled or
           buried: it is boxed and given room so it reads as a step, not a footnote. */
        .terms-row {
            border: 1px solid #fbcfe8;
            background: #fff1f7;
            border-radius: 8px;
            padding: 11px 13px;
            margin-bottom: 1rem;
        }

        .terms-row label {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            cursor: pointer;
            /* Bengali glyphs need more than the form's default 0.8rem to stay legible,
               and this line carries the agreement. */
            font-size: 0.86rem;
            color: #4b5563;
            line-height: 1.55;
        }

        .terms-row input[type="checkbox"] {
            width: 17px;
            height: 17px;
            flex: none;
            margin-top: 1px;
            accent-color: #e11d48;
            cursor: pointer;
        }

        .terms-row a { color: #e11d48; font-weight: 600; text-decoration: underline; }

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

        .alert-ok {
            background: #dcfce7;
            color: #15803d;
            padding: 11px;
            border-radius: 8px;
            margin-bottom: 1.2rem;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* The code step replaces the whole form with one box, so that the only
           thing on the screen is the thing the visitor has to go and read off
           their phone. */
        .code-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.1rem;
            background: #f9fafb;
            margin-bottom: 1.1rem;
        }

        .code-card p {
            margin: 0 0 .9rem 0;
            font-size: .85rem;
            color: #4b5563;
            line-height: 1.55;
        }

        .code-number { font-weight: 700; color: #374151; }

        .otp-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            padding: 12px 8px;
            text-align: center;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: .35rem;
            color: #374151;
            font-family: 'Inter', sans-serif;
        }

        .otp-input:focus {
            outline: none;
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.1);
        }

        .code-help {
            font-size: .78rem;
            color: #6b7280;
            margin: .6rem 0 0 0 !important;
        }

        .btn-ghost {
            width: 100%;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            color: #4b5563;
            border-radius: 30px;
            padding: 11px;
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            font-family: 'Hind Siliguri', 'Inter', sans-serif;
        }

        .btn-ghost:hover { background: #f3f4f6; }

        .field-hint {
            margin: -6px 0 1rem 0;
            font-size: .78rem;
            color: #6b7280;
            line-height: 1.45;
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
                    <i class="fas fa-check-circle"></i> 7-day free trial — no card needed
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
            <h2><?php echo $pending === null ? 'Create Account' : 'Verify your phone'; ?></h2>

            <?php if ($error): ?>
                <div class="alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?>
                <div class="alert-ok">
                    <i class="fas fa-paper-plane"></i>
                    <span><?php echo $notice; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($pending === null): ?>
            <form method="POST">
                <input type="hidden" name="step" value="details">

                <div class="form-group">
                    <i class="fas fa-user form-icon"></i>
                    <input type="text" name="name" class="form-control" placeholder="Full Name"
                        required value="<?php echo isset($_POST['name']) ? sanitize($_POST['name']) : ($googlePending !== null ? sanitize($googlePending['name']) : ''); ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-store form-icon"></i>
                    <input type="text" name="business_name" class="form-control" placeholder="Business / Store Name"
                        required value="<?php echo isset($_POST['business_name']) ? sanitize($_POST['business_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-envelope form-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="Email Address"
                        required <?php if ($googlePending !== null): ?>readonly
                            style="background:#f3f4f6;color:#6b7280" <?php endif; ?>
                        value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ($googlePending !== null ? sanitize($googlePending['email']) : ''); ?>">
                    <?php if ($googlePending !== null): ?>
                        <span style="font-size:.78rem;color:#6b7280">
                            Verified by Google. A read-only address, because it came from your Google account
                            rather than from this form.
                        </span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <i class="fas fa-mobile-alt form-icon"></i>
                    <input type="tel" name="phone" class="form-control" placeholder="Mobile Number (01XXXXXXXXX)"
                        required autocomplete="tel"
                        value="<?php echo isset($_POST['phone']) ? sanitize($_POST['phone']) : ''; ?>">
                </div>
                <!-- Outside the .form-group on purpose: the icon sits at the vertical
                     middle of that box, so a note inside it pushes the icon off the
                     input it belongs to. -->
                <p class="field-hint">
                    Ei number e OTP jabe. Account ta OTP verify howar por-i toiri hobe.
                </p>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="password" class="form-control"
                        placeholder="Password (min <?php echo AUTH_PASSWORD_MIN_LENGTH; ?> characters)"
                        required maxlength="<?php echo AUTH_PASSWORD_MAX_LENGTH; ?>">
                </div>

                <div class="form-group">
                    <i class="fas fa-lock form-icon"></i>
                    <input type="password" name="confirm_password" class="form-control"
                        placeholder="Confirm Password" required maxlength="<?php echo AUTH_PASSWORD_MAX_LENGTH; ?>">
                </div>

                <div class="terms-row">
                    <label for="termsChk">
                        <input type="checkbox" id="termsChk" name="terms" value="1" required
                            <?php echo !empty($_POST['terms']) ? 'checked' : ''; ?>>
                        <span>আমি <a href="../terms.php" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                            ও <a href="../privacy.php" target="_blank" rel="noopener">Privacy Policy</a> পড়েছি এবং
                            এতে সম্মত আছি। / I have read and accept the Terms &amp; Conditions and Privacy Policy.</span>
                    </label>
                </div>

                <div class="free-badge">
                    <i class="fas fa-gift"></i>
                    <span><strong>৭ দিন ফ্রি ট্রায়াল</strong> — রেজিস্টার করলেই সব ফিচার সাথে সাথে চালু। ট্রায়াল শেষ হলে প্যাকেজ কিনে চালিয়ে যান।</span>
                </div>

                <button type="submit" class="btn-primary">
                    Continue &amp; get OTP
                </button>
            </form>
            <?php else: ?>
            <form method="POST">
                <div class="code-card">
                    <p>
                        <span class="code-number"><?php echo htmlspecialchars(otpMaskPhone($pending['phone']), ENT_QUOTES, 'UTF-8'); ?></span>
                        e ekta 6-digit code pathanoy hoise. Code ta <?php echo (int)round(OTP_TTL_SECONDS / 60); ?>
                        minute er moddhe din.
                    </p>
                    <input type="text" name="otp_code" class="otp-input" placeholder="------"
                        inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required autofocus>
                    <p class="code-help">
                        Account ta ekhon o toiri hoyni — code ta deowar por-i name, email, store ar trial
                        ekathe create hobe, ar apnar number ta verified hisebe save hobe.
                    </p>
                </div>

                <button type="submit" name="step" value="code" class="btn-primary">
                    Verify &amp; create account
                </button>
                <button type="submit" name="step" value="resend" class="btn-ghost" formnovalidate>
                    OTP abar pathan
                </button>
                <button type="submit" name="step" value="cancel" class="btn-ghost" formnovalidate>
                    Details abar edit korun
                </button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                Already have an account? <a href="login.php">Sign in here</a>
            </div>
        </div>
    </div>

    <!-- Powered by -->
    <div style="position: absolute; top: 30px; right: 50px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; font-family: 'Inter', sans-serif; pointer-events: none;">
        <span style="color: #9ca3af; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 3px; margin-bottom: 3px;">Powered By</span>
        <img src="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="AVA IT Solution" style="height: 40px; pointer-events: auto;">
    </div>
</body>

</html>
