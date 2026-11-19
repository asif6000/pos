<?php
/**
 * POS System - Returns Management
 * Handle product returns with automatic stock adjustment
 */

require_once '../config/db.php';
require_once '../config/csrf.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Returns');

$db = getDB();
$user = getCurrentUser();

// Load settings for receipt
$settings = [];
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE owner_id = ?");
$stmt->execute([$user['owner_id']]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle return submission (with exchange support)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return') {
    csrfRequire('returns.php', ['returns.php']);

    // DDL must not happen inside the return transaction - an ALTER commits it early,
    // and the rollback would then undo nothing.
    ensureCashbookSourceColumns();
    ensureReturnExchangeColumns();

    $saleId = (int) ($_POST['sale_id'] ?? 0);
    $items = $_POST['return_items'] ?? [];
    $exchangeProducts = isset($_POST['exchange_products']) ? json_decode($_POST['exchange_products'], true) : [];
    $refundMethod = sanitize($_POST['refund_method'] ?? 'cash');
    $reason = sanitize($_POST['reason'] ?? '');

    $isExchange = !empty($exchangeProducts);

    if ($saleId && !empty($items)) {
        try {
            $db->beginTransaction();

            // Whose sale is this, and on whose shelf? sale_id arrives from the form,
            // so without this gate any shop could hand back another shop's bill and
            // move that shop's stock and cash. A platform Super Admin is the one
            // caller that bills across shops, so the gate keeps their returns open.
            $isSuperAdmin = isSuperAdminUser($user);
            $stmtSale = $db->prepare("SELECT s.user_id AS maker_id, s.invoice_number, " . saleStoreExpr() . " AS store_id, " . saleOwnerExpr() . " AS owner_id
                                      FROM sales s JOIN users u ON s.user_id = u.id
                                      WHERE s.id = ?");
            $stmtSale->execute([$saleId]);
            $saleRow = $stmtSale->fetch();
            if (!$saleRow) {
                throw new Exception('Sale not found');
            }
            // COALESCE gives NULL when neither the sale nor its maker carries an
            // owner, which is a sale rung by its own owner: their id is the owner.
            $saleOwnerId = $saleRow['owner_id'] !== null ? (int)$saleRow['owner_id'] : (int)$saleRow['maker_id'];
            if (!$isSuperAdmin && $saleOwnerId !== (int)$user['owner_id']) {
                throw new Exception('Ei sale apnar shop-er na.');
            }

            // A counter account (staff/cashier) hands back only what it sold
            // itself: another counter's bill is theirs to return, and the stock
            // and cash of this shop answer for the difference.
            if (isOwnSalesOnlyUser($user) && (int)$saleRow['maker_id'] !== (int)$user['id']) {
                throw new Exception('Ei sale apnar nijer bikri na - return korte parben na.');
            }

            // Generate return number
            $returnNumber = 'RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Calculate total return amount
            $totalAmount = 0;
            $returnItemsData = [];

            foreach ($items as $itemId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0)
                    continue;

                // Get sale item details
                $stmt = $db->prepare("SELECT * FROM sale_items WHERE id = ? AND sale_id = ?");
                $stmt->execute([$itemId, $saleId]);
                $saleItem = $stmt->fetch();

                if ($saleItem && $qty <= $saleItem['quantity']) {
                    $itemTotal = $qty * $saleItem['unit_price'];
                    $totalAmount += $itemTotal;

                    $returnItemsData[] = [
                        'product_id' => $saleItem['product_id'],
                        'product_name' => $saleItem['product_name'],
                        'quantity' => $qty,
                        'unit_price' => $saleItem['unit_price'],
                        'total_price' => $itemTotal
                    ];
                }
            }

            if (empty($returnItemsData)) {
                throw new Exception('No valid items to return');
            }

            // Process exchange items (if any)
            $exchangeTotal = 0;
            $exchangeItemsData = [];

            if ($isExchange) {
                foreach ($exchangeProducts as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $qty = (int) ($item['quantity'] ?? 0);
                    if ($productId <= 0 || $qty <= 0)
                        continue;

                    $stmt = $db->prepare("SELECT id, name, sell_price FROM products WHERE id = ? AND status = 'active'");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch();

                    if (!$product) {
                        throw new Exception("Product ID {$productId} not found");
                    }

                    $itemTotal = $qty * $product['sell_price'];
                    $exchangeTotal += $itemTotal;

                    $exchangeItemsData[] = [
                        'product_id' => $product['id'],
                        'product_name' => $product['name'],
                        'quantity' => $qty,
                        'unit_price' => $product['sell_price'],
                        'total_price' => $itemTotal
                    ];
                }
            }

            // The cash difference the exchange settles: positive = the customer pays
            // extra, negative = money handed back. A plain return is the same
            // calculation with nothing taken in exchange - which is how the mobile
            // door already books it, because that refund really does leave the drawer.
            $exchangeBalance = round($exchangeTotal - $totalAmount, 2);
            $extraReceived = $exchangeBalance > 0 ? $exchangeBalance : 0.0;
            $refundPaid    = $exchangeBalance < 0 ? -$exchangeBalance : 0.0;

            // A refund can never outrun the invoice it came from. sales.total is
            // reduced by every return, so this also stops a double-click or a
            // replayed form from refunding the same sale a second time.
            $stmt = $db->prepare("SELECT total FROM sales WHERE id = ? FOR UPDATE");
            $stmt->execute([$saleId]);
            $saleRemaining = (float) $stmt->fetchColumn();
            if ($totalAmount > $saleRemaining + 0.005) {
                throw new Exception('Return value is more than the remaining sale amount of ' . formatCurrency($saleRemaining));
            }

            // Which shelf the goods go back on: the sale's own store first, because a
            // Super Admin's till can empty a different shop's stock, and only then
            // the current till. Settled before anything is written, because the
            // drawer a refund comes out of is that shop's, not the till's.
            $saleStoreId = (int)($saleRow['store_id'] ?? 0);

            // Fallback: use current user's store or first active store
            if (!$saleStoreId) {
                $stmtCur = $db->prepare("SELECT store_id FROM users WHERE id = ?");
                $stmtCur->execute([$user['id']]);
                $saleStoreId = $stmtCur->fetchColumn();
            }
            if (!$saleStoreId) {
                $stmtFallback = $db->prepare("SELECT id FROM stores WHERE status = 'active' AND owner_id = ? LIMIT 1");
                $stmtFallback->execute([$user['owner_id']]);
                $saleStoreId = $stmtFallback->fetchColumn() ?: 0;
            }

            // Cash only leaves a till that can spare it. A refund the drawer cannot
            // cover is refused here rather than booked and left to make the balance
            // negative, because the shop then has two figures it cannot both believe.
            if ($refundPaid > 0.009) {
                $guard = cashRefundGuard($saleOwnerId, $saleStoreId ?: null, $refundMethod, $refundPaid);
                if (!$guard['ok']) {
                    throw new Exception($guard['message']);
                }
            }

            // Determine the actual payment/refund method
            $finalRefundMethod = $refundMethod;

            // Create return record
            $stmt = $db->prepare("INSERT INTO returns (return_number, sale_id, user_id, total_amount, transaction_type, exchange_amount, extra_amount_received, refund_amount, refund_method, reason, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $returnNumber, $saleId, $user['id'], $totalAmount,
                $isExchange ? 'exchange' : 'return',
                round($exchangeTotal, 2), $extraReceived, $refundPaid,
                $finalRefundMethod, $reason, $saleOwnerId
            ]);
            $returnId = $db->lastInsertId();

            // Insert return items and update stock (+)
            foreach ($returnItemsData as $item) {
                $stmt = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$returnId, $item['product_id'], $item['product_name'], $item['quantity'], $item['unit_price'], $item['total_price']]);

                if ($saleStoreId > 0) {
                    $stmtCheck = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
                    $stmtCheck->execute([$saleStoreId, $item['product_id']]);
                    if (!$stmtCheck->fetch()) {
                        $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                            ->execute([$saleStoreId, $item['product_id'], 0]);
                    }
                    $db->prepare("UPDATE store_stocks SET quantity = quantity + ? WHERE store_id = ? AND product_id = ?")
                        ->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
                }

                $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'return', ?, ?, ?)");
                $stmt->execute([$item['product_id'], $item['quantity'], $returnId, "Return: {$returnNumber}", $user['id']]);
            }

            // Insert exchange items into return_items with [EXCHANGE] prefix (no separate table needed)
            if (!empty($exchangeItemsData)) {
                foreach ($exchangeItemsData as $item) {
                    $exchangeName = '[EXCHANGE] ' . $item['product_name'];
                    $stmt = $db->prepare("INSERT INTO return_items (return_id, product_id, product_name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$returnId, $item['product_id'], $exchangeName, $item['quantity'], $item['unit_price'], $item['total_price']]);

                    if ($saleStoreId > 0) {
                        $stmtCheck = $db->prepare("SELECT quantity FROM store_stocks WHERE store_id = ? AND product_id = ?");
                        $stmtCheck->execute([$saleStoreId, $item['product_id']]);
                        if (!$stmtCheck->fetch()) {
                            $db->prepare("INSERT INTO store_stocks (store_id, product_id, quantity) VALUES (?, ?, ?)")
                                ->execute([$saleStoreId, $item['product_id'], 0]);
                        }
                        $db->prepare("UPDATE store_stocks SET quantity = quantity - ? WHERE store_id = ? AND product_id = ?")
                            ->execute([$item['quantity'], $saleStoreId, $item['product_id']]);
                    }

                    $stmt = $db->prepare("INSERT INTO stock_history (product_id, quantity_change, type, reference_id, note, user_id) VALUES (?, ?, 'adjustment', ?, ?, ?)");
                    $stmt->execute([$item['product_id'], -$item['quantity'], $returnId, "Exchange deduction: {$returnNumber}", $user['id']]);
                }
            }

            // Update original sale record (deduct returned amount)
            $stmt = $db->prepare("UPDATE sales SET subtotal = subtotal - ?, total = total - ?, paid_amount = paid_amount - ? WHERE id = ?");
            $stmt->execute([$totalAmount, $totalAmount, $totalAmount, $saleId]);

            // Auto Cashbook entries for return/exchange net cash flow
            // refund amount = money returned to customer (only when exchange value < returned value)
            // One linked movement per return, written through the shared helper so
            // a re-save replaces the amount instead of adding another line. The
            // refund half is filed under the shop's Product Refund expense category,
            // against the bill it came from, by the method it was paid in.
            exchangeBalanceSettle($returnId, $exchangeBalance, $returnNumber, [
                'owner_id'       => $saleOwnerId,
                'store_id'       => $saleStoreId ?: null,
                'user_id'        => $user['id'],
                'payment_method' => $finalRefundMethod,
                'invoice_no'     => $saleRow['invoice_number'] ?? '',
            ]);

            $db->commit();

            // Store receipt data in session for display after redirect
            $stmtInv = $db->prepare("SELECT invoice_number FROM sales WHERE id = ?");
            $stmtInv->execute([$saleId]);
            $saleInvoiceNumber = $stmtInv->fetchColumn();

            startSecureSession();
            $_SESSION['return_receipt'] = [
                'return_number' => $returnNumber,
                'sale_invoice' => $saleInvoiceNumber ?: 'N/A',
                'return_items' => $returnItemsData,
                'exchange_items' => $exchangeItemsData,
                'return_total' => (float) $totalAmount,
                'exchange_total' => (float) $exchangeTotal,
                'balance' => (float) $exchangeBalance,
                'is_exchange' => $isExchange
            ];
            setFlash('success', "Return #{$returnNumber} processed successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('danger', 'Error processing return: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Please select items to return.');
    }
    redirect('returns.php');
}

