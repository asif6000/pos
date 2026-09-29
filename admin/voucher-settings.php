<?php
/**
 * POS System - Voucher Settings
 * Configure the Lucky Entry Coupon
 */

require_once'../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}

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
            'coupon_raffle_url' => sanitize($_POST['coupon_raffle_url'] ?? ''),
            // A checkbox that is absent means off, so this is written as an
            // explicit 0 or 1 rather than left unset. A missing row would read as
            // on, which is the wrong default here: the promo is what pushes the
            // SMS past one segment, and the shop pays for the second one.
            'invoice_sms_promo' => isset($_POST['invoice_sms_promo']) ? 1 : 0,
            'invoice_sms_link'  => isset($_POST['invoice_sms_link']) ? 1 : 0,
            'invoice_sms_max_items' => max(1, min(30, (int)($_POST['invoice_sms_max_items'] ?? 8))),
            // Whitelisted rather than sanitized alone: a select can only send one
            // of three values, and anything else arriving here is either a hand
            // edit or a stale row. Both land on 'auto', which is the behaviour
            // that still delivers - a shop is never locked out of its own
            // invoices by a bad setting.
            'invoice_sms_channel' => (function () {
                $c = strtolower(trim((string)($_POST['invoice_sms_channel'] ?? 'auto')));
                return in_array($c, ['auto', 'whatsapp', 'sms'], true) ? $c : 'auto';
            })(),
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

include'includes/header.php';
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
                        value="<?php echo sanitize($settings['coupon_prize_1'] ?? '🥇 ৫,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Prize 2 (2nd Prize)</label>
                    <input type="text" name="coupon_prize_2" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_2'] ?? '🥈 ৩,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prize 3 (3rd Prize)</label>
                    <input type="text" name="coupon_prize_3" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_3'] ?? '🥉 ২,০০০ Shopping Voucher — ১ জন'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Prize 4</label>
                    <input type="text" name="coupon_prize_4" class="form-control"
                        value="<?php echo sanitize($settings['coupon_prize_4'] ?? '🎁 ৫০০ Shopping Voucher — ১০ জন'); ?>">
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

                <div class="card mb-3" style="border:1px solid #e5e7eb;">
                    <div class="card-body">
                        <h6 class="mb-2">SMS invoice-e promotion link</h6>

                        <div class="form-group">
                            <label class="form-label">Raffle draw link</label>
                            <input type="url" name="coupon_raffle_url" class="form-control"
                                placeholder="https://www.facebook.com/share/..."
                                value="<?php echo sanitize($settings['coupon_raffle_url'] ?? 'https://www.facebook.com/share/1BvdYPmRoH/'); ?>">
                            <small class="text-muted">
                                Ei link-ta invoice SMS-er shathe customer-der paithai. Khali
                                <code>http://</code> ba <code>https://</code> diye shuru holei
                                kaaj korbe - onno kono link niloye deya hobe na.
                            </small>
                        </div>

                        <div class="form-group mb-0">
                            <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                                <input type="checkbox" name="invoice_sms_promo" value="1"
                                    <?php echo (($settings['invoice_sms_promo'] ?? '0') === '1') ? 'checked' : ''; ?>
                                    style="margin-top:0.2rem;">
                                <span>
                                    <strong>SMS e ei link-ta pathao</strong><br>
                                    <small class="text-muted">
                                        OFF thakle SMS ta ekta segment-e thakiye 1 ta charge.
                                        ON thakle link djukar jonno message 2 ta segment hoy,
                                        mane dui gun charge. Link katei felha hoy na - ekta
                                        kkata link-i dead link.
                                    </small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card mb-3" style="border:1px solid #e5e7eb;">
                    <div class="card-body">
                        <h6 class="mb-2">Invoice kivabe pathabe</h6>

                        <div class="form-group mb-0">
                            <label class="form-label">Channel</label>
                            <select name="invoice_sms_channel" class="form-control" style="max-width:340px;">
                                <?php
                                $channelNow = strtolower(trim((string)($settings['invoice_sms_channel'] ?? 'auto')));
                                if (!in_array($channelNow, ['auto', 'whatsapp', 'sms'], true)) {
                                    $channelNow = 'auto';
                                }
                                $channelOptions = [
                                    'auto'     => 'Auto - SMS tried first, WhatsApp-e fallback',
                                    'whatsapp' => 'WhatsApp only - SMS credit lagbe na',
                                    'sms'      => 'SMS only - WhatsApp use hobe na',
                                ];
                                foreach ($channelOptions as $val => $label) {
                                    echo '<option value="' . $val . '"'
                                        . (($channelNow === $val) ? ' selected' : '') . '>'
                                        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                                ?>
                            </select>
                            <small class="text-muted">
                                <strong>Auto</strong> thakle prothome SMS choley, SMS na gele ei text-ta
                                WhatsApp-e pathiye dey - shop er nijer bridge diye, tai kono SMS charge
                                lage na. Eta-i setting ta jodi gateway kaj na kore (mane provider e
                                server IP whitelist nai) tabo-o invoice customer er paithay.
                                <strong>WhatsApp only</strong> thakle SMS credit ekdom chhay, WhatsApp-i
                                jabe. Bridge agent mode e nao thakle WhatsApp pathano jabe na -
                                Settings &gt; WhatsApp Bridge e chalu korte hobe.
                            </small>
                        </div>
                    </div>
                </div>

                <div class="card mb-3" style="border:1px solid #e5e7eb;">
                    <div class="card-body">
                        <h6 class="mb-2">SMS invoice-e item list</h6>

                        <div class="form-group">
                            <label class="form-label">Maximum items in the SMS</label>
                            <input type="number" name="invoice_sms_max_items" class="form-control"
                                min="1" max="30" style="max-width:120px;"
                                value="<?php echo (int)($settings['invoice_sms_max_items'] ?? 8); ?>">
                            <small class="text-muted">
                                Eta-i SMS er charge thik kore. 160 character porjonto ekta
                                segment; 1-2 ta item ekta segment-e thaki, 5-6 ta item
                                hole 2 ta segment - mane dui gun charge. Sonlimit porjonto
                                item na thakle SMS e "...and 3 more items" likha thakbe,
                                jate customer bujhte pare na je list kutu.
                            </small>
                        </div>

                        <div class="form-group mb-0">
                            <label style="display:flex; align-items:flex-start; gap:0.6rem; cursor:pointer;">
                                <input type="checkbox" name="invoice_sms_link" value="1"
                                    <?php echo (($settings['invoice_sms_link'] ?? '0') === '1') ? 'checked' : ''; ?>
                                    style="margin-top:0.2rem;">
                                <span>
                                    <strong>SMS e full invoice link-o pathao</strong><br>
                                    <small class="text-muted">
                                        OFF thakle SMS e shudhu invoice-e text thakbe, link thakbe
                                        na - mane kom charge. ON thakle customer browser e
                                        puro invoice dekhete parbe.
                                    </small>
                                </span>
                            </label>
                        </div>
                    </div>
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
                        <i class="fas fa-save"></i> Save Voucher Settings</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php include'includes/footer.php'; ?>
