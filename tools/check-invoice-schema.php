<?php
/**
 * Check the live database against the columns the invoice feature reads.
 *
 * "Invoice ta banano jay ni" means a query threw, and the browser is told
 * nothing about which one or why. pos_schema.sql is a starting point, not a
 * guarantee: the live tables were built by a series of migrations, and any of
 * them can leave a column named differently. A receipt that reads "price" when
 * the table says "unit_price" does not even throw - it silently prints 0.00 -
 * which is worse than an error, because it reaches a customer.
 *
 * So this asks the database itself. Every column named here is one the invoice
 * path depends on, and every one is verified to exist before a sale can fail on
 * it again.
 *
 *   php tools/check-invoice-schema.php
 */

// Command line only. It prints the shape of the live tables.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This is a command line tool.');
}

require_once __DIR__ . '/../config/db.php';

$db = getDB();

/** table => columns the invoice path reads */
$needs = [
    'sales' => [
        'id', 'invoice_number', 'customer_id', 'user_id', 'subtotal',
        'discount_percent', 'discount_amount', 'vat_percent', 'vat_amount',
        'total', 'paid_amount', 'change_amount', 'payment_method',
        'payment_status', 'owner_id', 'created_at', 'invoice_token',
    ],
    'sale_items' => [
        'id', 'sale_id', 'product_id', 'product_name', 'quantity',
        'unit_price', 'total_price',
    ],
    'customers' => [
        'id', 'name', 'phone', 'owner_id', 'has_whatsapp', 'whatsapp_checked_at',
    ],
    'users' => [
        'id', 'name', 'owner_id',
    ],
    'settings' => [
        'setting_key', 'setting_value', 'owner_id',
    ],
    'wa_jobs' => [
        'id', 'owner_id', 'kind', 'to', 'payload', 'payload_html',
        'campaign_id', 'recipient_id', 'status', 'result', 'error',
        'wa_message_id', 'request_key', 'attempts', 'available_at',
        'claim_token', 'claimed_at',
    ],
    'wa_agents' => [
        'id', 'owner_id', 'status', 'me_name', 'me_id', 'qr', 'events',
        'catalog_chats', 'catalog_contacts', 'command', 'last_seen',
    ],
];

$problems = 0;

foreach ($needs as $table => $cols) {
    try {
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $have[strtolower($c['Field'])] = true;
        }
    } catch (Throwable $e) {
        echo "MISSING TABLE  $table\n";
        $problems++;
        continue;
    }

    $missing = [];
    foreach ($cols as $c) {
        if (!isset($have[strtolower($c)])) {
            $missing[] = $c;
        }
    }

    if ($missing) {
        echo "MISSING COLUMN  $table: " . implode(', ', $missing) . "\n";
        $problems++;
    } else {
        echo "ok  $table (" . count($have) . " columns)\n";
    }
}

// sales.invoice_token is created on demand, so its absence is worth calling out
// separately: it is not a broken install, it is a database user without ALTER.
try {
    $db->query("SELECT invoice_token FROM sales LIMIT 1");
} catch (Throwable $e) {
    echo "\nnote: sales.invoice_token is missing.\n";
    echo "      That is added on demand the first time an invoice link is built.\n";
    echo "      If it never appears, the database user has no ALTER permission and\n";
    echo "      the SMS invoice link cannot work. Ask the host for ALTER on sales,\n";
    echo "      or run this once as a user that has it:\n";
    echo "        ALTER TABLE sales ADD COLUMN invoice_token VARCHAR(40) NULL;\n";
    echo "        ALTER TABLE sales ADD UNIQUE KEY uq_sales_invoice_token (invoice_token);\n";
}

echo "\n" . ($problems === 0 ? "PASS - every column the invoice path needs exists"
                             : "$problems table(s) need attention") . "\n";
exit($problems === 0 ? 0 : 1);
