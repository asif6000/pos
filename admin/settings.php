<?php
/**
 * POS System - Settings
 * Configure shop information, system settings, and admin profile
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Settings');

$db = getDB();
$currentUser = getCurrentUser();

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
            'currency_symbol' => sanitize($_POST['currency_symbol'] ?? '৳'),
            'vat_percent' => (float) ($_POST['vat_percent'] ?? 0),
            'low_stock_threshold' => (int) ($_POST['low_stock_threshold'] ?? 10),
            'invoice_prefix' => sanitize($_POST['invoice_prefix'] ?? 'INV'),
            'receipt_footer' => sanitize($_POST['receipt_footer'] ?? ''),
            'voucher_terms' => sanitize($_POST['voucher_terms'] ?? ''),
            'timezone' => sanitize($_POST['timezone'] ?? 'Asia/Dhaka'),
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
