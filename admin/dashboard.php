<?php
/**
 * POS System - Admin Dashboard
 * Modern dashboard with complete business overview and reports
 */

require_once '../config/db.php';
require_once '../config/sms_usage.php';
startSecureSession();

// Check authentication
if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$user = getCurrentUser();
$store_id = $user['store_id'] ?? null;
$owner_id = $user['owner_id'] ?? $user['id'];
// If admin and no store assigned, they see global data.
// If specific store admin, they see only their store data.

define('PAGE_TITLE', $store_id ? 'Store Dashboard' : 'Global Dashboard');

$db = getDB();

// Get today's date range
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');

// Is this the platform account, which has no shop of its own and rings goods from
// every one of them at one till? The shared test, not a local spelling of it: an
// empty owner column also describes a shop's own keeper, and treating that as the
// platform account would put every other shop's takings on this page.
$isSuper = isSuperAdminUser($user);

// A counter account (staff/cashier) sees only the sales it rang up itself; the
// shop's totals belong to the owner. Every sales query below receives this id,
// including the ones that do not go through scopeSql() (commission, store-wise,
// top products and the return money that comes off those sales).
$ownUserId = isOwnSalesOnlyUser($user) ? (int)$user['id'] : 0;

// Scope helper: applies store filter to a query
function scopeSql($sql, $store_id, $owner_id, $is_super = false, $own_user_id = 0) {
    if ($own_user_id) {
        // Narrower than any store/owner scope: the person, not the shop.
        $sql .= " JOIN users u ON s.user_id = u.id WHERE s.user_id = ?";
        return [$sql, [$own_user_id]];
    }
    if ($store_id) {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE " . saleStoreExpr() . " = ?";
        return [$sql, [$store_id]];
    }
    if ($is_super) {
        // Requirement 1 and 10 together mean the platform account's figures cover
        // every shop it can sell from, so no owner filter narrows its totals. It
        // owns nothing, so there is no other shop's money it could be reading.
        return [$sql . " JOIN users u ON s.user_id = u.id WHERE 1=1", []];
    }
    $sql .= " JOIN users u ON s.user_id = u.id WHERE " . saleOwnerScopeSql($owner_id);
    // Two placeholders (see saleOwnerScopeSql), so the owner id is bound twice.
    // Returning it once here is what produced
    // SQLSTATE[HY093]: Invalid parameter number on the first query of the page.
    return [$sql, [$owner_id, $owner_id]];
}

/**
 * The WHERE fragment that means "sales belonging to this owner".
 *
 * WHY THIS EXISTS
 * ---------------
 * A top-level admin has users.owner_id = NULL: they have no owner, they ARE the
 * owner. getCurrentUser() hides that by handing back an *effective* owner_id
 * (the user's own id when the column is NULL) so that owner-scoped queries have
 * something to compare against.
 *
 * Comparing that effective id against the raw column is a category error, and
 * it fails silently rather than loudly. NULL = 2 is NULL, not true, so it is
 * never true, so the query is well formed, returns no rows, and every figure on
 * the dashboard reads 0.00. There is no error anywhere to trace it from - the
 * page renders perfectly. That is what it did here: an admin with 9 sales worth
 * of 6,893 saw 0.00 for today's sales, this month, all time and commission.
 *
 * The same NULL is also how a real super admin is recognised, so the fragment
 * has to match both: the owner column, or the row that is its own owner.
 *
 * @param string $alias  table alias for users
 * @param int    $owner  effective owner id (never null)
 * @return string SQL fragment with two '?' placeholders
 */
function ownerScopeSql($alias, $owner_id) {
    return "({$alias}.owner_id = ? OR ({$alias}.owner_id IS NULL AND {$alias}.id = ?))";
}

/**
 * Does cashbook_entries carry the source_type column yet?
 *
 * It is what lets the Net Balance card tell a refund or a book transfer apart
 * from a real expense. An install whose migration has not run has no such
 * column - and, because every writer of those two kinds of line needs it, no
 * such lines either.
 *
 * @return bool
 */
function cashbookHasSourceType()
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = !empty(getDB()->query("SHOW COLUMNS FROM cashbook_entries LIKE 'source_type'")->fetchAll());
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

