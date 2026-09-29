<?php
/**
 * API - Send a sale invoice on WhatsApp.
 *
 * Enqueues one job. It does not send anything itself: the WhatsApp session lives
 * on the shop PC with the bridge, and the image has to be drawn by the Chromium
 * next to it. So the markup is built here and queued, and the agent renders and
 * delivers it on its next poll. On a shared host that is the only arrangement
 * that works, and it is why pressing this button returns immediately rather than
 * making the cashier wait on a phone on the other side of the shop.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/wa_agent.php';
require_once __DIR__ . '/../../config/invoice_image.php';
startSecureSession();

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Read and close, so a wobbly connection cannot be held open by a request that
// has already answered.
@ignore_user_abort(true);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$saleId = (int)($input['sale_id'] ?? $input['id'] ?? 0);
if ($saleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invoice number bujha jay ni.']);
    exit;
}

try {
    $db = getDB();
    $currentUser = getCurrentUser();
    $ownerId = $currentUser['owner_id'];   // always non-null, see getCurrentUser()
} catch (Throwable $e) {
    error_log('invoice-send: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server ekhon pares na.']);
    exit;
}

// Only a shop that has actually switched the bridge over to agent mode can queue
// anything: in the other arrangement the job would sit in wa_jobs forever with
// no process to pick it up, and the button would look broken.
$settings = getMarketingSettings($ownerId);
if (!waAgentModeEnabled($settings)) {
    echo json_encode([
        'success' => false,
        'message' => 'WhatsApp bridge agent mode e nai. Settings > WhatsApp Bridge '
                   . 'theke "Bridge ei POS e call kore" select kore save korun.',
    ]);
    exit;
}

// Check before queueing, for the same reason. A button that reports "not
// connected" straight away is useful; one that says "queued" and then silently
// never arrives is worse.
$status = marketingAgentStatus($ownerId, $settings);
if (empty($status['connected'])) {
    $why = trim((string)($status['hint'] ?? ''));
    echo json_encode([
        'success' => false,
        'status'  => $status['status'],
        'message' => $why !== '' ? $why : 'WhatsApp connect nai. QR scan kore link korun.',
    ]);
    exit;
}

// Where it goes: the customer's number, unless the caller names one. Falling back
// to a typed number matters for a walk-in, who has no customer record but often
// has a phone number typed at the counter.
//
// The number comes from the customer record only. sales has no phone column -
// a sale stores customer_id and the rest is looked up - so naming one here put
// a column that does not exist into the query, and the PDO error came back as an
// uncaught 500 with an HTML body, which the POS could only report as "Invoice
// pathano jay ni".
$phone = trim((string)($input['phone'] ?? ''));
if ($phone === '') {
    $stmt = $db->prepare("
        SELECT NULLIF(TRIM(c.phone), '') AS num
        FROM sales s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.id = ? AND s.owner_id = ?");
    $stmt->execute([$saleId, $ownerId]);
    $phone = trim((string)($stmt->fetchColumn() ?: ''));
}

$digits = marketingNormalizePhone($phone);
if ($digits === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Ei invoice-ta kono customer number nai, tai WhatsApp e pathano jay na. '
                   . 'Numbar ta diye chalan -',
    ]);
    exit;
}

// Everything from here on is inside a try. A PDO error thrown by any of these
// queries is an uncaught 500 with an HTML body, and the POS reads a non-JSON
// response as "Invoice pathano jay ni" with no reason attached - which is exactly
// how the missing column in the previous version of this file went unnoticed.
// A named failure is one the shop can act on.
try {
    $built = invoiceBuildHtml($db, $saleId, $ownerId, $digits);
    if (empty($built['ok'])) {
        echo json_encode(['success' => false, 'message' => $built['error'] ?? 'Invoice banano jay ni.']);
        exit;
    }
    // No $ on the call. Written as $shopNameForCaption(...) this is not a
    // function call at all - it is a variable holding a callable, that variable
    // does not exist, and PHP throws "Value of type null is not callable" the
    // moment this line runs. It was the whole reason the WhatsApp invoice could
    // not be built: the receipt markup was fine, the queue was fine, and the one
    // line naming the shop brought the request down after the expensive part had
    // already succeeded.
    $caption = 'Invoice ' . ($built['number'] ?? $saleId) . ' - ' . shopNameForCaption($settings);

    $put = waAgentEnqueue($ownerId, 'send', $digits, $caption, [], '', $built['html']);
    if (empty($put['ok'])) {
        echo json_encode(['success' => false, 'message' => $put['error'] ?? 'Queue e pathano jay ni.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('invoice-send: ' . $e->getMessage());
    // The reason is passed back, shortened. This is an owner-only, authenticated
    // endpoint, and a PDO message names the column or table that was missing -
    // which is already in this repository - but never the query or any value, so
    // nothing about the shop's data goes out. A bare "database ta dekhe bolen"
    // left the shop with nothing to act on; this is the difference between one
    // round trip to a fix and an afternoon of guessing.
    echo json_encode([
        'success' => false,
        'message' => 'Invoice ta banano jay ni: ' . invoiceErrorHint($e),
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'queued'  => true,
    'job_id'  => $put['job_id'],
    'to'      => $digits,
    'message' => 'Invoice queue te dhaka holo - bridge poshtay pathiye debe.',
]);
exit;

/** One line naming the shop, so the message reads as a receipt and not a form. */
function shopNameForCaption($settings)
{
    $name = trim((string)($settings['shop_name'] ?? ''));
    return $name !== '' ? $name : 'POS System';
}

