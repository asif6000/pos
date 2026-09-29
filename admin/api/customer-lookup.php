<?php
/**
 * API - Has this phone number already been saved?
 *
 * Backs the live "already saved" hint under the customer phone field, so a
 * number is noticed as a duplicate while it is still being typed rather than
 * after the row is written.
 *
 * The matching is deliberately about the *number*, not the string: Bangladeshi
 * numbers get typed as 01712345678, +8801712345678 and 8801712345678, and all
 * three are the same subscriber. Comparing raw text would let the same person
 * in three times. marketingNormalizePhone() folds all of them to one canonical
 * form, and that is what gets compared.
 *
 * Rows whose owner_id is NULL are searched too. A few customers were saved
 * before ownership was settled and belong to no shop, so an owner-scoped query
 * would report their numbers as unsaved - and would happily let the same person
 * be added a third time. Those hits come back flagged as `orphaned` so the UI
 * can say the number exists but is not attached to a shop.
 *
 * GET phone=...            the number being typed
 *     exclude_id=...       a customer id to ignore (editing that same row)
 *   -> { ok, matches: [{ id, name, phone, phone_display, orphaned }] }
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = getDB();
$user   = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];

$raw     = trim($_GET['phone'] ?? '');
$exclude = (int)($_GET['exclude_id'] ?? 0);

// Fewer than 7 digits is not yet a number worth guessing about. Typing "0171" is
// a prefix, and warning on it would flash a false alarm at every keystroke.
$canonical = marketingNormalizePhone($raw);
if (strlen($canonical) < 7) {
    echo json_encode(['ok' => true, 'matches' => [], 'incomplete' => true]);
    exit;
}

/**
 * The two shapes a Bangladeshi number is actually stored in. Used only to keep
 * the query cheap; the authoritative comparison is the canonical one in PHP,
 * because a stored number may carry spaces or dashes that these will not match.
 */
$local = (strlen($canonical) === 13 && strpos($canonical, '880') === 0)
    ? '0' . substr($canonical, 3)
    : $canonical;

$sql = "SELECT id, name, phone, owner_id
        FROM customers
        WHERE (owner_id = ? OR owner_id IS NULL)
          AND id <> ?
          AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                COALESCE(phone, ''), '+', ''), '-', ''), ' ', ''), '(', ''), ')', '')
              IN (?, ?)
        LIMIT 25";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute([$ownerId, $exclude, $canonical, $local]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed.']);
    exit;
}

$matches = [];
foreach ($rows as $r) {
    // Second pass: the SQL prefilter can be fooled by stored formatting, so the
    // real decision is made here on the canonical number.
    if (marketingNormalizePhone($r['phone']) !== $canonical) {
        continue;
    }
    $matches[] = [
        'id'            => (int)$r['id'],
        'name'          => $r['name'],
        'phone'         => $r['phone'],
        'phone_display' => marketingFormatPhone($r['phone']),
        'orphaned'      => $r['owner_id'] === null,
    ];
}

echo json_encode(['ok' => true, 'matches' => $matches, 'incomplete' => false]);
