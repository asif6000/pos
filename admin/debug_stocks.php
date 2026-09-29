<?php
/**
 * Debug helper - dumps the current stock rows.
 *
 * Guarded in code, not only by the .htaccess filename rule.
 *
 * This file had no check of any kind: no login, no role, nothing. It was being
 * served with HTTP 200 to anybody who asked, printing every store_stocks row -
 * which is the product catalogue with live quantities, to an anonymous visitor.
 * The filename rule in the root .htaccess is what stopped it on paper, and the
 * rule did not in fact stop it on the live host, which is how this was found.
 * A rule matched on a filename is one rename away from being no protection at
 * all, and the diagnostic value of the output does not justify leaving a
 * database dump one rename away from the public.
 *
 * isLoggedIn() is enough here: this is a stock diagnostic, and every role that
 * reaches the POS can see the same figures on the Stock page anyway.
 */
require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}

$db = getDB();
$stocks = $db->query("SELECT * FROM store_stocks WHERE quantity > 0")->fetchAll();

// Text/plain, so the dump is never mistaken for a page and never rendered as
// markup by anything that ends up pointed at it.
header('Content-Type: text/plain; charset=utf-8');
print_r($stocks);