// ── Filters ────────────────────────────────────────────────────────────────
// Every filter is optional: with none set the list shows all returns, newest
// first, which is how this page behaved before the filters existed.
$refundMethods = [
    'cash'         => 'Cash',
    'bkash'        => 'bKash',
    'nagad'        => 'Nagad',
    'rocket'       => 'Rocket',
    'card'         => 'Card',
    'bank'         => 'Bank',
    'store_credit' => 'Store Credit',
];

// What the till can pay out, read the same way the guard reads it, so the number
// beside the refund method and the number that refuses a refund are one number.
$drawerState = cashLedgerState($user['owner_id'] ?: $user['id'], $user['store_id'] ?: null);

function returnDateFilter($value)
{
    $value = trim((string) $value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return '';
    }
    return $value;
}

$search = trim((string) ($_GET['search'] ?? ''));
$type = (string) ($_GET['type'] ?? '');
$type = ($type === 'exchange' || $type === 'return') ? $type : '';
$refundMethod = (string) ($_GET['refund_method'] ?? '');
$refundMethod = isset($refundMethods[$refundMethod]) ? $refundMethod : '';
$dateFrom = returnDateFilter($_GET['date_from'] ?? '');
$dateTo = returnDateFilter($_GET['date_to'] ?? '');
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $swap = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $swap;
}

// Scope is unchanged from the previous version of this page: a return belongs
// to the shop that owns the user who processed it. A counter account
// (staff/cashier) reads the narrower list underneath that: only the returns
// against bills it rang up itself.
list($ownScope, $ownParams) = ownSalesScopeSql($user);
$sqlFrom = " FROM returns r
            JOIN sales s ON r.sale_id = s.id
            JOIN users u ON r.user_id = u.id
            LEFT JOIN customers c ON s.customer_id = c.id
            WHERE u.owner_id = ?";
$params = [$user['owner_id'] ?? null];

if ($dateFrom !== '') {
    $sqlFrom .= " AND DATE(r.created_at) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sqlFrom .= " AND DATE(r.created_at) <= ?";
    $params[] = $dateTo;
}
if ($type !== '') {
    // Exchange rows are the ones stored with the [EXCHANGE] name prefix.
    $exists = "EXISTS (SELECT 1 FROM return_items ri WHERE ri.return_id = r.id AND ri.product_name LIKE '[EXCHANGE] %')";
    $sqlFrom .= ' AND ' . ($type === 'exchange' ? $exists : 'NOT ' . $exists);
}
if ($refundMethod !== '') {
    $sqlFrom .= " AND r.refund_method = ?";
    $params[] = $refundMethod;
}
if ($search !== '') {
    $sqlFrom .= " AND (r.return_number LIKE ? OR s.invoice_number LIKE ? OR c.name LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}

// The own-bills filter goes in after every optional filter above, so the order
// of the bindings follows the order of the placeholders.
$sqlFrom .= $ownScope;
$params = array_merge($params, $ownParams);

$hasFilters = $search !== '' || $type !== '' || $refundMethod !== '' || $dateFrom !== '' || $dateTo !== '';

// ── One page of the list ───────────────────────────────────────────────────
$perPage = 20;
$stmt = $db->prepare("SELECT COUNT(*)" . $sqlFrom);
$stmt->execute($params);
$totalFiltered = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT r.*, s.invoice_number, c.name AS customer_name, u.name AS processed_by,
        (SELECT COUNT(*) FROM return_items WHERE return_id = r.id) AS item_count,
        (SELECT COUNT(*) FROM return_items WHERE return_id = r.id AND product_name LIKE '[EXCHANGE] %') AS exchange_count
    " . $sqlFrom . "
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$returns = $stmt->fetchAll();

/**
 * Rebuild a returns.php URL with the current filters plus these overrides.
 * `invoice` is the "return this bill" shortcut coming from the sales list; it
 * opens the modal once and must not be carried into every pager link.
 */
function returnsQuery($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    unset($q['invoice']);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return 'returns.php' . ($q ? '?' . http_build_query($q) : '');
}

// ── KPI cards ──────────────────────────────────────────────────────────────
//
// The counter account's cards answer for its own sales only, which is why the
// sale is joined in here: the staff filter is the bill's cashier, not whoever
// pressed the refund button. Without a filter to add the cards read the whole
// shop, exactly as they always did for an owner.
$ownKpiJoin = '';
$ownKpiWhere = '';
$ownKpiParams = [];
if (!empty($ownScope)) {
    $ownKpiJoin = " JOIN sales s2 ON r.sale_id = s2.id";
    $ownKpiWhere = " AND s2.user_id = ?";
    $ownKpiParams = $ownParams;
}

$stmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(r.total_amount), 0) AS total
                     FROM returns r JOIN users u ON r.user_id = u.id{$ownKpiJoin}
                     WHERE u.owner_id = ?{$ownKpiWhere} AND DATE(r.created_at) = CURDATE()");
$stmt->execute(array_merge([$user['owner_id']], $ownKpiParams));
$todayReturns = $stmt->fetch();

$stmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(r.total_amount), 0) AS total
                     FROM returns r JOIN users u ON r.user_id = u.id{$ownKpiJoin}
                     WHERE u.owner_id = ?{$ownKpiWhere} AND DATE(r.created_at) = CURDATE() - INTERVAL 1 DAY");
$stmt->execute(array_merge([$user['owner_id']], $ownKpiParams));
$yesterdayReturns = $stmt->fetch();

$stmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(r.total_amount), 0) AS total
                     FROM returns r JOIN users u ON r.user_id = u.id{$ownKpiJoin}
                     WHERE u.owner_id = ?{$ownKpiWhere} AND r.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$stmt->execute(array_merge([$user['owner_id']], $ownKpiParams));
$monthReturns = $stmt->fetch();

$stmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(r.total_amount), 0) AS total
                     FROM returns r JOIN users u ON r.user_id = u.id{$ownKpiJoin}
                     WHERE u.owner_id = ?{$ownKpiWhere} AND r.created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')
                       AND r.created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')");
$stmt->execute(array_merge([$user['owner_id']], $ownKpiParams));
$lastMonthReturns = $stmt->fetch();

/**
 * Percentage change against the matching earlier window. null means there is
 * nothing to compare with yet, which the card shows as "New" rather than a
 * made-up 100%.
 */
function returnPctChange($now, $prev)
{
    $prev = (float) $prev;
    if ($prev <= 0) {
        return (float) $now > 0 ? null : 0.0;
    }
    return round(((float) $now - $prev) / $prev * 100, 1);
}

/** The signed "vs yesterday" pill. Neutral colour on purpose: on a returns
 *  page more volume is not a win, so green would read the wrong way. */
$rkDelta = function ($now, $prev, $label) {
    $pct = returnPctChange($now, $prev);
    if ($pct === null) {
        return '<span class="delta flat">New &middot; vs ' . $label . '</span>';
    }
    if ($pct == 0.0) {
        return '<span class="delta flat">Same as ' . $label . '</span>';
    }
    return '<span class="delta flat">' . ($pct > 0 ? '+' : '-') . abs($pct) . '% vs ' . $label . '</span>';
};

/** The corner arrow, which only shows direction, never a judgement. */
$rkTrend = function ($now, $prev, $tip) {
    $pct = returnPctChange($now, $prev);
    $icon = 'fa-arrow-right';
    if ($pct === null || $pct > 0) {
        $icon = 'fa-arrow-trend-up';
    } elseif ($pct < 0) {
        $icon = 'fa-arrow-trend-down';
    }
    return '<span class="rk-trend" title="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fas ' . $icon . '"></i></span>';
};

