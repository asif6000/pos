<?php
/**
 * POS System - Print Product Labels
 * Print barcode labels for products (35mm x 45mm)
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$db = getDB();

// Get settings
$settings = [];
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$shopName = $settings['shop_name'] ?? 'Smart Collection';

// Get product IDs from query
$productIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$quantity = (int) ($_GET['qty'] ?? 1);

if (empty($productIds)) {
    echo '<p>No products selected</p>';
    exit;
}

// Get products
$placeholders = str_repeat('?,', count($productIds) - 1) . '?';
$stmt = $db->prepare("SELECT id, name, barcode, sell_price FROM products WHERE id IN ($placeholders)");
$stmt->execute($productIds);
$products = $stmt->fetchAll();

// Get sizes and layout settings (default to DB settings)
$getOr = function ($key, $default) use ($settings) {
    return (isset($_GET[$key]) && trim($_GET[$key]) !== '') ? $_GET[$key] : ($settings[$key] ?? $default);
};
$stickerWidth = (float) $getOr('sw', $settings['barcode_sticker_width'] ?? 1.7700);
$stickerHeight = (float) $getOr('sh', $settings['barcode_sticker_height'] ?? 1.3800);
$paperWidth = (float) $getOr('pw', $settings['barcode_paper_width'] ?? 1.8000);
$paperHeight = (float) $getOr('ph', $settings['barcode_paper_height'] ?? 1.4000);

$topMargin = (float) ($settings['barcode_top_margin'] ?? 0.0000);
$leftMargin = (float) ($settings['barcode_left_margin'] ?? 0.0000);
$stickersPerRow = (int) ($settings['barcode_stickers_per_row'] ?? 1);
$rowDistance = (float) ($settings['barcode_row_distance'] ?? 0.0000);
$colDistance = (float) ($settings['barcode_col_distance'] ?? 0.0000);
$stickersPerSheet = (int) ($settings['barcode_stickers_per_sheet'] ?? 1);

// Ensure reasonable limits
$stickerWidth = max(0.1, $stickerWidth);
$stickerHeight = max(0.1, $stickerHeight);
$stickersPerRow = max(1, $stickersPerRow);
$stickersPerSheet = max(1, $stickersPerSheet);

if (empty($products)) {
    echo '<p>No products found</p>';
    exit;
}

/**
 * Generate a CODE128-B barcode as an inline SVG (no JS dependency).
 * @param string $data  Barcode content (ASCII 32-126 supported)
 * @param int $height   Barcode height in px
 * @param float $module Bar module width in px
 * @return string SVG markup
 */
