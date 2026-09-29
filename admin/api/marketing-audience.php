<?php
/**
 * API - Marketing audience
 * Returns customers with a usable phone number, for the recipient picker.
 *
 * By default this returns only customers confirmed to be on WhatsApp, because
 * that is what a recipient list is for: every row here is a number a campaign
 * can actually reach. An unconfirmed number is not excluded because it was
 * judged useless, it is excluded because nobody has verified it yet, and the
 * header says how many of those are waiting.
 *
 * GET params:
 *   q        search on name / phone
 *   only_inactive  1 = customers with no purchase in the last N days
 *   days     look-back window for the inactive filter (default 90)
 *   wa       1 = only numbers confirmed to be on WhatsApp (the default)
 *            0 = every customer with a number, whatever the WhatsApp state.
 *                Kept for the shopkeeper who wants to see the whole list and
 *                read the badges, not for picking a send target.
 *   limit    max rows (default 500)
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
require_once '../../config/whatsapp_inbox.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasPermission('marketing')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$db     = getDB();
$user   = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];

$search  = trim($_GET['q'] ?? '');
$days    = max(1, (int)($_GET['days'] ?? 90));
$onlyOff = isset($_GET['only_inactive']) && $_GET['only_inactive'] === '1';
// Defaulting to on matters: a caller that has not been updated to send the
// parameter gets the safe list rather than every number in the shop. Being
// wrong here means sending a campaign to numbers nobody has verified.
$onlyWa  = !isset($_GET['wa']) || $_GET['wa'] !== '0';
$limit   = min(2000, max(1, (int)($_GET['limit'] ?? 500)));

// has_whatsapp is read from the customer row, never probed here. Asking WhatsApp
// whether a number exists is a round trip, so it is done by the sync endpoint and
// cached; this file only reports the cached answer. The bridge also fills in the
// ones it can see for free as the address book arrives - see
// whatsappMarkCustomersOnWa() - which is why this cache is usually current
// without anybody pressing anything.
$sql = "SELECT c.id, c.name, c.phone, c.has_whatsapp, c.whatsapp_checked_at,
               MAX(s.created_at) AS last_visit
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.id
        WHERE c.owner_id = ?";
$params = [$ownerId];

if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

// A WHERE, not a HAVING on the grouped row. c.has_whatsapp does not depend on
// the sales join, so filtering before the grouping lets the aggregate below see
// only the rows it is meant to summarise, and lets the DB use idx_owner.
if ($onlyWa) {
    $sql .= " AND c.has_whatsapp = 1";
}

$sql .= " GROUP BY c.id, c.name, c.phone, c.has_whatsapp, c.whatsapp_checked_at";

if ($onlyOff) {
    $sql .= " HAVING (last_visit IS NULL OR last_visit < DATE_SUB(NOW(), INTERVAL ? DAY))";
    $params[] = $days;
}

$sql .= " ORDER BY c.name ASC LIMIT $limit";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $out = [];
    $waYes = 0;
    $waUnchecked = 0;
    foreach ($rows as $r) {
        $phone = marketingNormalizePhone($r['phone']);
        if ($phone === '') {
            continue; // no usable number, nothing to send to
        }
        $lastVisit = $r['last_visit'] ? substr($r['last_visit'], 0, 10) : null;
        $daysSince = null;
        if ($lastVisit) {
            $daysSince = (int)((time() - strtotime($lastVisit)) / 86400);
        }
        // Three-valued on purpose: true, false, or null for "not probed yet".
        $hasWa = $r['has_whatsapp'] === null ? null : ((int)$r['has_whatsapp'] === 1);
        if ($hasWa === true) { $waYes++; } elseif ($hasWa === null) { $waUnchecked++; }
        $out[] = [
            'id'               => (int)$r['id'],
            'name'             => $r['name'],
            'phone'            => $phone,
            'phone_display'    => marketingFormatPhone($phone),
            'has_whatsapp'     => $hasWa,
            'whatsapp_checked_at' => $r['whatsapp_checked_at']
                                        ? substr($r['whatsapp_checked_at'], 0, 16) : null,
            'last_visit'       => $lastVisit,
            'last_visit_days'  => $daysSince,
        ];
    }

    // Totals for the whole owner, not just this page, so the header can say
    // "12 of 40 have WhatsApp" and stay honest while a search filter is on.
    //
    // wa_no is the one number that used to be missing and it is the one a
    // shopkeeper needs after a check run: it is the list they are not allowed
    // to send to, and without it the only way to find out why a customer is
    // absent from the picker is to turn the filter off and hunt.
    $totals = $db->prepare("SELECT
            COUNT(*) AS all_customers,
            SUM(TRIM(COALESCE(phone, '')) <> '') AS usable,
            SUM(TRIM(COALESCE(phone, '')) <> '' AND has_whatsapp = 1)  AS wa_yes,
            SUM(TRIM(COALESCE(phone, '')) <> '' AND has_whatsapp = 0)  AS wa_no,
            SUM(TRIM(COALESCE(phone, '')) <> '' AND has_whatsapp IS NULL) AS wa_unchecked
        FROM customers WHERE owner_id = ?");
    $totals->execute([$ownerId]);
    $t = $totals->fetch() ?: [];

    // Why the two numbers the shopkeeper sees never match.
    //
    // marketing_recipients holds one row per send, per campaign, so the same
    // customer is counted again every time they are targeted. "298
    // recipients" is therefore 298 send rows across every campaign ever run,
    // not 298 different people, and comparing it to a customer count is
    // comparing two different things. This returns both, plus the number of
    // distinct humans behind the rows, so the header can say so in words
    // instead of leaving the shopkeeper to work it out.
    $rowsSent = 0;
    $people = 0;
    if (appTableExists($db, 'marketing_recipients')) {
        $rc = $db->prepare("SELECT COUNT(*) AS rows_sent, COUNT(DISTINCT customer_id) AS people
                            FROM marketing_recipients WHERE owner_id = ?");
        $rc->execute([$ownerId]);
        $r = $rc->fetch() ?: [];
        $rowsSent = (int)($r['rows_sent'] ?? 0);
        $people   = (int)($r['people'] ?? 0);
    }

    echo json_encode([
        'ok'        => true,
        'customers' => $out,
        'count'     => count($out),
        'wa_in_page' => $waYes,
        'wa_unchecked_in_page' => $waUnchecked,
        'totals'    => [
            'all'       => (int)($t['all_customers'] ?? 0),
            'usable'   => (int)($t['usable'] ?? 0),
            'wa_yes'   => (int)($t['wa_yes'] ?? 0),
            'wa_no'    => (int)($t['wa_no'] ?? 0),
            'unchecked' => (int)($t['wa_unchecked'] ?? 0),
        ],
        'recipients' => [
            // Rows written, summed over all campaigns. This is the number a
            // naive COUNT(*) gives and the one that looks wrong next to the
            // customer total.
            'rows'   => $rowsSent,
            // Distinct customers ever targeted. The one comparable to
            // 'all' above, and the one that should be below it.
            'people' => $people,
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