/**
 * A database failure in one readable line, for the shop rather than a log file.
 *
 * Kept deliberately short. A PDO message can carry a value that was sent - a
 * customer's number, say - so the parts that are not a column or table name are
 * dropped and only the SQLSTATE and the named object survive. tools/check-invoice-
 * schema.php then turns that name into the fix.
 */
function invoiceErrorHint(Throwable $e)
{
    $msg = $e->getMessage();

    // Not a database problem at all, most of the time.
    //
    // An Error - a missing function, a bad argument type - carries code 0, so the
    // SQLSTATE branch below never fired and the shop was told "database error"
    // about something that had nothing to do with the database. The usual cause
    // here is a half-finished upload: config/invoice_image.php present but an
    // older copy that does not define invoiceBuildHtml, so calling it is a fatal
    // Error and not a PDOException. Saying so turns an unexplained message into
    // "upload these three files together".
    if ($e instanceof Error) {
        // Four backslashes, because this is a single-quoted PHP string: \\ there
        // is one backslash, so the pattern needs \\\\ to mean an escaped backslash
        // in the character class. With only \\ the class is left unterminated and
        // preg_match fails to compile, which is exactly the bug this branch was
        // written to avoid.
        if (preg_match('/undefined function\s+([A-Za-z0-9_\\\\]+)/i', $msg, $m)) {
            return "code-er kache function nai: {$m[1]}. config/invoice_image.php ba invoice_token.php purano copy upload hoye giyechhilo.";
        }
        if (stripos($msg, 'undefined method') !== false) {
            return 'code-e kono method nai - file-ta purano copy.';
        }
        // A function called through a variable that holds nothing. Worth naming,
        // because it is always one specific mistake - a $ typed in front of a
        // function name - and it looks like nothing at all in the source. This is
        // the fault that stopped the WhatsApp invoice from being built, and it
        // arrived at the shop as "server log dekhe bolen" pointing at a log file
        // that php.ini pointed into a directory which did not exist.
        if (stripos($msg, 'is not callable') !== false) {
            return 'code-er moddhe ekta function name er age $ chara likha ache - '
                 . 'PHP eta function call na, variable call dhore nibo. '
                 . 'invoice-send.php ba config/invoice_image.php er latest copy upload korun.';
        }
        if ($e instanceof TypeError) {
            return 'code-e argument type bhul - file-ta purano copy.';
        }
        return 'PHP error, database na. server log dekhe bolen.';
    }

    // "Unknown column 'x' in 'field list'" and "Base table or view not found: x"
    // are the two this feature actually hits, and both name the thing to fix.
    if (preg_match("/Unknown column '([^']+)'/i", $msg, $m)) {
        return "table e '{$m[1]}' column-ta nai.";
    }
    if (preg_match("/Table '[^']*?([A-Za-z0-9_]+)' doesn't exist/i", $msg, $m)) {
        return "'{$m[1]}' table-ta nai.";
    }
    if (preg_match('/Base table or view not found.*?([A-Za-z0-9_]+)\s*doesn/i', $msg, $m)) {
        return "'{$m[1]}' table-ta nai.";
    }
    if (stripos($msg, 'denied') !== false) {
        return 'database user er permission nai.';
    }
    if (stripos($msg, 'syntax') !== false) {
        return 'query-te syntax error.';
    }

    // Anything else. The exception's class is included because it is the one
    // thing that settles what to look at next, and it is a PHP class name - it
    // says nothing about the shop's data. Without it a PDOException and an Error
    // both arrived as the word "database", and the shop was sent to the database
    // when the fault was in a file.
    $code = $e instanceof PDOException ? trim((string)$e->getCode()) : '';
    $codePart = ($code !== '' && $code !== '0') ? " (code {$code})" : '';

    // PDOException means the query really did fail. Anything else is a fault in
    // the PHP itself, and saying so is more useful than the word "database".
    if (!$e instanceof PDOException) {
        return 'PHP fault, not the database: ' . get_class($e) . '. server log dekhe bolen.';
    }

    return 'database error' . $codePart . ' [' . get_class($e) . ']. server log e "invoice-send:" dekho.';
}