function code128BarcodeSvg($data, $height = 30, $module = 1.5)
{
    static $patterns = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
        5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
        15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
        25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
        35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
        45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
        55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
        65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
        75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
        85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
        95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131'
    ];
    $startPattern = '211214'; // Start code B
    $stopPattern = '2331112'; // Stop (13 modules)

    $data = (string) $data;
    $chars = [];
    $check = 104; // Start B value
    for ($i = 0; $i < strlen($data); $i++) {
        $ascii = ord($data[$i]);
        if ($ascii < 32 || $ascii > 126) {
            continue;
        }
        $chars[] = $ascii - 32;
        $check += ($ascii - 32) * ($i + 1);
    }
    $check %= 103;

    $modules = [];
    $append = function ($p) use (&$modules) {
        for ($k = 0; $k < strlen($p); $k++) {
            $modules[] = (int) $p[$k];
        }
    };
    $append($startPattern);
    foreach ($chars as $v) {
        $append($patterns[$v]);
    }
    $append($patterns[$check]);
    $append($stopPattern);

    $quiet = 10;
    $totalModules = $quiet * 2 + array_sum($modules);
    $barWidth = $module;
    $x = $quiet * $barWidth;
    $bars = '';
    $isBar = true;
    foreach ($modules as $m) {
        $w = $m * $barWidth;
        if ($isBar) {
            $bars .= '<rect x="' . number_format($x, 2, '.', '') . '" y="0" width="' . number_format($w, 2, '.', '') . '" height="' . (int) $height . '" fill="#000"/>';
        }
        $x += $w;
        $isBar = !$isBar;
    }
    $totalWidth = $totalModules * $barWidth;

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . number_format($totalWidth, 2, '.', '') . '" height="' . (int) $height . '" viewBox="0 0 ' . number_format($totalWidth, 2, '.', '') . ' ' . (int) $height . '">' . $bars . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Labels</title>
    <link rel="stylesheet" href="../assets/css/hind-siliguri.css">
    <style>
        @page {
            size: <?php echo floatval($paperWidth); ?>in <?php echo floatval($paperHeight); ?>in;
            margin: 0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Hind Siliguri', Arial, sans-serif;
            background: #f0f0f0;
        }

        .print-controls {
            background: white;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            justify-content: center;
        }

        .print-controls button {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            cursor: pointer;
            border: none;
            border-radius: 4px;
        }

        .btn-print {
            background: #2563eb;
            color: white;
        }

        .btn-back {
            background: #6b7280;
            color: white;
        }

        /* Container for the sheet/page content */
        .sheet {
            width: <?php echo floatval($paperWidth); ?>in;
            background: white;
            position: relative;
            /* Margins applied as padding to the sheet container */
            padding-top: <?php echo floatval($topMargin); ?>in;
            padding-left: <?php echo floatval($leftMargin); ?>in;
            display: flex;
            flex-wrap: wrap;
            align-content: flex-start;
        }

        .label-wrapper {
            /* Wrapper to handle margins/spacing without affecting label size */
            width: <?php echo floatval($stickerWidth); ?>in;
            height: <?php echo floatval($stickerHeight); ?>in;
            margin-right: <?php echo floatval($colDistance); ?>in;
            margin-bottom: <?php echo floatval($rowDistance); ?>in;
            /* Ensure correct number of items per row */
            /* If we have specific stickers per row, we might need a hard break or width calculation, 
               but flex-wrap usually handles it if width + margin matches paper width. 
               However, with fixed "stickers per row", we can force a break if needed, but CSS grid is better for strict rows.
            */
            display: inline-block;
            vertical-align: top;
        }

        /* Clear margin for last item in row if needed, but for simplicity we keep it 
           unless it pushes content off page. With flexible paper size, it's usually fine. 
        */

        .label {
            width: 100%;
            height: 100%;
            background: white;
            border: 1px dashed #ccc;
            /* Removed in print */
            padding: 0.04in 0.04in 0.03in 0.04in;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            overflow: hidden;
            padding-top: 0.1in;
        }

        .label-shop {
            font-size: 12pt;
            font-weight: bold;
            text-align: center;
            color: #333;
            line-height: 1.15;
            max-width: 100%;
            max-height: 0.24in;
            overflow: hidden;
            word-wrap: break-word;
            margin-bottom: 1px;
        }

        .label-name {
            font-size: 10.5pt;
            font-weight: bold;
            text-align: center;
            line-height: 1.15;
            max-height: 0.32in;
            overflow: hidden;
            margin: 3px 0 1px 0;
            word-wrap: break-word;
        }

        .label-barcode {
            width: 100%;
            height: auto;
            min-height: 0.32in;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }

        .label-barcode svg {
            max-width: 100%;
            height: auto;
            display: block;
        }

        .label-barcode-text {
            font-size: 7pt;
            font-family: monospace;
            text-align: center;
            margin-top: -2px;
        }

        .label-price {
            font-size: 11pt;
            font-weight: bold;
            text-align: center;
            margin: 3px 0 1px 0;
        }

        .label-currency {
            font-size: 6pt;
            vertical-align: top;
        }

        @media print {
            body {
                background: white;
            }

            .print-controls {
                display: none !important;
            }

            .sheet {
                /* Ensure background and borders are handled correctly */
                box-shadow: none;
                margin: 0;
            }
            
            

            .sheet:last-child {
                page-break-after: auto;
            }

            .label {
                border: none;
            }
        }
    </style>
