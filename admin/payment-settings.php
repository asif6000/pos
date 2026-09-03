<?php
/**
 * POS System - Payment Settings
 * Configure payment gateway numbers (bKash / Nagad / Bank) shown on the public checkout page
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !isSuperAdmin()) {
    redirect('../admin/login.php');
}

define('PAGE_TITLE', 'Payment Settings');

$db = getDB();

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_payment') {
        $data = [
            'bkash_number'     => sanitize($_POST['bkash_number']     ?? ''),
            'nagad_number'     => sanitize($_POST['nagad_number']     ?? ''),
            'payment_note'     => sanitize($_POST['payment_note']     ?? ''),
            'whatsapp_number'  => preg_replace('/[^0-9]/', '', $_POST['whatsapp_number']  ?? ''),
            'whatsapp_message' => sanitize($_POST['whatsapp_message'] ?? 'Hello! I need support for AVA POS System.'),
        ];
        try {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) 
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
            foreach ($data as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            setFlash('success', 'Payment settings saved successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error saving payment settings.');
        }
        redirect('payment-settings.php');
    }

    if ($action === 'save_whatsapp') {
        $waNumber  = preg_replace('/[^0-9]/', '', $_POST['whatsapp_number']  ?? '');
        $waMessage = sanitize($_POST['whatsapp_message'] ?? 'Hello! I need support for AVA POS System.');
        try {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['whatsapp_number',  $waNumber]);
            $stmt->execute(['whatsapp_message', $waMessage]);
            setFlash('success', 'WhatsApp settings saved! Landing page-এ এখনই প্রভাব পড়বে।');
        } catch (PDOException $e) {
            setFlash('danger', 'Error saving WhatsApp settings.');
        }
        redirect('payment-settings.php#whatsapp');
    }
}

// Load current settings
$get = [];
$q = $db->query("SELECT setting_key, setting_value FROM system_settings");
while ($row = $q->fetch()) {
    $get[$row['setting_key']] = $row['setting_value'];
}

include 'includes/header.php';
?>
<div class="page-heading">
    <h2>Payment Gateway Settings</h2>
    <p class="text-muted">These numbers are shown on the public checkout page when a customer chooses a plan. Customers send payment to these numbers.</p>
</div>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<div style="max-width: 700px;">

    <!-- ── Payment Methods Card ── -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <i class="fas fa-credit-card"></i> Payment Methods
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save_payment">

                <div class="form-group">
                    <label>bKash Number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-mobile-alt" style="color:#e2136e;"></i></span>
                        <input type="text" name="bkash_number" class="form-control"
                            value="<?php echo htmlspecialchars($get['bkash_number'] ?? ''); ?>"
                            placeholder="e.g. 017XXXXXXXX">
                    </div>
                </div>

                <div class="form-group">
                    <label>Nagad Number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-mobile-alt" style="color:#f97316;"></i></span>
                        <input type="text" name="nagad_number" class="form-control"
                            value="<?php echo htmlspecialchars($get['nagad_number'] ?? ''); ?>"
                            placeholder="e.g. 017XXXXXXXX">
                    </div>
                </div>

                <div class="form-group">
                    <label>Payment Instructions / Note</label>
                    <textarea name="payment_note" class="form-control" rows="3"
                        placeholder="e.g. After payment, please enter your sender number and Transaction ID."><?php echo htmlspecialchars($get['payment_note'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Payment Settings
                </button>
            </form>
        </div>
    </div>

    <!-- ── WhatsApp Support Card ── -->
    <div class="card" id="whatsapp" style="margin-bottom:1.5rem;scroll-margin-top:80px;">
        <div class="card-header" style="background:linear-gradient(135deg,#064e3b,#059669);color:#fff;border:none;">
            <i class="fab fa-whatsapp"></i> WhatsApp Support Settings
        </div>
        <div class="card-body">
            <p class="text-muted" style="margin-bottom:1.25rem;font-size:.9rem;">
                এই number টা landing page-এ floating WhatsApp button-এ ব্যবহার হবে। Customer সরাসরি এই number-এ WhatsApp করতে পারবে।
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="save_whatsapp">

                <div class="form-group">
                    <label>
                        <i class="fab fa-whatsapp" style="color:#25D366;"></i>
                        WhatsApp Number
                        <small class="text-muted">(country code সহ, শুধু সংখ্যা)</small>
                    </label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:#25D366;color:#fff;border-color:#25D366;">
                            <i class="fab fa-whatsapp"></i>
                        </span>
                        <input type="text" name="whatsapp_number" class="form-control"
                            value="<?php echo htmlspecialchars($get['whatsapp_number'] ?? ''); ?>"
                            placeholder="e.g. 8801XXXXXXXXX"
                            pattern="[0-9]+"
                            title="শুধু সংখ্যা দিন, country code সহ">
                    </div>
                    <small class="text-muted">
                        উদাহরণ: Bangladesh → <strong>8801712345678</strong> &nbsp;|&nbsp;
                        <a href="https://wa.me/<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $get['whatsapp_number'] ?? '')); ?>" target="_blank" rel="noopener" style="color:#25D366;">
                            <i class="fas fa-external-link-alt"></i> Test করুন
                        </a>
                    </small>
                </div>

                <div class="form-group">
                    <label>Default WhatsApp Message</label>
                    <textarea name="whatsapp_message" class="form-control" rows="3"
                        placeholder="Hello! I need support for AVA POS System."><?php echo htmlspecialchars($get['whatsapp_message'] ?? 'Hello! I need support for AVA POS System.'); ?></textarea>
                    <small class="text-muted">Customer button click করলে এই message auto-fill হবে।</small>
                </div>

                <!-- Live Preview -->
                <?php
                $waNum = preg_replace('/[^0-9]/', '', $get['whatsapp_number'] ?? '');
                $waMsg = $get['whatsapp_message'] ?? 'Hello! I need support for AVA POS System.';
                if ($waNum):
                ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;">
                    <a href="https://wa.me/<?php echo $waNum; ?>?text=<?php echo urlencode($waMsg); ?>"
                       target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:.6rem;background:#25D366;color:#fff;text-decoration:none;padding:.6rem 1.25rem;border-radius:30px;font-weight:700;font-size:.88rem;box-shadow:0 4px 14px rgba(37,211,102,.35);">
                        <i class="fab fa-whatsapp" style="font-size:1.1rem;"></i>
                        WhatsApp Preview
                    </a>
                    <span style="font-size:.82rem;color:#15803d;">
                        <i class="fas fa-check-circle"></i>
                        Number saved: <strong>+<?php echo $waNum; ?></strong>
                    </span>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-success">
                    <i class="fab fa-whatsapp"></i> Save WhatsApp Settings
                </button>
            </form>
        </div>
    </div>

    <a href="dashboard.php" class="btn btn-secondary" style="margin-top:.25rem;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<?php include 'includes/footer.php'; ?>
