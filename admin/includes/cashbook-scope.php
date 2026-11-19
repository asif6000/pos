<?php
/**
 * POS System - Cashbook ledger filter/scope
 *
 * The transaction list on cashbook.php and its export endpoint must never
 * disagree about which rows a visitor may see, so the FROM/WHERE/params build
 * lives here once.
 *
 * The scope mirrors getCashbookSummaryStats() in config/cashbook.php exactly -
 * the owner's own rows, and for a shop user that shop's rows plus the rows no
 * shop ever claimed - so the table can never add up to money the KPI cards are
 * not showing.
 */

/** The two directions an entry can move money in. */
function cashbookTypeOptions()
{
    return ['cash_in' => 'Cash In', 'cash_out' => 'Cash Out'];
}

/**
 * The selected window, defaulting to the current month. A blank or unparsable
 * date never falls back to 1970.
 *
 * @return array 0 => dateFrom, 1 => dateTo
 */
function cashbookDateRange($get)
{
    $from = trim((string)($get['date_from'] ?? ''));
    $to   = trim((string)($get['date_to'] ?? ''));

    $start = strtotime($from);
    $end = strtotime($to);
    if ($from === '' || $start === false) { $from = date('Y-m-01'); $start = strtotime($from); }
    if ($to === '' || $end === false) { $to = date('Y-m-d'); $end = strtotime($to); }
    $from = date('Y-m-d', $start);
    $to = date('Y-m-d', $end);
    if ($end < $start) {
        $swap = $from; $from = $to; $to = $swap;
    }
    return [$from, $to];
}

/**
 * FROM + WHERE for the filtered cashbook ledger.
 *
 * @return array from, where, params, filters
 */
function cashbookFilterSql($user, $get)
{
    list($dateFrom, $dateTo) = cashbookDateRange($get);

    $types = cashbookTypeOptions();
    $type = isset($get['type']) && isset($types[$get['type']]) ? $get['type'] : '';
    $book = (int)($get['book'] ?? 0);
    $search = trim((string)($get['search'] ?? ''));
    $filterStore = (int)($get['store'] ?? 0);

    $storeId = !empty($user['store_id']) ? (int)$user['store_id'] : 0;
    $ownerId = (int)($user['owner_id'] ?? $user['id'] ?? 0);

    $from = "FROM cashbook_entries ce
            LEFT JOIN cash_books cb ON cb.id = ce.cashbook_id";

    // A movement booked for a past day is found by that day, not by the moment
    // somebody typed it in - which is what entry_date holds.
    $where = " WHERE ce.owner_id = ?
                AND DATE(COALESCE(ce.entry_date, ce.created_at)) BETWEEN ? AND ?";
    $params = [$ownerId, $dateFrom, $dateTo];

    // A staff user pinned to one shop sees that shop's money plus the entries no
    // shop claimed; an owner can narrow the whole shop ledger with the Store
    // filter, which asks the same question of the same two kinds of rows.
    if ($storeId || $filterStore) {
        $where .= " AND (ce.store_id = ? OR ce.store_id IS NULL)";
        $params[] = $storeId ?: $filterStore;
    }

    if ($book) {
        $where .= " AND ce.cashbook_id = ?";
        $params[] = $book;
    }

    if ($type !== '') {
        $where .= " AND ce.type = ?";
        $params[] = $type;
    }

    if ($search !== '') {
        $where .= " AND (ce.note LIKE ? OR ce.reference_no LIKE ? OR ce.invoice_no LIKE ? OR cb.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    return [
        'from' => $from,
        'where' => $where,
        'params' => $params,
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'book' => $book,
            'type' => $type,
            'search' => $search,
            'store' => $filterStore,
        ],
    ];
}

/**
 * What a ledger row actually was, in words the shop owner already uses.
 *
 * The category tick only exists for hand-written entries, so an auto-posted sale,
 * refund or transfer answers from its own source instead - otherwise the whole
 * POS half of the ledger would read "Other". The rows the Expense page writes
 * carry no source at all, just a category or none, which is what the fallbacks
 * below are for.
 */
function cashbookCategoryLabel($row, array $categoryNames = [])
{
    if (!empty($row['category_id']) && isset($categoryNames[(int)$row['category_id']])) {
        return $categoryNames[(int)$row['category_id']];
    }
    switch ($row['source_type']) {
        case 'sale':
            return 'Sales';
        case 'purchase':
            return 'Supplier Payment';
        case 'return':
            return 'Customer Refund';
        case 'transfer':
            return 'Transfer';
        default:
            return $row['type'] === 'cash_in' ? 'Deposit' : 'Other';
    }
}