</head>

<body>
    <div class="print-controls">
        <button class="btn-back" onclick="window.close(); window.history.back();">
            ← Back
        </button>
        <button class="btn-print" id="printLabelsBtn" onclick="window.print();">
            🖨️ Print Labels
        </button>
        <div style="background: #f8f9fa; padding: 0.5rem; border-radius: 4px; font-size: 0.9rem;">
            <strong>Layout:</strong> <?php echo floatval($stickerWidth); ?>"x<?php echo floatval($stickerHeight); ?>" |
            Paper: <?php echo floatval($paperWidth); ?>"x<?php echo floatval($paperHeight); ?>" |
            Margins: T:<?php echo floatval($topMargin); ?>" L:<?php echo floatval($leftMargin); ?>"
        </div>
    </div>

    <!-- Content Generation -->
    <?php
    $allLabels = [];
    foreach ($products as $product) {
        for ($i = 0; $i < $quantity; $i++) {
            $allLabels[] = $product;
        }
    }

    $totalLabels = count($allLabels);
    $currentLabel = 0;

    // If strict sheets, chunk them. If continuous (1 per sheet), it's 1 label per 'sheet' div
    // But for 1 sticker per sheet (continuous), we actually want 1 div per label, effectively acting as a page.
    // If multiple per sheet, we fill the sheet.
    
    $labelsPerSheet = $stickersPerSheet;

    // Processing sheets
    while ($currentLabel < $totalLabels) {
        $sheetStyle = '';
        if ($stickersPerSheet > 1) {
            $sheetStyle = "height: {$paperHeight}in; overflow: hidden;";
        } elseif ($stickersPerSheet == 1) {
            $sheetStyle .= "height: {$paperHeight}in; page-break-after: always;";
        }
        echo '<div class="sheet" style="' . $sheetStyle . '">';

        $labelsOnThisSheet = 0;
        while ($labelsOnThisSheet < $labelsPerSheet && $currentLabel < $totalLabels) {
            $product = $allLabels[$currentLabel];
            $uniqueId = "barcode-" . $product['id'] . "-" . $currentLabel;
            ?>
            <div class="label-wrapper">
                <div class="label">
                    <div class="label-shop"><?php echo htmlspecialchars($shopName); ?></div>
                    <div class="label-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <?php if (!empty($product['barcode'])): ?>
                        <div class="label-price">
                            Price <?php echo number_format($product['sell_price'], 2); ?><span
                                class="label-currency"><?php echo CURRENCY; ?></span>
                        </div>
                        <div class="label-barcode">
                            <?php echo code128BarcodeSvg($product['barcode'], 30, 1.5); ?>
                        </div>
                        <div class="label-barcode-text"><?php echo htmlspecialchars($product['barcode']); ?></div>
                    <?php else: ?>
                        <div class="label-price">
                            Price <?php echo number_format($product['sell_price'], 2); ?><span
                                class="label-currency"><?php echo CURRENCY; ?></span>
                        </div>
                        <div class="label-barcode" style="border: 1px dashed #ccc; font-size: 8pt; color: #999;">No Barcode</div>
                        <div class="label-barcode-text">ID: <?php echo $product['id']; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            // Row break logic if needed, but flex-wrap handles flow. 
            // If we strictly want to enforce 'stickers per row', we can add a break div if (count % perRow == 0)
            // But CSS width/margin usually dictates this better.
    
            $currentLabel++;
            $labelsOnThisSheet++;
        }

        echo '</div>'; // End sheet
    }
    ?>

    <script>
        (function () {
            var printBtn = document.getElementById('printLabelsBtn');
            if (printBtn) {
                printBtn.disabled = false;
            }
        })();
    </script>
</body>

</html>