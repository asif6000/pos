<?php
/**
 * POS System - Cashbook Management
 *
 * The books the shop's money actually sits in, the movements that filled them,
 * and the four figures both add up to.
 *
 * Everything here reads and writes through config/cashbook.php, because the POS,
 * the Expense page and the returns counter already post into that one ledger. A
 * second set of rules on this page would only be a second set of numbers for the
 * shop to find disagreeing with the first.
 */

require_once '../config/db.php';
require_once '../config/csrf.php';
require_once '../config/cashbook.php';
require_once 'includes/cashbook-scope.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('cashbook')) {
    setFlash('danger', 'You do not have permission to access the cashbook.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Cashbook');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];
$storeId = !empty($user['store_id']) ? (int)$user['store_id'] : null;
$actor = [
    'owner_id' => $ownerId,
    'store_id' => $storeId,
    'user_id'  => $user['id'],
    'name'     => $user['name'],
];

ensureCashbookModuleTables();
ensureDefaultCashbooksForOwner($db, $ownerId, $storeId);

/**
 * Where a POST comes back to: this page, still narrowed by whatever the ledger
 * was filtered by. Only a query string this page wrote itself is honoured, so a
 * crafted `ret` cannot turn a saved entry into a redirect elsewhere.
 */
function cashbookBack()
{
    $ret = isset($_POST['ret']) && is_string($_POST['ret']) ? $_POST['ret'] : '';
    return 'cashbook.php' . (strncmp($ret, '?', 1) === 0 ? $ret : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfRequire('cashbook.php', ['cashbook.php']);
    $action = (string)($_POST['action'] ?? '');
    $back = cashbookBack();

    if ($action === 'save_book') {
        $id = (int)($_POST['book_id'] ?? 0);
        $r = saveCashbook($db, $ownerId, $storeId, $_POST, $id ?: null);
        setFlash($r['ok'] ? 'success' : 'danger', $r['ok']
            ? ($id ? 'Cashbook updated.' : 'Cashbook created.')
            : $r['error']);
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'toggle_book') {
        $r = toggleCashbookStatus($db, (int)($_POST['book_id'] ?? 0), $ownerId);
        if (!$r['ok']) {
            setFlash('danger', $r['error']);
        } elseif ($r['status'] === 'archived') {
            setFlash('success', 'Cashbook archived. Its history stays in the ledger - open "View all" above the books to see it again.');
        } else {
            setFlash('success', 'Cashbook restored. It is taking money again.');
        }
        header('Location: ' . ($r['ok'] && $r['status'] === 'archived' ? 'cashbook.php?show_archived=1' : $back));
        exit;
    }

    if ($action === 'delete_book') {
        $r = deleteCashbook($db, $ownerId, (int)($_POST['book_id'] ?? 0));
        setFlash($r['ok'] ? 'success' : 'danger', $r['ok']
            ? 'Cashbook "' . sanitize($r['name']) . '" deleted.' . ($r['lines'] > 0
                ? ' Its ' . number_format($r['lines']) . ' ledger line' . ($r['lines'] === 1 ? '' : 's') . ' went with it.'
                : '')
            : $r['error']);
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'save_entry') {
        $r = recordCashbookEntry($db, $actor, [
            'type'            => $_POST['type'] ?? '',
            'cashbook_id'     => (int)($_POST['cashbook_id'] ?? 0),
            'amount'          => $_POST['amount'] ?? 0,
            'entry_date'      => $_POST['entry_date'] ?? '',
            'idempotency_key' => trim((string)($_POST['idempotency_key'] ?? '')),
        ], !empty($_FILES['attachment']['tmp_name']) ? $_FILES['attachment'] : null);

        if ($r['ok']) {
            setFlash('success', empty($r['duplicate'])
                ? 'Entry recorded.'
                : 'That entry was already recorded a moment ago, so it was not booked twice.');
        } else {
            setFlash('danger', $r['error']);
        }
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'transfer') {
        $r = transferCashbookFunds($db, $actor, [
            'from_book_id'  => (int)($_POST['from_book_id'] ?? 0),
            'to_book_id'    => (int)($_POST['to_book_id'] ?? 0),
            'amount'        => $_POST['amount'] ?? 0,
            'transfer_date' => $_POST['transfer_date'] ?? '',
            'reference_no'  => $_POST['reference_no'] ?? '',
            'note'          => $_POST['note'] ?? '',
        ]);
        setFlash($r['ok'] ? 'success' : 'danger', $r['ok']
            ? 'Transferred. Both books carry their own line for it.'
            : $r['error']);
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'void_entry') {
        $r = voidCashbookEntry($db, $actor, (int)($_POST['entry_id'] ?? 0), $_POST['reason'] ?? '');
        setFlash($r['ok'] ? 'success' : 'danger', $r['ok']
            ? 'Transaction voided. The line stays in the ledger with the reason on it.'
            : $r['error']);
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'delete_entry') {
        $r = deleteCashbookEntry($db, $actor, (int)($_POST['entry_id'] ?? 0));
        setFlash($r['ok'] ? 'success' : 'danger', $r['ok']
            ? 'Transaction deleted. The line is gone and the book has been rebalanced.'
            : $r['error']);
        header('Location: ' . $back);
        exit;
    }

    // A POST that names none of these arrived from a form this page no longer
    // has. Sending the visitor back without a message is the honest answer.
    header('Location: ' . $back);
    exit;
}

// ── The books ────────────────────────────────────────────────────────────────
$showArchived = !empty($_GET['show_archived']);
$books = getCashbooksWithBalances($db, $ownerId, $storeId, true);
$activeBooks = [];
$allBooks = [];
foreach ($books as $b) {
    $allBooks[(int)$b['id']] = $b;
    if ($b['status'] === 'active') {
        $activeBooks[] = $b;
    }
}
$visibleBooks = $showArchived ? $books : $activeBooks;

// How many ledger lines each book would take with it, so the delete button can
// say what is about to be removed instead of the shop finding out afterwards.
$bookLines = [];
if ($allBooks) {
    $ids = array_keys($allBooks);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT cashbook_id, COUNT(*) FROM cashbook_entries
        WHERE owner_id = ? AND cashbook_id IN ($ph) GROUP BY cashbook_id");
    $stmt->execute(array_merge([$ownerId], $ids));
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
        $bookLines[(int)$r[0]] = (int)$r[1];
    }
}

