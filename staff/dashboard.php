<?php
/**
 * POS System - Staff Dashboard
 * Shows the logged-in staff member their salary and payment information
 */

require_once '../config/db.php';
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
    include 'includes/header.php';
    ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i>
        <span>Your staff profile could not be found. Please contact your administrator.</span>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// Payment history
$stmt = $db->prepare("SELECT * FROM staff_payments WHERE staff_id = ? ORDER BY payment_date DESC, id DESC");
$stmt->execute([$member['id']]);
$payments = $stmt->fetchAll();

// Period boundaries
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

// Period totals
$stmt = $db->prepare("SELECT
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ? AND sp.payment_date BETWEEN ? AND ?), 0) as paid_month,
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ? AND sp.payment_date BETWEEN ? AND ?), 0) as paid_week,
    COALESCE((SELECT SUM(amount) FROM staff_payments sp WHERE sp.staff_id = ?), 0) as total_paid
");
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
$dailyRate = $totalDays > 0 ? round((float)$member['salary'] / $totalDays, 2) : 0;

$salaryType = $member['salary_type'] === 'weekly' ? 'Weekly' : 'Monthly';
$periodLabel = $member['salary_type'] === 'weekly' ? 'This Week' : 'This Month';
$paidPeriod = $member['salary_type'] === 'weekly' ? (float)$totals['paid_week'] : (float)$totals['paid_month'];
$due = max(0, (float)$member['salary'] - $paidPeriod);

// Latest payment breakdown
$latest = !empty($payments) ? $payments[0] : null;

// Paid percentage for progress bar
$payPct = (float)$member['salary'] > 0 ? min(100, round(($paidPeriod / (float)$member['salary']) * 100)) : 0;

// Business profit data (owner's total)
$bizProfit = ['today_sales' => 0, 'today_expenses' => 0, 'today_profit' => 0, 'month_sales' => 0, 'month_expenses' => 0, 'month_profit' => 0, 'all_sales' => 0, 'all_expenses' => 0, 'all_profit' => 0];
try {
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd = date('Y-m-d 23:59:59');
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd = date('Y-m-t 23:59:59');

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.total), 0) as total FROM sales s JOIN users u ON s.user_id = u.id WHERE u.owner_id = ? AND s.created_at BETWEEN ? AND ?");
    $stmt->execute([$owner_id, $todayStart, $todayEnd]);
    $bizProfit['today_sales'] = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.total), 0) as total FROM sales s JOIN users u ON s.user_id = u.id WHERE u.owner_id = ?");
    $stmt->execute([$owner_id]);
    $bizProfit['all_sales'] = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.total), 0) as total FROM sales s JOIN users u ON s.user_id = u.id WHERE u.owner_id = ? AND s.created_at BETWEEN ? AND ?");
    $stmt->execute([$owner_id, $monthStart, $monthEnd]);
    $bizProfit['month_sales'] = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.created_at BETWEEN ? AND ?");
    $stmt->execute([$owner_id, $todayStart, $todayEnd]);
    $bizProfit['today_expenses'] = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out FROM cashbook_entries ce WHERE ce.owner_id = ? AND ce.created_at BETWEEN ? AND ?");
    $stmt->execute([$owner_id, $monthStart, $monthEnd]);
    $bizProfit['month_expenses'] = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END), 0) as cash_out FROM cashbook_entries ce WHERE ce.owner_id = ?");
    $stmt->execute([$owner_id]);
    $bizProfit['all_expenses'] = (float)$stmt->fetchColumn();

    $bizProfit['today_profit'] = $bizProfit['today_sales'] - $bizProfit['today_expenses'];
    $bizProfit['month_profit'] = $bizProfit['month_sales'] - $bizProfit['month_expenses'];
    $bizProfit['all_profit'] = $bizProfit['all_sales'] - $bizProfit['all_expenses'];
} catch (Exception $e) {}

include 'includes/header.php';
?>

