<?php
/**
 * POS System - Sales list filter/scope
 *
 * The sales list and its export endpoint must never disagree about which rows a
 * given user may see, so the FROM/WHERE/params build lives here once.
 */

/** Every payment method the `sales.payment_method` enum accepts. */
function salesPaymentMethods()
{
    return [
        'cash'   => 'Cash',
        'bkash'  => 'bKash',
        'nagad'  => 'Nagad',
        'rocket' => 'Rocket',
        'card'   => 'Card',
        'bank'   => 'Bank',
    ];
}

/**
 * Payment labels for the till only: the methods above plus Lottery.
 *
 * Lottery is a counter sale, not a way to pay a supplier, so it lives here and
 * not in salesPaymentMethods() - that list also feeds the supplier payment
 * picker on Settings. The sales list and its filter use this wider map.
 */
function salesPaymentLabels()
{
    $methods = salesPaymentMethods();
    $methods['lottery'] = 'Lottery';
    return $methods;
}

/**
 * The selected window plus the equally long window straight before it, which is
 * what the KPI "vs previous" figures compare against.
 *
 * @return array 0 => dateFrom, 1 => dateTo, 2 => prevFrom, 3 => prevTo, 4 => days in range
 */
function salesDateRange($get)
{
    $from = isset($get['date_from']) ? trim((string) $get['date_from']) : '';
    $to = isset($get['date_to']) ? trim((string) $get['date_to']) : '';

    $start = strtotime($from);
    $end = strtotime($to);
    // A blank or unparsable date falls back to the current month, never to 1970.
    if ($from === '' || $start === false) { $from = date('Y-m-01'); $start = strtotime($from); }
    if ($to === '' || $end === false) { $to = date('Y-m-d'); $end = strtotime($to); }
    $from = date('Y-m-d', $start);
    $to = date('Y-m-d', $end);
    if ($end < $start) {
        $swap = $from; $from = $to; $to = $swap;
        $swap = $start; $start = $end; $end = $swap;
    }

    $days = (int) floor(($end - $start) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' day'));

    return [$from, $to, $prevFrom, $prevTo, $days];
}

/**
 * FROM + WHERE for the filtered sales list.
 *
 * The date placeholders are always the first two parameters, so a caller can run
 * the same statement over the previous period by swapping just those two.
 *
 * @return array from, where, params, filters
 */
function salesFilterSql($user, $get)
{
    list($dateFrom, $dateTo) = salesDateRange($get);

    $methods = salesPaymentLabels();
    $payment = isset($get['payment']) && isset($methods[$get['payment']]) ? $get['payment'] : '';
    $search = isset($get['search']) ? trim((string) $get['search']) : '';
    $filterStore = (int) ($get['store'] ?? 0);
    $storeId = !empty($user['store_id']) ? (int) $user['store_id'] : 0;

    $from = "FROM sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN stores st ON st.id = " . saleStoreExpr() . "";

    $where = " WHERE DATE(s.created_at) BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];

    // A cashier/staff user is pinned to their own store; an owner sees the whole
    // shop and can narrow it with the Store filter. Narrowing asks the sale which
    // shelf it came out of, because a Super Admin till rings up several shops in
    // one basket and the money belongs to each of them separately.
    // A counter account (staff/cashier) reads only the bills it rang up itself.
    // This is narrower than the store/owner scopes below, which is why it is
    // checked first: without it a staff login opened this page on the whole
    // shop's takings, including every other staff member's.
    if (isOwnSalesOnlyUser($user)) {
        $where .= " AND s.user_id = ?";
        $params[] = (int) $user['id'];
    } elseif ($storeId) {
        $where .= " AND " . saleStoreExpr() . " = ?";
        $params[] = $storeId;
    } elseif (isSuperAdminUser($user)) {
        // The platform account sells off every shop's shelf, so its list is every
        // shop's bills and the Store filter is the only thing that narrows it. It
        // owns no shop, so there is no single tenant this list could belong to.
        if ($filterStore) {
            $where .= " AND " . saleStoreExpr() . " = ?";
            $params[] = $filterStore;
        }
    } else {
        // Exactly the scope the list has always used: an account with no owner id
        // sees nothing rather than borrowing someone else's till records. The sale
        // answers for its own shop first, so a Super Admin bill rung up over this
        // shop's shelf lands in this shop's list instead of vanishing.
        $where .= " AND " . saleOwnerScopeSql($user['owner_id'] ?? null);
        $params[] = $user['owner_id'] ?? null;
        $params[] = $user['owner_id'] ?? null;
        if ($filterStore) {
            $where .= " AND " . saleStoreExpr() . " = ?";
            $params[] = $filterStore;
        }
    }

    if ($payment !== '') {
        $where .= " AND s.payment_method = ?";
        $params[] = $payment;
    }

    if ($search !== '') {
        $where .= " AND (s.invoice_number LIKE ? OR c.name LIKE ? OR u.name LIKE ?)";
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
            'payment' => $payment,
            'search' => $search,
            'store' => $filterStore,
        ],
    ];
}