// The category list belongs to the Expense page; the ledger only names it.
$categories = [];
if (appTableExists($db, 'expense_categories')) {
    $stmt = $db->prepare("SELECT id, name FROM expense_categories WHERE owner_id = ? ORDER BY name");
    $stmt->execute([$ownerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $categories[(int)$c['id']] = $c['name'];
    }
}

// ── The ledger ───────────────────────────────────────────────────────────────
$filter = cashbookFilterSql($user, $_GET);
$from = $filter['from'];
$where = $filter['where'];
$params = $filter['params'];
$dateFrom = $filter['filters']['date_from'];
$dateTo = $filter['filters']['date_to'];
$bookFilter = $filter['filters']['book'];
$typeFilter = $filter['filters']['type'];
$search = $filter['filters']['search'];
$filterStore = $filter['filters']['store'];

// ── What the four cards on top say ───────────────────────────────────────────
// The book the page is standing in, not the whole shop: opening bKash and being
// shown the drawer's money is the one thing a cashbook figure must not do.
$kpiBook = $bookFilter && isset($allBooks[$bookFilter]) ? $allBooks[$bookFilter] : null;
$kpiBookName = $kpiBook ? sanitize($kpiBook['name']) : '';
$kpiScope = $kpiBookName ? ' · ' . $kpiBookName : '';
$stats = getCashbookSummaryStats($db, $ownerId, $storeId, $kpiBook ? $bookFilter : null);
$netToday = round($stats['today_in'] - $stats['today_out'], 2);

// The mock-up's "+12 transactions" under today's figures. Same window, same book
// and the same exclusions the amounts use, so a count can never name money the
// card above it does not show.
$cntSql = "SELECT
        COALESCE(SUM(CASE WHEN type = 'cash_in' THEN 1 ELSE 0 END), 0) AS in_cnt,
        COALESCE(SUM(CASE WHEN type = 'cash_out' THEN 1 ELSE 0 END), 0) AS out_cnt
        FROM cashbook_entries
        WHERE owner_id = ? AND is_void = 0 AND (source_type != 'transfer' OR source_type IS NULL)
        AND DATE(COALESCE(entry_date, created_at)) = ?";
$cntParams = [$ownerId, date('Y-m-d')];
if ($storeId) {
    $cntSql .= " AND (store_id = ? OR store_id IS NULL)";
    $cntParams[] = $storeId;
}
if ($kpiBook) {
    $cntSql .= " AND cashbook_id = ?";
    $cntParams[] = $bookFilter;
}
$stmt = $db->prepare($cntSql);
$stmt->execute($cntParams);
$todayCnt = $stmt->fetch(PDO::FETCH_ASSOC);
$todayInCount  = (int)($todayCnt['in_cnt'] ?? 0);
$todayOutCount = (int)($todayCnt['out_cnt'] ?? 0);

$stmt = $db->prepare("SELECT COUNT(*) $from$where");
$stmt->execute($params);
$totalFiltered = (int)$stmt->fetchColumn();

$perPage = 15;
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT ce.*, cb.name AS book_name
            $from$where
            ORDER BY ce.created_at DESC, ce.id DESC
            LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * The running balance the ledger's last column shows.
 *
 * Each book's balance is opened at its own starting figure plus everything that
 * moved before this page's oldest line, then walked forward over the page. So
 * page 3 says what the drawer held on that day, not what page 3 happens to add
 * up to - and a filter that hides rows cannot bend the figures either.
 */
$running = [];
if ($entries) {
    $oldestPerBook = [];
    foreach ($entries as $e) {
        $bid = (int)$e['cashbook_id'];
        // Rows came in newest-first, so the last one seen per book is its oldest.
        $oldestPerBook[$bid] = ['at' => $e['created_at'], 'id' => (int)$e['id']];
    }

    $cursor = [];
    foreach ($oldestPerBook as $bid => $first) {
        $opening = isset($allBooks[$bid]) ? (float)$allBooks[$bid]['opening_balance'] : 0.0;
        if ($bid > 0) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END), 0)
                                  FROM cashbook_entries
                                  WHERE cashbook_id = ? AND is_void = 0
                                    AND (created_at < ? OR (created_at = ? AND id < ?))");
            $stmt->execute([$bid, $first['at'], $first['at'], $first['id']]);
            $opening += (float)$stmt->fetchColumn();
        }
        $cursor[$bid] = $opening;
    }

    foreach (array_reverse($entries) as $e) {
        $bid = (int)$e['cashbook_id'];
        if (!(int)$e['is_void']) {
            $cursor[$bid] += ($e['type'] === 'cash_in' ? 1 : -1) * (float)$e['amount'];
        }
        $running[(int)$e['id']] = round($cursor[$bid], 2);
    }
}

// Stores for the scope filter: only someone who is not already standing in one
// shop gets to ask which shop's money to look at.
$stores = [];
if (!$storeId) {
    if (isSuperAdminUser($user)) {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' ORDER BY name");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT id, name FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
        $stmt->execute([$ownerId]);
    }
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Rebuild this page's URL with the current filters plus these overrides. */
function cashbookQuery($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === false) {
            unset($q[$k]);
        }
    }
    return 'cashbook.php' . ($q ? '?' . http_build_query($q) : '');
}

// What a POST form carries so the redirect lands back on the same view.
$selfQuery = $_GET;
unset($selfQuery['page']);
$retQuery = $selfQuery ? '?' . http_build_query($selfQuery) : '';

