<?php
/**
 * API - Marketing audience
 * Returns customers with a usable phone number, for the recipient picker.
 *
 * Every row here is a number a campaign can send to, so the list is filtered on
 * phone presence only.
 *
 * GET params:
 *   q        search on name / phone
 *   only_inactive  1 = customers with no purchase in the last N days
 *   days     look-back window for the inactive filter (default 90)
 *   limit    max rows (default 500)
 *   ids_only 1 = ignore every filter above and return only the ids of every
 *            customer who has a usable number, for the "Select total" button.
 *            The list endpoint is filtered and capped, so "Select all" on the
 *            page can only ever reach what is on screen; this is the only way
 *            to reach the number the header calls Total.
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
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

// ── Ids only: every customer with a number ──────────────────────────────────
//
// Kept as a separate early return rather than a flag threaded through the query
// below, because this answer deliberately ignores every filter the list applies
// and folding it into the same WHERE would make that invisible.
//
// The same reachability rule the list uses: a customer with no usable number is
// not selectable, because a campaign cannot send to them.
if (isset($_GET['ids_only']) && $_GET['ids_only'] === '1') {
    try {
        // No LIMIT on the id list itself: it is one integer per customer, so even
        // a shop with tens of thousands of customers sends a payload in the
        // hundreds of kilobytes. The list endpoint is the one that needs the cap,
        // because it carries a row per customer with names and visit dates.
        $stmt = $db->prepare("SELECT c.id, c.phone
                                FROM customers c
                                WHERE c.owner_id = ?
                                  AND TRIM(COALESCE(c.phone, '')) <> ''
                                ORDER BY c.name ASC");
        $stmt->execute([$ownerId]);

        $ids = [];
        foreach ($stmt->fetchAll() as $r) {
            // marketingNormalizePhone is the same filter the list applies a row
            // at a time. Doing it here too keeps the count the button promises
            // equal to the count the campaign will actually create.
            if (marketingNormalizePhone($r['phone']) === '') {
                continue;
            }
            $ids[] = (int)$r['id'];
        }

        echo json_encode([
            'ok'    => true,
            'ids'   => $ids,
            'count' => count($ids),
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$search  = trim($_GET['q'] ?? '');
$days    = max(1, (int)($_GET['days'] ?? 90));
$onlyOff = isset($_GET['only_inactive']) && $_GET['only_inactive'] === '1';
$limit   = min(2000, max(1, (int)($_GET['limit'] ?? 500)));

$sql = "SELECT c.id, c.name, c.phone, MAX(s.created_at) AS last_visit
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

$sql .= " GROUP BY c.id, c.name, c.phone";

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
        $out[] = [
            'id'               => (int)$r['id'],
            'name'             => $r['name'],
            'phone'            => $phone,
            'phone_display'    => marketingFormatPhone($phone),
            'last_visit'       => $lastVisit,
            'last_visit_days'  => $daysSince,
        ];
    }

    // Totals for the whole owner, not just this page, so the header stays honest
    // while a search filter is on.
    $totals = $db->prepare("SELECT
            COUNT(*) AS all_customers,
            SUM(TRIM(COALESCE(phone, '')) <> '') AS usable
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
        'totals'    => [
            'all'    => (int)($t['all_customers'] ?? 0),
            'usable' => (int)($t['usable'] ?? 0),
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
