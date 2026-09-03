<?php
/**
 * POS System - Barcode Settings
 * Configure barcode printer settings
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Barcode Settings');

$db = getDB();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsData = [
        'shop_name' => trim($_POST['shop_name'] ?? ''),
        'barcode_top_margin' => (float) ($_POST['top_margin'] ?? 0),
        'barcode_left_margin' => (float) ($_POST['left_margin'] ?? 0),
        'barcode_sticker_width' => (float) ($_POST['sticker_width'] ?? 1.7700),
        'barcode_sticker_height' => (float) ($_POST['sticker_height'] ?? 1.3800),
        'barcode_paper_width' => (float) ($_POST['paper_width'] ?? 1.8000),
        'barcode_paper_height' => (float) ($_POST['paper_height'] ?? 1.4000),
        'barcode_stickers_per_row' => (int) ($_POST['stickers_per_row'] ?? 1),
        'barcode_row_distance' => (float) ($_POST['row_distance'] ?? 0),
        'barcode_col_distance' => (float) ($_POST['col_distance'] ?? 0),
        'barcode_stickers_per_sheet' => (int) ($_POST['stickers_per_sheet'] ?? 1),
    ];

    try {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

        foreach ($settingsData as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        setFlash('success', 'Barcode settings saved successfully!');
    } catch (PDOException $e) {
        setFlash('danger', 'Error saving settings.');
    }
    redirect('barcode-settings.php');
}

// Load current settings
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
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

<form method="POST">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-barcode"></i> Barcode Printer Settings (Smart Collection)</h3>
        </div>
        <div class="card-body">
            <p class="text-muted" style="margin-bottom: 1.5rem;">
                Configure the default dimensions for your label printer. All units are in <strong>Inches</strong> unless
                specified.
            </p>

            <!-- Store Name -->
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label required">Store Name</label>
                <input type="text" name="shop_name" class="form-control" 
                    value="<?php echo htmlspecialchars($settings['shop_name'] ?? 'Smart Collection'); ?>" 
                    placeholder="Enter your store name" required>
                <small class="form-text text-muted">This will appear prominently in bold, larger font at the top of printed labels</small>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <!-- Margins -->
                <div>
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem;">Margins
                        (Inches)</h4>
                    <div class="form-group">
                        <label class="form-label required">Additional Top Margin</label>
                        <input type="number" name="top_margin" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_top_margin'] ?? 0.0000; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Additional Left Margin</label>
                        <input type="number" name="left_margin" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_left_margin'] ?? 0.0000; ?>" required>
                    </div>
                </div>

                <!-- Sticker Dimensions -->
                <div>
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem;">Sticker
                        Dimensions (Inches)</h4>
                    <div class="form-group">
                        <label class="form-label required">Width of Sticker</label>
                        <input type="number" name="sticker_width" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_sticker_width'] ?? 1.7700; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Height of Sticker</label>
                        <input type="number" name="sticker_height" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_sticker_height'] ?? 1.3800; ?>" required>
                    </div>
                </div>

                <!-- Paper Dimensions -->
                <div>
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem;">Paper
                        Dimensions (Inches)</h4>
                    <div class="form-group">
                        <label class="form-label required">Paper Width</label>
                        <input type="number" name="paper_width" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_paper_width'] ?? 1.8000; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Paper Height</label>
                        <input type="number" name="paper_height" class="form-control" step="0.0001" min="0"
                            value="<?php echo $settings['barcode_paper_height'] ?? 1.4000; ?>" required>
                    </div>
                </div>

                <!-- Layout Config -->
                <div>
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem;">Layout
                        Configuration</h4>
                    <div class="form-group">
                        <label class="form-label required">Stickers in One Row</label>
                        <input type="number" name="stickers_per_row" class="form-control" step="1" min="1"
                            value="<?php echo $settings['barcode_stickers_per_row'] ?? 1; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">No. of Stickers per Sheet</label>
                        <input type="number" name="stickers_per_sheet" class="form-control" step="1" min="1"
                            value="<?php echo $settings['barcode_stickers_per_sheet'] ?? 1; ?>" required>
                        <small class="form-text text-muted">Use 1 for continuous roll</small>
                    </div>
                </div>

                <!-- Distances -->
                <div style="grid-column: span 2;">
                    <h4 style="margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem;">Distances
                        (Inches)</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group">
                            <label class="form-label required">Distance between two rows</label>
                            <input type="number" name="row_distance" class="form-control" step="0.0001" min="0"
                                value="<?php echo $settings['barcode_row_distance'] ?? 0.0000; ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Distance between two columns</label>
                            <input type="number" name="col_distance" class="form-control" step="0.0001" min="0"
                                value="<?php echo $settings['barcode_col_distance'] ?? 0.0000; ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info" style="margin-top: 1rem;">
                <i class="fas fa-info-circle"></i>
                These settings will be used as defaults when printing labels.
            </div>
        </div>
        <div class="card-footer" style="text-align: right;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Settings
            </button>
        </div>
    </div>
</form>

<?php include 'includes/footer.php'; ?>