<style>
    .staff-dash {
        --s-green: #10b981;
        --s-blue: #3b82f6;
        --s-indigo: #4f46e5;
        --s-amber: #f59e0b;
        --s-rose: #ef4444;
        --s-violet: #8b5cf6;
        --s-cyan: #06b6d4;
    }

    .hero-card {
        position: relative;
        border-radius: 1.25rem;
        padding: 2rem;
        margin-bottom: 1.75rem;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 45%, #4f46e5 100%);
        color: #fff;
        overflow: hidden;
        box-shadow: 0 20px 40px -12px rgba(49, 46, 129, 0.55);
    }
    .hero-card::before {
        content: '';
        position: absolute;
        top: -80px;
        right: -60px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,0.16) 0%, transparent 70%);
    }
    .hero-card::after {
        content: '';
        position: absolute;
        bottom: -100px;
        left: -40px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(139,92,246,0.35) 0%, transparent 70%);
    }
    .hero-top {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        position: relative;
        z-index: 1;
        flex-wrap: wrap;
    }
    .hero-avatar {
        width: 76px;
        height: 76px;
        border-radius: 20px;
        background: rgba(255,255,255,0.18);
        border: 2px solid rgba(255,255,255,0.35);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 800;
        flex-shrink: 0;
    }
    .hero-name {
        font-size: 1.6rem;
        font-weight: 800;
        margin-bottom: 0.35rem;
    }
    .hero-role {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255,255,255,0.16);
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .hero-badges {
        margin-left: auto;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.4rem;
        position: relative;
        z-index: 1;
    }
    .hero-badge {
        background: rgba(255,255,255,0.14);
        padding: 0.3rem 0.9rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
    }
    .hero-bottom {
        margin-top: 1.75rem;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 1rem;
        position: relative;
        z-index: 1;
    }
    .hero-stat {
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.18);
        border-radius: 1rem;
        padding: 1rem 1.25rem;
        backdrop-filter: blur(4px);
    }
    .hero-stat-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        opacity: 0.75;
        margin-bottom: 0.3rem;
    }
    .hero-stat-value {
        font-size: 1.35rem;
        font-weight: 800;
    }
    .hero-stat-sub {
        font-size: 0.75rem;
        opacity: 0.8;
    }

    /* Modern stat cards */
    .m-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.75rem;
    }
    .m-stat {
        background: #fff;
        border-radius: 1.1rem;
        padding: 1.4rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .m-stat:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
    }
    .m-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .m-icon.green  { background: #ecfdf5; color: #059669; }
    .m-icon.blue   { background: #eff6ff; color: #2563eb; }
    .m-icon.indigo { background: #eef2ff; color: #4f46e5; }
    .m-icon.amber  { background: #fffbeb; color: #d97706; }
    .m-icon.rose   { background: #fef2f2; color: #dc2626; }
    .m-icon.violet { background: #f5f3ff; color: #7c3aed; }
    .m-icon.cyan   { background: #ecfeff; color: #0891b2; }
    .m-label {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
        margin-bottom: 0.3rem;
    }
    .m-value {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
    }
    .m-sub {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-top: 0.25rem;
    }

    /* Salary progress */
    .salary-card {
        background: #fff;
        border-radius: 1.1rem;
        padding: 1.5rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        margin-bottom: 1.75rem;
    }
    .salary-progress {
        height: 12px;
        border-radius: 999px;
        background: #eef2f7;
        overflow: hidden;
        margin: 1rem 0 0.6rem;
    }
    .salary-progress-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #10b981, #34d399);
        transition: width .5s ease;
    }
    .progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 0.78rem;
        color: #64748b;
    }

    .salary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1rem;
        margin-top: 1.25rem;
    }
    .salary-item {
        background: #f8fafc;
        border: 1px solid #eef0f4;
        border-radius: 0.9rem;
        padding: 0.9rem 1rem;
        text-align: center;
    }
    .salary-item .si-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #94a3b8;
        margin-bottom: 0.25rem;
    }
    .salary-item .si-value {
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
    }

    /* Monthly chart */
    .chart-card {
        background: #fff;
        border-radius: 1.1rem;
        padding: 1.5rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        margin-bottom: 1.75rem;
    }
    .chart-bars {
        display: flex;
        align-items: flex-end;
        gap: 10px;
        height: 150px;
        margin-top: 1rem;
    }
    .chart-col {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        height: 100%;
        justify-content: flex-end;
    }
    .chart-bar {
        width: 100%;
        max-width: 40px;
        border-radius: 8px 8px 4px 4px;
        background: linear-gradient(180deg, #4f46e5, #6366f1);
        min-height: 4px;
        transition: height .5s ease;
    }
    .chart-col.zero .chart-bar {
        background: #e2e8f0;
    }
    .chart-col.cur .chart-bar {
        background: linear-gradient(180deg, #10b981, #34d399);
    }
    .chart-val {
        font-size: 0.68rem;
        font-weight: 700;
        color: #475569;
    }
    .chart-label {
        font-size: 0.7rem;
        color: #94a3b8;
    }

    /* Section headers */
    .sec-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 0 0 1rem;
    }
    .sec-head h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
    }

    .payout-card {
        background: #fff;
        border-radius: 1.1rem;
        padding: 1.5rem;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        border: 1px solid #eef0f4;
        margin-bottom: 1.75rem;
    }
    .payout-flow {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
    }
    .flow-item {
        background: #f8fafc;
        border: 1px solid #eef0f4;
        border-radius: 0.9rem;
        padding: 1rem;
        text-align: center;
    }
    .flow-item .fi-icon {
        font-size: 1.2rem;
        margin-bottom: 0.4rem;
    }
    .flow-item .fi-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #94a3b8;
        margin-bottom: 0.25rem;
    }
    .flow-item .fi-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0f172a;
    }
    .flow-plus .fi-value { color: #059669; }
    .flow-minus .fi-value { color: #dc2626; }
    .flow-total {
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border: 2px solid #10b981;
    }
    .flow-total .fi-value {
        font-size: 1.35rem;
        color: #059669;
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

<!-- Hero -->
<div class="hero-card">
    <div class="hero-top">
        <div class="hero-avatar"><?php echo strtoupper(substr($member['name'], 0, 1)); ?></div>
        <div>
            <div class="hero-name"><?php echo sanitize($member['name']); ?></div>
            <span class="hero-role">
                <i class="fas fa-user-tie"></i> <?php echo sanitize($member['designation'] ?: 'Staff Member'); ?>
            </span>
        </div>
        <div class="hero-badges">
            <span class="hero-badge"><i class="fas fa-calendar-alt"></i> <?php echo date('d M Y'); ?></span>
            <span class="hero-badge"><i class="fas fa-tag"></i> <?php echo $salaryType; ?> Salary</span>
        </div>
    </div>
    <div class="hero-bottom">
        <div class="hero-stat">
            <div class="hero-stat-label">Base Salary</div>
            <div class="hero-stat-value"><?php echo formatCurrency($member['salary']); ?></div>
            <div class="hero-stat-sub">per <?php echo strtolower($salaryType); ?> period</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-label">Daily Rate (<?php echo $totalDays; ?> days)</div>
            <div class="hero-stat-value"><?php echo formatCurrency($dailyRate); ?></div>
            <div class="hero-stat-sub">Salary ÷ <?php echo $totalDays; ?> days</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-label">Paid <?php echo $periodLabel; ?></div>
            <div class="hero-stat-value"><?php echo formatCurrency($paidPeriod); ?></div>
            <div class="hero-stat-sub"><?php echo $payPct; ?>% of salary</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-label">Due <?php echo $periodLabel; ?></div>
            <div class="hero-stat-value"><?php echo formatCurrency($due); ?></div>
            <div class="hero-stat-sub"><?php echo $due > 0 ? 'Salary still pending' : 'All paid up'; ?></div>
        </div>
    </div>
</div>

<!-- Modern Stat Cards -->
<div class="m-stats">
    <div class="m-stat">
        <div class="m-icon green"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="m-label">Paid <?php echo $periodLabel; ?></div>
            <div class="m-value"><?php echo formatCurrency($paidPeriod); ?></div>
            <div class="m-sub">Salary already received</div>
        </div>
    </div>
    <div class="m-stat">
        <div class="m-icon rose"><i class="fas fa-hourglass-half"></i></div>
        <div>
            <div class="m-label">Due <?php echo $periodLabel; ?></div>
            <div class="m-value"><?php echo formatCurrency($due); ?></div>
            <div class="m-sub"><?php echo $due > 0 ? 'Salary still pending' : 'Fully paid'; ?></div>
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

<!-- Business Profit Overview -->
<?php if ($bizProfit['all_sales'] > 0): ?>
<div class="salary-card" style="border-left:4px solid #10b981;">
    <div class="sec-head">
        <h3><i class="fas fa-chart-line" style="color:#10b981; margin-right:0.5rem;"></i>Business Profit Overview (মোট লাভ)</h3>
    </div>
    <div class="salary-grid">
        <div class="salary-item" style="border-left:3px solid #10b981;">
            <div class="si-label">আজকের বিক্রয়</div>
            <div class="si-value" style="color:#059669;"><?php echo formatCurrency($bizProfit['today_sales']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid #dc2626;">
            <div class="si-label">আজকের খরচ</div>
            <div class="si-value" style="color:#dc2626;"><?php echo formatCurrency($bizProfit['today_expenses']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid <?php echo $bizProfit['today_profit'] >= 0 ? '#10b981' : '#dc2626'; ?>;">
            <div class="si-label">আজকের লাভ</div>
            <div class="si-value" style="color:<?php echo $bizProfit['today_profit'] >= 0 ? '#059669' : '#dc2626'; ?>;"><?php echo formatCurrency($bizProfit['today_profit']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid #3b82f6;">
            <div class="si-label">মাসিক বিক্রয়</div>
            <div class="si-value" style="color:#2563eb;"><?php echo formatCurrency($bizProfit['month_sales']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid #f59e0b;">
            <div class="si-label">মাসিক খরচ</div>
            <div class="si-value" style="color:#d97706;"><?php echo formatCurrency($bizProfit['month_expenses']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid <?php echo $bizProfit['month_profit'] >= 0 ? '#10b981' : '#dc2626'; ?>;">
            <div class="si-label">মাসিক লাভ</div>
            <div class="si-value" style="color:<?php echo $bizProfit['month_profit'] >= 0 ? '#059669' : '#dc2626'; ?>;"><?php echo formatCurrency($bizProfit['month_profit']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid #8b5cf6;">
            <div class="si-label">সর্বকালের বিক্রয়</div>
            <div class="si-value" style="color:#7c3aed;"><?php echo formatCurrency($bizProfit['all_sales']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid #ef4444;">
            <div class="si-label">সর্বকালের খরচ</div>
            <div class="si-value" style="color:#dc2626;"><?php echo formatCurrency($bizProfit['all_expenses']); ?></div>
        </div>
        <div class="salary-item" style="border-left:3px solid <?php echo $bizProfit['all_profit'] >= 0 ? '#10b981' : '#dc2626'; ?>; background:<?php echo $bizProfit['all_profit'] >= 0 ? '#ecfdf5' : '#fef2f2'; ?>;">
            <div class="si-label">সর্বকালের লাভ (মোট)</div>
            <div class="si-value" style="color:<?php echo $bizProfit['all_profit'] >= 0 ? '#059669' : '#dc2626'; ?>; font-size:1.2rem;"><?php echo formatCurrency($bizProfit['all_profit']); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Salary Progress -->
<div class="salary-card">
    <div class="sec-head">
        <h3><i class="fas fa-chart-line" style="color:#10b981; margin-right:0.5rem;"></i><?php echo $salaryType; ?> Salary Progress</h3>
        <span style="font-size:0.8rem; color:#64748b;"><?php echo $payPct; ?>% of <?php echo formatCurrency($member['salary']); ?></span>
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
            <div class="si-label">Earned (<?php echo date('M'); ?>)</div>
            <div class="si-value"><?php echo formatCurrency($monthAgg['m_earned'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Bonus (<?php echo date('M'); ?>)</div>
            <div class="si-value" style="color:#059669;"><?php echo formatCurrency($monthAgg['m_bonus'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Advance (<?php echo date('M'); ?>)</div>
            <div class="si-value" style="color:#dc2626;"><?php echo formatCurrency($monthAgg['m_advance'] ?: 0); ?></div>
        </div>
        <div class="salary-item">
            <div class="si-label">Net Received (<?php echo date('M'); ?>)</div>
            <div class="si-value" style="color:#059669;"><?php echo formatCurrency($monthAgg['m_net'] ?: 0); ?></div>
        </div>
    </div>
</div>

<!-- Monthly Earnings Chart -->
<?php if (!empty($monthRows)): ?>
<div class="chart-card">
    <div class="sec-head">
        <h3><i class="fas fa-chart-bar" style="color:#4f46e5; margin-right:0.5rem;"></i>Monthly Earnings (Last 12 Months)</h3>
        <span style="font-size:0.8rem; color:#64748b;"><?php echo formatCurrency(array_sum(array_column($monthRows, 'total'))); ?> total</span>
    </div>
    <?php
    $chartMax = max(array_column($monthRows, 'total')) ?: 1;
    $curYm = date('Y-m');
    ?>
    <div class="chart-bars">
        <?php foreach ($monthRows as $mr): ?>
            <?php
            $isCur = $mr['ym'] === $curYm;
            $h = max(4, round(((float)$mr['total'] / $chartMax) * 100));
            $label = date('M', strtotime($mr['ym'] . '-01'));
            ?>
            <div class="chart-col <?php echo $isCur ? 'cur' : ''; ?> <?php echo (float)$mr['total'] <= 0 ? 'zero' : ''; ?>">
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
<div class="payout-card">
    <div class="sec-head">
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
            <div class="fi-value"><?php echo $latest['bonus'] ? formatCurrency($latest['bonus']) : '৳ 0.00'; ?></div>
        </div>
        <div class="flow-item flow-minus">
            <div class="fi-icon">➖</div>
            <div class="fi-label">Advance Deduction</div>
            <div class="fi-value"><?php echo $latest['advance_deduction'] ? formatCurrency($latest['advance_deduction']) : '৳ 0.00'; ?></div>
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
<div class="chart-card">
    <div class="sec-head">
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

<?php include 'includes/footer.php'; ?>