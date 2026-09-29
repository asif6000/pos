<?php
/**
 * The secret that lets a customer open one invoice without an account.
 *
 * An SMS receipt cannot carry the whole receipt - it would be four segments of
 * charged text - so it carries a link instead, and this is what that link is
 * made of. One random token per sale, looked up and nothing else.
 *
 * The rule this file exists to enforce: the public page finds a sale by token
 * ONLY. There is no "and also by id" path, because the moment an id is accepted
 * the page becomes a way to walk the whole sales table by counting. So a sale is
 * reachable publicly if and only if somebody was given its link.
 *
 * The token is 32 hex characters - 128 bits, which is not enumerable in any
 * lifetime that matters. It is generated once, on demand, the first time a link
 * is asked for, so a shop that never sends an SMS invoice never grows a column
 * full of secrets it does not use.
 */

/**
 * Add the column, if this database does not have it yet.
 *
 * Uses appSafeDdl, which swallows both "already there" and the privilege error
 * cPanel raises against a migration it does not need. On a host where DDL is
 * denied the column simply will not exist, and the caller gets told so rather
 * than being left with a silent failure on every send.
 *
 * @return bool true when the column is present
 */
function invoiceEnsureTokenColumn($db)
{
    // A cheap probe beats running an ALTER on every page load. The column is
    // asked for by name and the answer is a row, so this is a single indexed
    // read that costs nothing on a database that already has it.
    try {
        $db->query("SELECT invoice_token FROM sales LIMIT 1");
        return true;
    } catch (Throwable $_) {
        // Not there. Fall through to the migration.
    }

    appSafeDdl($db, "ALTER TABLE sales ADD COLUMN invoice_token VARCHAR(40) NULL");
    // Indexed because the public page looks up by nothing else. Without it this
    // query is a full table scan of the sales history on every page view, which
    // is both slow and the sort of thing a host notices.
    appSafeDdl($db, "ALTER TABLE sales ADD UNIQUE KEY uq_sales_invoice_token (invoice_token)");

    try {
        $db->query("SELECT invoice_token FROM sales LIMIT 1");
        return true;
    } catch (Throwable $e) {
        error_log('invoice token column unavailable: ' . $e->getMessage());
        return false;
    }
}

/**
 * The token for one sale, created on first use.
 *
 * @return string 32 hex characters, or '' when the row is missing or the
 *                 column could not be created
 */
function invoiceEnsureToken($db, $saleId, $ownerId)
{
    $saleId = (int)$saleId;
    if ($saleId <= 0) {
        return '';
    }
    if (!invoiceEnsureTokenColumn($db)) {
        return '';
    }

    $stmt = $db->prepare("SELECT invoice_token FROM sales WHERE id = ? AND owner_id = ?");
    $stmt->execute([$saleId, $ownerId]);
    $token = trim((string)($stmt->fetchColumn() ?: ''));
    if ($token !== '') {
        return $token;
    }

    // random_bytes is the CSPRNG, not rand()/uniqid(). A token anybody could
    // guess is the same as no token at all, and a receipt link is the one place
    // where a predictable value hands over a customer's purchase.
    $token = bin2hex(random_bytes(16));

    $stmt = $db->prepare("UPDATE sales SET invoice_token = ? WHERE id = ? AND owner_id = ?");
    $stmt->execute([$token, $saleId, $ownerId]);

    return $token;
}

/**
 * The public address of one invoice.
 *
 * Built from the host the request actually arrived on rather than a configured
 * domain, because the same code runs on localhost during testing and on the live
 * cPanel site, and a hard-coded base would put "http://localhost" into real SMS
 * messages.
 *
 * @param string $token
 * @param string $schemeHint 'http' when the current request came over plain
 *                 HTTP, so a shop with no SSL still gets a working link
 */
function invoicePublicUrl($token, $schemeHint = '')
{
    $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
    if ($token === '') {
        return '';
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $schemeHint !== '' ? $schemeHint : ($https ? 'https' : 'http');

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // HTTP_HOST is whatever the client sent, so it is not trusted. Anything that
    // is not a plain hostname is dropped rather than echoed back into a link
    // that a customer is about to open.
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = '';
    }
    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host . '/invoice-view.php?t=' . $token;
}

/**
/**
 * The receipt as an SMS, in the layout the shop asked for:
 *
 *     SMART COLLECTION
 *     Invoice: INV-000014
 *     27 Sep 2026, 04:17 PM
 *     Baby Pant x1 - 678 Tk
 *     Total: 678 Tk
 *     Paid: Cash 678 Tk
 *     Thank you for shopping!
 *
 * English and ASCII on purpose. A GSM message holds 160 characters, and one
 * Bengali character drops that to 70 and makes every message cost two. The
 * printed receipt can be in Bengali because it is free; this is billed per
 * character.
 *
 * THE COST, WHICH THE SHOP NEEDS TO SEE
 * ------------------------------------
 * A receipt with items is not a summary. One item fits in a single segment;
 * five or six will not, and every segment past the first is charged again. The
 * item list is therefore capped, and when it is capped the message says so and
 * points at the link - a customer told "and 4 more" is told the truth, whereas a
 * receipt that silently stops halfway reads as a receipt for four items.
 *
 * Nothing is ever truncated mid-value. Prices, totals and links are whole or
 * absent, because a clipped link is a dead one and a clipped total is a wrong one.
 *
 * @param array $r  keys: shop_name, invoice_number, date_label, items (array of
 *                  name/qty/total), total, paid, paid_label, change, footer,
 *                  link, promo (array of label/url)
 * @return array{text:string, segments:int, items_shown:int, items_total:int,
 *               truncated:bool}
 */
