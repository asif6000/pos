<?php
/**
 * POS System - Settings
 * Configure shop information, system settings, and admin profile
 */

require_once '../config/db.php';
require_once '../config/auth_otp.php';
require_once 'includes/sales-scope.php';
startSecureSession();

requirePermission('settings');

define('PAGE_TITLE', 'Settings');

$db = getDB();
$currentUser = getCurrentUser();

// Owner scoping for the settings this page reads and writes, matching the
// fallback the rest of the app uses. Without it, a user row with no owner_id
// would read and write settings under NULL.
$ownerId = $currentUser['owner_id'] ?? $currentUser['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // The phone number gets two steps of its own, driven by named submit buttons
    // inside the Shop Information form. It rides in that form rather than a form
    // of its own because the number being verified is the one the shop just typed
    // there - a separate form could not read it without making them type it twice.
    //
    // Neither step saves anything else on the page: "OTP Pathao" and "Verify" are
    // not save buttons, and losing half-typed settings because somebody pressed
    // them would be a bad trade.
    if ($action === 'update_settings' && isset($_POST['phone_step'])) {
        $phoneStep = (string)$_POST['phone_step'];

        $pending = (isset($_SESSION['phone_otp_pending']) && is_array($_SESSION['phone_otp_pending']))
            ? $_SESSION['phone_otp_pending'] : null;

        $shopSettings = [];
        $st = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
        $st->execute([$currentUser['owner_id']]);
        while ($row = $st->fetch()) {
            $shopSettings[$row['setting_key']] = $row['setting_value'];
        }
        // The number that was proved, not the number that is stored: an admin may
        // have typed a new one into the box since.
        $verifiedPhone = trim((string)($shopSettings['shop_phone_verified'] ?? ''));

        if ($phoneStep === 'cancel') {
            unset($_SESSION['phone_otp_pending']);
            setFlash('info', 'OTP request bat jawa hoise.');
        } elseif ($phoneStep === 'send') {
            $phone = otpNormalizePhone($_POST['shop_phone'] ?? '');
            $heldElsewhere = $phone === '' ? null : otpPhoneTaken($db, $phone, $currentUser['id']);

            if ($phone === '') {
                setFlash('danger', 'Ekta thik mobile number din (01XXXXXXXXX).');
            } elseif ($heldElsewhere) {
                // Named in the message on purpose: "already in use" with no way to
                // find out who is holding it sends everybody to the Users page to
                // hunt, and the fix belongs there, on purpose, not here.
                setFlash('danger', 'Ai number "' . $heldElsewhere['name']
                    . '" account e already ache. Shekhane theke remove koron agey.');
            } elseif ($phone === $verifiedPhone) {
                setFlash('info', 'Ei number ta already verified ache.');
            } else {
                $res = otpSendCode($db, $ownerId, $phone, 'link', [
                    'user_id' => $currentUser['id'],
                    'app'     => substr(trim((string)($_POST['shop_name'] ?? '')), 0, 30) ?: 'Smart POS',
                ]);
                if ($res['ok']) {
                    $_SESSION['phone_otp_pending'] = ['phone' => $phone, 'at' => time()];
                    setFlash('success', 'OTP pathano hoise ' . otpMaskPhone($phone)
                        . ' e. ' . round(OTP_TTL_SECONDS / 60) . ' min er moddhe code ta din.');
                } else {
                    setFlash('danger', $res['error']);
                }
            }
        } elseif ($phoneStep === 'verify') {
            if (empty($pending['phone'])) {
                setFlash('danger', 'OTP request ta ar nei. Notun kore OTP din.');
            } else {
                $chk = otpConsume($db, 'link', $pending['phone'], $_POST['phone_otp_code'] ?? '');
                if (!$chk['ok']) {
                    // A wrong code leaves the request standing, because the same
                    // code may still be entered up to its attempt cap. An expired,
                    // spent or locked one does not: the next move is a new SMS.
                    if (!$chk['retryable']) {
                        unset($_SESSION['phone_otp_pending']);
                    }
                    setFlash('danger', $chk['error']);
                } else {
                    // The number the spent code was for, taken from the challenge
                    // rather than from the form - otherwise the box could be
                    // changed between the two steps and blessed by a code that
                    // never went to it.
                    $phone = $chk['phone'];
                    $heldElsewhere = otpPhoneTaken($db, $phone, $currentUser['id']);
                    if ($heldElsewhere) {
                        setFlash('danger', 'OTP thik, kintu ai number ekhon "'
                            . $heldElsewhere['name'] . '" account e ache.');
                    } else {
                        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id)
                                              VALUES (?, ?, ?)
                                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                        foreach (['shop_phone' => $phone, 'shop_phone_verified' => $phone] as $key => $value) {
                            $stmt->execute([$key, $value, $currentUser['owner_id']]);
                        }
                        otpMarkUserPhone($db, $currentUser['id'], $phone);
                        unset($_SESSION['phone_otp_pending']);
                        authAudit('phone_link', true, $currentUser['id'], otpMaskPhone($phone),
                            'shop phone verified with OTP');
                        setFlash('success', 'Phone number verify hoise! Ei number ta apnar account er '
                            . 'login number hisebeo save hol.');
                    }
                }
            }
        }

        redirect('settings.php');
    }

    if ($action === 'update_settings') {
        // System Settings
        $settingsData = [
            'shop_name' => sanitize($_POST['shop_name'] ?? ''),
            'shop_address' => sanitize($_POST['shop_address'] ?? ''),
            'shop_phone' => sanitize($_POST['shop_phone'] ?? ''),
            'shop_email' => sanitize($_POST['shop_email'] ?? ''),
            'currency' => sanitize($_POST['currency'] ?? 'BDT'),
            'currency_symbol' => sanitize($_POST['currency_symbol'] ?? ''),
            'vat_percent' => (float) ($_POST['vat_percent'] ?? 0),
            'low_stock_threshold' => (int) ($_POST['low_stock_threshold'] ?? 10),
            'invoice_prefix' => sanitize($_POST['invoice_prefix'] ?? 'INV'),
            'receipt_footer' => sanitize($_POST['receipt_footer'] ?? ''),
            'voucher_terms' => sanitize($_POST['voucher_terms'] ?? ''),
            'timezone' => sanitize($_POST['timezone'] ?? 'Asia/Dhaka'),
            // Checkboxes are absent from the POST when unticked, so these are read
            // with isset() rather than ?? - otherwise unticking one would fall back
            // to the default and switch it straight back on.
            'auto_send_invoice'          => isset($_POST['auto_send_invoice']) ? 1 : 0,
            'invoice_send_sms'           => isset($_POST['invoice_send_sms']) ? 1 : 0,
            'invoice_show_qr'            => isset($_POST['invoice_show_qr']) ? 1 : 0,
            // Which methods the shop may pay its suppliers with. The purchase
            // module reads this CSV back (purchasePaymentMethods) and rejects a
            // payment made any other way, so the list is intersected here against
            // the methods the database can store - a hand-edited form cannot sneak
            // in a key that would fail on INSERT later. Ticking nothing saves an
            // empty value, which the reader treats as "every method" rather than a
            // purchase form with no payment box.
            'purchase_payment_methods'   => implode(',', array_keys(array_intersect_key(
                salesPaymentMethods(),
                array_flip((array)($_POST['purchase_payment_methods'] ?? []))
            ))),
        ];

        try {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            // Which number an OTP actually proved, if any. Read here rather than
            // from the $settings list below, which is loaded after the save.
            $provedPhone = '';
            $vp = $db->prepare("SELECT setting_value FROM settings
                                WHERE owner_id = ? AND setting_key = 'shop_phone_verified' LIMIT 1");
            $vp->execute([$currentUser['owner_id']]);
            $provedPhone = trim((string)$vp->fetchColumn());

            // A number typed over a proved one is no longer the proved number. The
            // shop still gets to save it - it is business information that prints
            // on an invoice, and a shop with no SMS gateway has no way to prove it -
            // but the green tick goes with it, because a tick against digits nobody
            // checked is the one thing this field must never be.
            if (otpNormalizePhone($settingsData['shop_phone']) !== $provedPhone) {
                $settingsData['shop_phone_verified'] = '';
            }

            foreach ($settingsData as $key => $value) {
                $stmt->execute([$key, $value, $currentUser['owner_id']]);
            }

            setFlash('success', 'System settings saved successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error saving settings.');
        }
    } elseif ($action === 'update_profile') {
        // Profile Settings
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $userId = $currentUser['id'];

        if (empty($name) || empty($email)) {
            setFlash('danger', 'Name and email are required.');
        } else {
            try {
                // Check email uniqueness
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->fetch()) {
                    setFlash('danger', 'Email already in use.');
                } else {
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $hash, $userId]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $userId]);
                    }

                    // Update session
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;

                    setFlash('success', 'Profile updated successfully!');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Error updating profile.');
            }
        }
    }

    redirect('settings.php');
}