try {
    // ── Today's Sales ────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = $todayStart; $params[] = $todayEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $todaySales = $stmt->fetch();

    // ── Monthly Sales ────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = $monthStart; $params[] = $monthEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $monthlySales = $stmt->fetch();

    // ── All-time Sales ───────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $allTimeSales = $stmt->fetch();

    // ── Commission (৳5 per unit sold) ────────────────────────────────────────
    $commissionPerUnit = 5.00;
    $commScope = "FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN users u ON s.user_id = u.id WHERE";
    if ($ownUserId) {
        $commWhere = " s.user_id = ?";
        $commParams = [$ownUserId];
    } elseif ($store_id) {
        $commWhere = " " . saleStoreExpr() . " = ?";
        $commParams = [$store_id];
    } else {
        $commWhere = " " . saleOwnerScopeSql($owner_id);
        $commParams = [$owner_id, $owner_id];
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(si.quantity), 0) * $commissionPerUnit as commission
        $commScope $commWhere AND s.created_at BETWEEN ? AND ?");
    $stmt->execute(array_merge($commParams, [$todayStart, $todayEnd]));
    $commissionToday = (float)$stmt->fetchColumn();

    // ── This Year ────────────────────────────────────────────────────────────
    list($sql, $params) = scopeSql("SELECT COALESCE(SUM(s.total), 0) FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $sql .= " AND s.created_at BETWEEN ? AND ?";
    $params[] = date('Y-01-01 00:00:00'); $params[] = $todayEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $yearTotal = (float)$stmt->fetchColumn();

    // ── Sales by Store ───────────────────────────────────────────────────────
    // $isSuper was settled above, before the KPI cards, so the table below and
    // those cards cannot disagree about whose money the page shows.

    /**
     * One store's share of the takings over a window.
     *
     * Grouped on the sale's own store rather than on the cashier's, because a
     * Super Admin till rings up several shops in one basket and each of those bills
     * belongs to the shop whose shelf it came off. A window of null is all-time.
     *
     * @return array [sql, params]
     */
    function storeSalesWindowSql($store_id, $owner_id, $isSuper, $start = null, $end = null, $ownUserId = 0)
    {
        $scope = '';
        $params = [];

        if ($ownUserId) {
            // A counter account's per-store row counts its own bills only.
            $scope .= " AND s.user_id = ?";
            $params[] = $ownUserId;
        } elseif ($store_id) {
            $scope .= " AND " . saleStoreExpr() . " = ?";
            $params[] = $store_id;
        } elseif (!$isSuper) {
            // Inside one tenant's money. A top-level account that only *looks*
            // like the platform one is a shop's own keeper with an empty owner
            // column, so this branch is what keeps another shop's takings off
            // their page; a verified platform admin has no shop of their own and
            // is shown each shop's own figures.
            $scope .= " AND " . saleOwnerScopeSql($owner_id);
            $params[] = $owner_id;
            $params[] = $owner_id;
        }

        if ($start !== null) {
            $scope .= " AND s.created_at BETWEEN ? AND ?";
            $params[] = $start;
            $params[] = $end;
        }

        // The store list stays the caller's own: their till, or their shop's
        // shelves. Only a platform Super Admin, who has no shop of their own, is
        // shown every shop.
        if ($store_id) {
            $stFilter = " AND st.id = ?";
            $params[] = $store_id;
        } elseif (!$isSuper) {
            $stFilter = " AND st.owner_id = ?";
            $params[] = $owner_id;
        } else {
            $stFilter = '';
        }

        $sql = "SELECT st.id AS store_id, st.name AS store_name,
                       COALESCE(agg.sale_count, 0) AS sale_count,
                       COALESCE(agg.total, 0) AS total
                FROM stores st
                LEFT JOIN (
                    SELECT " . saleStoreExpr() . " AS sid,
                           COUNT(*) AS sale_count, COALESCE(SUM(s.total), 0) AS total
                    FROM sales s
                    JOIN users u ON s.user_id = u.id
                    WHERE 1=1" . $scope . "
                    GROUP BY sid
                ) agg ON agg.sid = st.id
                WHERE st.status = 'active'" . $stFilter . "
                ORDER BY total DESC";

        return [$sql, $params];
    }

    /**
     * Return and exchange money per store over a window, keyed by store id.
     *
     * `returns` has no store column, so a return is filed under the store of the
     * sale the goods came back from - the same store the refund cash was booked
     * against, and the only answer that is right when the bill spanned two shops.
     */
    function storeReturnsByWindow($owner_id, $store_id, $start, $end, $isSuper = false, $ownUserId = 0)
    {
        ensureReturnExchangeColumns();

        // Scoped the same way as the sales window above, and for the same reason:
        // an account that merely has an empty owner column is a shop's own keeper,
        // so the refund figures stay inside their tenant unless the shared test
        // says this is the platform account, which owns no shop at all.
        $params = [];
        $scope = '';
        if ($ownUserId) {
            // Money handed back on this person's own bills, so the net figure
            // beside Today's Sale is their sale minus their refunds.
            $scope = " AND s.user_id = ?";
            $params = [$ownUserId];
        } elseif (!$isSuper) {
            $scope = " AND " . saleOwnerScopeSql($owner_id);
            $params = [$owner_id, $owner_id];
        }
        if ($store_id) {
            $scope .= " AND " . saleStoreExpr() . " = ?";
            $params[] = $store_id;
        }
        $params[] = $start;
        $params[] = $end;

        try {
            $stmt = getDB()->prepare("SELECT " . saleStoreExpr() . " AS sid,
                       COALESCE(SUM(r.refund_amount), 0) AS refund,
                       COALESCE(SUM(r.extra_amount_received), 0) AS extra,
                       COALESCE(SUM(CASE WHEN r.transaction_type = 'exchange' THEN r.refund_amount ELSE 0 END), 0) AS exchange_refund
                     FROM returns r
                     JOIN sales s ON r.sale_id = s.id
                     JOIN users u ON s.user_id = u.id
                     WHERE 1=1" . $scope . " AND r.created_at BETWEEN ? AND ?
                     GROUP BY sid");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            // No exchange columns yet means no exchange money to show, which is the
            // same answer as an empty one.
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $extra = round((float)$row['extra'], 2);
            $out[(int)$row['sid']] = [
                'refund'     => round((float)$row['refund'], 2),
                'extra'      => $extra,
                'exchange_refund' => round((float)$row['exchange_refund'], 2),
                'net_effect' => round($extra - (float)$row['exchange_refund'], 2),
            ];
        }
        return $out;
    }

    list($sql, $params) = storeSalesWindowSql($store_id, $owner_id, $isSuper, $todayStart, $todayEnd, $ownUserId);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSales = $stmt->fetchAll();

    list($sql, $params) = storeSalesWindowSql($store_id, $owner_id, $isSuper, $monthStart, $monthEnd, $ownUserId);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSalesMonth = $stmt->fetchAll();

    list($sql, $params) = storeSalesWindowSql($store_id, $owner_id, $isSuper, null, null, $ownUserId);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $storeSalesAll = $stmt->fetchAll();

    $storeMoney = storeReturnsByWindow($owner_id, $store_id, $todayStart, $todayEnd, $isSuper, $ownUserId);

    // ── Total Products ───────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM products p JOIN store_stocks ss ON p.id = ss.product_id JOIN stores st ON ss.store_id = st.id WHERE st.owner_id = ? AND p.status = 'active'");
    $stmt->execute([$owner_id]);
    $totalProducts = $stmt->fetch()['count'];

    // ── Total Customers ──────────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM customers WHERE owner_id = ?");
    $stmt->execute([$owner_id]);
    $totalCustomers = $stmt->fetch()['count'];

    // ── Total Staff & Salary Due ─────────────────────────────────────────────
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM staff WHERE owner_id = ? AND status = 'active'");
    $stmt->execute([$owner_id]);
    $totalStaff = $stmt->fetch()['count'];

    // ── Low Stock Products ───────────────────────────────────────────────────
    if ($store_id) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM store_stocks ss JOIN products p ON ss.product_id = p.id WHERE ss.store_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active'");
        $stmt->execute([$store_id]);
        $lowStockCount = $stmt->fetch()['count'];
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM store_stocks ss JOIN products p ON ss.product_id = p.id JOIN stores st ON ss.store_id = st.id WHERE st.owner_id = ? AND ss.quantity <= p.min_stock AND p.status = 'active'");
        $stmt->execute([$owner_id]);
        $lowStockCount = $stmt->fetch()['count'];
    }

    // ── Top Selling Products (This Month) ────────────────────────────────────
    $sql = "SELECT p.name, p.image, SUM(si.quantity) as total_qty, SUM(si.total_price) as total_revenue
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        JOIN sales s ON si.sale_id = s.id";
    $params = [];
    if ($ownUserId) {
        // A counter account ranks the products it sold itself.
        $sql .= " JOIN users u ON s.user_id = u.id WHERE s.user_id = ? AND s.created_at BETWEEN ? AND ?";
        $params[] = $ownUserId;
    } elseif ($store_id) {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE " . saleStoreExpr() . " = ? AND s.created_at BETWEEN ? AND ?";
        $params[] = $store_id;
    } else {
        $sql .= " JOIN users u ON s.user_id = u.id WHERE " . saleOwnerScopeSql($owner_id) . " AND s.created_at BETWEEN ? AND ?";
        $params[] = $owner_id;
        $params[] = $owner_id;
    }
    $params[] = $monthStart; $params[] = $monthEnd;
    $sql .= " GROUP BY p.id ORDER BY total_qty DESC LIMIT 6";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $topProducts = $stmt->fetchAll();

    // ── Payment method breakdown (This Month) ────────────────────────────────
    list($sql, $params) = scopeSql("SELECT s.payment_method, COUNT(s.id) as count, COALESCE(SUM(s.total), 0) as total FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $sql .= " AND s.created_at BETWEEN ? AND ? GROUP BY s.payment_method ORDER BY total DESC";
    $params[] = $monthStart; $params[] = $monthEnd;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $paymentStats = $stmt->fetchAll();

    // ── Daily sales, for the Sales — Last 30 Days bars ───────────────────────
    $chartDays = 30;
    list($sql, $params) = scopeSql("SELECT DATE(s.created_at) as sale_date, COALESCE(SUM(s.total), 0) as total, COUNT(*) as count FROM sales s", $store_id, $owner_id, $isSuper, $ownUserId);
    $sql .= " AND s.created_at >= ? GROUP BY DATE(s.created_at) ORDER BY sale_date";
    $params[] = date('Y-m-d 00:00:00', strtotime('-' . ($chartDays - 1) . ' days'));
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $chartRows = $stmt->fetchAll();
    $chartData = [];
    for ($i = $chartDays - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $chartData[$d] = ['total' => 0, 'count' => 0];
    }
    foreach ($chartRows as $row) {
        if (isset($chartData[$row['sale_date']])) {
            $chartData[$row['sale_date']] = ['total' => (float)$row['total'], 'count' => (int)$row['count']];
        }
    }

    // ── Last 7 days ──────────────────────────────────────────────────────────
    $weekStart = date('Y-m-d 00:00:00', strtotime('-6 days'));

    // There is no SMS credit to show - sending is unmetered now - so the card
    // counts what actually left the shop, straight out of the same log the SMS
    // Service page reads.
    smsUsageEnsureTables($db);
    $smsUsage = smsUsageStats($db, $owner_id);

    // ── Online shop ──────────────────────────────────────────────────────────
    // An order that was rejected or cancelled never became money, so the card
    // counts what the shop actually took. ecom_orders is created on first use by
    // config/ecommerce.php, so an install that never opened the Online Shop page
    // has no table at all - hence the guard, and the card simply not appearing.
    $ecomWeekTotal = null;
    if (hasPermission('ecommerce')) {
        try {
            $stmt = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM ecom_orders
                WHERE owner_id = ? AND status NOT IN ('cancelled', 'rejected')
                AND created_at BETWEEN ? AND ?");
            $stmt->execute([$owner_id, $weekStart, $todayEnd]);
            $ecomWeekTotal = (float)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $ecomWeekTotal = null;
        }
    }

    // ── Cashbook summary ─────────────────────────────────────────────────────
    // A counter account reads its own movements only, like every other figure on
    // this page: another staff member's expenses are not theirs to see.
    $cashTotalsSql = "SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out
        FROM cashbook_entries ce WHERE ce.owner_id = ?";
    $cashTotalsParams = [$owner_id];
    if ($ownUserId) {
        $cashTotalsSql .= " AND ce.user_id = ?";
        $cashTotalsParams[] = (int)$ownUserId;
    }
    $stmt = $db->prepare($cashTotalsSql);
    $stmt->execute($cashTotalsParams);
    $cashTotals = $stmt->fetch();

    // Today's cash out, for the Expenses card. A counter account (staff/cashier)
    // reads its own movements only, like every other card this page shows it.
    $cashOwn = '';
    $cashTodayParams = [$owner_id, $todayStart, $todayEnd];
    if ($ownUserId) {
        $cashOwn = " AND ce.user_id = ?";
        array_splice($cashTodayParams, 1, 0, [(int)$ownUserId]);
    }
    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out
        FROM cashbook_entries ce WHERE ce.owner_id = ?" . $cashOwn . " AND ce.created_at BETWEEN ? AND ?");
    $stmt->execute($cashTodayParams);
    $cashToday = $stmt->fetch();

    // Today's expenses, for the Net Balance card. Two kinds of cash_out line are
    // not a cost of the day and stay out of that sum:
    //   * source_type = 'return' - a refund is already off the sale it came from,
    //     inside today's net sale, so subtracting it here takes the money twice;
    //   * source_type = 'transfer' - moving cash between the shop's own books is
    //     not spending, and its matching cash_in is not on this side either.
    // An install without the source_type column has held neither kind of line, so
    // it reads cash_out as it always did.
    $expenseSql = "SELECT COALESCE(SUM(ce.amount), 0)
        FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.type = 'cash_out'";
    $expenseParams = [$owner_id];
    if ($ownUserId) {
        $expenseSql .= " AND ce.user_id = ?";
        $expenseParams[] = (int)$ownUserId;
    }
    if (cashbookHasSourceType()) {
        $expenseSql .= " AND (ce.source_type IS NULL OR ce.source_type NOT IN ('return', 'transfer'))";
    }
    $expenseSql .= " AND ce.created_at BETWEEN ? AND ?";
    $expenseParams[] = $todayStart;
    $expenseParams[] = $todayEnd;
    $stmt = $db->prepare($expenseSql);
    $stmt->execute($expenseParams);
    $expenseToday = (float)$stmt->fetchColumn();

    // ── Returns and exchanges ────────────────────────────────────────────────
    // An exchange settles the difference between what the customer handed back and
    // what they took. Collected is money in, refunded is money out, and both are
    // stored on the return row itself rather than inferred from the product names.
    $today = date('Y-m-d');
    $returnsToday = returnCashSummary($owner_id, $today, $today, $store_id, $ownUserId ?: null);

    // Net Balance = today's net sale minus today's expenses. The sale side folds
    // returns the way the rest of the page does: a plain refund is already off the
    // sale it came from, and an exchange adds the extra it collected while taking
    // back what it refunded. Nothing is counted twice on either side.
    $netToday = round((float)$todaySales['total'] + (float)$returnsToday['net_effect'] - $expenseToday, 2);

    // ── Recent cashbook entries ──────────────────────────────────────────────
    $recentCashSql = "SELECT ce.*, u.name as user_name FROM cashbook_entries ce LEFT JOIN users u ON ce.user_id = u.id WHERE ce.owner_id = ?";
    $recentCashParams = [$owner_id];
    if ($ownUserId) {
        $recentCashSql .= " AND ce.user_id = ?";
        $recentCashParams[] = (int)$ownUserId;
    }
    $recentCashSql .= " ORDER BY ce.created_at DESC, ce.id DESC LIMIT 6";
    $stmt = $db->prepare($recentCashSql);
    $stmt->execute($recentCashParams);
    $recentCash = $stmt->fetchAll();

    // ── Staff salary overview ────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT s.name, s.salary, s.salary_type,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month,
        COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = s.id), 0) as total_paid
        FROM staff s WHERE s.owner_id = ? AND s.status = 'active' ORDER BY s.created_at DESC LIMIT 5");
    $stmt->execute([$monthStart, $monthEnd, $owner_id]);
    $staffOverview = $stmt->fetchAll();

    // ── Salary balance (total due for the period across all active staff) ────
    if ($isSuper) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(salary), 0) FROM staff WHERE status = 'active'");
        $stmt->execute();
        $salaryTotals = ['total_salary' => (float)$stmt->fetchColumn(), 'paid_period' => 0];

        $stmt = $db->prepare("SELECT COALESCE(SUM(sp.amount), 0)
            FROM staff_payments sp JOIN staff s ON sp.staff_id = s.id
            WHERE sp.payment_date BETWEEN ? AND ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $salaryTotals['paid_period'] = (float)$stmt->fetchColumn();
    } else {
        $stmt = $db->prepare("SELECT COALESCE(SUM(salary), 0) as total_salary
            FROM staff WHERE owner_id = ? AND status = 'active'");
        $stmt->execute([$owner_id]);
        $salaryTotals = ['total_salary' => (float)$stmt->fetchColumn(), 'paid_period' => 0];

        $stmt = $db->prepare("SELECT COALESCE(SUM(sp.amount), 0)
            FROM staff_payments sp JOIN staff s ON sp.staff_id = s.id
            WHERE s.owner_id = ? AND sp.payment_date BETWEEN ? AND ?");
        $stmt->execute([$owner_id, $monthStart, $monthEnd]);
        $salaryTotals['paid_period'] = (float)$stmt->fetchColumn();
    }
    $salaryBalance = max(0, $salaryTotals['total_salary'] - $salaryTotals['paid_period']);

    // What the drawer holds, straight from the ledger: every movement that touches
    // cash is a line here - a sale's collection, an exchange extra, an expense, a
    // refund - so cash in minus cash out is the whole story with nothing doubled.
    //
    // It used to be "Sales - Expenses + exchange collected", which counted a refund
    // twice: the returned amount comes off the sale it came from AND stands as a
    // cash_out row. Same reader the refund guard uses, so the card and the guard
    // agree on what the till can pay out.
    //
    // A counter account gets the same arithmetic over its own ledger lines only.
    // cashLedgerState() answers for the whole shop, and the drawer's total is
    // not the counter person's figure to read.
    if ($ownUserId) {
        $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE -amount END), 0)
            FROM cashbook_entries WHERE owner_id = ? AND user_id = ?");
        $stmt->execute([$owner_id, (int)$ownUserId]);
        $netCash = (float)$stmt->fetchColumn();
    } else {
        $netCash = cashLedgerState($owner_id)['balance'];
    }

} catch (PDOException $e) {
    $error = 'Error loading dashboard data: ' . $e->getMessage();
}