function invoiceSmsText(array $r)
{
    // How many lines may be spent on items. Chosen so a normal basket still fits
    // one segment: each line costs roughly its name plus "x1 - 678 Tk".
    $maxItems = isset($r['max_items']) ? max(1, (int)$r['max_items']) : 8;

    $out = [];

    // Upper case, because that is how the shop asked for it and a receipt
    // screenshot is easier to place when the shop's name is the loudest line.
    $shop = trim((string)($r['shop_name'] ?? ''));
    if ($shop !== '') {
        $out[] = strtoupper($shop);
    }

    $number = trim((string)($r['invoice_number'] ?? ''));
    if ($number !== '') {
        // Labelled only when the shop's own numbering does not already say so:
        // these are generated as INV-000014, and "Invoice: INV INV-000014" on a
        // customer's phone reads as a bug.
        $out[] = 'Invoice: ' . (stripos($number, 'inv') !== false ? $number : 'INV ' . $number);
    }

    $dateLabel = trim((string)($r['date_label'] ?? ''));
    if ($dateLabel !== '') {
        $out[] = $dateLabel;
    }

    $items = is_array($r['items'] ?? null) ? array_values($r['items']) : [];
    $total = is_array($r['items'] ?? null) ? count($items) : 0;
    $shown = 0;

    foreach ($items as $it) {
        if ($shown >= $maxItems) {
            break;
        }
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        // A product name may contain a newline or a control character typed into
        // the product form. Left alone it would break the layout of every line
        // after it, so it is flattened to spaces.
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim(invoiceSmsStrim($name, 40));

        $qty = (float)($it['qty'] ?? 0);
        $line = (float)($it['total'] ?? 0);
        $qtyText = ($qty == floor($qty) && abs($qty - round($qty)) < 0.0001)
            ? (string)(int)round($qty)
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');

        $out[] = $name . ' x' . $qtyText . ' - ' . invoiceSmsMoney($line) . ' Tk';
        $shown++;
    }

    $truncated = ($shown < $total);
    if ($truncated) {
        $left = $total - $shown;
        $out[] = '...and ' . $left . ' more item' . ($left === 1 ? '' : 's');
    }

    $grand = (float)($r['total'] ?? 0);
    if ($grand > 0) {
        $out[] = 'Total: ' . invoiceSmsMoney($grand) . ' Tk';
    }

    $paid = (float)($r['paid'] ?? 0);
    if ($paid > 0) {
        $paidLabel = trim((string)($r['paid_label'] ?? ''));
        $out[] = 'Paid' . ($paidLabel !== '' ? ' (' . ucfirst(strtolower($paidLabel)) . ')' : '')
              . ' ' . invoiceSmsMoney($paid) . ' Tk';
    }

    $change = (float)($r['change'] ?? 0);
    if ($change > 0) {
        $out[] = 'Change: ' . invoiceSmsMoney($change) . ' Tk';
    }

    $footer = trim((string)($r['footer'] ?? ''));
    if ($footer !== '') {
        $out[] = preg_replace('/\s+/u', ' ', $footer);
    }

    // The link is optional and off unless asked for, because it is what pushes a
    // receipt this size over a single segment. The shop chooses on the settings
    // screen, where the cost difference is written next to the checkbox.
    $link = trim((string)($r['link'] ?? ''));
    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        $link = '';
    }
    if ($link !== '') {
        $out[] = 'Full invoice: ' . $link;
    }

    $promoLabel = trim((string)($r['promo']['label'] ?? ''));
    $promoUrl   = trim((string)($r['promo']['url'] ?? ''));
    // Only http(s). A javascript: or data: URL in a message a customer taps is a
    // phishing link, and the value comes from a settings screen.
    if ($promoUrl !== '' && !preg_match('#^https?://#i', $promoUrl)) {
        $promoUrl = '';
    }
    if ($promoLabel !== '' && $promoUrl !== '') {
        $out[] = $promoLabel . ': ' . $promoUrl;
    }

    $text = implode("\n", $out);

    // 160 is the GSM limit for a single message; a concatenated one is 153 a
    // part. The count is returned so the shop is told what it is being charged
    // for instead of discovering it on the bill.
    $segments = (strlen($text) <= 160) ? 1 : (int)ceil(strlen($text) / 153);

    return [
        'text'         => $text,
        'segments'     => $segments,
        'items_shown'  => $shown,
        'items_total'  => $total,
        'truncated'    => $truncated,
    ];
}

/** A money value with no thousands separator: "678.00". */
function invoiceSmsMoney($v)
{
    return number_format((float)$v, 2, '.', '');
}

/**
 * Cut a string to a character count without splitting a UTF-8 sequence in half.
 *
 * substr() on bytes would leave a lone lead byte at the end, which renders as a
 * replacement character on the customer's phone - and a product name is the one
 * part of the message a customer recognises.
 */
function invoiceSmsStrim($s, $max)
{
    $s = (string)$s;
    if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    if (strlen($s) <= $max) {
        return $s;
    }
    // No mbstring: cut on bytes, then back off to a lead byte boundary.
    $cut = substr($s, 0, $max);
    while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
        $cut = substr($cut, 0, -1);
    }
    return $cut;
}
