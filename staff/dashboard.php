<?php
/**
 * POS System - Staff Dashboard
 * Shows the logged-in staff member their salary and payment information
 */

require_once'../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

$user = getCurrentUser();

if ($user['role'] !== 'staff') {
    redirect('../admin/dashboard.php');
}

define('PAGE_TITLE', 'My Dashboard');

$db = getDB();
$owner_id = $user['owner_id'] ?? $user['id'];

// Find the staff record linked to this login account
$stmt = $db->prepare("SELECT * FROM staff WHERE user_id = ? AND owner_id = ? LIMIT 1");
$stmt->execute([$user['id'], $owner_id]);
$member = $stmt->fetch();

if (!$member) {
    // An account with the staff role but no staff record - made on the Users page
    // rather than the Staff page. This page is that person's own payroll view and
    // has nothing to show for them.
    //
    // It used to greet them with an error and leave them there, and when no
    // permission at all was ticked it had nowhere to send them either. Now it keeps
    // the menu, so whatever this role can open is one click away, and says plainly
    // what is missing and who can add it. Redirecting away was what made this
    // confusing: you land somewhere that is not the page you asked about.
    $landing = defaultLandingPage();
    $canSeeApp = ($landing !== null);
    include'includes/header.php';
    ?>
    <div class="alert alert-warning">
        <i class="fas fa-user-clock"></i>
        <span>
            <strong>Apnar staff profile nai.</strong>
            Apnar account ache, kintu Staff page e apnar naam add hoy nai - tai
            ei page e apnar salary, bonus ba payment history dekhay na.
        </span>
    </div>

    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-body">
            <h3 style="margin-top:0;"><i class="fas fa-user-plus"></i> Ki korte hobe</h3>
            <p>Apnar admin ke bolun<strong>Staff</strong> page e apnar email diye
                apnake add korte. Ei page e thik ei jaygay apni staff hisebe listed hobe.</p>
            <p class="text-muted" style="margin-bottom:0;">
                Staff page theke add korle apnar account-ei link hoye jabe - notun
                password banate holei hobe na.
            </p>
        </div>
    </div>

    <?php if ($canSeeApp): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted" style="margin-bottom:0.6rem;">
                Apni ei jinish gulo korte paren, side menu te dekhchen:
            </p>
            <a href="../admin/<?php echo $landing; ?>" class="btn btn-primary">
                <i class="fas fa-arrow-right"></i> <?php echo sanitize(ucfirst(str_replace('.php', '', $landing))); ?>
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted" style="margin-bottom:0;">
                Apnar role e ekhon kono permission nai, tai ei app er kono page
                khola jay na. Admin ke Roles &amp; Permissions page e apnar role er
                tick box gulo dekhate bolun.
            </p>
        </div>
    </div>
    <?php endif; ?>
    <?php
    include'includes/footer.php';
    exit;
}

// Payment history
$stmt = $db->prepare("SELECT * FROM staff_payments WHERE staff_id = ? ORDER BY payment_date DESC, id DESC");
$stmt->execute([$member['id']]);
$payments = $stmt->fetchAll();

// Period boundaries
$monthStart = date;
$monthEnd = date;
$weekStart = date('Y-m-d', strtotime);
$weekEnd = date('Y-m-d', strtotime);

// Period totals
$stmt = $db->prepare("SELECT
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ? AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month,
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ? AND sp.payment_date BETWEEN ? AND ?), 0) as paid_week,
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ?), 0) as total_paid");
$stmt->execute([$member['id'], $monthStart, $monthEnd, $member['id'], $weekStart, $weekEnd, $member['id']]);
$totals = $stmt->fetch();