// The bar panel below reads $chartData, which is only filled once the queries
// have run; a PDOException part-way through leaves it unset.
$chartData = $chartData ?? [];

// ── Sales — Last 30 Days (bars) ──────────────────────────────────────────────
// A row of bars answers "which days were good and which were flat", and it only
// reads as a row with a full month of them. It is drawn from the $chartData the
// daily-sales query already loaded, so the panel costs no extra query.
$barDays = array_slice($chartData, -30, null, true);
$barMax = $barDays ? (float)max(array_column($barDays, 'total')) : 0;
if ($barMax <= 0) { $barMax = 1; }
$barTotal = array_sum(array_column($barDays, 'total'));
$barToday = date('Y-m-d');

/**
 * A money amount short enough to sit above a 20px bar: 15600 -> "15.6k".
 * Only this chart needs it, which is why it lives here and not in db.php.
 */
function compactMoney($v)
{
    $v = (float)$v;
    if ($v >= 1000000) { return number_format($v / 1000000, 1) . 'm'; }
    if ($v >= 1000) { return number_format($v / 1000, 1) . 'k'; }
    return number_format($v);
}

// The hero names the shop rather than saying "single store view" twice over.
$storeName = '';
if ($store_id) {
    $stmt = $db->prepare("SELECT name FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    $storeName = (string)$stmt->fetchColumn();
}

$todayTotal = (float)$todaySales['total'];

/**
 * One KPI card: icon, label, amount.
 *
 * 'value' arrives already formatted by the caller, and 'style' is the page's own
 * colour coding (Expenses red, Salary violet), never user data.
 *
 * 'href' turns the card into a button that opens that page. It is a real <a>
 * rather than a click handler so a tap, a keyboard and the app's WebView all
 * behave the same.
 */
function kpiCard($c)
{
    $link = !empty($c['href']);
    $tag = $link ? 'a' : 'div';
    $html = '<' . $tag . ' class="kpi-card"' . ($link ? ' href="' . $c['href'] . '"' : '') . '>';
    $html .= '<span class="kpi-ico ' . $c['tone'] . '"><i class="fas ' . $c['icon'] . '"></i></span>';
    $html .= '<div class="kpi-label">' . $c['label'] . '</div>';
    $html .= '<div class="kpi-value"'
        . (empty($c['style']) ? '' : ' style="' . $c['style'] . '"') . '>' . $c['value'] . '</div>';
    $html .= '</' . $tag . '>';
    return $html;
}

// Card defaults, so a card still renders if the data block above bailed out.
$monthlySales = $monthlySales ?? ['total' => 0];
$yearTotal = $yearTotal ?? 0;

// Payment method colours, shared by the donut and the transaction list.
$payColors = [
    'cash'   => ['#10b981', '#ecfdf5'],
    'bkash'  => ['#ec4899', '#fdf2f8'],
    'nagad'  => ['#f59e0b', '#fffbeb'],
    'rocket' => ['#8b5cf6', '#f5f3ff'],
    'card'   => ['#3b82f6', '#eff6ff'],
    'bank'   => ['#0ea5e9', '#ecfeff'],
    'lottery' => ['#ca8a04', '#fefce8'],
];

include 'includes/header.php';
?>

<style>
    /* ── Hero ─────────────────────────────────────────────────────────────── */
    .dash-hero {
        position: relative;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        border-radius: 20px;
        padding: 1.7rem 2rem;
        margin-bottom: 1.5rem;
        background: linear-gradient(105deg, #0ea5e9 0%, #2563eb 38%, #4338ca 72%, #6d28d9 100%);
        color: #fff;
        overflow: hidden;
        box-shadow: 0 16px 34px rgba(37, 99, 235, .26);
    }
    .dash-hero::before, .dash-hero::after {
        content: ''; position: absolute; border-radius: 50%; pointer-events: none;
        background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.22), rgba(255,255,255,0) 70%);
    }
    .dash-hero::before { width: 320px; height: 320px; right: 26%; top: -180px; }
    .dash-hero::after { width: 280px; height: 280px; right: -70px; bottom: -160px; }
    .dash-hero-copy { flex: 1 1 300px; min-width: 0; position: relative; z-index: 1; }
    .hero-chip {
        display: inline-flex; align-items: center; gap: .4rem;
        background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.30);
        color: #fff; border-radius: 999px; padding: .28rem .85rem;
        font-size: .76rem; font-weight: 700;
    }
    .dash-hero-title { margin-top: .65rem; font-size: 1.85rem; font-weight: 800; color: #fff; line-height: 1.15; }
    .dash-hero-sub { font-size: .88rem; color: rgba(255,255,255,.84); margin-top: .35rem; }
    .hero-meta { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
    .hm {
        display: inline-flex; align-items: center; gap: .4rem;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22);
        border-radius: 999px; padding: .26rem .7rem;
        font-size: .74rem; font-weight: 600; color: #fff;
    }
    .hm i { color: #dbeafe; }
    .hm b { font-weight: 800; }
    .dash-hero-quote {
        font-style: italic; font-weight: 700; color: #fff; font-size: 1.02rem;
        max-width: 200px; line-height: 1.45; text-align: right;
        position: relative; z-index: 1; margin-left: auto;
    }
    .dash-hero-quote span { display: block; font-style: normal; font-weight: 600; color: rgba(255,255,255,.66); font-size: .74rem; margin-top: .35rem; }
    .dash-hero-art { width: 168px; flex-shrink: 0; position: relative; z-index: 1; }
    html[data-theme="dark"] .dash-hero { box-shadow: 0 16px 34px rgba(0, 0, 0, .45); }
    @media screen and (max-width: 768px) { .dash-hero { display: none; } }

    /* ── KPI cards ────────────────────────────────────────────────────────── */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: .8rem;
        margin-bottom: 1.25rem;
    }
    .kpi-card {
        position: relative;
        display: flex; flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--gray-200);
        border-radius: 14px;
        padding: .8rem .9rem;
        overflow: hidden;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(15, 23, 42, .1); }
    /* A card with a destination is an <a>: it must not pick up the global link
       colour or underline, and the chevron is what tells it apart from a card
       that only displays a number. */
    a.kpi-card { color: inherit; text-decoration: none; }
    a.kpi-card::after {
        content: '\203A';
        position: absolute; top: .45rem; right: .6rem;
        font-size: 1.05rem; font-weight: 800; line-height: 1;
        color: var(--gray-300);
    }
    a.kpi-card:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; }
    .kpi-label { font-size: .72rem; font-weight: 700; color: var(--gray-500); margin-top: .5rem; }
    .kpi-value { font-size: 1.22rem; font-weight: 800; color: var(--gray-900); line-height: 1.2; margin-top: .05rem; }
    .kpi-ico {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: .88rem; color: #fff;
    }
    .kpi-ico.blue   { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
    .kpi-ico.green  { background: linear-gradient(135deg, #10b981, #047857); }
    .kpi-ico.violet { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
    .kpi-ico.amber  { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .kpi-ico.rose   { background: linear-gradient(135deg, #ec4899, #be185d); }
    .kpi-ico.cyan   { background: linear-gradient(135deg, #06b6d4, #0e7490); }
    .kpi-ico.sky    { background: linear-gradient(135deg, #0ea5e9, #0369a1); }
    .kpi-ico.indigo { background: linear-gradient(135deg, #6366f1, #4338ca); }
    .kpi-ico.teal   { background: linear-gradient(135deg, #14b8a6, #0d9488); }
    .kpi-ico.gold   { background: linear-gradient(135deg, #eab308, #b45309); }
    .kpi-ico.red    { background: linear-gradient(135deg, #ef4444, #b91c1c); }

    /* ── Layout rows ──────────────────────────────────────────────────────── */
    .grid-2col { display: grid; grid-template-columns: 1.6fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem; }
    .grid-2col-eq { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem; }
    /* For a row whose panels are permission-gated: auto-fit collapses the empty
       track, so the one panel that rendered takes the full width. */
    .grid-adaptive { grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); }
    @media (max-width: 900px) {
        .grid-2col, .grid-2col-eq { grid-template-columns: 1fr; }
        .dash-hero-art { display: none; }
        .dash-hero-quote { margin-left: 0; text-align: left; }
    }
    .stack { display: flex; flex-direction: column; gap: 1.25rem; }
    .stack > .panel { margin-bottom: 0; }

    .panel {
        background: var(--surface);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        border: 1px solid var(--gray-200);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .panel-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: .75rem; flex-wrap: wrap;
        padding: 1rem 1.35rem;
        border-bottom: 1px solid var(--gray-100);
    }
    .panel-head h3 { margin: 0; font-size: .98rem; font-weight: 800; color: var(--gray-900); }
    .panel-body { padding: 1.35rem; }
    .panel-body.no-pad { padding: 0; }
    .panel-link { font-size: .78rem; font-weight: 700; color: #2563eb; text-decoration: none; }
    .panel-link:hover { text-decoration: underline; }

    /* ── Clickable panels ─────────────────────────────────────────────────── */
    /* The whole card opens the page its header link points at: that link carries
       a stretched ::after over the panel, so it works with a mouse, a tap and
       the keyboard, and needs no JS. Links in the body are raised above the
       overlay so they keep their own target - "Create a store" must not become
       a second route to stores.php's list. */
    .panel-click {
        position: relative; cursor: pointer;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .panel-click:hover, .panel-click:focus-within {
        transform: translateY(-2px);
        box-shadow: 0 14px 30px rgba(15, 23, 42, .1);
        border-color: #93c5fd;
    }
    .panel-click .panel-link::after {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    }
    .panel-click .panel-link:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; border-radius: 6px; }
    .panel-click .panel-body a { position: relative; z-index: 1; }
    html[data-theme="dark"] .panel-click:hover, html[data-theme="dark"] .panel-click:focus-within { border-color: #2b457a; }
    .ph-ico {
        width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center; font-size: .9rem;
    }
    .panel-head h3 { display: flex; align-items: center; gap: .6rem; }
    .panel-head h3 i { margin: 0; }

    /* ── Daily bars (Sales — Last 30 Days) ────────────────────────────────── */
    /* HTML flex rather than SVG: thirty bars have to survive being a third of
       the width they were designed at, and a viewBox stretched with
       preserveAspectRatio="none" would squash the value labels. The scale tops
       out at 84% of the track so the tallest bar still leaves room for its own
       number above it. */
    .bars { display: flex; align-items: flex-end; gap: 5px; }
    .bar-col { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; }
    .bar-track { height: 160px; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; }
    .bar-val { font-size: .62rem; font-weight: 800; color: var(--gray-600); white-space: nowrap; margin-bottom: 4px; }
    .bar-val:empty { display: none; }
    .bar-fill { width: 100%; max-width: 26px; min-width: 5px; border-radius: 8px 8px 3px 3px; background: linear-gradient(180deg, #6366f1, #3b82f6); }
    /* Today is a partial day. Drawing it in the same colour as a finished one
       invites the question "why did sales collapse at 4pm". */
    .bar-col.today .bar-fill { background: linear-gradient(180deg, #34d399, #059669); }
    .bar-col.today .bar-val { color: #059669; }
    .bar-day { margin-top: .45rem; text-align: center; font-size: .62rem; font-weight: 700; color: var(--gray-400); }
    .bar-empty { padding: 1rem .2rem; text-align: center; font-size: .8rem; font-weight: 700; color: var(--gray-400); }
    @media screen and (max-width: 768px) {
        .bars { gap: 2px; }
        .bar-track { height: 120px; }
        /* Thirty numbers cannot be read across a phone screen; the bars carry
           the shape and every fifth day carries the scale. */
        .bar-val { display: none; }
        .bar-col:not(:nth-child(5n)) .bar-day { visibility: hidden; }
        .bar-fill { border-radius: 4px 4px 2px 2px; }
    }

    /* ── Donut ────────────────────────────────────────────────────────────── */
    .donut-wrap { display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap; }
    .donut { width: 150px; height: 150px; flex-shrink: 0; }
    .donut circle { fill: none; stroke-width: 18; }
    .donut-center-value { font-size: 15px; font-weight: 800; fill: var(--gray-900); }
    .donut-center-label { font-size: 8px; fill: var(--gray-400); letter-spacing: .04em; }
    .pay-legend { flex: 1 1 150px; min-width: 0; }
    .pay-legend-row { display: flex; align-items: center; gap: .55rem; padding: .38rem 0; font-size: .8rem; }
    .pay-legend-row .swatch { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
    .pay-legend-row .pl-name { font-weight: 700; color: var(--gray-700); text-transform: capitalize; }
    .pay-legend-row .pl-amt { margin-left: auto; font-weight: 700; color: var(--gray-900); white-space: nowrap; }
    .pay-legend-row .pl-pct { width: 42px; text-align: right; color: var(--gray-400); font-size: .74rem; font-weight: 700; }

    /* ── Page footer band ─────────────────────────────────────────────────── */
    .page-foot {
        display: flex; align-items: center; justify-content: space-between;
        gap: .75rem; flex-wrap: wrap;
        padding: 1rem .25rem 0; margin-top: .25rem;
        border-top: 1px solid var(--gray-200);
        font-size: .76rem; color: var(--gray-500);
    }
    .page-foot .pf-brand { font-weight: 800; color: var(--gray-800); }
    .page-foot .pf-sep { margin: 0 .5rem; color: var(--gray-300); }

    /* ── Shared bits kept by the store / cashbook / staff panels ──────────── */
    .pay-bar-track { height: 8px; border-radius: 999px; background: var(--gray-100); overflow: hidden; margin-top: 4px; }
    .pay-bar-fill { height: 100%; border-radius: 999px; }
    .list-row {
        display: flex; justify-content: space-between; align-items: center;
        gap: .75rem; padding: .6rem 0; border-bottom: 1px solid var(--gray-100);
    }
    .list-row:last-child { border-bottom: none; }
    .lr-main { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .list-row .lr-amt { font-weight: 700; white-space: nowrap; }
    .mini-num { font-size: .74rem; color: var(--gray-400); }
    .badge-pay { font-size: .72rem; padding: .25rem .6rem; border-radius: 999px; font-weight: 700; }
    .cols-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: .7rem; }
    @media (max-width: 520px) { .cols-3 { grid-template-columns: 1fr; } }
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

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo $error; ?></span>
    </div>
<?php endif; ?>

<!-- Hero -->
<?php
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = sanitize(explode(' ', trim($user['name']))[0]);
?>
<div class="dash-hero">
    <div class="dash-hero-copy">
        <span class="hero-chip">Welcome Back, <?php echo $firstName; ?> <i class="fas fa-hand-paper"></i></span>
        <div class="dash-hero-title"><?php echo ucfirst($greeting); ?>, <?php echo $firstName; ?>!</div>
        <div class="dash-hero-sub">Here's what's happening with your business today.</div>
        <div class="hero-meta">
            <span class="hm">
                <i class="fas fa-store"></i>
                <?php echo $store_id ? ($storeName !== '' ? sanitize($storeName) : 'Your store') : 'All of your stores'; ?>
            </span>
            <span class="hm"><i class="fas fa-calendar-day"></i> <?php echo date('l, d M Y'); ?></span>
            <span class="hm">
                <i class="fas fa-cash-register"></i>
                <b><?php echo formatCurrency($todayTotal); ?></b> &middot; <?php echo (int)$todaySales['count']; ?> sales today
            </span>
        </div>
    </div>
    <svg class="dash-hero-art" viewBox="0 0 190 150" fill="none" aria-hidden="true">
        <!-- monitor -->
        <rect x="10" y="18" width="112" height="72" rx="9" fill="#ffffff" fill-opacity=".95"/>
        <rect x="18" y="26" width="96" height="56" rx="5" fill="#1e3a8a"/>
        <rect x="24" y="32" width="38" height="6" rx="3" fill="#60a5fa"/>
        <rect x="24" y="44" width="84" height="5" rx="2.5" fill="#3b82f6" fill-opacity=".55"/>
        <rect x="24" y="53" width="66" height="5" rx="2.5" fill="#3b82f6" fill-opacity=".35"/>
        <rect x="24" y="64" width="34" height="12" rx="6" fill="#34d399"/>
        <rect x="64" y="64" width="44" height="12" rx="6" fill="#2563eb"/>
        <rect x="54" y="90" width="26" height="12" rx="3" fill="#dbeafe"/>
        <rect x="36" y="102" width="62" height="8" rx="4" fill="#bfdbfe"/>
        <!-- receipt printer -->
        <rect x="96" y="70" width="46" height="42" rx="8" fill="#0f172a" fill-opacity=".55"/>
        <rect x="106" y="58" width="26" height="20" rx="3" fill="#f8fafc"/>
        <rect x="110" y="63" width="18" height="3" rx="1.5" fill="#94a3b8"/>
        <rect x="110" y="69" width="12" height="3" rx="1.5" fill="#cbd5e1"/>
        <circle cx="119" cy="99" r="6" fill="#34d399"/>
        <!-- potted plant -->
        <path d="M156 96c0-16 10-26 22-28-2 16-9 26-22 28z" fill="#34d399"/>
        <path d="M156 96c-2-14-10-21-22-22 3 14 10 21 22 22z" fill="#10b981"/>
        <path d="M156 96V70" stroke="#059669" stroke-width="3" stroke-linecap="round"/>
        <path d="M144 96h24l-3 26a5 5 0 0 1-5 5h-8a5 5 0 0 1-5-5z" fill="#e2e8f0"/>
        <path d="M144 96h24l-1.4 12h-21.2z" fill="#cbd5e1"/>
        <circle cx="20" cy="12" r="4" fill="#bfdbfe" fill-opacity=".8"/>
        <circle cx="176" cy="24" r="6" fill="#a5b4fc" fill-opacity=".7"/>
        <circle cx="8" cy="118" r="5" fill="#93c5fd" fill-opacity=".55"/>
    </svg>
    <div class="dash-hero-quote">
        &ldquo;Better Tools for a Smarter Business&rdquo;
        <span>&mdash; AVA IT Solution</span>
    </div>
</div>

<!-- Sales by Store + Daily report -->
<?php
$grandTotal = array_sum(array_column($storeSales, 'total'));
$grandCount = array_sum(array_column($storeSales, 'sale_count'));
$storeMonthTotal = array_sum(array_column($storeSalesMonth, 'total'));
$storeAllTotal = array_sum(array_column($storeSalesAll, 'total'));
$allStoreNames = [];
foreach (array_merge($storeSales, $storeSalesMonth, $storeSalesAll) as $ss) {
    $allStoreNames[$ss['store_id']] = $ss['store_name'];
}
$avatarColors = [
    'linear-gradient(135deg, #3b82f6, #1d4ed8)',
    'linear-gradient(135deg, #10b981, #047857)',
    'linear-gradient(135deg, #f59e0b, #d97706)',
    'linear-gradient(135deg, #ef4444, #b91c1c)',
    'linear-gradient(135deg, #8b5cf6, #6d28d9)',
    'linear-gradient(135deg, #06b6d4, #0e7490)',
    'linear-gradient(135deg, #ec4899, #be185d)',
    'linear-gradient(135deg, #64748b, #334155)'
];

// Merge today/all and this store's return money into a single row.
// Net follows the rule the sales report uses: an exchange's extra is money in and
// its difference payout is money out, while a plain return's refund is already off
// the sale total.
function storeRow($id, $name, $today, $all, $money) {
    $t = null; foreach ($today as $r) { if ($r['store_id'] == $id) $t = $r; }
    $a = null; foreach ($all as $r) { if ($r['store_id'] == $id) $a = $r; }
    $mm = $money[$id] ?? null;
    $todayTotal = $t ? (float)$t['total'] : 0;
    return [
        'store_id' => $id,
        'store_name' => $name,
        'today'  => $todayTotal,
        'today_count'  => $t ? (int)$t['sale_count'] : 0,
        'refund' => $mm ? (float)$mm['refund'] : 0,
        'exchange' => $mm ? (float)$mm['extra'] : 0,
        'net'    => round($todayTotal + ($mm ? (float)$mm['net_effect'] : 0), 2),
        'all'    => $a ? (float)$a['total'] : 0,
        'all_count'    => $a ? (int)$a['sale_count'] : 0,
    ];
}
$storeRows = [];
foreach ($allStoreNames as $sid => $sname) {
    $storeRows[] = storeRow($sid, $sname, $storeSales, $storeSalesAll, $storeMoney);
}
usort($storeRows, function ($x, $y) { return $y['all'] <=> $x['all']; });
?>
<?php if (hasPermission('stores')): ?>
<div class="panel panel-click" style="margin-bottom:1.75rem;">
    <div class="panel-head">
        <h3><span class="ph-ico" style="background:#fff4e0; color:#d97706;"><i class="fas fa-store"></i></span>Store-Wise Sales</h3>
        <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
            <span class="badge badge-warning">Today: <?php echo formatCurrency($grandTotal); ?></span>
            <span class="badge badge-primary">This Month: <?php echo formatCurrency($storeMonthTotal); ?></span>
            <span class="badge badge-success">All-Time: <?php echo formatCurrency($storeAllTotal); ?></span>
            <a href="stores.php" class="panel-link">View All</a>
        </div>
    </div>
    <div class="panel-body no-pad">
        <?php if (empty($storeRows)): ?>
            <p class="text-muted text-center" style="padding:1.5rem;">
                No stores yet. <a href="stores.php">Create a store</a> to start tracking store-wise sales.
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Store</th>
                            <th>Today</th>
                            <th>Refund</th>
                            <th>Exchange</th>
                            <th>Net Sales</th>
                            <th>All-Time</th>
                            <th>Share (All-Time)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storeRows as $i => $ss): ?>
                            <?php
                            $pct = $storeAllTotal > 0 ? round(($ss['all'] / $storeAllTotal) * 100, 1) : 0;
                            $color = $avatarColors[$i % count($avatarColors)];
                            ?>
                            <tr>
                                <td>
                                    <div class="lr-main">
                                        <span style="width:10px; height:10px; border-radius:50%; background:<?php echo $color; ?>; flex-shrink:0;"></span>
                                        <strong><?php echo sanitize($ss['store_name']); ?></strong>
                                        <?php if ($i === 0): ?><i class="fas fa-crown" style="color:#f59e0b;" title="Top Store"></i><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($ss['today']); ?></strong>
                                    <div class="mini-num"><?php echo $ss['today_count']; ?> txns</div>
                                </td>
                                <td>
                                    <strong style="color:#dc2626;"><?php echo formatCurrency($ss['refund']); ?></strong>
                                </td>
                                <td>
                                    <strong style="color:#0d9488;"><?php echo formatCurrency($ss['exchange']); ?></strong>
                                </td>
                                <td>
                                    <strong style="color:#16a34a;"><?php echo formatCurrency($ss['net']); ?></strong>
                                </td>
                                <td>
                                    <strong><?php echo formatCurrency($ss['all']); ?></strong>
                                    <div class="mini-num"><?php echo $ss['all_count']; ?> txns</div>
                                </td>
                                <td style="width:160px;">
                                    <div class="pay-bar-track">
                                        <div class="pay-bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>;"></div>
                                    </div>
                                    <div class="mini-num" style="margin-top:3px;"><?php echo $pct; ?>% of total</div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<!-- KPI cards -->
<?php
// bKash collection for the month, read out of the same payment breakdown the
// Payment Method donut uses, so the two can never disagree.
$bkashMonth = 0.0;
foreach ($paymentStats ?? [] as $pstat) {
    if ($pstat['payment_method'] === 'bkash') { $bkashMonth += (float)$pstat['total']; }
}
?>
<div class="kpi-grid">
    <?php
    // Each card is emitted only when this account has been granted it.
    //
    // Owner, admin and manager pass every hasKpiPermission() check, so their
    // dashboard is exactly as before. A staff/cashier account gets only the
    // boxes an admin ticked for it on the Users page - the unticked ones are
    // never built and their figures never reach the HTML. The grid is
    // auto-fit, so what is left reflows with no empty slots.
    $kpis = [];

    if (hasKpiPermission('kpi_today_sale')) {
        $kpis[] = ['tone' => 'teal', 'icon' => 'fa-money-check-alt', 'label' => "Today's Sale",
            'value' => formatCurrency($todayTotal)];
    }
    // Kept apart rather than netted into one figure: 150 collected on an exchange and
    // 150 handed back are different events for the shop, even where they meet.
    if (hasKpiPermission('kpi_exchange')) {
        $kpis[] = ['tone' => 'cyan', 'icon' => 'fa-arrow-down', 'label' => 'Exchange Extra',
            'value' => formatCurrency($returnsToday['extra']), 'style' => 'color:#0d9488;'];
    }
    if (hasKpiPermission('kpi_refund')) {
        $kpis[] = ['tone' => 'red', 'icon' => 'fa-arrow-up', 'label' => 'Refund',
            'value' => formatCurrency($returnsToday['refund']), 'style' => 'color:#dc2626;'];
    }
    if (hasKpiPermission('kpi_monthly_sale')) {
        $kpis[] = ['tone' => 'indigo', 'icon' => 'fa-calendar-days', 'label' => 'This Month',
            'value' => formatCurrency((float)$monthlySales['total'])];
    }
    if (hasKpiPermission('kpi_yearly_sale')) {
        $kpis[] = ['tone' => 'sky', 'icon' => 'fa-chart-column', 'label' => 'This Year',
            'value' => formatCurrency($yearTotal)];
    }
    if (hasKpiPermission('kpi_bkash_month')) {
        $kpis[] = ['tone' => 'rose', 'icon' => 'fa-mobile-alt', 'label' => 'bKash (This Month)',
            'value' => formatCurrency($bkashMonth), 'style' => 'color:#ec4899;'];
    }
    if (hasKpiPermission('kpi_total_products')) {
        $kpis[] = ['tone' => 'green', 'icon' => 'fa-box-open', 'label' => 'Total Products',
            'value' => number_format((int)$totalProducts)];
    }
    // What the Top Selling panel used to spell out over six rows: the month's
    // best earner, and the card opens the report that ranks every product.
    // The link only becomes a link when the page behind it is allowed too,
    // so a granted card cannot bounce the person back with a refusal flash.
    if (hasKpiPermission('kpi_top_product')) {
        $kpis[] = ['tone' => 'amber', 'icon' => 'fa-trophy', 'label' => 'Top Product (Month)',
            'value' => formatCurrency((float)($topProducts[0]['total_revenue'] ?? 0)),
            'href' => hasPermission('reports') ? 'reports.php' : ''];
    }
    if (hasKpiPermission('kpi_total_customers')) {
        $kpis[] = ['tone' => 'violet', 'icon' => 'fa-users', 'label' => 'Total Customers',
            'value' => number_format((int)$totalCustomers)];
    }
    if (hasKpiPermission('kpi_total_invoices')) {
        $kpis[] = ['tone' => 'amber', 'icon' => 'fa-receipt', 'label' => 'Total Transactions',
            'value' => number_format((int)($allTimeSales['count'] ?? 0)),
            'href' => hasPermission('sales') ? 'sales.php' : ''];
    }
    if (hasKpiPermission('kpi_sms_sent')) {
        $kpis[] = ['tone' => 'rose', 'icon' => 'fa-comment-sms', 'label' => 'SMS Sent',
            'value' => number_format((int)$smsUsage['month'])];
    }

    if ($ecomWeekTotal !== null && hasKpiPermission('kpi_online_orders')) {
        $kpis[] = ['tone' => 'cyan', 'icon' => 'fa-basket-shopping', 'label' => 'Online Orders',
            'value' => formatCurrency($ecomWeekTotal)];
    }

    if (hasPermission('cashbook')) {
        if (hasKpiPermission('kpi_total_expense')) {
            $kpis[] = ['tone' => 'red', 'icon' => 'fa-arrow-up', 'label' => 'Expenses',
                'value' => formatCurrency($cashToday['cash_out']), 'style' => 'color:#dc2626;'];
        }
        // The day's net sale after every expense it paid for. A refund and a book
        // transfer are not expenses here - the refund is already inside the net
        // sale and the transfer only moves cash between the shop's own drawers -
        // so neither is taken off twice. Red when the day spent more than it sold.
        if (hasKpiPermission('kpi_net_balance')) {
            $kpis[] = ['tone' => $netToday >= 0 ? 'teal' : 'red', 'icon' => 'fa-scale-balanced', 'label' => 'Net Balance',
                'value' => formatCurrency($netToday), 'style' => $netToday < 0 ? 'color:#dc2626;' : ''];
        }
        if (hasKpiPermission('kpi_cash_balance')) {
            $kpis[] = ['tone' => $netCash >= 0 ? 'teal' : 'red', 'icon' => 'fa-hand-holding-usd', 'label' => 'Cash Balance',
                'value' => formatCurrency($netCash), 'style' => $netCash < 0 ? 'color:#dc2626;' : ''];
        }
    }

    if (hasKpiPermission('kpi_commission')) {
        $kpis[] = ['tone' => 'gold', 'icon' => 'fa-coins', 'label' => 'Commission',
            'value' => formatCurrency($commissionToday), 'style' => 'color:#b45309;'];
    }

    if (hasPermission('staff')) {
        if (hasKpiPermission('kpi_staff_members')) {
            $kpis[] = ['tone' => 'indigo', 'icon' => 'fa-user-tie', 'label' => 'Staff Members',
                'value' => (int)$totalStaff];
        }
        if (hasKpiPermission('kpi_salary_balance')) {
            $kpis[] = ['tone' => 'violet', 'icon' => 'fa-wallet', 'label' => 'Salary Balance',
                'value' => formatCurrency($salaryBalance), 'style' => 'color:#7c3aed;'];
        }
    }

    if (hasPermission('stock') && hasKpiPermission('kpi_low_stock')) {
        $kpis[] = ['tone' => 'rose', 'icon' => 'fa-exclamation-triangle', 'label' => 'Low Stock Alerts',
            'value' => (int)$lowStockCount, 'href' => 'stock.php'];
    }

    foreach ($kpis as $kpi) {
        echo kpiCard($kpi);
    }
    ?>
</div>

<?php if (!$kpis && in_array((string)$user['role'], kpiGatedRoles(), true)): ?>
<div class="bar-empty" style="margin-bottom:1.25rem;">
    <i class="fas fa-lock"></i>
    No dashboard cards have been enabled for your account. Ask the shop owner to tick them for you on the Users page.
</div>
<?php endif; ?>

<!-- Sales — Last 30 Days -->
<div class="panel">
    <div class="panel-head">
        <h3><span class="ph-ico" style="background:#eef2ff; color:#4f46e5;"><i class="fas fa-chart-column"></i></span>Sales &mdash; Last 30 Days</h3>
        <span class="badge badge-info"><?php echo formatCurrency($barTotal); ?></span>
    </div>
    <div class="panel-body">
        <?php if ($barTotal <= 0): ?>
            <div class="bar-empty">No sales in the last 30 days.</div>
        <?php else: ?>
            <div class="bars">
                <?php foreach ($barDays as $bd_date => $bd_row):
                    $v = (float)$bd_row['total'];
                    $pct = $v > 0 ? max(4, round($v / $barMax * 84)) : 0;
                ?>
                    <div class="bar-col <?php echo $bd_date === $barToday ? 'today' : ''; ?>"
                         title="<?php echo date('d M', strtotime($bd_date)) . ' · ' . formatCurrency($v) . ' · ' . (int)$bd_row['count'] . ' ' . ((int)$bd_row['count'] === 1 ? 'bill' : 'bills'); ?>">
                        <div class="bar-track">
                            <span class="bar-val"><?php echo $v > 0 ? compactMoney($v) : ''; ?></span>
                            <i class="bar-fill" style="height:calc(<?php echo $pct; ?>% + 3px);"></i>
                        </div>
                        <span class="bar-day"><?php echo (int)date('j', strtotime($bd_date)); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Method -->
<div class="grid-2col-eq grid-adaptive">

    <div class="panel">
        <div class="panel-head">
            <h3><span class="ph-ico" style="background:#e7f9f1; color:#059669;"><i class="fas fa-chart-pie"></i></span>Payment Method</h3>
            <span class="badge badge-success"><?php echo date('M Y'); ?></span>
        </div>
        <div class="panel-body">
            <?php
            $payTotal = array_sum(array_column($paymentStats, 'total'));
            if (empty($paymentStats) || $payTotal <= 0): ?>
                <p class="text-muted text-center">No sales this month yet.</p>
            <?php else: ?>
                <?php
                $R = 42; $CIRC = 2 * M_PI * $R; $acc = 0;
                $payIcon = function ($m) {
                    if ($m === 'cash') { return 'fa-money-bill-wave'; }
                    if (in_array($m, ['bkash', 'nagad', 'rocket'], true)) { return 'fa-mobile-alt'; }
                    return 'fa-credit-card';
                };
                ?>
                <div class="donut-wrap">
                    <svg class="donut" viewBox="0 0 100 100" role="img" aria-label="Payment method split">
                        <g transform="rotate(-90 50 50)">
                            <circle cx="50" cy="50" r="<?php echo $R; ?>" stroke="#eef2f7"></circle>
                            <?php foreach ($paymentStats as $ps): ?>
                                <?php
                                $pc = $payColors[$ps['payment_method']] ?? ['#64748b', '#f1f5f9'];
                                $share = (float)$ps['total'] / $payTotal;
                                $len = $share * $CIRC;
                                ?>
                                <circle cx="50" cy="50" r="<?php echo $R; ?>" stroke="<?php echo $pc[0]; ?>"
                                        stroke-dasharray="<?php echo round($len, 2); ?> <?php echo round($CIRC - $len, 2); ?>"
                                        stroke-dashoffset="<?php echo round(-$acc, 2); ?>"></circle>
                                <?php $acc += $len; ?>
                            <?php endforeach; ?>
                        </g>
                        <text class="donut-center-label" x="50" y="42" text-anchor="middle">TOTAL SALES</text>
                        <text class="donut-center-value" x="50" y="55" text-anchor="middle"><?php echo $payTotal >= 1000 ? number_format($payTotal / 1000, 1) . 'k' : number_format($payTotal); ?></text>
                        <text class="donut-center-label" x="50" y="65" text-anchor="middle"><?php echo date('M Y'); ?></text>
                    </svg>
                    <div class="pay-legend">
                        <?php foreach ($paymentStats as $ps): ?>
                            <?php
                            $pc = $payColors[$ps['payment_method']] ?? ['#64748b', '#f1f5f9'];
                            $pct = round((float)$ps['total'] / $payTotal * 100, 1);
                            ?>
                            <div class="pay-legend-row">
                                <span class="swatch" style="background:<?php echo $pc[0]; ?>;"></span>
                                <i class="fas <?php echo $payIcon($ps['payment_method']); ?>" style="color:<?php echo $pc[0]; ?>; font-size:.8rem;"></i>
                                <span class="pl-name"><?php echo $ps['payment_method']; ?></span>
                                <span class="pl-amt"><?php echo formatCurrency($ps['total']); ?></span>
                                <span class="pl-pct"><?php echo $pct; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Expense summary -->
<?php if (hasPermission('cashbook')): ?>
<div class="panel panel-click">
    <div class="panel-head">
        <h3><span class="ph-ico" style="background:#e4f8fb; color:#0891b2;"><i class="fas fa-book"></i></span>Cashbook</h3>
        <a href="expense.php" class="panel-link">Open</a>
    </div>
    <div class="panel-body">
        <div class="cols-3" style="margin-bottom:1rem;">
            <div style="background:var(--gray-50); border-radius:.75rem; padding:.7rem; text-align:center;">
                <div class="mini-num">Sales</div>
                <strong style="color:#059669;"><?php echo formatCurrency($allTimeSales['total']); ?></strong>
            </div>
            <div style="background:var(--gray-50); border-radius:.75rem; padding:.7rem; text-align:center;">
                <div class="mini-num">Expenses</div>
                <strong style="color:#dc2626;"><?php echo formatCurrency($cashTotals['cash_out']); ?></strong>
            </div>
            <div style="background:var(--gray-50); border-radius:.75rem; padding:.7rem; text-align:center;">
                <div class="mini-num">Balance</div>
                <strong style="color:#2563eb;"><?php echo formatCurrency($netCash); ?></strong>
            </div>
        </div>
        <?php if (!empty($recentCash)): ?>
            <?php foreach ($recentCash as $ce): ?>
                <div class="list-row">
                    <div class="lr-main">
                        <span class="badge badge-<?php echo $ce['type'] === 'cash_in' ? 'success' : 'danger'; ?>">
                            <i class="fas fa-<?php echo $ce['type'] === 'cash_in' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                        </span>
                        <span>
                            <strong style="font-size:0.85rem;"><?php echo $ce['type'] === 'cash_in' ? 'Cash In' : sanitize($ce['note'] ?: 'Expense'); ?></strong>
                            <div class="mini-num"><?php echo date('d M, h:i A', strtotime($ce['created_at'])); ?></div>
                        </span>
                    </div>
                    <div class="lr-amt" style="color:<?php echo $ce['type'] === 'cash_in' ? '#059669' : '#dc2626'; ?>;">
                        <?php echo $ce['type'] === 'cash_in' ? '+' : '-'; ?> <?php echo formatCurrency($ce['amount']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted text-center">No cashbook entries yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Staff Salary Overview -->
<?php if (hasPermission('staff')): ?>
<div class="panel" style="margin-bottom:1.5rem;">
    <div class="panel-head">
        <h3><span class="ph-ico" style="background:#f1ecff; color:#7c3aed;"><i class="fas fa-user-tie"></i></span>Staff Salary Overview</h3>
        <a href="staff.php" class="btn btn-sm btn-outline">Manage Staff</a>
    </div>
    <div class="panel-body no-pad">
        <?php if (empty($staffOverview)): ?>
            <p class="text-muted text-center" style="padding:1.5rem;">No active staff members yet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th>Salary</th>
                            <th>Type</th>
                            <th>Paid (Month)</th>
                            <th>Total Paid</th>
                            <th>Due (Month)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffOverview as $s): ?>
                            <?php $due = max(0, (float)$s['salary'] - (float)$s['paid_month']); ?>
                            <tr>
                                <td><strong><?php echo sanitize($s['name']); ?></strong></td>
                                <td><?php echo formatCurrency($s['salary']); ?></td>
                                <td><span class="badge badge-<?php echo $s['salary_type'] === 'weekly' ? 'warning' : 'primary'; ?>"><?php echo ucfirst($s['salary_type']); ?></span></td>
                                <td class="text-success"><?php echo formatCurrency($s['paid_month']); ?></td>
                                <td><?php echo formatCurrency($s['total_paid']); ?></td>
                                <td><?php if ($due > 0): ?><strong class="text-danger"><?php echo formatCurrency($due); ?></strong><?php else: ?><span class="badge badge-success">Paid</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="page-foot">
    <div>
        <span class="pf-brand">AVA IT Solution</span>
        <span class="pf-sep">|</span>
        <span>Smart Collection</span>
    </div>
    <div>&copy; <?php echo date('Y'); ?> AVA IT Solution. All rights reserved.</div>
</div>

<?php include 'includes/footer.php'; ?>