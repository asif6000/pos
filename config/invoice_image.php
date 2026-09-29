<?php
/**
 * The invoice as a self-contained HTML page, for the bridge to draw to a PNG.
 *
 * This exists because the receipt image cannot be made where the sale is. The
 * POS is a shared host with neither GD nor a browser, and the only thing that
 * can rasterise a page is the Chromium sitting on the shop PC next to the
 * WhatsApp bridge. So the POS writes the markup and the bridge draws it - see
 * whatsapp-server/lib/render.js, which was written for this and had never been
 * called by anything.
 *
 * Self-contained on purpose. renderHtml() blocks every request that is not a
 * data: URL, so a web font or a remote logo would not load and the receipt would
 * arrive half drawn. That means the font has to be a family the machine already
 * has, which is why this uses generic families and no @import.
 *
 * Only ever shown on a phone, so it is laid out for a narrow screen at 2x and
 * kept to one column: a receipt that needs scrolling is a receipt that gets
 * misread.
 */

/**
 * Build the invoice page for one sale.
 *
 * @param PDO    $db
 * @param int    $saleId
 * @param int    $ownerId  settings are owner-scoped, and the shop name on a
 *                        receipt must be this shop's
 * @param string $phone    the number it is going to, shown so the customer can
 *                        see it went to their own phone
 * @return array{ok: bool, html?: string, error?: string}
 */
/**
 * One sale, its items and its shop settings, read once.
 *
 * Both the printed receipt and the SMS receipt are built from this, and that is
 * the point of it. When each read the sale separately they drifted: the receipt
 * used "unit_price" where the table said "price" and printed 0.00, and nothing
 * threw, so the two could quietly disagree about what a customer had bought. One
 * read, two renderings.
 *
 * Owner-scoped, unlike the unfiltered read in get-invoice.php: a receipt built
 * for the wrong shop is worse than no receipt.
 *
 * @return array{ok:bool, error?:string, sale?:array, items?:array, settings?:array}
 */