$filtered = ($bookFilter || $typeFilter || $search || $filterStore || $showArchived
    || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d'));

$bookTypes = [
    'cash'   => ['Cash',           'fas fa-wallet'],
    'bank'   => ['Bank',           'fas fa-building-columns'],
    'bkash'  => ['Mobile Wallet',  'fas fa-mobile-screen-button'],
    'nagad'  => ['Mobile Wallet',  'fas fa-mobile-screen-button'],
    'rocket' => ['Mobile Wallet',  'fas fa-mobile-screen-button'],
    'other'  => ['Other',          'fas fa-coins'],
];
$dotColors = [
    'cash' => '#059669', 'bank' => '#2563eb', 'bkash' => '#db2777',
    'nagad' => '#b45309', 'rocket' => '#6d28d9', 'other' => '#475569',
];

// A book can be the account one supplier is paid from. Optional, and a supplier
// belongs to a shop, so a shop with no suppliers recorded simply gets no choice.
$bookSuppliers = [];
if (appTableExists($db, 'suppliers')) {
    $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE owner_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([$ownerId]);
    $bookSuppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$supplierNames = [];
foreach ($bookSuppliers as $s) {
    $supplierNames[(int)$s['id']] = $s['name'];
}

$bookForm = [];
foreach ($books as $b) {
    $bookForm[(int)$b['id']] = [
        'name'            => $b['name'],
        'type'            => $b['type'],
        'supplier_id'     => (int)($b['supplier_id'] ?? 0),
        'opening_balance' => (float)$b['opening_balance'],
        'opening_date'    => $b['opening_date'],
        'status'          => $b['status'],
    ];
}

include 'includes/header.php';
?>

<style>
    /* ── Page hero ────────────────────────────────────────────────────────── */
    .pg-hero {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        background: linear-gradient(115deg, #dbeafe 0%, #eef4ff 45%, #f7fbff 100%);
        border: 1px solid #e0eaff; border-radius: 18px;
        padding: 1.1rem 1.4rem; margin-bottom: 1.25rem;
    }
    .pg-hero-ico {
        width: 46px; height: 46px; border-radius: 13px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 1.15rem;
    }
    .pg-hero-txt { flex: 1 1 220px; min-width: 0; }
    .pg-hero-txt h2 { margin: 0; font-size: 1.3rem; font-weight: 800; color: #0f172a; }
    .pg-hero-txt p { margin: .15rem 0 0; font-size: .82rem; color: #475569; }
    .pg-hero-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    html[data-theme="dark"] .pg-hero { background: linear-gradient(115deg, #16233f 0%, #111a2e 100%); border-color: #1e2a44; }
    html[data-theme="dark"] .pg-hero-txt h2 { color: var(--gray-900); }
    html[data-theme="dark"] .pg-hero-txt p { color: var(--gray-500); }

    /* ── KPI cards ────────────────────────────────────────────────────────── */
    .kpi-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 1rem; margin-bottom: 1.25rem;
    }
    .kpi-card {
        display: flex; align-items: flex-start; gap: .85rem;
        background: var(--surface); border: 1px solid var(--gray-200);
        border-radius: 16px; padding: 1.05rem 1.15rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(15, 23, 42, .1); }
    .kpi-body { flex: 1 1 auto; min-width: 0; }
    .kpi-label { font-size: .78rem; font-weight: 600; color: var(--gray-500); }
    .kpi-value { font-size: 1.4rem; font-weight: 800; color: var(--gray-900); line-height: 1.25; margin-top: .2rem; }
    .kpi-foot { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; font-size: .72rem; color: var(--gray-400); margin-top: .4rem; }
    .kpi-ico {
        width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 1.05rem;
    }
    .kpi-ico.blue { background: #e8f1ff; color: #2563eb; }
    .kpi-ico.green { background: #e6f9f0; color: #059669; }
    .kpi-ico.violet { background: #f1ecff; color: #7c3aed; }
    .kpi-ico.rose { background: #ffeaf1; color: #db2777; }
    html[data-theme="dark"] .kpi-ico { background: rgba(255, 255, 255, .07); }

    /* ── Two columns: the page's own work, then the rail ──────────────────── */
    .cb-layout { display: grid; grid-template-columns: minmax(0, 1fr) 296px; gap: 1.15rem; align-items: start; }
    .cb-main { min-width: 0; display: flex; flex-direction: column; gap: 1.15rem; }
    .cb-rail { display: flex; flex-direction: column; gap: 1.15rem; position: sticky; top: 1rem; }
    @media (max-width: 1180px) {
        .cb-layout { grid-template-columns: minmax(0, 1fr); }
        .cb-rail { position: static; }
    }

    /* ── Book cards ───────────────────────────────────────────────────────── */
    .book-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(216px, 1fr));
        gap: .9rem; padding: 1.15rem 1.25rem;
    }
    .book-card {
        border: 1px solid var(--gray-200); border-radius: 14px; padding: .95rem 1rem;
        background: linear-gradient(160deg, #f8fbff 0%, var(--surface) 60%);
        display: flex; flex-direction: column; gap: .7rem;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .book-card:hover { transform: translateY(-3px); box-shadow: 0 14px 28px rgba(15, 23, 42, .1); border-color: #bfdbfe; }
    html[data-theme="dark"] .book-card { background: linear-gradient(160deg, #16233f 0%, var(--surface) 60%); }
    .book-card.archived { background: var(--surface); border-style: dashed; }
    .book-top { display: flex; align-items: center; gap: .6rem; }
    .book-ico {
        width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: .95rem;
        background: #eef2f7; color: #475569;
    }
    .book-ico.cash { background: #e6f9f0; color: #059669; }
    .book-ico.bank { background: #e8f1ff; color: #2563eb; }
    .book-ico.bkash { background: #ffeaf1; color: #db2777; }
    .book-ico.nagad { background: #fff4e0; color: #b45309; }
    .book-ico.rocket { background: #f1ecff; color: #6d28d9; }
    html[data-theme="dark"] .book-ico { background: rgba(255, 255, 255, .07); }
    .book-id { flex: 1 1 auto; min-width: 0; }
    .book-id strong { display: block; font-size: .92rem; font-weight: 800; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .book-id small { font-size: .72rem; color: var(--gray-500); }
    .book-bal { border-top: 1px dashed var(--gray-200); padding-top: .6rem; display: flex; align-items: flex-start; gap: .5rem; }
    .book-bal > div:first-child { flex: 1 1 auto; min-width: 0; }
    .book-bal-val { font-size: 1.18rem; font-weight: 800; color: var(--gray-900); }
    .book-bal small { display: block; font-size: .7rem; color: var(--gray-400); margin-top: .15rem; }
    .book-bal .book-sup { color: #2563eb; }
    .book-bal .book-sup i { margin-right: .25rem; }
    .book-foot { display: flex; align-items: center; gap: .4rem; }
    .book-foot .btn { flex: 1; justify-content: space-between; }
    .book-foot .act.ci:hover { color: #059669; }
    .book-foot .act.co:hover { color: #dc2626; }
    .pill {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .7rem; font-weight: 700; padding: .22rem .55rem;
        border-radius: 999px; white-space: nowrap;
    }
    .pill.on { background: #e6f9f0; color: #047857; }
    .pill.off { background: var(--gray-100); color: var(--gray-500); }
    .pill.in { background: #e6f9f0; color: #047857; }
    .pill.out { background: #fdeaea; color: #b91c1c; }
    html[data-theme="dark"] .pill.on, html[data-theme="dark"] .pill.in { background: #0b2e22; color: #6ee7b7; }
    html[data-theme="dark"] .pill.out { background: #3a1212; color: #fca5a5; }

    /* ── Right rail ───────────────────────────────────────────────────────── */
    .rail-card {
        background: var(--surface); border: 1px solid var(--gray-200);
        border-radius: 16px; padding: 1.05rem 1.15rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
    }
    .rail-card h3 {
        margin: 0 0 .85rem; font-size: .95rem; font-weight: 800; color: var(--gray-900);
        display: flex; align-items: center; gap: .5rem;
    }
    .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .qa {
        display: flex; align-items: center; gap: .5rem; justify-content: center;
        border: 1px solid var(--gray-200); border-radius: 11px; cursor: pointer;
        background: var(--gray-50); padding: .6rem .5rem;
        font-size: .78rem; font-weight: 700; color: var(--gray-700);
        text-align: center; transition: transform .15s ease, filter .15s ease;
    }
    .qa:hover { transform: translateY(-2px); filter: brightness(.98); }
    .qa.in { background: #e6f9f0; color: #047857; border-color: #bbf7d0; }
    .qa.out { background: #fdeaea; color: #b91c1c; border-color: #fecaca; }
    .qa.tr { background: #e8f1ff; color: #1d4ed8; border-color: #bfdbfe; }
    .qa.new { background: #f1ecff; color: #6d28d9; border-color: #ddd6fe; }
    html[data-theme="dark"] .qa.in { background: #0b2e22; border-color: #14532d; color: #6ee7b7; }
    html[data-theme="dark"] .qa.out { background: #3a1212; border-color: #7f1d1d; color: #fca5a5; }
    html[data-theme="dark"] .qa.tr { background: #16233f; border-color: #1e3a8a; color: #93c5fd; }
    html[data-theme="dark"] .qa.new { background: #211a3a; border-color: #4c1d95; color: #c4b5fd; }

    .sum-row { display: flex; align-items: center; gap: .5rem; font-size: .8rem; padding: .38rem 0; }
    .sum-row .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .sum-row span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--gray-700); }
    .sum-row b { font-weight: 800; color: var(--gray-900); white-space: nowrap; }
    .sum-more { display: block; margin-top: .7rem; font-size: .78rem; font-weight: 700; text-align: right; }

    .tips { margin: 0; padding-left: 1.05rem; font-size: .78rem; color: var(--gray-600); line-height: 1.65; }
    .tips li { margin-bottom: .25rem; }

    /* ── Table head, its inline filters, footer ───────────────────────────── */
    .tbl-head {
        display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100);
    }
    .tbl-head h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
    .cnt { font-size: .72rem; font-weight: 700; background: #e8f1ff; color: #1d4ed8; padding: .2rem .6rem; border-radius: 999px; }
    .tbl-head .spacer { flex: 1; }
    .flt-inline { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; margin: 0; }
    .flt-inline select, .flt-inline input {
        height: 34px; font-size: .78rem; padding: .25rem .55rem;
        border: 1px solid var(--gray-200); border-radius: 9px;
        background: var(--surface); color: var(--gray-700);
    }
    .flt-inline .date-wrap { display: flex; align-items: center; gap: .3rem; }
    .flt-inline .date-wrap i { font-size: .74rem; color: var(--gray-400); }
    .search-wrap { position: relative; display: inline-flex; }
    .search-wrap > i { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); font-size: .72rem; color: var(--gray-400); pointer-events: none; }
    .search-wrap input { padding-left: 1.9rem; width: 186px; }
    .clr { font-size: .76rem; font-weight: 700; color: var(--gray-500); white-space: nowrap; }
    .clr:hover { color: #dc2626; }

    .amt-in { color: #047857; font-weight: 800; white-space: nowrap; }
    .amt-out { color: #b91c1c; font-weight: 800; white-space: nowrap; }
    .bal { font-weight: 700; color: var(--gray-700); white-space: nowrap; }
    .desc strong { display: block; font-weight: 700; color: var(--gray-800); }
    .desc small { color: var(--gray-400); font-size: .7rem; }
    .table tbody tr.voided { opacity: .62; }
    .table tbody tr.voided td:first-child { position: relative; }

    .row-acts { display: flex; align-items: center; gap: .35rem; }
    .act {
        width: 32px; height: 32px; border: none; border-radius: 9px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; font-size: .78rem;
        background: var(--gray-50); border: 1px solid var(--gray-200); color: var(--gray-600);
        text-decoration: none; transition: transform .15s ease, filter .15s ease;
    }
    .act:hover { transform: translateY(-1px); filter: brightness(.97); color: #2563eb; text-decoration: none; }
    .act.danger:hover { color: #dc2626; }
    .menu { position: relative; }
    .menu-list {
        display: none; position: absolute; right: 0; top: 34px; z-index: 40;
        min-width: 158px; padding: .3rem; background: var(--surface);
        border: 1px solid var(--gray-200); border-radius: 10px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, .16);
    }
    .menu.open .menu-list { display: block; }
    /* The table scrolls, so a menu on the last rows would be clipped by the
       wrapper's bottom edge - those open upwards instead. */
    .menu.up .menu-list { top: auto; bottom: 34px; }
    .menu-list a, .menu-list button {
        display: flex; align-items: center; gap: .5rem; width: 100%;
        padding: .45rem .6rem; border: none; background: none; cursor: pointer;
        border-radius: 7px; font-size: .8rem; font-weight: 600; text-align: left;
        color: var(--gray-700); text-decoration: none;
    }
    .menu-list a:hover, .menu-list button:hover { background: var(--gray-100); text-decoration: none; }
    .menu-list .danger { color: #dc2626; }

    .tbl-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        flex-wrap: wrap; padding: .85rem 1.25rem; border-top: 1px solid var(--gray-100);
        font-size: .8rem; color: var(--gray-500);
    }
    .pager { display: flex; align-items: center; gap: .35rem; }
    .pg {
        min-width: 30px; height: 30px; padding: 0 .45rem; border-radius: 8px;
        border: 1px solid var(--gray-200); background: var(--surface); color: var(--gray-600);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .76rem; font-weight: 700; text-decoration: none;
    }
    .pg:hover { border-color: #bfdbfe; color: #2563eb; text-decoration: none; }
    .pg.cur { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pg.off { opacity: .4; pointer-events: none; }
    .pg-dots { color: var(--gray-400); font-size: .8rem; padding: 0 .1rem; }

    /* A phone has no room for four hero buttons beside a heading. */
    @media (max-width: 820px) {
        .pg-hero-actions { width: 100%; }
        .pg-hero-actions .btn { flex: 1; justify-content: center; }
        .flt-inline { width: 100%; }
        .search-wrap, .search-wrap input { width: 100%; }
    }
</style>

<!-- Flash Message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span>
            <?php echo $flash['message']; ?>
        </span>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div class="pg-hero">
    <span class="pg-hero-ico"><i class="fas fa-book-open"></i></span>
    <div class="pg-hero-txt">
        <h2>Cashbook Management</h2>
        <p>Manage your cashbooks, cash in, cash out and transfers</p>
    </div>
    <div class="pg-hero-actions">
        <button type="button" class="btn btn-primary" onclick="openBookModal()">
            <i class="fas fa-plus"></i> Create Cashbook
        </button>
        <button type="button" class="btn btn-success" onclick="openEntryModal('cash_in')">
            <i class="fas fa-arrow-down"></i> Cash In
        </button>
        <button type="button" class="btn btn-danger" onclick="openEntryModal('cash_out')">
            <i class="fas fa-arrow-up"></i> Cash Out
        </button>
        <button type="button" class="btn btn-secondary" onclick="openTransferModal()">
            <i class="fas fa-right-left"></i> Transfer
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <span class="kpi-ico blue"><i class="fas fa-vault"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Total Balance</div>
            <div class="kpi-value"><?php echo formatCurrency($stats['total_balance']); ?></div>
            <div class="kpi-foot"><?php echo $kpiBookName ? 'Balance of ' . $kpiBookName : 'Across all cashbooks'; ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico green"><i class="fas fa-arrow-up"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Cash In Today</div>
            <div class="kpi-value"><?php echo formatCurrency($stats['today_in']); ?></div>
            <div class="kpi-foot">+<?php echo number_format($todayInCount); ?> transaction<?php echo $todayInCount === 1 ? '' : 's'; ?><?php echo $kpiScope; ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico rose"><i class="fas fa-arrow-down"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Cash Out Today</div>
            <div class="kpi-value"><?php echo formatCurrency($stats['today_out']); ?></div>
            <div class="kpi-foot">-<?php echo number_format($todayOutCount); ?> transaction<?php echo $todayOutCount === 1 ? '' : 's'; ?><?php echo $kpiScope; ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <span class="kpi-ico violet"><i class="fas fa-right-left"></i></span>
        <div class="kpi-body">
            <div class="kpi-label">Net Cash Flow</div>
            <div class="kpi-value"><?php echo formatCurrency($netToday); ?></div>
            <div class="kpi-foot">Today's net flow<?php echo $kpiScope; ?></div>
        </div>
    </div>
</div>

<div class="cb-layout">
    <div class="cb-main">

        <!-- Your Cashbooks -->
        <div class="card" id="books">
            <div class="tbl-head">
                <h3><i class="fas fa-wallet" style="color:#2563eb;"></i> Your Cashbooks</h3>
                <span class="cnt"><?php echo count($visibleBooks); ?></span>
                <span class="spacer"></span>
                <a class="btn btn-outline btn-sm" href="<?php echo htmlspecialchars($showArchived
                    ? cashbookQuery(['show_archived' => null])
                    : cashbookQuery(['show_archived' => 1])); ?>">
                    <?php echo $showArchived ? 'Active only' : 'View all'; ?>
                </a>
                <button type="button" class="btn btn-primary btn-sm" onclick="openBookModal()">
                    <i class="fas fa-plus"></i> New Cashbook
                </button>
            </div>
            <?php if (empty($visibleBooks)): ?>
                <p class="text-muted" style="padding:1.5rem 1.25rem;margin:0;">
                    No cashbook yet - create one for the drawer, the bank, or the bKash number the shop takes money on.
                </p>
            <?php else: ?>
                <div class="book-grid">
                    <?php foreach ($visibleBooks as $b):
                        $bid = (int)$b['id'];
                        $type = isset($bookTypes[$b['type']]) ? $b['type'] : 'other';
                        $archived = $b['status'] !== 'active';
                    ?>
                        <div class="book-card <?php echo $archived ? 'archived' : ''; ?>">
                            <div class="book-top">
                                <span class="book-ico <?php echo $type; ?>"><i class="<?php echo $bookTypes[$type][1]; ?>"></i></span>
                                <span class="book-id">
                                    <strong><?php echo sanitize($b['name']); ?></strong>
                                    <small><?php echo $bookTypes[$type][0]; ?></small>
                                </span>
                                <span class="pill <?php echo $archived ? 'off' : 'on'; ?>">
                                    <?php echo $archived ? 'Archived' : 'Active'; ?>
                                </span>
                            </div>
                            <div class="book-bal">
                                <div>
                                    <div class="kpi-label">Current Balance</div>
                                    <div class="book-bal-val"><?php echo formatCurrency($b['current_balance']); ?></div>
                                    <small>Opening: <?php echo formatCurrency($b['opening_balance']); ?>
                                        <?php if ($b['is_default']): ?> &middot; Default <?php endif; ?>
                                    </small>
                                    <?php $sid = (int)($b['supplier_id'] ?? 0); ?>
                                    <?php if ($sid && isset($supplierNames[$sid])): ?>
                                        <small class="book-sup"><i class="fas fa-truck"></i> Pays <?php echo sanitize($supplierNames[$sid]); ?></small>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="act danger" title="Delete book"
                                    data-id="<?php echo $bid; ?>"
                                    data-name="<?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-lines="<?php echo (int)($bookLines[$bid] ?? 0); ?>"
                                    onclick="openDeleteBookModal(this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                            <div class="book-foot">
                                <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars(cashbookQuery(['book' => $bid, 'show_archived' => $showArchived ? 1 : null, 'page' => null])); ?>">
                                    View Details <i class="fas fa-arrow-right"></i>
                                </a>
                                <?php if (!$archived): ?>
                                    <button type="button" class="act ci" title="Cash In to <?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openEntryModal('cash_in', <?php echo $bid; ?>)">
                                        <i class="fas fa-arrow-down"></i>
                                    </button>
                                    <button type="button" class="act co" title="Cash Out from <?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openEntryModal('cash_out', <?php echo $bid; ?>)">
                                        <i class="fas fa-arrow-up"></i>
                                    </button>
                                <?php endif; ?>
                                <div class="menu">
                                    <button type="button" class="act" title="More" onclick="toggleMenu(this.parentNode)">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="menu-list">
                                        <button type="button" onclick="openBookModal(<?php echo $bid; ?>)">
                                            <i class="fas fa-pen"></i> Edit book
                                        </button>
                                        <form method="POST">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="toggle_book">
                                            <input type="hidden" name="book_id" value="<?php echo $bid; ?>">
                                            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="<?php echo $archived ? '' : 'danger'; ?>">
                                                <i class="fas fa-<?php echo $archived ? 'rotate-left' : 'archive'; ?>"></i>
                                                <?php echo $archived ? 'Restore book' : 'Archive book'; ?>
                                            </button>
                                        </form>
                                        <button type="button" class="danger"
                                            data-id="<?php echo $bid; ?>"
                                            data-name="<?php echo htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-lines="<?php echo (int)($bookLines[$bid] ?? 0); ?>"
                                            onclick="openDeleteBookModal(this)">
                                            <i class="fas fa-trash"></i> Delete book
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
            <div class="tbl-head">
                <h3><i class="fas fa-clock-rotate-left" style="color:#2563eb;"></i> Recent Transactions</h3>
                <span class="cnt"><?php echo number_format($totalFiltered); ?> Records</span>
                <span class="spacer"></span>
                <form method="GET" class="flt-inline" id="ledgerFilter">
                    <span class="date-wrap">
                        <i class="far fa-calendar"></i>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>" aria-label="From date">
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>" aria-label="To date">
                    </span>
                    <select name="book" aria-label="Cashbook">
                        <option value="">All Cashbooks</option>
                        <?php foreach ($books as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo $bookFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" aria-label="Type">
                        <option value="">All Types</option>
                        <?php foreach (cashbookTypeOptions() as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $typeFilter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by description...">
                    </span>
                    <?php if (!$storeId && $stores): ?>
                        <select name="store" aria-label="Store">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $st): ?>
                                <option value="<?php echo (int)$st['id']; ?>" <?php echo $filterStore === (int)$st['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($st['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if ($showArchived): ?>
                        <input type="hidden" name="show_archived" value="1">
                    <?php endif; ?>
                    <!-- Present so pressing Enter in the search box submits the
                         filter; visible is not its job. -->
                    <button type="submit" class="btn btn-primary btn-sm" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;overflow:hidden;">Go</button>
                    <?php if ($filtered): ?>
                        <a class="clr" href="cashbook.php"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </form>
                <div class="menu" id="exportMenu">
                    <button class="btn btn-outline btn-sm" type="button" onclick="toggleMenu('exportMenu')">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <div class="menu-list">
                        <button type="button" onclick="exportCashbook('xls')"><i class="fas fa-file-excel"></i> Excel (XLS)</button>
                        <button type="button" onclick="exportCashbook('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
                    </div>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Book</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <?php echo $totalFiltered ? 'No transactions on this page.' : 'No transactions match these filters.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($entries as $e):
                                $eid = (int)$e['id'];
                                $voided = (int)$e['is_void'] === 1;
                                $isIn = $e['type'] === 'cash_in';
                                $when = ($e['entry_date'] ?: substr($e['created_at'], 0, 10))
                                    . ' ' . substr($e['created_at'], 11, 5);
                                $refs = array_filter([(string)$e['reference_no'], (string)$e['invoice_no']]);
                            ?>
                                <tr class="<?php echo $voided ? 'voided' : ''; ?>">
                                    <td><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $e['book_name'] ? sanitize($e['book_name']) : '<span class="text-muted">Unassigned</span>'; ?></td>
                                    <td>
                                        <span class="pill <?php echo $isIn ? 'in' : 'out'; ?>">
                                            <i class="fas fa-<?php echo $isIn ? 'arrow-up' : 'arrow-down'; ?> fa-2xs"></i>
                                            <?php echo $isIn ? 'Cash In' : 'Cash Out'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo sanitize(cashbookCategoryLabel($e, $categories)); ?></td>
                                    <td class="desc">
                                        <strong><?php echo $e['note'] !== null && $e['note'] !== '' ? sanitize($e['note']) : '-'; ?></strong>
                                        <?php if ($refs): ?>
                                            <small><?php echo sanitize(implode(' · ', $refs)); ?></small>
                                        <?php endif; ?>
                                        <?php if ($voided): ?>
                                            <small style="color:#b91c1c;">Voided: <?php echo sanitize($e['void_reason'] ?: 'no reason given'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $isIn ? 'amt-in' : 'amt-out'; ?>">
                                        <?php echo $voided ? '' : ($isIn ? '+' : '-') . formatCurrency($e['amount']); ?>
                                        <?php if ($voided): ?><s><?php echo formatCurrency($e['amount']); ?></s><?php endif; ?>
                                    </td>
                                    <td class="bal"><?php echo $voided ? '-' : formatCurrency($running[$eid] ?? 0); ?></td>
                                    <td>
                                        <div class="row-acts">
                                            <div class="menu">
                                                <button type="button" class="act" title="More" onclick="toggleMenu(this.parentNode)">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="menu-list">
                                                    <?php if (!empty($e['attachment'])): ?>
                                                        <a href="<?php echo htmlspecialchars(assetUrl($e['attachment']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                                            <i class="fas fa-paperclip"></i> Attachment
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!$voided): ?>
                                                        <button type="button" class="danger"
                                                            data-id="<?php echo $eid; ?>"
                                                            data-label="<?php echo htmlspecialchars($when . ' · ' . ($e['note'] ?: 'entry') . ' · ' . formatCurrency($e['amount']), ENT_QUOTES, 'UTF-8'); ?>"
                                                            onclick="openVoidModal(this)">
                                                            <i class="fas fa-ban"></i> Void transaction
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="danger"
                                                        data-id="<?php echo $eid; ?>"
                                                        data-label="<?php echo htmlspecialchars($when . ' · ' . ($e['note'] ?: 'entry') . ' · ' . formatCurrency($e['amount']) . ' · ' . ($e['book_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?>"
                                                        onclick="openDeleteModal(this)">
                                                        <i class="fas fa-trash"></i> Delete transaction
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="tbl-foot">
                <span>
                    <?php if ($totalFiltered === 0): ?>
                        Nothing to show for these filters
                    <?php else: ?>
                        Showing <?php echo $offset + 1; ?> to <?php echo min($page * $perPage, $totalFiltered); ?>
                        of <?php echo number_format($totalFiltered); ?> transactions
                    <?php endif; ?>
                </span>
                <?php if ($totalPages > 1): ?>
                    <div class="pager">
                        <a class="pg <?php echo $page <= 1 ? 'off' : ''; ?>"
                            href="<?php echo htmlspecialchars(cashbookQuery(['page' => max(1, $page - 1)])); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <?php
                        // A window of pages around the current one, never more than 7 links.
                        $from2 = max(1, min($page - 3, $totalPages - 6));
                        $to2 = min($totalPages, max($page + 3, 7));
                        ?>
                        <?php if ($from2 > 1): ?>
                            <a class="pg" href="<?php echo htmlspecialchars(cashbookQuery(['page' => 1])); ?>">1</a>
                            <?php if ($from2 > 2): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($i = $from2; $i <= $to2; $i++): ?>
                            <a class="pg <?php echo $i === $page ? 'cur' : ''; ?>"
                                href="<?php echo htmlspecialchars(cashbookQuery(['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($to2 < $totalPages): ?>
                            <?php if ($to2 < $totalPages - 1): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                            <a class="pg" href="<?php echo htmlspecialchars(cashbookQuery(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                        <?php endif; ?>
                        <a class="pg <?php echo $page >= $totalPages ? 'off' : ''; ?>"
                            href="<?php echo htmlspecialchars(cashbookQuery(['page' => min($totalPages, $page + 1)])); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right rail -->
    <aside class="cb-rail">
        <div class="rail-card">
            <h3><i class="fas fa-bolt" style="color:#f59e0b;"></i> Quick Actions</h3>
            <div class="qa-grid">
                <button type="button" class="qa in" onclick="openEntryModal('cash_in')">
                    <i class="fas fa-plus"></i> Cash In
                </button>
                <button type="button" class="qa out" onclick="openEntryModal('cash_out')">
                    <i class="fas fa-minus"></i> Cash Out
                </button>
                <button type="button" class="qa tr" onclick="openTransferModal()">
                    <i class="fas fa-right-left"></i> Transfer
                </button>
                <button type="button" class="qa new" onclick="openBookModal()">
                    <i class="fas fa-plus"></i> Create Cashbook
                </button>
            </div>
        </div>

        <div class="rail-card">
            <h3><i class="fas fa-chart-pie" style="color:#2563eb;"></i> Cashbook Summary</h3>
            <?php if (empty($activeBooks)): ?>
                <p class="text-muted" style="font-size:.8rem;margin:0;">No active cashbook yet.</p>
            <?php else: ?>
                <?php foreach ($activeBooks as $b): $t = isset($dotColors[$b['type']]) ? $b['type'] : 'other'; ?>
                    <div class="sum-row">
                        <i class="dot" style="background:<?php echo $dotColors[$t]; ?>;"></i>
                        <span><?php echo sanitize($b['name']); ?></span>
                        <b><?php echo formatCurrency($b['current_balance']); ?></b>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a class="sum-more" href="<?php echo htmlspecialchars($showArchived ? cashbookQuery(['show_archived' => null]) : cashbookQuery(['show_archived' => 1])); ?>#books">
                View All Cashbooks <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <div class="rail-card">
            <h3><i class="fas fa-circle-info" style="color:#0ea5e9;"></i> Cashbook Tips</h3>
            <ul class="tips">
                <li>Use <strong>Cash In</strong> for sales, deposits and collections.</li>
                <li>Use <strong>Cash Out</strong> for payments, refunds and expenses.</li>
                <li>Transfer moves money between cashbooks, never into income.</li>
                <li>Keep each book balanced so the reports tell the truth.</li>
            </ul>
        </div>
    </aside>
</div>

<!-- Create / Edit Cashbook -->
<div class="modal-overlay" id="bookModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="bookModalTitle">Create Cashbook</h3>
            <button type="button" class="modal-close" onclick="closeBookModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_book">
            <input type="hidden" name="book_id" id="bkId" value="">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Cashbook Name</label>
                    <input type="text" name="name" id="bkName" class="form-control" placeholder="e.g. Main Cash, bKash Personal" required>
                </div>
                <!-- No type is asked for: a new book is a cash drawer, and editing one
                     carries its stored type through so a bank or bKash book saved earlier
                     keeps matching the payment method it was built for. -->
                <input type="hidden" name="type" id="bkType" value="cash">
                <?php if ($bookSuppliers): ?>
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" id="bkSupplier" class="form-control">
                        <option value="">No supplier</option>
                        <?php foreach ($bookSuppliers as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">Payments to this supplier open with this book already picked. Leave it on "No supplier" for the shop's own money.</small>
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Opening Balance</label>
                    <input type="number" name="opening_balance" id="bkOpening" class="form-control" min="0" step="0.01" value="0">
                    <small class="form-text">What was already in it before this shop started keeping books here.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Opening Date</label>
                    <input type="date" name="opening_date" id="bkDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBookModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Cashbook</button>
            </div>
        </form>
    </div>
</div>

<!-- Cash In / Cash Out -->
<div class="modal-overlay" id="entryModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="entryModalTitle">Cash In</h3>
            <button type="button" class="modal-close" onclick="closeEntryModal()">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="entryForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_entry">
            <input type="hidden" name="type" id="enType" value="cash_in">
            <!-- One token per page load, so a double click on Save books once. -->
            <input type="hidden" name="idempotency_key" value="<?php echo htmlspecialchars(bin2hex(random_bytes(8)), ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Cashbook</label>
                    <select name="cashbook_id" id="enBook" class="form-control" required>
                        <?php foreach ($activeBooks as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo $bookFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($activeBooks)): ?>
                        <small class="form-text">Create a cashbook first - an entry has to go somewhere.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label required">Amount</label>
                    <div class="input-group">
                        <input type="number" name="amount" id="enAmount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                        <button type="button" class="btn btn-outline" style="border-radius: 0 var(--border-radius) var(--border-radius) 0; border-left: none;">&#2547;</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label required">Date</label>
                    <input type="date" name="entry_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    <small class="form-text">The day the money actually moved, which the ledger searches by.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Attachment</label>
                    <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                    <small class="form-text">A slip or screenshot, up to 5MB.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEntryModal()">Cancel</button>
                <button type="submit" class="btn btn-success" id="entrySubmit">
                    <i class="fas fa-save"></i> Save Entry
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer -->
<div class="modal-overlay" id="transferModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Transfer Between Cashbooks</h3>
            <button type="button" class="modal-close" onclick="closeTransferModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">From Cashbook</label>
                    <select name="from_book_id" class="form-control" required>
                        <?php foreach ($activeBooks as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo sanitize($b['name']); ?> (<?php echo formatCurrency($b['current_balance']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label required">To Cashbook</label>
                    <select name="to_book_id" class="form-control" required>
                        <?php foreach ($activeBooks as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>"><?php echo sanitize($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text">A transfer is not income: it leaves one book and enters the other.</small>
                </div>
                <div class="form-group">
                    <label class="form-label required">Amount</label>
                    <input type="number" name="amount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="transfer_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Reference No</label>
                    <input type="text" name="reference_no" class="form-control" placeholder="e.g. Bank slip 4412">
                </div>
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <input type="text" name="note" class="form-control" placeholder="e.g. Banked the day's cash">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-right-left"></i> Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Void -->
<div class="modal-overlay" id="voidModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Void Transaction</h3>
            <button type="button" class="modal-close" onclick="closeVoidModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="void_entry">
            <input type="hidden" name="entry_id" id="vdId" value="">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <p class="text-muted" style="font-size:.82rem;margin:0 0 .9rem;">
                    <span id="vdLabel"></span>
                </p>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>A transfer voids both of its sides, because one book cannot give without the other receiving.</span>
                </div>
                <div class="form-group">
                    <label class="form-label required">Reason</label>
                    <textarea name="reason" id="vdReason" class="form-control" rows="3"
                        placeholder="Why is this line coming off the books?" required></textarea>
                    <small class="form-text">Nothing is deleted - the line stays with this reason on it.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVoidModal()">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Void Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Delete Transaction</h3>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_entry">
            <input type="hidden" name="entry_id" id="dlId" value="">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <p class="text-muted" style="font-size:.82rem;margin:0 0 .9rem;">
                    <span id="dlLabel"></span>
                </p>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Deleting leaves no trace - the line goes and the book's balance moves with it. The sale, purchase or return it came from is not touched, only this cash record. A transfer takes both of its sides off.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete book -->
<div class="modal-overlay" id="deleteBookModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Delete Cashbook</h3>
            <button type="button" class="modal-close" onclick="closeDeleteBookModal()">&times;</button>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_book">
            <input type="hidden" name="book_id" id="dlbId" value="">
            <input type="hidden" name="ret" value="<?php echo htmlspecialchars($retQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-body">
                <p class="text-muted" style="font-size:.82rem;margin:0 0 .9rem;">
                    <span id="dlbLabel"></span>
                </p>
                <div class="alert alert-warning" style="margin-bottom:1rem;">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span>Deleting takes the book and the ledger lines inside it off for good. The sale, purchase or return those lines came from is not touched, and a transfer goes on both of its sides. This cannot be undone.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteBookModal()">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete Cashbook</button>
            </div>
        </form>
    </div>
</div>

<script>
    const CB_BOOKS = <?php echo json_encode($bookForm, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function show(id) { document.getElementById(id).classList.add('active'); }
    function hide(id) { document.getElementById(id).classList.remove('active'); }

    /** No id means a new book; an id loads that book back into the same form. */
    function openBookModal(id) {
        const t = document.getElementById('bookModalTitle');
        const f = {
            bkId: id || '', bkName: '', bkType: 'cash', bkSupplier: '', bkOpening: 0,
            bkDate: '<?php echo date('Y-m-d'); ?>'
        };
        if (id && CB_BOOKS[id]) {
            const b = CB_BOOKS[id];
            f.bkName = b.name; f.bkType = b.type; f.bkOpening = b.opening_balance;
            f.bkSupplier = b.supplier_id || '';
            f.bkDate = b.opening_date || f.bkDate;
            t.textContent = 'Edit Cashbook';
        } else {
            t.textContent = 'Create Cashbook';
        }
        document.getElementById('bkId').value = f.bkId;
        document.getElementById('bkName').value = f.bkName;
        document.getElementById('bkType').value = f.bkType;
        const sup = document.getElementById('bkSupplier');
        if (sup) sup.value = f.bkSupplier;
        document.getElementById('bkOpening').value = f.bkOpening;
        document.getElementById('bkDate').value = f.bkDate;
        show('bookModal');
    }
    function closeBookModal() { hide('bookModal'); }

    /** One form for both directions; the button that opened it says which. */
    function openEntryModal(type, bookId) {
        type = type === 'cash_out' ? 'cash_out' : 'cash_in';
        document.getElementById('enType').value = type;
        document.getElementById('entryModalTitle').textContent = type === 'cash_in' ? 'Cash In' : 'Cash Out';
        const btn = document.getElementById('entrySubmit');
        btn.className = 'btn ' + (type === 'cash_in' ? 'btn-success' : 'btn-danger');
        const sel = document.getElementById('enBook');
        // The card that opened the modal names its own book, so the money starts
        // where it was clicked from - still one change away if that was wrong.
        if (bookId && sel.querySelector('option[value="' + bookId + '"]')) sel.value = String(bookId);
        document.getElementById('enAmount').value = '';
        show('entryModal');
        setTimeout(function () { document.getElementById('enAmount').focus(); }, 60);
    }
    function closeEntryModal() { hide('entryModal'); }

    function openTransferModal() {
        const books = document.querySelectorAll('#transferModal select[name="from_book_id"] option, #transferModal select[name="to_book_id"] option');
        if (!books.length) {
            alert('Create a cashbook first - there is nothing to transfer between yet.');
            return;
        }
        show('transferModal');
    }
    function closeTransferModal() { hide('transferModal'); }

    function openVoidModal(btn) {
        document.getElementById('vdId').value = btn.dataset.id;
        document.getElementById('vdLabel').textContent = btn.dataset.label;
        document.getElementById('vdReason').value = '';
        show('voidModal');
        setTimeout(function () { document.getElementById('vdReason').focus(); }, 60);
    }
    function closeVoidModal() { hide('voidModal'); }

    function openDeleteModal(btn) {
        document.getElementById('dlId').value = btn.dataset.id;
        document.getElementById('dlLabel').textContent = btn.dataset.label;
        show('deleteModal');
    }
    function closeDeleteModal() { hide('deleteModal'); }

    function openDeleteBookModal(btn) {
        const lines = parseInt(btn.dataset.lines, 10) || 0;
        document.getElementById('dlbId').value = btn.dataset.id;
        document.getElementById('dlbLabel').textContent = lines > 0
            ? '"' + btn.dataset.name + '" and its ' + lines + ' ledger line' + (lines === 1 ? '' : 's') + ' will be deleted.'
            : '"' + btn.dataset.name + '" will be removed from the list.';
        show('deleteBookModal');
    }
    function closeDeleteBookModal() { hide('deleteBookModal'); }

    document.querySelectorAll('.modal-overlay').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('active'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(function (m) { m.classList.remove('active'); });
        }
    });

    function exportCashbook(format) {
        const p = new URLSearchParams(location.search);
        p.set('format', format);
        location.href = 'api/export-cashbook.php?' + p.toString();
    }

    /* One dropdown component: any element with class "menu" toggles its own
       list, and a click anywhere else closes them all. */
    function toggleMenu(menu) {
        const el = typeof menu === 'string' ? document.getElementById(menu) : menu;
        if (!el) return;
        const wasOpen = el.classList.contains('open');
        document.querySelectorAll('.menu.open').forEach(function (m) { m.classList.remove('open'); });
        if (wasOpen) return;

        const box = (el.querySelector('.act, .btn') || el).getBoundingClientRect();
        // Only the scrolling table clips its children; a menu outside it (the
        // Export button) can always hang downwards.
        const clip = el.closest('.table-wrapper');
        el.classList.toggle('up', !!clip && clip.getBoundingClientRect().bottom - box.bottom < 90);
        el.classList.add('open');
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.menu')) {
            document.querySelectorAll('.menu.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });

    // The ledger filter has no Apply button, so picking a date or a book is the
    // answer to the question rather than a draft of one.
    document.querySelectorAll('#ledgerFilter select, #ledgerFilter input[type="date"]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });
</script>

<?php include 'includes/footer.php'; ?>