$rkWave = function ($color) {
    return '<svg class="rk-wave" viewBox="0 0 200 100" preserveAspectRatio="none" aria-hidden="true">'
        . '<path d="M0 60 C38 28 62 86 100 58 C138 30 164 76 200 44 L200 100 L0 100 Z" fill="' . $color . '" opacity=".30"/>'
        . '<path d="M0 78 C42 52 66 96 104 74 C142 52 168 92 200 66 L200 100 L0 100 Z" fill="' . $color . '" opacity=".18"/>'
        . '</svg>';
};

$refundIcons = [
    'cash'         => 'fa-wallet',
    'bkash'        => 'fa-mobile-screen',
    'nagad'        => 'fa-mobile-screen',
    'rocket'       => 'fa-mobile-screen',
    'card'         => 'fa-credit-card',
    'bank'         => 'fa-building-columns',
    'store_credit' => 'fa-store',
];
$refundColors = [
    'cash'         => ['#e8f1ff', '#1d4ed8'],
    'bkash'        => ['#ffeaf1', '#be185d'],
    'nagad'        => ['#fff4e0', '#b45309'],
    'rocket'       => ['#f1ecff', '#6d28d9'],
    'card'         => ['#e4f8fb', '#0e7490'],
    'bank'         => ['#eef2f7', '#475569'],
    'store_credit' => ['#e6f9f0', '#047857'],
];

// Load products for exchange feature (barcode included for scan-based lookup)
$stmtProds = $db->prepare("SELECT p.id, p.name, p.barcode, p.sell_price FROM products p WHERE p.status = 'active' AND p.owner_id = ? ORDER BY p.name");
$stmtProds->execute([$user['owner_id']]);
$exchangeProducts = $stmtProds->fetchAll();

