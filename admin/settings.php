<?php
/**
 * POS System - Settings
 * Configure shop information, system settings, and admin profile
 */

require_once '../config/db.php';
require_once '../config/marketing.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Settings');

$db = getDB();
$currentUser = getCurrentUser();

// Owner scoping for the bridge settings, matching the fallback the rest of the
// app uses. Without it, a user row with no owner_id would read and write the
// bridge settings under NULL and the panel would always look unconfigured.
$bridgeOwnerId = $currentUser['owner_id'] ?? $currentUser['id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
            'invoice_send_whatsapp'      => isset($_POST['invoice_send_whatsapp']) ? 1 : 0,
            'invoice_sms_whatsapp_only'  => isset($_POST['invoice_sms_whatsapp_only']) ? 1 : 0,
        ];

        try {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            foreach ($settingsData as $key => $value) {
                $stmt->execute([$key, $value, $currentUser['owner_id']]);
            }

            setFlash('success', 'System settings saved successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error saving settings.');
        }
    } elseif ($action === 'save_bridge') {
        // The WhatsApp bridge connection. Saved and tested together, because
        // saving a URL that nothing is listening on is the normal case here -
        // the tunnel is often not up yet - and reporting that as a failed save
        // would only make someone retype the same correct value.
        //
        // whatsapp-server/SETUP-SHOP-PC.md sends the reader to "Settings, the
        // Marketing / WhatsApp panel". This is that panel; the Inbox page has
        // the same fields, so a shop can fix the link from either side.
        //
        // Agent mode is passed as a constant, not read from the form. The mode
        // select and the two tunnel fields are gone from this card, so there is
        // nothing left to read - and marketingSaveBridgeSettings() treats
        // anything that is not exactly the agent mode as the direct arrangement.
        // Left to a missing $_POST it would fall through and try to save a bridge
        // URL instead, which fails on the empty one: this card could then never
        // save a working agent token again, and the shop would be told to type a
        // tunnel address it does not need. A shop that genuinely needs the
        // tunnel still has the full form on the Inbox page.
        $bridgeSaved = marketingSaveBridgeSettings(
            $bridgeOwnerId,
            '',
            '',
            WA_AGENT_MODE,
            $_POST['agent_token'] ?? ''
        );

        if (!$bridgeSaved['ok']) {
            setFlash('danger', $bridgeSaved['error']);
        } else {
            $bridgeTest = marketingTestBridgeConnection($bridgeOwnerId);
            if ($bridgeTest['connected']) {
                setFlash('success', 'Agent mode save hoye gelo. WhatsApp connected'
                    . ($bridgeTest['me'] ? ' as ' . $bridgeTest['me'] : '') . '.');
            } else {
                // The only remaining answer. Agent mode cannot report "working"
                // at the instant it is saved, because the bridge has not polled
                // yet - so this is the expected result, not a failure, and the
                // hint is what tells the shop what to do next.
                setFlash('warning', 'Agent mode save hoye gelo. Bridge chaltese nijei theke '
                    . 'report dewa hobe - ektu porjonto wait korun. '
                    . ($bridgeTest['hint'] ?: $bridgeTest['error']));
            }
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

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">

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

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="shop_phone" class="form-control"
                                value="<?php echo sanitize($settings['shop_phone'] ?? ''); ?>">
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
                        Sale sesh hoile customer er number e invoice automatically pathay.
                        SMS e text hisabe jay, WhatsApp e bill-er chobi.
                    </p>

                    <?php
                    // A missing row means on, matching what the API assumes. Shown
                    // checked so the page agrees with the behaviour.
                    $invOn = function ($key) use ($settings) {
                        $v = $settings[$key] ?? '1';
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
                                    Protita SMS er credit lage. Gateway configure kora thakle kaj kore.
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                            <input type="checkbox" name="invoice_send_whatsapp" value="1"
                                <?php echo $invOn('invoice_send_whatsapp') ? 'checked' : ''; ?>
                                style="margin-top:0.2rem;">
                            <span>
                                <strong>WhatsApp e pathao</strong><br>
                                <small class="text-muted">
                                    Bill er chobi (image) WhatsApp e jay. WhatsApp QR scan
                                    kora thakte hoy.
                                </small>
                            </span>
                        </label>
                    </div>

                    <div class="form-group" style="margin-bottom:1rem;">
                        <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                            <input type="checkbox" name="invoice_sms_whatsapp_only" value="1"
                                <?php echo $invOn('invoice_sms_whatsapp_only') ? 'checked' : ''; ?>
                                style="margin-top:0.2rem;">
                            <span>
                                <strong>Sudhu ja check kora number e pathao</strong><br>
                                <small class="text-muted">
                                    Apni ja bolechen: number e WhatsApp thaklei pathabe.
                                    Check hoy nai number gulor jonno SMS jabe na - Marketing
                                    page theke number check kora jay.
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

        <!-- WhatsApp Bridge.
             A separate form, not another card inside the settings form above:
             it saves different rows, to a different helper, and folding it in
             would mean saving shop name and bridge token as one operation. -->
        <?php
        // Live state, so the card can say what is wrong rather than just
        // offering two empty boxes. Safe to call even when nothing is
        // configured - that is one of the states it reports.
        $bridgeState = marketingGetBridgeStatus($bridgeOwnerId);
        ?>
        <form method="POST">
            <input type="hidden" name="action" value="save_bridge">
            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <h3 class="card-title"><i class="fab fa-whatsapp"></i> WhatsApp Bridge</h3>
                </div>
                <div class="card-body">

                    <?php if ($bridgeState['connected']): ?>
                        <div class="alert alert-success" style="margin-top:0;">
                            <i class="fas fa-check-circle"></i>
                            Connected<?php echo $bridgeState['me']['name'] ? ' as ' . sanitize($bridgeState['me']['name']) : ''; ?>.
                            <a href="whatsapp-inbox.php">Inbox kholun</a>.
                        </div>
                    <?php elseif ($bridgeState['configured']): ?>
                        <div class="alert alert-warning" style="margin-top:0;">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo sanitize($bridgeState['error'] ?: 'Bridge theke uttor ashche na.'); ?>
                            <?php if (!empty($bridgeState['hint'])): ?>
                                <br><small><?php echo sanitize($bridgeState['hint']); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-top:0;">
                            <i class="fas fa-info-circle"></i>
                            Agent token dewa hoye ni. Bridge er <code>.env</code>
                            file er <code>AGENT_TOKEN</code> value ta niche bosiye
                            Save korun - WhatsApp campaign o WhatsApp invoice
                            cholbe na, SMS cholbe.
                        </div>
                    <?php endif; ?>

                    <!-- Agent token. The only field left in this card.

                         The mode select and the two tunnel fields (Bridge URL,
                         API token) were removed. In agent mode the bridge dials
                         the POS, so there is no address for the shop to type and
                         no second token to keep in step with the first - and a
                         card that asks for values this arrangement never uses is
                         a card that gets filled in wrong and then blamed.

                         A shop that does need the tunnel still has the full form
                         on the Inbox page, under Bridge setup.

                         The token is never echoed back. Left blank it keeps
                         whatever is already saved, so this card cannot quietly
                         erase a working token. -->
                    <div class="form-group">
                        <label class="form-label">Agent token</label>
                        <input type="password" name="agent_token" class="form-control" value=""
                            placeholder="<?php echo $bridgeState['configured'] ? 'Chalu ache - bondho rakhle othe' : 'whatsapp-server\.env er AGENT_TOKEN'; ?>">
                        <small class="text-muted">
                            Bridge er <code>.env</code> file er <code>AGENT_TOKEN</code>
                            value ta - <code>API_TOKEN</code> er cheye alada.
                            Bridge nijei ei POS e call kore, tai kono bridge URL
                            ba domain lagbe na. Steps:
                            <code>whatsapp-server\AGENT-MODE.md</code>
                        </small>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save &amp; Test Connection
                        </button>
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

        <div class="card" style="margin-top: 1.5rem;">
            <div class="card-body">
                <h4 style="margin-top:0;">Database Info</h4>
                <p style="font-size: 0.9rem; margin-bottom: 0.5rem;"><strong>Host:</strong> <?php echo DB_HOST; ?></p>
                <p style="font-size: 0.9rem; margin-bottom: 0.5rem;"><strong>Database:</strong> <?php echo DB_NAME; ?>
                </p>
                <p style="font-size: 0.9rem; margin-bottom: 0;"><strong>User:</strong> <?php echo DB_USER; ?></p>
            </div>
        </div>

    </div>
</div>

<?php include 'includes/footer.php'; ?>