function invoiceLoadSale($db, $saleId, $ownerId)
{
    $saleId = (int)$saleId;
    if ($saleId <= 0) {
        return ['ok' => false, 'error' => 'Invoice number bujha jay ni.'];
    }

    $stmt = $db->prepare("
        SELECT s.*, c.name AS customer_name, c.phone AS customer_phone,
               u.name AS cashier_name
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.owner_id = ?");
    $stmt->execute([$saleId, $ownerId]);
    $sale = $stmt->fetch();
    if (!$sale) {
        return ['ok' => false, 'error' => 'Ei invoice-ta nai.'];
    }

    // The columns are read by name, never as "price" or "total": sale_items calls
    // them unit_price and total_price, and a missing key under ?? is a silent 0.
    $items = [];
    $stmt = $db->prepare("
        SELECT si.product_name, si.quantity, si.unit_price, si.total_price
        FROM sale_items si
        WHERE si.sale_id = ?
        ORDER BY si.id ASC");
    $stmt->execute([$saleId]);
    foreach ($stmt->fetchAll() as $row) {
        $qty = (float)($row['quantity'] ?? 0);
        $rate = (float)($row['unit_price'] ?? 0);
        $items[] = [
            'name'  => trim((string)($row['product_name'] ?? '')),
            'qty'   => $qty,
            'rate'  => $rate,
            // A sale item whose total was never written still has a quantity and a
            // rate, and a receipt that under-charges because a column was empty
            // is worse than one that adds up.
            'total' => (float)($row['total_price'] ?? ($qty * $rate)),
        ];
    }

    // Owner-scoped, and the fallback is per key rather than per row on purpose.
    //
    // This table holds two kinds of row: the shop's own, and a set written with no
    // owner at all - shop_name lives there and nowhere else, so a receipt built
    // for a real shop came out with no shop name on it and a generic
    // "POS System" where the header should be. Falling back for the WHOLE row
    // would be wrong: a shop that has deliberately set its own receipt footer
    // would be overwritten by the global one. So the global rows are laid
    // underneath and the owner's own values win, key by key.
    //
    // Owner scoping is not weakened by this. Every key the owner has set is still
    // the owner's, and a receipt for the wrong shop is still impossible - the
    // sale and its items are read with owner_id in the WHERE, and only the
    // shop's own cosmetics come from the fallback.
    $settings = [];
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id IS NULL");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            if (!array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        // A receipt without the global cosmetics still prints. Losing the shop
        // name is not worth refusing to send the invoice over.
        error_log('invoice settings fallback failed: ' . $e->getMessage());
    }

    return ['ok' => true, 'sale' => $sale, 'items' => $items, 'settings' => $settings];
}

/**
 * The receipt as a self-contained HTML page, for the bridge to draw to a PNG.
 *
 * @see invoiceLoadSale() for the data this renders.
 */
function invoiceBuildHtml($db, $saleId, $ownerId, $phone = '')
{
    $data = invoiceLoadSale($db, $saleId, $ownerId);
    if (empty($data['ok'])) {
        return ['ok' => false, 'error' => $data['error'] ?? 'Invoice banano jay ni.'];
    }

    $sale     = $data['sale'];
    $items    = $data['items'];
    $settings = $data['settings'];

    $shopName   = trim((string)($settings['shop_name'] ?? '')) ?: 'POS System';
    $shopPhone  = trim((string)($settings['shop_phone'] ?? ''));
    $shopSite   = trim((string)($settings['shop_website'] ?? ''));
    $footer     = trim((string)($settings['receipt_footer'] ?? ''));
    $terms      = trim((string)($settings['voucher_terms'] ?? ''));
    $returnUrl  = trim((string)($settings['return_qr_url'] ?? ''));

    $number     = (string)($sale['invoice_number'] ?? ('#' . $saleId));
    $customer   = trim((string)($sale['customer_name'] ?? '')) ?: 'Walk-in Customer';
    $cashier    = trim((string)($sale['cashier_name'] ?? ''));
    $createdAt  = strtotime((string)($sale['created_at'] ?: 'now'));
    $when       = date('d M Y, h:i A', $createdAt ?: time());
    $paidLabel  = ucfirst((string)($sale['payment_method'] ?: ''));

    $money = function ($v) {
        return number_format((float)$v, 2);
    };

    // ── rows ────────────────────────────────────────────────────────────────
    // The keys this reads are the ones invoiceLoadSale() actually returns -
    // name, qty, rate, total - and the column names behind them (product_name,
    // quantity, unit_price, total_price) are kept only as a fallback.
    //
    // The order matters and it used to be wrong. This loop asked for
    // "unit_price" and "price", and invoiceLoadSale() has returned "rate" for
    // every one of its keys, so both lookups missed and the ?? quietly produced
    // 0: every line of the receipt printed Rate 0.00 next to a correct Amount,
    // and nothing threw. The customer's copy of the receipt disagreed with the
    // till. A missing key under ?? is not an error, it is a zero, so the first
    // name in the chain has to be the name the array really uses.
    $rows = '';
    foreach ($items as $it) {
        $qty   = (float)($it['qty'] ?? $it['quantity'] ?? 0);
        $rate  = (float)($it['rate'] ?? $it['unit_price'] ?? $it['price'] ?? 0);
        $line  = (float)($it['total'] ?? $it['total_price'] ?? ($qty * $rate));
        $name  = trim((string)($it['name'] ?? $it['product_name'] ?? ''));
        $rows .= '<tr>'
               . '<td class="n">' . invoiceEscape($name) . '</td>'
               . '<td class="c">' . invoiceEscape(rtrim(rtrim(number_format($qty, 2), '0'), '.')) . '</td>'
               . '<td class="r">' . invoiceEscape(number_format($rate, 2)) . '</td>'
               . '<td class="r">' . invoiceEscape(number_format($line, 2)) . '</td>'
               . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="4" class="c muted">No items on this invoice.</td></tr>';
    }

    // ── totals ──────────────────────────────────────────────────────────────
    $totals = '';
    $add = function ($label, $value, $strong = false) use (&$totals) {
        $cls = $strong ? ' class="strong"' : '';
        $totals .= '<tr' . $cls . '>'
                 . '<td colspan="3"' . $cls . '>' . invoiceEscape($label) . '</td>'
                 . '<td class="r"' . $cls . '>' . invoiceEscape($value) . '</td>'
                 . '</tr>';
    };
    $add('Subtotal', $money($sale['subtotal'] ?? 0));
    if ((float)($sale['discount_amount'] ?? 0) > 0) {
        $add('Discount' . ((float)($sale['discount_percent'] ?? 0) > 0
            ? ' (' . rtrim(rtrim(number_format((float)$sale['discount_percent'], 2), '0'), '.') . '%)'
            : ''), '-' . $money($sale['discount_amount']));
    }
    if ((float)($sale['vat_amount'] ?? 0) > 0) {
        $add('VAT' . ((float)($sale['vat_percent'] ?? 0) > 0
            ? ' (' . rtrim(rtrim(number_format((float)$sale['vat_percent'], 2), '0'), '.') . '%)'
            : ''), $money($sale['vat_amount']));
    }
    $add('TOTAL', $money($sale['total'] ?? 0), true);
    $add('Paid' . ($paidLabel !== '' ? " ($paidLabel)" : ''), $money($sale['paid_amount'] ?? 0));
    if ((float)($sale['change_amount'] ?? 0) > 0) {
        $add('Change', $money($sale['change_amount']));
    }

    // ── optional blocks ─────────────────────────────────────────────────────
    $footBlocks = '';
    if ($returnUrl !== '' && preg_match('#^https?://#i', $returnUrl)) {
        $footBlocks .= '<div class="qr"><div class="qrlabel">Return / Exchange</div>'
                     . '<img src="' . invoiceEscape($returnUrl) . '" alt=""></div>';
    }
    if ($terms !== '') {
        $footBlocks .= '<div class="terms">' . nl2br(invoiceEscape($terms)) . '</div>';
    }
    if ($footer !== '') {
        $footBlocks .= '<div class="thanks">' . nl2br(invoiceEscape($footer)) . '</div>';
    }
    $toLine = $phone !== '' ? '<div class="to">Sent to +' . invoiceEscape($phone) . '</div>' : '';

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><style>'
        // The paper is white because this says so, not because the machine
        // happened to be in light mode.
        //
        // Nothing here set a background, so the canvas was painted with whatever
        // the browser's own colour scheme defaults to. Headless Chrome on the
        // bridge answered dark: the PNG came out #121212, and the body text is
        // #111 - a difference of one step, so the customer received a black
        // rectangle with the receipt printed on it in a shade of black they could
        // not tell apart from the paper. Nothing errored; the file was a
        // perfectly valid PNG of nothing legible.
        //
        // Three declarations, because each covers a different way the background
        // can go missing: color-scheme stops the UA painting a dark canvas,
        // background on html and body puts white on the two boxes that are
        // actually painted, and print-color-adjust keeps it if this is ever
        // printed rather than photographed.
        . 'html{background:#fff;color-scheme:light}'
        . 'body{background:#fff;color-scheme:light;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . '*{box-sizing:border-box;margin:0;padding:0}'
        . 'body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;color:#111;'
        . 'width:340px;margin:0 auto;padding:18px 16px;font-size:13px;line-height:1.45;'
        . '-webkit-font-smoothing:antialiased}'
        . '.shop{text-align:center;border-bottom:2px solid #111;padding-bottom:10px}'
        . '.shop h1{font-size:19px;letter-spacing:.5px;font-weight:700}'
        . '.shop p{font-size:11px;color:#333;margin-top:2px}'
        . '.meta{margin:10px 0;border-bottom:1px dashed #999;padding-bottom:8px}'
        . '.meta div{display:flex;justify-content:space-between;font-size:12px;padding:1px 0}'
        . '.meta b{font-weight:600;color:#000}'
        . 'table{width:100%;border-collapse:collapse;font-size:12px}'
        . 'th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;'
        . 'color:#555;border-bottom:1px solid #111;padding:4px 2px}'
        . 'td{padding:5px 2px;border-bottom:1px solid #eee;vertical-align:top}'
        . '.n{word-break:break-word;padding-right:6px}'
        . '.c{width:34px;text-align:center}'
        . '.r{text-align:right;white-space:nowrap;padding-left:6px}'
        . '.tots{margin-top:8px;border-top:1px solid #111;padding-top:6px}'
        . '.strong{font-weight:700;font-size:14px;border-top:1px solid #111;border-bottom:1px solid #111}'
        . '.muted{color:#666;padding:8px 0}'
        . '.to{text-align:center;font-size:11px;color:#333;margin-top:8px;'
        . 'border-top:1px dashed #999;padding-top:8px}'
        . '.qr{text-align:center;margin-top:10px}'
        . '.qr img{width:120px;height:120px}'
        . '.qrlabel{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#555}'
        . '.terms{font-size:10px;color:#444;margin-top:10px;text-align:center}'
        . '.thanks{text-align:center;font-size:12px;margin-top:10px;font-weight:600}'
        . '</style></head><body>'
        . '<div class="shop"><h1>' . invoiceEscape($shopName) . '</h1>'
        . ($shopPhone !== '' ? '<p>' . invoiceEscape($shopPhone) . '</p>' : '')
        . ($shopSite !== '' ? '<p>' . invoiceEscape($shopSite) . '</p>' : '')
        . '</div>'
        . '<div class="meta">'
        . '<div><span>Invoice</span><b>' . invoiceEscape($number) . '</b></div>'
        . '<div><span>Date</span><b>' . invoiceEscape($when) . '</b></div>'
        . '<div><span>Customer</span><b>' . invoiceEscape($customer) . '</b></div>'
        . ($cashier !== '' ? '<div><span>Cashier</span><b>' . invoiceEscape($cashier) . '</b></div>' : '')
        . '</div>'
        . '<table><thead><tr><th class="n">Item</th><th class="c">Qty</th>'
        . '<th class="r">Rate</th><th class="r">Amount</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<table class="tots"><tbody>' . $totals . '</tbody></table>'
        . $toLine
        . $footBlocks
        . '</body></html>';

    return ['ok' => true, 'html' => $html, 'number' => $number, 'phone' => $phone];
}

/**
 * Escape for both HTML and the attribute context.
 *
 * ENT_QUOTES covers the one attribute in here that carries a URL, and the shop's
 * own name and the product names are all user input that has to survive being
 * pasted into a page a browser will render.
 */
function invoiceEscape($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