// Check for return receipt data to show print modal
$returnReceipt = null;
if (isset($_SESSION['return_receipt'])) {
    $returnReceipt = $_SESSION['return_receipt'];
    unset($_SESSION['return_receipt']);
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
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 1.05rem;
        box-shadow: 0 6px 14px rgba(37, 99, 235, .25);
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
        display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1rem; margin-bottom: 1.25rem;
    }
    .rk {
        position: relative; overflow: hidden;
        display: flex; align-items: flex-start; gap: .9rem;
        background: linear-gradient(120deg, #eaf3ff 0%, #f6faff 55%, #ffffff 100%);
        border: 1px solid #dbeafe; border-radius: 18px; padding: 1.15rem 1.3rem;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
    }
    .rk.green {
        background: linear-gradient(120deg, #e6f9f0 0%, #f4fdf9 55%, #ffffff 100%);
        border-color: #d5f2e4;
    }
    html[data-theme="dark"] .rk { background: linear-gradient(120deg, #16233f 0%, #111a2e 70%); border-color: #1e2a44; }
    html[data-theme="dark"] .rk.green { background: linear-gradient(120deg, #0d2b23 0%, #101a24 70%); border-color: #16352c; }
    .rk-ico {
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; z-index: 1;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.05rem;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        box-shadow: 0 6px 14px rgba(37, 99, 235, .28);
    }
    .rk.green .rk-ico {
        background: linear-gradient(135deg, #10b981, #047857);
        box-shadow: 0 6px 14px rgba(5, 150, 105, .28);
    }
    .rk-body { flex: 1 1 auto; min-width: 0; z-index: 1; }
    .rk-label { font-size: .8rem; font-weight: 700; color: var(--gray-700); }
    .rk-value { font-size: 1.5rem; font-weight: 800; color: var(--gray-900); line-height: 1.2; margin-top: .15rem; }
    .rk-foot { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; font-size: .75rem; color: var(--gray-500); margin-top: .5rem; }
    .rk-trend {
        position: absolute; right: 1.1rem; top: 1.1rem; z-index: 1;
        width: 30px; height: 30px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-size: .75rem;
        background: rgba(255, 255, 255, .7); color: #2563eb; border: 1px solid rgba(37, 99, 235, .18);
    }
    .rk.green .rk-trend { color: #059669; border-color: rgba(5, 150, 105, .18); }
    html[data-theme="dark"] .rk-trend { background: rgba(255, 255, 255, .07); color: #60a5fa; }
    html[data-theme="dark"] .rk.green .rk-trend { color: #34d399; }
    .rk-wave { position: absolute; right: 0; bottom: 0; width: 48%; height: 62%; opacity: .8; pointer-events: none; }
    .delta { font-size: .72rem; font-weight: 700; padding: .2rem .5rem; border-radius: 999px; white-space: nowrap; }
    .delta.flat { background: rgba(255, 255, 255, .75); color: var(--gray-600); border: 1px solid var(--gray-200); }
    html[data-theme="dark"] .delta.flat { background: rgba(255, 255, 255, .06); }

    /* ── Table panel ──────────────────────────────────────────────────────── */
    .tbl-head {
        display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100);
    }
    .tbl-head h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
    .cnt { font-size: .72rem; font-weight: 700; background: #e8f1ff; color: #1d4ed8; padding: .2rem .6rem; border-radius: 999px; }
    .tbl-head .spacer { flex: 1; }
    .search-wrap { position: relative; display: block; min-width: 210px; }
    .search-wrap > i { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); font-size: .78rem; color: var(--gray-400); pointer-events: none; }
    .search-wrap input { padding-left: 2.2rem; }

    /* The advanced filters live in a drawer under the panel head rather than a
       floating menu: the card clips overflow, so a dropdown would be cut off. */
    .rf-form { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin: 0; }
    .flt-more { display: none; padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100); background: var(--gray-50); }
    .flt-more.open { display: block; }
    .flt-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)) auto; gap: .9rem; align-items: end; }
    .flt-actions { display: flex; gap: .6rem; }
    .flt-more .form-group { margin-bottom: 0; }
    @media (max-width: 1100px) { .flt-grid { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 700px) { .flt-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 460px) { .flt-grid { grid-template-columns: 1fr; } .flt-actions .btn { flex: 1; } }
    #fltToggle i.fa-chevron-down { font-size: .62rem; transition: transform .18s ease; }
    #fltToggle.on i.fa-chevron-down { transform: rotate(180deg); }

    .inv { display: flex; align-items: center; gap: .45rem; white-space: nowrap; }
    .inv-no { font-weight: 700; color: var(--gray-800); }
    .dot { width: 7px; height: 7px; border-radius: 50%; background: #f87171; flex-shrink: 0; }
    .lnk { color: #2563eb; font-weight: 600; text-decoration: none; }
    .lnk:hover { text-decoration: underline; }
    .itn { font-weight: 600; color: var(--gray-700); }
    .itx { display: block; font-size: .72rem; font-weight: 700; color: #059669; }
    .amt { color: #dc2626; font-weight: 800; white-space: nowrap; }
    .pill {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .72rem; font-weight: 700; padding: .25rem .6rem;
        border-radius: 999px; white-space: nowrap;
    }
    .pill i { font-size: .62rem; }
    .pill.return { background: #eef2f7; color: #475569; }
    .pill.exchange { background: #fff4e0; color: #b45309; }
    .act {
        width: 32px; height: 32px; border: none; border-radius: 9px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; font-size: .78rem;
        background: var(--gray-50); border: 1px solid var(--gray-200); color: var(--gray-600);
        text-decoration: none;
        transition: transform .15s ease, filter .15s ease;
    }
    .act:hover { transform: translateY(-1px); filter: brightness(.97); color: #2563eb; text-decoration: none; }

    /* The "Invoice: INV-x" banner inside the return modal. It deliberately does
       NOT use .alert: app.js removes every .alert node 5 seconds after load,
       and this one carries the id showReturnItems() writes into. */
    .inv-note {
        display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
        padding: .85rem 1rem; margin-bottom: 1rem; border-radius: var(--border-radius);
        background: var(--info-light, #e0f2fe); color: #1e40af;
    }
    html[data-theme="dark"] .inv-note { color: #bfdbfe; }

    /* ── Footer + pager ───────────────────────────────────────────────────── */
    .tbl-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        flex-wrap: wrap; padding: .85rem 1.25rem;
        border-top: 1px solid var(--gray-100);
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
    <span class="pg-hero-ico"><i class="fas fa-rotate-left"></i></span>
    <div class="pg-hero-txt">
        <h2>Returns</h2>
        <p>Process product returns, exchanges and track refunds</p>
    </div>
    <div class="pg-hero-actions">
        <button class="btn btn-primary" onclick="openReturnModal()">
            <i class="fas fa-plus"></i> New Return / Exchange
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="kpi-grid">
    <div class="rk">
        <span class="rk-ico"><i class="fas fa-rotate-left"></i></span>
        <div class="rk-body">
            <div class="rk-label">Today's Returns</div>
            <div class="rk-value"><?php echo formatCurrency($todayReturns['total']); ?></div>
            <div class="rk-foot">
                <?php echo (int) $todayReturns['cnt']; ?> returns
                <?php echo $rkDelta($todayReturns['total'], $yesterdayReturns['total'], 'yesterday'); ?>
            </div>
        </div>
        <?php echo $rkTrend($todayReturns['total'], $yesterdayReturns['total'], 'Compared with yesterday'); ?>
        <?php echo $rkWave('#93c5fd'); ?>
    </div>
    <div class="rk green">
        <span class="rk-ico"><i class="fas fa-calendar-days"></i></span>
        <div class="rk-body">
            <div class="rk-label">This Month</div>
            <div class="rk-value"><?php echo formatCurrency($monthReturns['total']); ?></div>
            <div class="rk-foot">
                <?php echo (int) $monthReturns['cnt']; ?> returns
                <?php echo $rkDelta($monthReturns['total'], $lastMonthReturns['total'], 'last month'); ?>
            </div>
        </div>
        <?php echo $rkTrend($monthReturns['total'], $lastMonthReturns['total'], 'Compared with last month'); ?>
        <?php echo $rkWave('#6ee7b7'); ?>
    </div>
</div>

<!-- Returns List -->
<div class="card">
    <form method="GET" class="rf-form">
        <div class="tbl-head">
            <h3><i class="fas fa-rotate-left" style="color:#2563eb;"></i> Recent Returns</h3>
            <span class="cnt"><?php echo number_format($totalFiltered); ?> Record<?php echo $totalFiltered === 1 ? '' : 's'; ?></span>
            <span class="spacer"></span>
            <span class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control"
                    placeholder="Search by return #, invoice #, or customer..."
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            </span>
            <button type="button" class="btn btn-outline btn-sm <?php echo $hasFilters ? 'on' : ''; ?>" id="fltToggle"
                onclick="toggleFilters()">
                <i class="fas fa-filter"></i> Filter <i class="fas fa-chevron-down"></i>
            </button>
        </div>

        <div class="flt-more <?php echo $hasFilters ? 'open' : ''; ?>" id="fltMore">
            <div class="flt-grid">
                <div class="form-group">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <option value="return" <?php echo $type === 'return' ? 'selected' : ''; ?>>Return only</option>
                        <option value="exchange" <?php echo $type === 'exchange' ? 'selected' : ''; ?>>With exchange</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Refund Method</label>
                    <select name="refund_method" class="form-control">
                        <option value="">All Methods</option>
                        <?php foreach ($refundMethods as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $refundMethod === $key ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flt-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="returns.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </div>
    </form>

    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Return #</th>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th>Refund Method</th>
                        <th>Processed By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No returns found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $return): ?>
                            <?php
                            $isExchange = (int) $return['exchange_count'] > 0;
                            $rm = $return['refund_method'];
                            $rmColor = $refundColors[$rm] ?? ['#eef2f7', '#475569'];
                            ?>
                            <tr>
                                <td>
                                    <div class="inv">
                                        <i class="dot" title="Return"></i>
                                        <span class="inv-no"><?php echo sanitize($return['return_number']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <a class="lnk" href="sales.php?search=<?php echo urlencode($return['invoice_number']); ?>">
                                        <?php echo sanitize($return['invoice_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo date('d M Y, h:i A', strtotime($return['created_at'])); ?></td>
                                <td>
                                    <span class="itn"><?php echo (int) $return['item_count']; ?> items</span>
                                    <?php if ($isExchange): ?>
                                        <span class="itx">+<?php echo (int) $return['exchange_count']; ?> exchange</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="amt"><?php echo formatCurrency($return['total_amount']); ?></span></td>
                                <td>
                                    <?php if ($isExchange): ?>
                                        <span class="pill exchange"><i class="fas fa-right-left"></i> Exchange</span>
                                    <?php else: ?>
                                        <span class="pill return"><i class="fas fa-rotate-left"></i> Return</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="pill" style="background:<?php echo $rmColor[0]; ?>; color:<?php echo $rmColor[1]; ?>;">
                                        <i class="fas <?php echo $refundIcons[$rm] ?? 'fa-wallet'; ?>"></i>
                                        <?php echo $refundMethods[$rm] ?? ucfirst($rm); ?>
                                    </span>
                                </td>
                                <td><?php echo sanitize($return['processed_by']); ?></td>
                                <td>
                                    <button class="act" type="button" title="View Return"
                                        onclick="viewReturn(<?php echo (int) $return['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tbl-foot">
        <span>
            <?php if ($totalFiltered === 0): ?>
                No returns match these filters
            <?php else: ?>
                Showing <?php echo $offset + 1; ?> to <?php echo min($page * $perPage, $totalFiltered); ?>
                of <?php echo number_format($totalFiltered); ?> return<?php echo $totalFiltered === 1 ? '' : 's'; ?>
            <?php endif; ?>
        </span>
        <?php if ($totalPages > 1): ?>
            <div class="pager">
                <a class="pg <?php echo $page <= 1 ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(returnsQuery(['page' => max(1, $page - 1)])); ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php
                // A window of pages around the current one, never more than 7 links.
                $from2 = max(1, min($page - 3, $totalPages - 6));
                $to2 = min($totalPages, max($page + 3, 7));
                ?>
                <?php if ($from2 > 1): ?>
                    <a class="pg" href="<?php echo htmlspecialchars(returnsQuery(['page' => 1])); ?>">1</a>
                    <?php if ($from2 > 2): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $from2; $i <= $to2; $i++): ?>
                    <a class="pg <?php echo $i === $page ? 'cur' : ''; ?>"
                        href="<?php echo htmlspecialchars(returnsQuery(['page' => $i])); ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($to2 < $totalPages): ?>
                    <?php if ($to2 < $totalPages - 1): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                    <a class="pg" href="<?php echo htmlspecialchars(returnsQuery(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                <a class="pg <?php echo $page >= $totalPages ? 'off' : ''; ?>"
                    href="<?php echo htmlspecialchars(returnsQuery(['page' => min($totalPages, $page + 1)])); ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Return Modal -->
<div class="modal-overlay" id="returnModal">
    <div class="modal" style="max-width: 700px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo"></i> Process Return</h3>
            <button class="modal-close" onclick="closeReturnModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Step 1: Search Invoice -->
            <div id="step1">
                <div class="form-group">
                    <label class="form-label required">Invoice Number</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="invoiceSearch" class="form-control"
                            placeholder="Enter invoice number (e.g., INV-20260129-0001)">
                        <button class="btn btn-primary" onclick="searchInvoice()">
                            <i class="fas fa-search"></i> Find
                        </button>
                    </div>
                </div>
                <div id="invoiceResult"></div>
            </div>

            <!-- Step 2: Select Items + Exchange (hidden initially) -->
            <div id="step2" style="display: none;">
                <form method="POST" id="returnForm">
                    <input type="hidden" name="action" value="process_return">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="sale_id" id="returnSaleId">
                    <input type="hidden" name="exchange_products" id="exchangeProductsInput">

                    <div class="inv-note">
                        <i class="fas fa-info-circle"></i>
                        Invoice: <strong id="selectedInvoice"></strong>
                        <button type="button" class="btn btn-sm btn-outline" onclick="resetReturn()"
                            style="margin-left: 1rem;">
                            <i class="fas fa-times"></i> Change
                        </button>
                    </div>

                    <!-- Return Items Section -->
                    <h4 style="margin-bottom: 0.5rem;">
                        <i class="fas fa-undo"></i> Items to Return
                    </h4>
                    <div id="returnItemsList"></div>

                    <!-- Exchange Section -->
                    <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px solid var(--gray-200);">
                        <h4 style="margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-exchange-alt"></i> Exchange Items
                            <span
                                style="font-size: 0.8rem; font-weight: normal; color: var(--gray-500);">(optional)</span>
                        </h4>

                        <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem; align-items: flex-start;">
                            <div class="exch-search-wrap" id="exchangeSearchWrap" style="flex: 2;">
                                <input type="text" id="exchangeSearch" class="form-control"
                                    placeholder="Scan barcode or type product name..." autocomplete="off"
                                    role="combobox" aria-expanded="false" aria-autocomplete="list"
                                    aria-controls="exchangeDropdown">
                                <button type="button" class="exch-scan-btn" id="exchangeScanBtn"
                                    title="Scan barcode with camera" aria-label="Scan barcode with camera">
                                    <i class="fas fa-camera"></i>
                                </button>
                                <div class="exch-dropdown" id="exchangeDropdown" role="listbox"></div>
                            </div>
                            <input type="number" id="exchangeQty" class="form-control" value="1" min="1"
                                style="width: 70px;" aria-label="Exchange quantity">
                            <button type="button" class="btn btn-primary" id="addExchangeBtn"
                                onclick="addExchangeItem()" style="height: 42px;">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>

                        <!-- Selected exchange product indicator -->
                        <div id="exchangeSelectedBox" style="display: none; margin-bottom: 0.75rem;">
                            <div
                                style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; background: var(--success-bg, #ecfdf5); border: 1px solid var(--success, #059669); border-radius: var(--border-radius); font-size: 0.85rem;">
                                <i class="fas fa-check-circle" style="color: var(--success, #059669);"></i>
                                <span style="flex: 1;">
                                    <strong id="exchangeSelectedName"></strong>
                                    <span id="exchangeSelectedPrice" class="text-muted"></span>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline"
                                    onclick="clearExchangeSelection()" title="Clear selection">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <div id="exchangeItemsList">
                            <p class="text-muted" style="font-size: 0.85rem; font-style: italic;">
                                No exchange items added yet.
                            </p>
                        </div>
                    </div>

                    <!-- Summary -->
                    <div
                        style="margin-top: 1rem; padding: 1rem; background: var(--gray-100); border-radius: var(--border-radius);">
                        <div class="pos-cart-row">
                            <span>Return Total</span>
                            <span id="totalRefund" class="text-danger"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                        <div class="pos-cart-row">
                            <span>Exchange Total</span>
                            <span id="totalExchange" class="text-success"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                        <div style="text-align: center; font-size: 0.75rem; color: var(--gray-400); padding: 2px 0;">
                            Exchange − Return = <span id="balanceFormula">0.00</span>
                        </div>
                        <div class="pos-cart-row total"
                            style="font-size: 1.1rem; border-top: 1px solid var(--gray-300); padding-top: 6px;">
                            <span id="balanceLabel">Balance</span>
                            <span id="totalBalance"><?php echo CURRENCY; ?> 0.00</span>
                        </div>
                    </div>

                    <!-- Payment/Refund Method -->
                    <div class="cols-2" style="gap: 1rem; margin-top: 1rem;">
                        <div class="form-group">
                            <label class="form-label required">Refund Method</label>
                            <select name="refund_method" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="bkash">bKash</option>
                                <option value="nagad">Nagad</option>
                                <option value="store_credit">Store Credit</option>
                            </select>
                            <div class="text-muted" style="font-size: 0.78rem; margin-top: 4px;">
                                <?php if ($drawerState['cashIn'] > 0.009): ?>
                                    Cash in hand: <strong><?php echo formatCurrency($drawerState['balance']); ?></strong>
                                    &middot; a larger cash refund is refused; Store Credit moves no cash.
                                <?php else: ?>
                                    This till's cash is not being recorded yet, so any method is accepted.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reason for Return</label>
                            <input type="text" name="reason" class="form-control"
                                placeholder="e.g., Defective, Wrong item">
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
            <button type="button" class="btn btn-danger" id="submitReturnBtn" onclick="submitReturn()"
                style="display: none;">
                <i class="fas fa-exchange-alt"></i> Process Return / Exchange
            </button>
        </div>
    </div>
</div>

<!-- View Return Modal -->
<div class="modal-overlay" id="viewReturnModal">
    <div class="modal" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">Return / Exchange Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="returnDetails">
            <div class="text-center">
                <div class="spinner"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Barcode Return Confirm Modal -->
<div class="modal-overlay" id="barcodeReturnModal">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-undo"></i> Product Return</h3>
            <button class="modal-close" onclick="closeBarcodeReturnModal()">&times;</button>
        </div>
        <div class="modal-body" id="barcodeReturnContent"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeBarcodeReturnModal()">Cancel</button>
            <button class="btn btn-danger" id="barcodeReturnBtn" onclick="confirmBarcodeReturn()">
                <i class="fas fa-undo"></i> Return Now
            </button>
        </div>
    </div>
</div>

<!-- Exchange Barcode Camera Scan Modal -->
<div class="modal-overlay" id="exchangeCameraModal">
    <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-camera"></i> Scan Exchange Product</h3>
            <button class="modal-close" onclick="closeExchangeCamera()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="exchangeCameraReader" style="width: 100%;"></div>
            <p style="text-align: center; margin-top: 10px; color: var(--gray-500); font-size: 0.85rem;">
                Point the camera at the product barcode
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeExchangeCamera()">Close</button>
        </div>
    </div>
</div>

<!-- Receipt Print Modal -->
<?php if ($returnReceipt): ?>
    <div class="modal-overlay active" id="receiptModal">
        <div class="modal" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-receipt"></i>
                    <?php echo $returnReceipt['is_exchange'] ? 'Exchange Receipt' : 'Return Receipt'; ?>
                </h3>
                <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="printableReceipt" style="font-family: 'Hind Siliguri', monospace; font-size: 12px;">
                    <div style="text-align: center; margin-bottom: 1rem;">
                        <h3 style="margin: 0;"><?php echo sanitize($settings['shop_name'] ?? 'POS System'); ?></h3>
                        <p style="margin: 0.25rem 0; font-size: 10px;">
                            <?php echo sanitize($settings['shop_address'] ?? ''); ?></p>
                        <svg id="returnReceiptBarcode"
                            data-barcode="<?php echo sanitize($returnReceipt['sale_invoice']); ?>"
                            style="height: 50px; margin-top: 8px;"></svg>
                    </div>
                    <hr style="border-style: dashed;">
                    <p><strong>Return #:</strong> <?php echo $returnReceipt['return_number']; ?></p>
                    <p><strong>Invoice #:</strong> <?php echo $returnReceipt['sale_invoice']; ?></p>
                    <p><strong>Date:</strong> <?php echo date('d M Y, h:i A'); ?></p>
                    <hr style="border-style: dashed;">

                    <h4 style="margin: 0.5rem 0;">Returned Items</h4>
                    <table style="width: 100%; font-size: 11px;">
                        <thead>
                            <tr>
                                <th style="text-align: left;">Item</th>
                                <th style="text-align: center;">Qty</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($returnReceipt['return_items'] as $ri): ?>
                                <tr>
                                    <td><?php echo sanitize($ri['product_name']); ?></td>
                                    <td style="text-align: center;"><?php echo $ri['quantity']; ?></td>
                                    <td style="text-align: right;"><?php echo formatCurrency($ri['total_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($returnReceipt['exchange_items'])): ?>
                        <hr style="border-style: dashed;">
                        <h4 style="margin: 0.5rem 0; color: #059669;">Exchange Items</h4>
                        <table style="width: 100%; font-size: 11px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Item</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($returnReceipt['exchange_items'] as $ei): ?>
                                    <tr>
                                        <td><?php echo sanitize($ei['product_name']); ?></td>
                                        <td style="text-align: center;"><?php echo $ei['quantity']; ?></td>
                                        <td style="text-align: right;"><?php echo formatCurrency($ei['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <hr style="border-style: dashed;">
                    <table style="width: 100%; font-size: 11px;">
                        <tr>
                            <td>Subtotal (Return)</td>
                            <td style="text-align: right;"><?php echo formatCurrency($returnReceipt['return_total']); ?>
                            </td>
                        </tr>
                        <?php if (!empty($returnReceipt['exchange_items'])): ?>
                            <tr>
                                <td>Subtotal (Exchange)</td>
                                <td style="text-align: right;"><?php echo formatCurrency($returnReceipt['exchange_total']); ?>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="text-align: center; font-size: 10px; padding: 4px 0;">
                                    ───────────────────────────────────────
                                </td>
                            </tr>
                            <tr style="font-weight: bold;">
                                <td>
                                    Exchange − Return =
                                    <?php
                                    $bal = $returnReceipt['balance'];
                                    if ($bal > 0) {
                                        echo '<span class="text-danger">(Customer pays extra)</span>';
                                    } elseif ($bal < 0) {
                                        echo '<span class="text-success">(Customer gets refund)</span>';
                                    } else {
                                        echo '<span>(No balance)</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($bal > 0): ?>
                                        <span class="text-danger">+ <?php echo formatCurrency($bal); ?></span>
                                    <?php elseif ($bal < 0): ?>
                                        <span class="text-success">− <?php echo formatCurrency(abs($bal)); ?></span>
                                    <?php else: ?>
                                        <span>৳ 0.00</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <hr style="border-style: dashed;">
                    <p style="text-align: center; font-size: 10px;">
                        <?php echo sanitize($settings['receipt_footer'] ?? 'Thank you!'); ?></p>
                    <?php if (!empty($settings['voucher_terms'])): ?>
                        <div style="margin-top: 8px; border-top: 1px dashed #000; padding-top: 6px;">
                            <p style="margin: 0 0 2px 0; font-size: 10px; font-weight: bold;">Terms & Conditions</p>
                            <p style="margin: 0; font-size: 9px; white-space: pre-line;">
                                <?php echo sanitize($settings['voucher_terms']); ?></p>
                        </div>
                    <?php endif; ?>
                    </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeReceiptModal()">Close</button>
                <button class="btn btn-primary" onclick="printReceipt()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(assetUrl('assets/js/html5-qrcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<style>
    /* --- Exchange product search / scan combobox --- */
    .exch-search-wrap {
        position: relative;
    }

    .exch-search-wrap .form-control {
        padding-right: 40px;
    }

    .exch-search-wrap.scanner-active .form-control {
        border-color: var(--primary, #2563eb);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
    }

    .exch-scan-btn {
        position: absolute;
        right: 4px;
        top: 50%;
        transform: translateY(-50%);
        width: 34px;
        height: 34px;
        border: none;
        border-radius: var(--border-radius);
        background: transparent;
        color: var(--gray-500);
        cursor: pointer;
        z-index: 2;
    }

    .exch-scan-btn:hover {
        background: var(--gray-100);
        color: var(--primary, #2563eb);
    }

    .exch-dropdown {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 4px);
        max-height: 260px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid var(--gray-300);
        border-radius: var(--border-radius);
        box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
        z-index: 1050;
    }

    .exch-dropdown.open {
        display: block;
    }

    .exch-dropdown-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 0.75rem;
        cursor: pointer;
        border-bottom: 1px solid var(--gray-100);
        font-size: 0.9rem;
    }

    .exch-dropdown-item:last-child {
        border-bottom: none;
    }

    .exch-dropdown-item:hover,
    .exch-dropdown-item.active {
        background: var(--gray-100);
    }

    .exch-dropdown-item .exch-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .exch-dropdown-item .exch-meta {
        color: var(--gray-500);
        font-size: 0.78rem;
        white-space: nowrap;
    }

    .exch-dropdown-item mark {
        background: #fef08a;
        padding: 0;
    }

    .exch-dropdown-empty {
        padding: 0.75rem;
        text-align: center;
        color: var(--gray-500);
        font-size: 0.85rem;
    }
</style>
<script>
    const currency = '<?php echo CURRENCY; ?>';
    const exchangeProductsData = <?php
    // Pre-encode as JSON so product names/barcodes are safe inside the <script> block
    echo json_encode(array_map(function ($p) {
        return [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'barcode' => $p['barcode'] ?: '',
            'sell_price' => (float) $p['sell_price']
        ];
    }, $exchangeProducts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    let exchangeItems = [];
    let pendingBarcodeReturn = null;
    let selectedExchangeProduct = null;
    let exchangeMatches = [];
    let exchangeActiveIndex = -1;
    let html5ExchScanner = null;
    // Fast keystrokes (< 50ms apart) indicate a USB barcode scanner, not manual typing
    const EXCHANGE_SCAN_TIMEOUT = 50;
    let exchangeScanBuffer = '';
    let exchangeLastKeyTime = 0;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const barcode = document.getElementById('returnReceiptBarcode');
        if (barcode && typeof JsBarcode !== 'undefined' && barcode.dataset.barcode) {
            try {
                JsBarcode(barcode, barcode.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                });
            } catch (e) { }
        }
    });

    function openReturnModal() {
        document.getElementById('returnModal').classList.add('active');
        document.getElementById('invoiceSearch').focus();
    }

    // The advanced filters sit in a drawer under the panel head; searching from
    // the collapsed state still applies them, because their inputs stay in the
    // form the whole time.
    function toggleFilters() {
        const more = document.getElementById('fltMore');
        const btn = document.getElementById('fltToggle');
        more.classList.toggle('open');
        btn.classList.toggle('on', more.classList.contains('open'));
    }

    function closeReturnModal() {
        closeExchangeCamera();
        document.getElementById('returnModal').classList.remove('active');
        resetReturn();
    }

    function resetReturn() {
        document.getElementById('step1').style.display = 'block';
        document.getElementById('step2').style.display = 'none';
        document.getElementById('submitReturnBtn').style.display = 'none';
        document.getElementById('invoiceSearch').value = '';
        document.getElementById('invoiceResult').innerHTML = '';
        exchangeItems = [];
        updateExchangeDisplay();
        clearExchangeSelection();
        document.querySelectorAll('.return-qty').forEach(el => el.value = 0);
    }

    async function searchInvoice() {
        const invoice = document.getElementById('invoiceSearch').value.trim();
        if (!invoice) {
            alert('Please enter an invoice number');
            return;
        }

        document.getElementById('invoiceResult').innerHTML = '<div class="text-center"><div class="spinner"></div></div>';

        try {
            const response = await fetch(`api/get-sale-for-return.php?invoice=${encodeURIComponent(invoice)}`);
            const data = await response.json();

            if (data.success) {
                showReturnItems(data.sale);
            } else {
                document.getElementById('invoiceResult').innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${data.message}</div>`;
            }
        } catch (error) {
            document.getElementById('invoiceResult').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error searching invoice</div>';
        }
    }

    function showReturnItems(sale, prefilledItemId) {
        document.getElementById('step1').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
        document.getElementById('submitReturnBtn').style.display = 'inline-flex';
        document.getElementById('returnSaleId').value = sale.id;
        document.getElementById('selectedInvoice').textContent = sale.invoice_number;

        let html = '<table class="table"><thead><tr><th>Product</th><th>Sold Qty</th><th>Return Qty</th><th>Amount</th></tr></thead><tbody>';

        sale.items.forEach(item => {
            const isScanned = prefilledItemId && (parseInt(item.id) === parseInt(prefilledItemId));
            const defaultValue = item.quantity;
            html += `
            <tr>
                <td>${item.product_name}${isScanned ? ' <span class="badge badge-success">Scanned</span>' : ''}</td>
                <td>${item.quantity}</td>
                <td>
                    <input type="number" name="return_items[${item.id}]"
                           class="form-control return-qty"
                           value="${defaultValue}" min="0" max="${item.quantity}"
                           data-price="${item.unit_price}"
                           style="width: 80px;"
                           onchange="calculateTotals()">
                </td>
                <td class="item-refund">${currency} 0.00</td>
            </tr>
        `;
        });

        html += '</tbody></table>';
        document.getElementById('returnItemsList').innerHTML = html;
        exchangeItems = [];
        clearExchangeSelection();
        updateExchangeDisplay();
        calculateTotals();
    }

    // ===== Exchange product search / scan =====
    function getExchangeElements() {
        return {
            wrap: document.getElementById('exchangeSearchWrap') || document.querySelector('.exch-search-wrap'),
            input: document.getElementById('exchangeSearch'),
            dropdown: document.getElementById('exchangeDropdown'),
            qty: document.getElementById('exchangeQty'),
            addBtn: document.getElementById('addExchangeBtn'),
            selectedBox: document.getElementById('exchangeSelectedBox'),
            selectedName: document.getElementById('exchangeSelectedName'),
            selectedPrice: document.getElementById('exchangeSelectedPrice')
        };
    }

    function findProductByBarcode(barcode) {
        const code = (barcode || '').trim().toLowerCase();
        if (code.length < 3) return null;
        return exchangeProductsData.find(p => {
            if (!p.barcode) return false;
            return String(p.barcode).trim().toLowerCase() === code;
        }) || null;
    }

    // Type-ahead search over product name and barcode
    function filterExchangeProducts(query) {
        const q = (query || '').trim().toLowerCase();
        if (!q) return exchangeProductsData.slice(0, 50);

        const digits = q.replace(/\D/g, '');
        return exchangeProductsData.filter(p => {
            if (String(p.name).toLowerCase().includes(q)) return true;
            if (p.barcode && String(p.barcode).toLowerCase().includes(q)) return true;
            if (digits && p.barcode && String(p.barcode).replace(/\D/g, '').includes(digits)) return true;
            return false;
        }).slice(0, 50);
    }

    function highlightMatch(text, query) {
        const safe = escapeHtml(text);
        const q = (query || '').trim();
        if (!q) return safe;
        const idx = String(text).toLowerCase().indexOf(q.toLowerCase());
        if (idx === -1) return safe;
        return escapeHtml(String(text).slice(0, idx)) +
            '<mark>' + escapeHtml(String(text).slice(idx, idx + q.length)) + '</mark>' +
            escapeHtml(String(text).slice(idx + q.length));
    }

    function renderExchangeDropdown(query) {
        const { dropdown, input } = getExchangeElements();
        if (!dropdown) return;

        exchangeMatches = filterExchangeProducts(query);
        exchangeActiveIndex = -1;

        if (exchangeMatches.length === 0) {
            dropdown.innerHTML = '<div class="exch-dropdown-empty">No products found</div>';
        } else {
            const q = (query || '').trim();
            dropdown.innerHTML = exchangeMatches.map((p, i) => `
                <div class="exch-dropdown-item" role="option" data-index="${i}" data-id="${p.id}">
                    <span class="exch-name">${highlightMatch(p.name, q)}</span>
                    <span class="exch-meta">
                        ${p.barcode ? 'BC: ' + escapeHtml(String(p.barcode)) + ' &middot; ' : ''}
                        ${currency} ${Number(p.sell_price).toFixed(2)}
                    </span>
                </div>`).join('');
        }

        dropdown.classList.add('open');
        if (input) input.setAttribute('aria-expanded', 'true');
    }

    function closeExchangeDropdown() {
        const { dropdown, input } = getExchangeElements();
        if (dropdown) dropdown.classList.remove('open');
        if (input) input.setAttribute('aria-expanded', 'false');
        exchangeActiveIndex = -1;
    }

    function setExchangeSelection(product, options) {
        if (!product) return false;
        const opts = options || {};
        const { input, selectedBox, selectedName, selectedPrice, addBtn } = getExchangeElements();

        selectedExchangeProduct = {
            id: parseInt(product.id),
            name: product.name,
            sell_price: parseFloat(product.sell_price) || 0
        };

        if (input && !opts.keepQuery) input.value = '';
        if (selectedName) selectedName.textContent = selectedExchangeProduct.name;
        if (selectedPrice) selectedPrice.textContent = '— ' + currency + ' ' + selectedExchangeProduct.sell_price.toFixed(2);
        if (selectedBox) selectedBox.style.display = 'flex';
        if (addBtn) addBtn.disabled = false;
        closeExchangeDropdown();

        // Auto-add straight away when triggered by a scan
        if (opts.autoAdd) {
            addExchangeItem();
        }
        return true;
    }

    function clearExchangeSelection() {
        const { input, selectedBox, addBtn } = getExchangeElements();
        selectedExchangeProduct = null;
        if (input) input.value = '';
        if (selectedBox) selectedBox.style.display = 'none';
        if (addBtn) addBtn.disabled = true;
        closeExchangeDropdown();
    }

    function selectExchangeByIndex(index) {
        if (index < 0 || index >= exchangeMatches.length) return;
        const product = exchangeMatches[index];
        setExchangeSelection(product);
        const { qty, input } = getExchangeElements();
        if (qty) qty.focus();
        else if (input) input.focus();
    }

    // Handle a scanned / entered code: exact barcode match wins, otherwise show matches
    function handleExchangeCode(code) {
        const { input } = getExchangeElements();
        const value = (code !== undefined ? code : (input ? input.value : '')).trim();
        if (!value) return;

        const exact = findProductByBarcode(value);
        if (exact) {
            setExchangeSelection(exact, { autoAdd: true });
            return;
        }

        // Not a barcode - fall back to a name/code search and let the user pick
        if (input) input.value = value;
        renderExchangeDropdown(value);
    }

    function addExchangeItem() {
        const { qty } = getExchangeElements();

        if (!selectedExchangeProduct) {
            // Fall back to whatever is typed in the search box (exact barcode only)
            const { input } = getExchangeElements();
            const exact = input ? findProductByBarcode(input.value) : null;
            if (exact) {
                setExchangeSelection(exact);
            } else {
                alert('Please select a product');
                if (input) input.focus();
                return;
            }
        }

        const productId = selectedExchangeProduct.id;
        const qtyValue = parseInt(qty ? qty.value : 1) || 1;
        if (qtyValue < 1) {
            alert('Invalid quantity');
            return;
        }

        const price = selectedExchangeProduct.sell_price;
        if (isNaN(price) || price < 0) {
            alert('Invalid product price');
            return;
        }

        // Merge if the same product is already in the exchange list
        const existing = exchangeItems.findIndex(item => item.product_id === productId);
        if (existing > -1) {
            exchangeItems[existing].quantity += qtyValue;
            exchangeItems[existing].total_price = exchangeItems[existing].quantity * price;
        } else {
            exchangeItems.push({
                product_id: productId,
                product_name: selectedExchangeProduct.name,
                quantity: qtyValue,
                unit_price: price,
                total_price: qtyValue * price
            });
        }

        updateExchangeDisplay();
        calculateTotals();

        if (qty) qty.value = 1;
        clearExchangeSelection();
        const { input } = getExchangeElements();
        if (input) input.focus();
    }

    function removeExchangeItem(index) {
        exchangeItems.splice(index, 1);
        updateExchangeDisplay();
        calculateTotals();
    }

    function updateExchangeDisplay() {
        const container = document.getElementById('exchangeItemsList');
        if (!container) return;
        if (exchangeItems.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size: 0.85rem; font-style: italic;">No exchange items added yet.</p>';
            return;
        }

        let html = '<table class="table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead><tbody>';
        exchangeItems.forEach((item, index) => {
            const up = isNaN(item.unit_price) ? 0 : item.unit_price;
            const tp = isNaN(item.total_price) ? 0 : item.total_price;
            html += `
            <tr>
                <td>${item.product_name || ''}</td>
                <td>${item.quantity || 0}</td>
                <td>${currency} ${up.toFixed(2)}</td>
                <td>${currency} ${tp.toFixed(2)}</td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeExchangeItem(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function calculateTotals() {
        let returnTotal = 0;
        document.querySelectorAll('.return-qty').forEach(input => {
            const qty = parseInt(input.value) || 0;
            const price = parseFloat(input.dataset.price) || 0;
            const itemTotal = qty * price;
            returnTotal += itemTotal;
            const el = input.closest('tr').querySelector('.item-refund');
            if (el) el.textContent = `${currency} ${itemTotal.toFixed(2)}`;
        });

        let exchangeTotal = 0;
        exchangeItems.forEach(item => {
            const tp = isNaN(item.total_price) ? 0 : item.total_price;
            exchangeTotal += tp;
        });

        const balance = exchangeTotal - returnTotal;
        const totalExchangeEl = document.getElementById('totalExchange');
        const totalRefundEl = document.getElementById('totalRefund');
        const balanceEl = document.getElementById('totalBalance');
        const formulaEl = document.getElementById('balanceFormula');
        const balanceLabel = document.getElementById('balanceLabel');

        if (totalRefundEl) totalRefundEl.textContent = `${currency} ${returnTotal.toFixed(2)}`;
        if (totalExchangeEl) totalExchangeEl.textContent = `${currency} ${exchangeTotal.toFixed(2)}`;

        if (formulaEl) {
            formulaEl.textContent = `${currency} ${exchangeTotal.toFixed(2)} - ${currency} ${returnTotal.toFixed(2)} = ${balance >= 0 ? '+' : ''}${currency} ${balance.toFixed(2)}`;
        }

        if (balance > 0) {
            if (balanceEl) {
                balanceEl.textContent = `${currency} ${balance.toFixed(2)} (Customer pays extra)`;
                balanceEl.style.color = 'var(--danger)';
            }
            if (balanceLabel) balanceLabel.textContent = 'Due from Customer';
        } else if (balance < 0) {
            if (balanceEl) {
                balanceEl.textContent = `${currency} ${Math.abs(balance).toFixed(2)} (Refund to Customer)`;
                balanceEl.style.color = 'var(--success)';
            }
            if (balanceLabel) balanceLabel.textContent = 'Customer Gets Refund';
        } else {
            if (balanceEl) {
                balanceEl.textContent = `${currency} 0.00 (No balance)`;
                balanceEl.style.color = '';
            }
            if (balanceLabel) balanceLabel.textContent = 'Balance';
        }
    }

    function submitReturn() {
        let hasItems = false;
        const qtyInputs = document.querySelectorAll('.return-qty');

        qtyInputs.forEach(input => {
            if (parseInt(input.value) > 0) hasItems = true;
        });

        // Auto-fill remaining quantities from the sale if none were entered
        if (!hasItems && qtyInputs.length > 0) {
            qtyInputs.forEach(input => {
                input.value = parseInt(input.max) || 0;
            });
            calculateTotals();
            hasItems = qtyInputs.length > 0;
        }

        if (!hasItems) {
            alert('Please select at least one item to return');
            return;
        }

        // Serialize exchange items
        document.getElementById('exchangeProductsInput').value = JSON.stringify(exchangeItems);

        const hasExchange = exchangeItems.length > 0;
        const msg = hasExchange
            ? 'Are you sure you want to process this exchange? Returned items stock will be restored and exchange items stock will be deducted.'
            : 'Are you sure you want to process this return? Stock will be automatically updated.';

        if (confirm(msg)) {
            document.getElementById('returnForm').submit();
        }
    }

    async function viewReturn(returnId) {
        document.getElementById('viewReturnModal').classList.add('active');

        try {
            const response = await fetch(`api/get-return-details.php?id=${returnId}`);
            const data = await response.json();

            if (data.success) {
                const r = data.return;
                let html = `
                <p><strong>Return #:</strong> ${r.return_number}</p>
                <p><strong>Invoice #:</strong> ${r.invoice_number}</p>
                <p><strong>Date:</strong> ${r.date}</p>
                <p><strong>Reason:</strong> ${r.reason || 'Not specified'}</p>
                <hr>
                <h4>Returned Items</h4>
                <table class="table">
                    <thead><tr><th>Product</th><th>Qty</th><th>Amount</th></tr></thead>
                    <tbody>
                        ${r.items.map(item => `
                            <tr>
                                <td>${item.product_name}</td>
                                <td>${item.quantity}</td>
                                <td>${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;

                // Show exchange items if present
                if (r.exchange_items && r.exchange_items.length > 0) {
                    html += `
                    <hr>
                    <h4 style="color: var(--success);">Exchange Items</h4>
                    <table class="table">
                        <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                        <tbody>
                            ${r.exchange_items.map(item => `
                                <tr>
                                    <td>${item.product_name}</td>
                                    <td>${item.quantity}</td>
                                    <td>${currency} ${parseFloat(item.unit_price).toFixed(2)}</td>
                                    <td>${currency} ${parseFloat(item.total_price).toFixed(2)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
                }

                html += `<hr>
                <p><strong>Total Refund:</strong> <span class="text-danger">${currency} ${parseFloat(r.total_amount).toFixed(2)}</span></p>`;

                if (r.exchange_balance && parseFloat(r.exchange_balance) !== 0) {
                    const bal = parseFloat(r.exchange_balance);
                    if (bal > 0) {
                        html += `<p><strong>Customer Pays Extra:</strong> <span class="text-danger">${currency} ${bal.toFixed(2)}</span></p>`;
                    } else {
                        html += `<p><strong>Customer Gets Refund:</strong> <span class="text-success">${currency} ${Math.abs(bal).toFixed(2)}</span></p>`;
                    }
                }

                html += `<p><strong>Refund Method:</strong> ${r.refund_method.toUpperCase()}</p>`;
                document.getElementById('returnDetails').innerHTML = html;
            }
        } catch (error) {
            document.getElementById('returnDetails').innerHTML = '<p class="text-danger">Error loading details</p>';
        }
    }

    function closeViewModal() {
        document.getElementById('viewReturnModal').classList.remove('active');
    }

    // Receipt print functions
    function closeReceiptModal() {
        const el = document.getElementById('receiptModal');
        if (el) el.classList.remove('active');
    }

    function printReceipt() {
        const content = document.getElementById('printableReceipt');
        if (!content) return;
        const win = window.open('', '_blank');
        win.document.write(`
        <html><head><title>Receipt</title>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
        <style>
            body { font-family: 'Hind Siliguri', monospace; font-size: 12px; margin: 0; padding: 10px; }
            #returnReceiptBarcode { height: 50px; }
            hr { border-style: dashed; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 2px 0; }
        </style>
        <script src="<?php echo htmlspecialchars(assetUrl('assets/js/jsbarcode.min.js'), ENT_QUOTES, 'UTF-8'); ?>"><\/script>
        </head>
        <body>${content.innerHTML}</body></html>
        `);
        win.document.close();
        win.onload = function () {
            const barcode = win.document.getElementById('returnReceiptBarcode');
            if (barcode && typeof win.JsBarcode !== 'undefined' && barcode.dataset.barcode) {
                try {
                    win.JsBarcode(barcode, barcode.dataset.barcode, {
                    format: 'CODE128',
                    width: 1.5,
                    height: 50,
                    displayValue: false
                    });
                } catch (e) { }
            }
            win.print();
            win.close();
        };
    }

    // Close receipt modal on overlay click
    const receiptModal = document.getElementById('receiptModal');
    if (receiptModal) {
        receiptModal.addEventListener('click', function (e) {
            if (e.target === this) closeReceiptModal();
        });
    }

    // Exchange product combobox wiring (search + barcode scan)
    const exchEls = getExchangeElements();

    if (exchEls.addBtn) exchEls.addBtn.disabled = true;

    if (exchEls.input) {
        // Typing filters the list (USB scanner keystrokes land here too)
        exchEls.input.addEventListener('input', function () {
            clearExchangeSelectionKeepingQuery();
            renderExchangeDropdown(this.value);
        });

        exchEls.input.addEventListener('focus', function () {
            renderExchangeDropdown(this.value);
        });

        // Enter = exact barcode match (adds immediately) or accept the highlighted match
        exchEls.input.addEventListener('keydown', function (e) {
            const now = Date.now();
            if (now - exchangeLastKeyTime > EXCHANGE_SCAN_TIMEOUT) {
                exchangeScanBuffer = '';
            }
            exchangeLastKeyTime = now;

            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopPropagation();
                const buffer = exchangeScanBuffer;
                exchangeScanBuffer = '';

                if (exchangeActiveIndex > -1) {
                    selectExchangeByIndex(exchangeActiveIndex);
                    return;
                }
                if (buffer.length >= 3) {
                    // Fast burst of characters => treat as a scan, don't show the list
                    handleExchangeCode(buffer);
                } else {
                    handleExchangeCode(this.value);
                }
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!exchangeMatches.length) return;
                exchangeActiveIndex += (e.key === 'ArrowDown' ? 1 : -1);
                if (exchangeActiveIndex < 0) exchangeActiveIndex = 0;
                if (exchangeActiveIndex >= exchangeMatches.length) exchangeActiveIndex = exchangeMatches.length - 1;
                highlightExchangeActive();
                return;
            }

            if (e.key === 'Escape') {
                closeExchangeDropdown();
                return;
            }

            if (e.key.length === 1) {
                exchangeScanBuffer += e.key;
            }
        });

        // Keep the buffer in sync so Enter can distinguish a scan from a short search
        exchEls.input.addEventListener('keyup', function (e) {
            if (e.key.length === 1) {
                exchangeScanBuffer = this.value;
            }
        });

        // Click a suggestion
        if (exchEls.dropdown) {
            exchEls.dropdown.addEventListener('mousedown', function (e) {
                const item = e.target.closest('.exch-dropdown-item');
                if (!item) return;
                e.preventDefault();
                selectExchangeByIndex(parseInt(item.dataset.index));
            });
        }
    }

    // Clear dropdown + Add button but keep what the user typed
    function clearExchangeSelectionKeepingQuery() {
        const { selectedBox, addBtn } = getExchangeElements();
        selectedExchangeProduct = null;
        if (selectedBox) selectedBox.style.display = 'none';
        if (addBtn) addBtn.disabled = true;
        closeExchangeDropdown();
    }

    function highlightExchangeActive() {
        const { dropdown } = getExchangeElements();
        if (!dropdown) return;
        dropdown.querySelectorAll('.exch-dropdown-item').forEach((el, i) => {
            el.classList.toggle('active', i === exchangeActiveIndex);
            if (i === exchangeActiveIndex) el.scrollIntoView({ block: 'nearest' });
        });
    }

    // Close the suggestion list when clicking outside the search box
    document.addEventListener('click', function (e) {
        if (!exchEls.wrap) return;
        if (!exchEls.wrap.contains(e.target)) closeExchangeDropdown();
    });

    // Qty box Enter also adds the selected product
    if (exchEls.qty) {
        exchEls.qty.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addExchangeItem();
            }
        });
    }

    // Camera barcode scan for exchange products
    const exchangeScanBtn = document.getElementById('exchangeScanBtn');
    if (exchangeScanBtn) {
        exchangeScanBtn.addEventListener('click', openExchangeCamera);
    }

    function openExchangeCamera() {
        const modal = document.getElementById('exchangeCameraModal');
        const reader = document.getElementById('exchangeCameraReader');
        if (!modal || !reader) return;
        modal.classList.add('active');
        reader.innerHTML = '';
        if (typeof Html5Qrcode === 'undefined') {
            alert('Camera scanner library failed to load');
            modal.classList.remove('active');
            return;
        }
        try {
            html5ExchScanner = new Html5Qrcode('exchangeCameraReader');
            html5ExchScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 150 } },
                function (decodedText) {
                    closeExchangeCamera();
                    handleExchangeCode(decodedText.trim());
                },
                function () { }
            ).catch(function (err) {
                alert('Camera error: ' + (err && err.message ? err.message : err));
                modal.classList.remove('active');
            });
        } catch (e) {
            alert('Camera error: ' + e.message);
            modal.classList.remove('active');
        }
    }

    function closeExchangeCamera() {
        const modal = document.getElementById('exchangeCameraModal');
        if (modal) modal.classList.remove('active');
        if (html5ExchScanner) {
            try {
                html5ExchScanner.stop().then(function () { html5ExchScanner.clear(); }).catch(function () { });
            } catch (e) { }
            html5ExchScanner = null;
        }
    }

    // Close modals on overlay click
    document.getElementById('returnModal').addEventListener('click', function (e) {
        if (e.target === this) closeReturnModal();
    });
    document.getElementById('viewReturnModal').addEventListener('click', function (e) {
        if (e.target === this) closeViewModal();
    });
    document.getElementById('barcodeReturnModal').addEventListener('click', function (e) {
        if (e.target === this) closeBarcodeReturnModal();
    });
    const exchangeCameraModal = document.getElementById('exchangeCameraModal');
    if (exchangeCameraModal) {
        exchangeCameraModal.addEventListener('click', function (e) {
            if (e.target === this) closeExchangeCamera();
        });
    }

    // Handle Enter key in search
    document.getElementById('invoiceSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchInvoice();
        }
    });

    // USB Barcode Scanner Support for product barcodes
    let productBarcodeBuffer = '';
    let lastScanTime = 0;
    let isScannerTyping = false;

    document.addEventListener('keydown', function (e) {
        const returnModalOpen = document.getElementById('returnModal').classList.contains('active');
        const activeEl = document.activeElement;

        // Skip when the exchange product search dropdown owns the keyboard
        // (typing a search, arrow-key selection, or scanning an exchange barcode)
        if (activeEl === document.getElementById('exchangeSearch')) return;
        if (exchEls.wrap && activeEl && exchEls.wrap.contains(activeEl)) return;
        if (exchEls.qty === activeEl) return;

        // When modal is closed, only intercept scans if focus is not on a text input
        if (!returnModalOpen && activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA')) return;

        const now = Date.now();
        const dt = now - lastScanTime;
        lastScanTime = now;

        if (e.key === 'Enter') {
            const barcode = productBarcodeBuffer.trim();
            productBarcodeBuffer = '';
            isScannerTyping = false;

            if (barcode.length >= 3) {
                e.preventDefault();
                e.stopImmediatePropagation();

                if (barcode.toUpperCase().startsWith('INV-')) {
                    document.getElementById('invoiceSearch').value = barcode;
                    searchInvoice();
                } else {
                    lookupSaleByBarcode(barcode);
                }
            }
            return;
        }

        if (dt > 50) {
            productBarcodeBuffer = '';
            isScannerTyping = false;
        }

        if (e.key.length === 1) {
            if (dt <= 50) {
                isScannerTyping = true;
                e.preventDefault();
            }
            productBarcodeBuffer += e.key;
        }
    }, true);

    async function lookupSaleByBarcode(barcode) {
        try {
            const response = await fetch(`api/get-sale-by-barcode.php?barcode=${encodeURIComponent(barcode)}`);
            const data = await response.json();

            if (!data.success) {
                alert(data.message || 'Product not found');
                return;
            }

            const sale = data.sale;
            const scannedItem = data.scanned_item_id
                ? sale.items.find(i => parseInt(i.id) === parseInt(data.scanned_item_id))
                : null;
            if (!scannedItem) {
                alert(data.message || 'This product has already been fully returned from its latest sale');
                return;
            }

            const qty = parseInt(scannedItem.quantity) || 0;
            const unitPrice = parseFloat(scannedItem.unit_price) || 0;
            const total = qty * unitPrice;

            pendingBarcodeReturn = {
                barcode: barcode,
                invoice: sale.invoice_number,
                product: scannedItem.product_name,
                quantity: qty,
                total: total
            };

            document.getElementById('barcodeReturnContent').innerHTML = `
                <p><strong>Invoice:</strong> ${escapeHtml(sale.invoice_number)}</p>
                <p><strong>Date:</strong> ${escapeHtml(sale.date)}</p>
                <hr>
                <table class="table">
                    <thead><tr><th>Product</th><th>Qty</th><th>Amount</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>${escapeHtml(scannedItem.product_name)}</td>
                            <td>${qty}</td>
                            <td>${currency} ${total.toFixed(2)}</td>
                        </tr>
                    </tbody>
                </table>
            `;
            document.getElementById('barcodeReturnBtn').disabled = false;
            document.getElementById('barcodeReturnBtn').innerHTML = '<i class="fas fa-undo"></i> Return Now';
            document.getElementById('barcodeReturnModal').classList.add('active');
        } catch (error) {
            console.error('Error finding sale by barcode:', error);
            alert('Error scanning product barcode');
        }
    }

    function closeBarcodeReturnModal() {
        document.getElementById('barcodeReturnModal').classList.remove('active');
        pendingBarcodeReturn = null;
    }

    async function confirmBarcodeReturn() {
        if (!pendingBarcodeReturn) return;

        const btn = document.getElementById('barcodeReturnBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

        try {
            const response = await fetch(`api/process-barcode-return.php?barcode=${encodeURIComponent(pendingBarcodeReturn.barcode)}`);
            const data = await response.json();

            if (data.success) {
                document.getElementById('invoiceSearch').value = '';
                document.getElementById('invoiceResult').innerHTML = '';
                location.reload();
            } else {
                alert(data.message || 'Return failed');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-undo"></i> Return Now';
            }
        } catch (error) {
            console.error('Error processing return:', error);
            alert('Error processing return');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo"></i> Return Now';
        }
    }

    // Auto-open modal if invoice parameter is present
    document.addEventListener('DOMContentLoaded', function () {
        const urlParams = new URLSearchParams(window.location.search);
        const invoiceParam = urlParams.get('invoice');
        if (invoiceParam) {
            document.getElementById('invoiceSearch').value = invoiceParam;
            openReturnModal();
            searchInvoice();
        }
    });
</script>

<?php include 'includes/footer.php'; ?>