<?php
/**
 * POS System - Voucher Settings
 * Configure the Lucky Entry Coupon
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Voucher Settings');

$db = getDB();
$currentUser = getCurrentUser();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_voucher') {
        $settingsData = [
            'coupon_status' => sanitize($_POST['coupon_status'] ?? '0'),
            'coupon_title' => sanitize($_POST['coupon_title'] ?? ''),
            'coupon_subtitle' => sanitize($_POST['coupon_subtitle'] ?? ''),
            'coupon_prize_1' => sanitize($_POST['coupon_prize_1'] ?? ''),
            'coupon_prize_2' => sanitize($_POST['coupon_prize_2'] ?? ''),
            'coupon_prize_3' => sanitize($_POST['coupon_prize_3'] ?? ''),
            'coupon_prize_4' => sanitize($_POST['coupon_prize_4'] ?? ''),
            'coupon_prize_5' => sanitize($_POST['coupon_prize_5'] ?? ''),
            'coupon_total_winners' => sanitize($_POST['coupon_total_winners'] ?? ''),
            'coupon_announcement' => sanitize($_POST['coupon_announcement'] ?? ''),
            'voucher_terms' => sanitize($_POST['voucher_terms'] ?? ''),
            'return_qr_url' => sanitize($_POST['return_qr_url'] ?? ''),
            'facebook_page' => sanitize($_POST['facebook_page'] ?? 'https://www.facebook.com'),
        ];

        try {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

            foreach ($settingsData as $key => $value) {
                $stmt->execute([$key, $value, $currentUser['owner_id']]);
            }

            setFlash('success', 'Voucher settings saved successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error saving voucher settings.');
        }
    }
    
    redirect('voucher-settings.php');
}

// Load current settings
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

<div style="max-width: 800px; margin: 0 auto;">
    <form method="POST">
        <input type="hidden" name="action" value="update_voucher">

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-ticket-alt"></i> Lucky Coupon Configuration</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label font-weight-bold">Enable Lucky Coupon on Invoice</label>
                    <select name="coupon_status" class="form-control">
                        <option value="1" <?php echo ($settings['coupon_status'] ?? '0') == '1' ? 'selected' : ''; ?>>Yes, print with invoice</option>
                        <option value="0" <?php echo ($settings['coupon_status'] ?? '0') == '0' ? 'selected' : ''; ?>>No, disable coupon</option>
                    </select>
                </div>

                <hr style="margin: 1.5rem 0; border: 0; border-top: 1px dashed #ccc;">

                <div class="form-group">
                    <label class="form-label">Facebook Page URL (For QR Code)</label>
                    <input type="url" name="facebook_page" class="form-control"
                        value="<?php echo sanitize($settings['facebook_page'] ?? 'https://www.facebook.com'); ?>" placeholder="https://facebook.com/yourpage">
                    <small class="text-muted">This URL will be used to generate the QR code on the invoice and voucher.</small>
                </div>

                <hr style="margin: 1.5rem 0; border: 0; border-top: 1px dashed #ccc;">

                <div class="form-group">
                    <label class="form-label">Coupon Title</label>
                    <input type="text" name="coupon_title" class="form-control"
                        value="<?php echo sanitize($settings['coupon_title'] ?? 'SMART COLLECTION MONTHLY LUCKY COUPON'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Coupon Subtitle</label>
                    <textarea name="coupon_subtitle" class="form-control" rows="2"><?php echo sanitize($settings['coupon_subtitle'] ?? 'প্রতিটি কেনাকাটায় নিশ্চিত Lucky Entry Coupon!'); ?></textarea>
                </div>

                <h5 style="margin-top: 1.5rem; margin-bottom: 1rem;"><i class="fas fa-gift"></i> Prizes</h5>
                
                <div class="form-group">
                    <label class="form-label">Prize 1 (1st Prize)</label>
                    <input type="text" name="coupon_prize_1" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_1'] ?? '🥇 ৳৫,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Prize 2 (2nd Prize)</label>
                    <input type="text" name="coupon_prize_2" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_2'] ?? '🥈 ৳৩,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prize 3 (3rd Prize)</label>
                    <input type="text" name="coupon_prize_3" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_3'] ?? '🥉 ৳২,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prize 4</label>
                    <input type="text" name="coupon_prize_4" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_4'] ?? '🎁 ৳৫০০ Shopping Voucher — ১০ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prize 5</label>
                    <input type="text" name="coupon_prize_5" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_5'] ?? '👕 Premium T-Shirt — ১০ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Total Winners Text</label>
                    <input type="text" name="coupon_total_winners" class="form-control"
                        value="<?php echo sanitize($settings['coupon_total_winners'] ?? 'মোট বিজয়ী: ২৩ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Announcement Details</label>
                    <textarea name="coupon_announcement" class="form-control" rows="2"><?php echo sanitize($settings['coupon_announcement'] ?? '📅 প্রতি মাসের ১ তারিখ রাত ৮:০০ টায় Smart Collection-এর অফিসিয়াল Facebook Live-এ বিজয়ী ঘোষণা করা হবে।'); ?></textarea>
                </div>

                <hr style="margin: 1.5rem 0; border: 0; border-top: 1px dashed #ccc;">

                <div class="form-group">
                    <label class="form-label">Voucher Terms & Conditions</label>
                    <textarea name="voucher_terms" class="form-control" rows="4"
                        placeholder="e.g. 1. This voucher is valid for 30 days. 2. Not redeemable for cash."><?php echo sanitize($settings['voucher_terms'] ?? ''); ?></textarea>
                    <small class="text-muted">These terms will be printed on the invoice</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Return QR Code URL</label>
                    <input type="url" name="return_qr_url" class="form-control"
                        value="<?php echo sanitize($settings['return_qr_url'] ?? ''); ?>" placeholder="https://yourdomain.com/pos/admin/returns.php">
                    <small class="text-muted">Base URL of your returns page. The invoice number will be appended automatically (e.g. ?invoice=INV-...). If empty, the system will use the current site URL.</small>
                </div>

                <div class="text-right" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Voucher Settings
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include 'includes/footer.php'; ?>