// Load current settings - Filter by owner
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$currentUser['owner_id']]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// How far along the phone number's OTP is, which is the only thing that decides
// whether the box says Verified, offers "OTP Pathao", or asks for a code that is
// already on its way.
$verifiedPhone = trim((string)($settings['shop_phone_verified'] ?? ''));
$phonePending = (isset($_SESSION['phone_otp_pending']) && is_array($_SESSION['phone_otp_pending']))
    ? $_SESSION['phone_otp_pending'] : null;
// A request past the life of its own code is not a request. Leaving the box up
// would invite somebody to type a code that can no longer be spent, and be told
// they are wrong when they are not.
if ($phonePending && (time() - (int)($phonePending['at'] ?? 0)) > OTP_TTL_SECONDS + 30) {
    $phonePending = null;
    unset($_SESSION['phone_otp_pending']);
}
$otpGatewayReady = otpTransportAvailable($ownerId);

include 'includes/header.php';
?>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<div class="settings-layout">

    <!-- Left Column: System Settings -->
    <div>
        <form method="POST">
            <input type="hidden" name="action" value="update_settings">

            <!-- Shop Information -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-store"></i> Shop Information</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label required">Shop Name</label>
                        <input type="text" name="shop_name" class="form-control"
                            value="<?php echo sanitize($settings['shop_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Shop Address</label>
                        <textarea name="shop_address" class="form-control"
                            rows="2"><?php echo sanitize($settings['shop_address'] ?? ''); ?></textarea>
                    </div>

                    <div class="cols-2" style="gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">
                                Phone Number
                                <?php if ($verifiedPhone !== ''): ?>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle"></i> Verified
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Unverified</span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="shop_phone" class="form-control" inputmode="tel"
                                value="<?php echo sanitize($settings['shop_phone'] ?? ''); ?>">

                            <?php if (!$otpGatewayReady): ?>
                                <small class="text-muted">
                                    OTP pete SMS gateway lagbe - Marketing &amp; SMS page e apnar SMS
                                    provider setup korun, tarpor ei number ta verify kora jabe.
                                </small>
                            <?php elseif ($phonePending): ?>
                                <?php // No required or pattern here: this input lives inside the big
                                    // settings form, and a client-side constraint on it would stop the
                                    // Save button working while a code is waiting to be typed. ?>
                                <div style="display:flex; gap:.5rem; align-items:center;
                                            flex-wrap:wrap; margin-top:.5rem;">
                                    <input type="text" name="phone_otp_code" class="form-control"
                                        style="width:9rem;" placeholder="6 digit code"
                                        maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                                    <button type="submit" name="phone_step" value="verify"
                                        class="btn btn-sm btn-primary">
                                        <i class="fas fa-shield-alt"></i> Verify Korun
                                    </button>
                                    <button type="submit" name="phone_step" value="send"
                                        class="btn btn-sm btn-outline">Notun OTP</button>
                                    <button type="submit" name="phone_step" value="cancel"
                                        class="btn btn-sm btn-secondary">Bat din</button>
                                </div>
                                <small class="text-muted">
                                    Code ta <?php echo sanitize(otpMaskPhone($phonePending['phone'])); ?>
                                    e geche - din, tarpor Verify korun. Save korle notun code lagbe.
                                </small>
                            <?php else: ?>
                                <div style="margin-top:.5rem;">
                                    <button type="submit" name="phone_step" value="send"
                                        class="btn btn-sm btn-secondary">
                                        <i class="fas fa-mobile-alt"></i> OTP Pathao
                                    </button>
                                </div>
                                <small class="text-muted">
                                    Number change korle notun number e OTP ashbe, seta verify korlei
                                    number ta save hobe apnar account er login number hisebe.
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="shop_email" class="form-control"
                                value="<?php echo sanitize($settings['shop_email'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>


            <!-- Receipt Settings -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-receipt"></i> Receipt Footer</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Receipt Footer</label>
                        <textarea name="receipt_footer" class="form-control" rows="2"
                            placeholder="Thank you for shopping with us!"><?php echo sanitize($settings['receipt_footer'] ?? ''); ?></textarea>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save System Settings
                        </button>
                    </div>
                </div>
            </div>

            <!-- Invoice Sending -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-paper-plane"></i> Invoice Sending</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted" style="margin-top:0;">
                        Sale sesh hoile customer er number e invoice automatically
                        SMS hisabe pathay.
                    </p>

                    <?php
                    // A missing row means on, matching what the API assumes - except
                    // for the opt-in rows listed in $invOff, which cost money or
                    // restrict delivery and so start closed until the shop asks.
                    $invOff = [];
                    $invOn = function ($key) use ($settings, $invOff) {
                        $v = $settings[$key] ?? (in_array($key, $invOff, true) ? '0' : '1');
                        return !($v === '0' || $v === 0 || $v === '');
                    };
                    ?>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                            <input type="checkbox" name="auto_send_invoice" value="1"
                                <?php echo $invOn('auto_send_invoice') ? 'checked' : ''; ?>
                                style="margin-top:0.2rem;">
                            <span>
                                <strong>Sale sesh hole automatically pathao</strong><br>
                                <small class="text-muted">Off korle invoice sudhu manual button e pathaora jabe.</small>
                            </span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                            <input type="checkbox" name="invoice_send_sms" value="1"
                                <?php echo $invOn('invoice_send_sms') ? 'checked' : ''; ?>
                                style="margin-top:0.2rem;">
                            <span>
                                <strong>SMS e pathao</strong><br>
                                <small class="text-muted">
                                    Invoice text hisabe jay - bill er chobi SMS e jay na.
                                    Gateway configure kora thakle kaj kore; kono package kinle na.
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                            <input type="checkbox" name="invoice_show_qr" value="1"
                                <?php echo $invOn('invoice_show_qr') ? 'checked' : ''; ?>
                                style="margin-top:0.2rem;">
                            <span>
                                <strong>Invoice e QR dekhai</strong><br>
                                <small class="text-muted">
                                    QR ta Facebook page er link - seta
                                    <em>Voucher Settings</em> e change kora jay.
                                    Off korle bill e ar QR ashbe na.
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save System Settings
                        </button>
                    </div>
                </div>
            </div>
            <!-- Supplier Payment Methods -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-truck-ramp-box"></i> Supplier Payment Methods</h3>
                </div>
                <div class="card-body">
                    <p class="text-muted" style="margin-top:0;">
                        Purchase entry, supplier payment ar return form e ei method gulo ashbe.
                        Jeta rakben na seta supplier ke dite parben na - balance ar report ar kichu change hobe na.
                    </p>

                    <?php
                    $puAll = salesPaymentMethods();
                    $puSaved = array_filter(array_map('trim',
                        explode(',', (string)($settings['purchase_payment_methods'] ?? ''))));
                    // No stored list means the shop never chose, and the purchase
                    // module offers every method - so the box shows every method
                    // ticked rather than an empty set that means the same thing.
                    $puOn = function ($key) use ($puSaved, $puAll) {
                        if (empty($puSaved)) {
                            return true;
                        }
                        return in_array($key, $puSaved, true);
                    };
                    ?>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <?php foreach ($puAll as $key => $label): ?>
                            <label style="display:inline-flex; align-items:center; gap:0.45rem;
                                           cursor:pointer; margin:0 1.1rem 0.5rem 0;">
                                <input type="checkbox" name="purchase_payment_methods[]"
                                       value="<?php echo $key; ?>"
                                       <?php echo $puOn($key) ? 'checked' : ''; ?>>
                                <span><?php echo $label; ?></span>
                            </label>
                        <?php endforeach; ?>
                        <small class="text-muted" style="display:block; margin-top:0.35rem;">
                            Sob deselect korle abar sob method phirbe (eta default).
                        </small>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save System Settings
                        </button>
                    </div>
                </div>
            </div>
            <!-- Voucher Settings -->
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-file-invoice"></i> Voucher Settings</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Voucher Terms & Conditions</label>
                        <textarea name="voucher_terms" class="form-control" rows="4"
                            placeholder="e.g. 1. This voucher is valid for 30 days. 2. Not redeemable for cash."><?php echo sanitize($settings['voucher_terms'] ?? ''); ?></textarea>
                        <small class="text-muted">These terms will be printed on the invoice</small>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Right Column: Profile Settings -->
    <div>
        <form method="POST">
            <input type="hidden" name="action" value="update_profile">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user-shield"></i> Admin Profile</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label required">My Name</label>
                        <input type="text" name="name" class="form-control"
                            value="<?php echo sanitize($currentUser['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">My Email</label>
                        <input type="email" name="email" class="form-control"
                            value="<?php echo sanitize($currentUser['email']); ?>" required>
                    </div>

                    <hr style="margin: 1.5rem 0; border: 0; border-top: 1px solid #eee;">
                    <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Leave password blank to keep
                        it unchanged.</p>

                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" placeholder="New Password">
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" style="width: 100%;">
                        <i class="fas fa-check"></i> Update Profile
                    </button>

                </div>
            </div>
        </form>

        </div>



    </div>
</div>

<?php include 'includes/footer.php'; ?>