// Monthly aggregate (earned / bonus / advance for current month)
$stmt = $db->prepare("SELECT
    COALESCE(SUM(earned_amount),0) as m_earned,
    COALESCE(SUM(bonus),0) as m_bonus,
    COALESCE(SUM(advance_deduction),0) as m_advance,
    COALESCE(SUM(amount),0) as m_net
    FROM staff_payments WHERE staff_id = ? AND payment_date BETWEEN ? AND ?");
$stmt->execute([$member['id'], $monthStart, $monthEnd]);
$monthAgg = $stmt->fetch();

// Monthly summary (last 12 months) for the mini chart
$stmt = $db->prepare("SELECT DATE_FORMAT(payment_date, '%Y-%m') as ym, SUM(amount) as total
    FROM staff_payments WHERE staff_id = ? GROUP BY ym ORDER BY ym DESC LIMIT 12");
$stmt->execute([$member['id']]);
$monthRows = $stmt->fetchAll();
$monthRows = array_reverse($monthRows);

// Current daily rate (monthly = 30 days, weekly = 7 days)
$totalDays = $member['salary_type'] === 'weekly' ? 7 : 30;
$dailyRate = $totalDays> 0 ? round((float)$member['salary'] / $totalDays, 2) : 0;

$salaryType = $member['salary_type'] === 'weekly' ? 'Weekly' : 'Monthly';
$periodLabel = $member['salary_type'] === 'weekly' ? 'This Week' : 'This Month';
$paidPeriod = $member['salary_type'] === 'weekly' ? (float)$totals['paid_week'] : (float)$totals['paid_month'];
$due = max(0, (float)$member['salary'] - $paidPeriod);

// Latest payment breakdown
$latest = !empty($payments) ? $payments[0] : null;

// Paid percentage for progress bar
$payPct = (float)$member['salary'] > 0 ? min(100, round(($paidPeriod / (float)$member['salary']) * 100)) : 0;

include'includes/header.php';
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/dashboard.css'), ENT_QUOTES, 'UTF-8'); ?>">
<style>
    /*
     * Only what is this page's own. The hero, the KPI row, the panels and the
     * chart all come from dashboard.css, which the admin dashboard uses too - that
     * file used to be a copy of the admin page's<style> block, and having a second
     * copy here is what let the two drift apart.
     */

    /* A thin bar showing how much of this period's salary has been paid. */
    .salary-progress {
        height: 12px;
        background: var(--gray-100);
        border-radius: 999px;
        overflow: hidden;
        margin: 1.25rem 0 0.6rem;
    }

    .salary-progress-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #10b981, #34d399);
        transition: width .4s ease;
    }

    .progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 0.8rem;
        color: var(--gray-600);
        font-weight: 600;
    }

    /* The six figures under the progress bar. */
    .salary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.85rem;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--gray-100);
    }

    .salary-item .si-label {
        font-size: 0.72rem;
        color: var(--gray-500);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .salary-item .si-value {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--gray-800);
        margin-top: 0.2rem;
    }

    /* Latest payout, as a left-to-right flow of figures. */
    .payout-flow {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 0.75rem;
    }

    .flow-item {
        background: var(--gray-50);
        border: 1px solid var(--gray-100);
        border-radius: 10px;
        padding: 0.85rem 0.75rem;
        text-align: center;
    }

    .flow-item .fi-icon {
        font-size: 1.15rem;
        line-height: 1;
        margin-bottom: 0.4rem;
    }

    .flow-item .fi-label {
        font-size: 0.68rem;
        color: var(--gray-500);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .flow-item .fi-value {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--gray-800);
        margin-top: 0.25rem;
    }

    .flow-plus { background: #ecfdf5; border-color: #d1fae5; }
    .flow-plus .fi-value { color: #059669; }
    .flow-minus { background: #fef2f2; border-color: #fee2e2; }
    .flow-minus .fi-value { color: #dc2626; }
    .flow-total { background: #eff6ff; border-color: #dbeafe; }
    .flow-total .fi-value { color: #1d4ed8; font-size: 1.1rem; }

    /*
     * The shared chart paints the current column with a .today class; this page
     * marks it .cur, so the accent is declared here rather than changing the
     * shared file's name for one caller.
     */
    .chart-col.cur .chart-bar { background: linear-gradient(180deg, #4f46e5, #6366f1); }
    .chart-col.zero .chart-bar { background: var(--gray-200); }
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

<!-- Hero -->
<div class="dash-hero">
    <div class="dash-hero-top">
        <div>
            <div class="dash-hero-title">My Earnings</div>
            <div class="dash-hero-sub">
                <i class="fas fa-user-tie"></i>
                <?php echo sanitize($member['name']); ?>
                &middot; <?php echo sanitize($member['designation'] ?: 'Staff Member'); ?>
                &middot; <?php echo $salaryType; ?> salary</div>
        </div>
        <span class="dash-hero-date">
            <i class="far fa-calendar-alt"></i> <?php echo date; ?>
        </span>
    </div>
    <div class="dash-hero-bottom">
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-wallet"></i> Base Salary</div>
            <div class="hero-kpi-value" style="color:#4ade80;"><?php echo formatCurrency($member['salary']); ?></div>
            <div class="hero-kpi-sub">per<?php echo strtolower($salaryType); ?> period</div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-calculator"></i> Daily Rate</div>
            <div class="hero-kpi-value"><?php echo formatCurrency($dailyRate); ?></div>
            <div class="hero-kpi-sub">Salary ÷ <?php echo $totalDays; ?> days</div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-check-circle"></i> Paid<?php echo $periodLabel; ?></div>
            <div class="hero-kpi-value"><?php echo formatCurrency($paidPeriod); ?></div>
            <div class="hero-kpi-sub"><?php echo $payPct; ?>% of salary</div>
        </div>
        <div class="hero-kpi">
            <div class="hero-kpi-label"><i class="fas fa-hourglass-half"></i> Due<?php echo $periodLabel; ?></div>
            <div class="hero-kpi-value" style="<?php echo $due> 0 ? 'color:#f87171;' : ''; ?>"><?php echo formatCurrency($due); ?></div>
            <div class="hero-kpi-sub"><?php echo $due> 0 ? 'Salary still pending' : 'All paid up'; ?></div>
        </div>
    </div>
</div>

<!-- Modern Stat Cards -->
<div class="m-stats">
    <div class="m-stat">
        <div class="m-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="m-label">Paid<?php echo $periodLabel; ?></div>
            <div class="m-value"><?php echo formatCurrency($paidPeriod); ?></div>
            <div class="m-sub">Salary already received</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon rose"><i class="fas fa-hourglass-half"></i></div>
        <div>
            <div class="m-label">Due<?php echo $periodLabel; ?></div>
            <div class="m-value"><?php echo formatCurrency($due); ?></div>
            <div class="m-sub"><?php echo $due> 0 ? 'Salary still pending' : 'Fully paid'; ?></div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon blue"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="m-label">Total Received</div>
            <div class="m-value"><?php echo formatCurrency($totals['total_paid']); ?></div>
            <div class="m-sub">All time</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon indigo"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
            <div class="m-label">Total Payments</div>
            <div class="m-value"><?php echo count($payments); ?></div>
            <div class="m-sub">Payment records</div>
        </div>
    </div>
</div>

<!-- Salary Progress -->
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-chart-line" style="color:#10b981; margin-right:0.5rem;"></i><?php echo $salaryType; ?> Salary Progress</h3>
        <span style="font-size:0.8rem; color:#64748b;"><?php echo $payPct; ?>% of<?php echo formatCurrency($member['salary']); ?></span>
    </div>
    <div class="salary-progress">
        <div class="salary-progress-fill" style="width: <?php echo $payPct; ?>%;"></div>
    </div>
    <div class="progress-labels">
        <span>Paid: <?php echo formatCurrency($paidPeriod); ?></span>
        <span>Due: <?php echo formatCurrency($due); ?></span>
    </div>

    <div class="salary-grid">
        <div class="salary-item">
            <div class="si-label">Working Days</div>
            <div class="si-value"><?php echo isset($latest['working_days']) && $latest['working_days'] ? $latest['working_days'] . ' / ' . $latest['total_days'] : '-'; ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Daily Rate</div>
            <div class="si-value"><?php echo $dailyRate ? formatCurrency($dailyRate) : '-'; ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Earned (<?php echo date; ?>)</div>
            <div class="si-value"><?php echo formatCurrency($monthAgg['m_earned'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Bonus (<?php echo date; ?>)</div>
            <div class="si-value" style="color:#059669;"><?php echo formatCurrency($monthAgg['m_bonus'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Advance (<?php echo date; ?>)</div>
            <div class="si-value" style="color:#dc2626;"><?php echo formatCurrency($monthAgg['m_advance'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Net Received (<?php echo date; ?>)</div>
            <div class="si-value" style="color:#059669;"><?php echo formatCurrency($monthAgg['m_net'] ?: 0); ?></div>
        </div>
    </div>
</div>

<!-- Monthly Earnings Chart -->
<?php if (!empty($monthRows)): ?>
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-chart-bar" style="color:#4f46e5; margin-right:0.5rem;"></i>Monthly Earnings (Last 12 Months)</h3>
        <span style="font-size:0.8rem; color:#64748b;"><?php echo formatCurrency(array_sum(array_column($monthRows, 'total'))); ?> total</span>
    </div>
    <?php
    $chartMax = max(array_column($monthRows, 'total')) ?: 1;
    $curYm = date;
    ?>
    <div class="chart-bars">
        <?php foreach ($monthRows as $mr): ?>
            <?php
            $isCur = $mr['ym'] === $curYm;
            $h = max(4, round(((float)$mr['total'] / $chartMax) * 100));
            $label = date('M', strtotime($mr['ym'] . '-01'));
            ?>
            <div class="chart-col<?php echo $isCur ? 'cur' : ''; ?> <?php echo (float)$mr['total'] <= 0 ? 'zero' : ''; ?>">
                <span class="chart-val"><?php echo (float)$mr['total'] > 0 ? number_format((float)$mr['total'] / 1000, 1) . 'k' : '-'; ?></span>
                <div class="chart-bar" style="height: <?php echo $h; ?>%;"></div>
                <span class="chart-label"><?php echo $label; ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Latest Payout Breakdown -->
<?php if ($latest): ?>
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-receipt" style="color:#10b981; margin-right:0.5rem;"></i>Latest Payout</h3>
        <span class="badge badge-success"><?php echo date('d M Y', strtotime($latest['payment_date'])); ?></span>
    </div>
    <div class="payout-flow">
        <div class="flow-item">
            <div class="fi-icon">📅</div>
            <div class="fi-label">Working Days</div>
            <div class="fi-value"><?php echo $latest['working_days'] ?: '-'; ?> / <?php echo $latest['total_days'] ?: '-'; ?></div>
        </div>
        <div class="flow-item flow-plus">
            <div class="fi-icon">💰</div>
            <div class="fi-label">Earned</div>
            <div class="fi-value"><?php echo $latest['earned_amount'] ? formatCurrency($latest['earned_amount']) : '-'; ?></div>
        </div>
        <div class="flow-item flow-plus">
            <div class="fi-icon">🎁</div>
            <div class="fi-label">Bonus</div>
            <div class="fi-value"><?php echo $latest['bonus'] ? formatCurrency($latest['bonus']) : ' 0.00'; ?></div>
        </div>
        <div class="flow-item flow-minus">
            <div class="fi-icon">➖</div>
            <div class="fi-label">Advance Deduction</div>
            <div class="fi-value"><?php echo $latest['advance_deduction'] ? formatCurrency($latest['advance_deduction']) : ' 0.00'; ?></div>
        </div>
        <div class="flow-item flow-total">
            <div class="fi-icon">✅</div>
            <div class="fi-label">Net Paid</div>
            <div class="fi-value"><?php echo formatCurrency($latest['amount']); ?></div>
        </div>
    </div>
    <?php if (!empty($latest['note'])): ?>
        <p class="text-muted" style="margin-top:1rem; margin-bottom:0;">
            <i class="fas fa-sticky-note"></i> <?php echo sanitize($latest['note']); ?>
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Payment History -->
<div class="panel">
    <div class="panel-head">
        <h3><i class="fas fa-history" style="color:#3b82f6; margin-right:0.5rem;"></i>Payment History</h3>
        <span class="badge badge-primary"><?php echo count($payments); ?> records</span>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Working Days</th>
                    <th>Daily Rate</th>
                    <th>Earned</th>
                    <th>Bonus</th>
                    <th>Advance</th>
                    <th>Net Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            No salary payments recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($p['payment_date'])); ?></td>
                            <td>
                                <?php if ($p['working_days']): ?>
                                    <?php echo $p['working_days']; ?> / <?php echo $p['total_days']; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo $p['daily_rate'] ? formatCurrency($p['daily_rate']) : '-'; ?></td>
                            <td><?php echo $p['earned_amount'] ? formatCurrency($p['earned_amount']) : '-'; ?></td>
                            <td><?php echo $p['bonus'] ? formatCurrency($p['bonus']) : '-'; ?></td>
                            <td><?php echo $p['advance_deduction'] ? formatCurrency($p['advance_deduction']) : '-'; ?></td>
                            <td><strong class="text-success"><?php echo formatCurrency($p['amount']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include'includes/footer.php'; ?>