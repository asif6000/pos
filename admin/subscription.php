<?php
/**
 * POS System - My Package (subscription / renewal)
 *
 * The place a shop owner sees what plan they are on, how much time is left
 * (a live day:hour:minute:second countdown), and renews / upgrades / downgrades
 * it. Buying posts to ../checkout.php, which charges the server-side price from
 * config/plans.php and hands off to UddoktaPay. Nothing here trusts a price from
 * the browser.
 *
 * Only the people who run the shop can reach it (same gate as checkout.php), so a
 * cashier cannot charge the owner's card.
 */

require_once '../config/db.php';
require_once '../config/subscription.php';
startSecureSession();

if (!isLoggedIn() || !hasRole(['admin', 'owner', 'manager'])) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'My Package');
$db = getDB();
$currentUser = getCurrentUser();
$ownerId = (int)($currentUser['owner_id'] ?: $currentUser['id']);

subEnsureTables($db);

$status = subStatus($db, $ownerId);
$expiresMs = ($status['expires_at'] ? (strtotime($status['expires_at']) * 1000) : 0);

// Plans that can actually be bought here (the free trial is auto-granted, hidden).
$plans = array_values(array_filter(subPlans(), function ($p) {
    return empty($p['hidden']);
}));

// Payment history for this owner, newest first.
$payments = [];
try {
    $st = $db->prepare(
        "SELECT * FROM subscription_payments WHERE owner_id = ? ORDER BY id DESC LIMIT 50"
    );
    $st->execute([$ownerId]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $payments = [];
}

// Hero gradient tone + status label follow the subscription state.
$heroTone = [
    'active'   => '',
    'expiring' => 'warn',
    'expired'  => 'bad',
    'none'     => 'none',
];
$tone = $heroTone[$status['state']] ?? '';

$stateLabel = [
    'active'   => 'সক্রিয়',
    'expiring' => 'শীঘ্রই শেষ',
    'expired'  => 'মেয়াদ শেষ',
    'none'     => 'কোনো প্যাকেজ নেই',
];
$badgeClass = [
    'active'   => 'badge-success',
    'expiring' => 'badge-warning',
    'expired'  => 'badge-danger',
    'none'     => 'badge-secondary',
];

include 'includes/header.php';
?>

<style>
    .pkg-wrap { display: flex; flex-direction: column; gap: 22px; }

    /* Upgrade nudge shown when a gated feature redirected here */
    .pkg-gate {
        display: flex; align-items: center; gap: 14px;
        background: var(--warning-light); border: 1px solid var(--warning);
        color: #92400e; border-radius: 14px; padding: 14px 18px; font-size: .92rem;
    }
    html[data-theme="dark"] .pkg-gate { color: var(--gray-800); }
    .pkg-gate i { font-size: 1.3rem; color: var(--warning); flex-shrink: 0; }

    /* ── Hero / current plan ─────────────────────────────── */
    .pkg-hero {
        position: relative; overflow: hidden;
        border-radius: 20px; padding: 26px 28px; color: #fff;
        background: linear-gradient(135deg, #2563eb 0%, #4f46e5 55%, #7c3aed 100%);
        box-shadow: 0 20px 45px -22px rgba(37, 99, 235, .65);
    }
    .pkg-hero.warn { background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); box-shadow: 0 20px 45px -22px rgba(239,68,68,.6); }
    .pkg-hero.bad  { background: linear-gradient(135deg, #ef4444 0%, #991b1b 100%); }
    .pkg-hero.none { background: linear-gradient(135deg, #334155 0%, #0f172a 100%); box-shadow: 0 20px 45px -22px rgba(15,23,42,.6); }
    .pkg-hero::after {
        content: ''; position: absolute; right: -60px; top: -70px; width: 260px; height: 260px;
        background: radial-gradient(circle, rgba(255,255,255,.18), transparent 62%); pointer-events: none;
    }
    .pkg-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; position: relative; z-index: 1; }
    .pkg-hero-eyebrow { font-size: .78rem; letter-spacing: .6px; text-transform: uppercase; opacity: .85; margin-bottom: 6px; }
    .pkg-hero-title { font-size: 1.7rem; font-weight: 800; line-height: 1.1; margin: 0; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .pkg-hero-sub { margin: 8px 0 0; opacity: .9; font-size: .95rem; }
    .pkg-hero-badge { font-size: .72rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,.22); color: #fff; }
    .pkg-hero-cta {
        display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;
        background: #fff; color: #1d4ed8; text-decoration: none; font-weight: 800;
        padding: 11px 20px; border-radius: 12px; box-shadow: 0 8px 20px -8px rgba(0,0,0,.4);
        transition: transform .15s ease;
    }
    .pkg-hero-cta:hover { transform: translateY(-2px); }
    .pkg-hero.none .pkg-hero-cta { color: #0f172a; }

    /* Countdown tiles */
    .pkg-cd { display: flex; gap: 12px; margin-top: 22px; position: relative; z-index: 1; flex-wrap: wrap; }
    .pkg-cd-tile {
        flex: 1; min-width: 82px; text-align: center;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22);
        border-radius: 14px; padding: 14px 8px; backdrop-filter: blur(4px);
    }
    .pkg-cd-num { font-size: 2.1rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
    .pkg-cd-lbl { font-size: .72rem; opacity: .85; margin-top: 7px; text-transform: uppercase; letter-spacing: .5px; }
    .pkg-cd-expired { display: none; margin-top: 14px; font-weight: 700; position: relative; z-index: 1; }

    /* ── Section shell ───────────────────────────────────── */
    .pkg-card { background: var(--surface, #fff); border: 1px solid var(--gray-200); border-radius: 18px; padding: 22px; }
    .pkg-card-head { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
    .pkg-h { font-size: 1.15rem; font-weight: 800; color: var(--gray-900); margin: 0; }
    .pkg-h-sub { color: var(--gray-500); font-size: .85rem; margin: 4px 0 0; }

    /* Segmented billing toggle */
    .pkg-seg { display: inline-flex; background: var(--gray-100); border: 1px solid var(--gray-200); border-radius: 999px; padding: 4px; }
    .pkg-seg button { border: none; background: transparent; padding: 8px 18px; border-radius: 999px; font-weight: 700; font-size: .85rem; color: var(--gray-500); cursor: pointer; transition: all .15s; font-family: inherit; }
    .pkg-seg button.on { background: var(--primary); color: #fff; box-shadow: var(--shadow-sm); }

    /* ── Plan cards ──────────────────────────────────────── */
    .pkg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 18px; }
    .pkg-plan {
        position: relative; display: flex; flex-direction: column; gap: 14px;
        background: var(--surface, #fff); border: 1px solid var(--gray-200);
        border-radius: 18px; padding: 22px; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .pkg-plan:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
    .pkg-plan.popular { border: 2px solid var(--primary); }
    .pkg-plan.current { border-color: var(--success); box-shadow: 0 0 0 3px var(--success-light); }
    .pkg-ribbon { position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: var(--primary); color: #fff; font-size: .68rem; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; padding: 4px 14px; border-radius: 999px; box-shadow: var(--shadow-md); }
    .pkg-plan-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .pkg-plan-name { font-size: 1.15rem; font-weight: 800; color: var(--gray-900); }
    .pkg-plan-tag { color: var(--gray-500); font-size: .82rem; margin-top: -8px; }
    .pkg-price { display: flex; align-items: baseline; gap: 4px; }
    .pkg-price .cur { font-size: 1.1rem; font-weight: 700; color: var(--gray-900); }
    .pkg-price .amt { font-size: 2.1rem; font-weight: 800; color: var(--gray-900); line-height: 1; }
    .pkg-price .suf { color: var(--gray-500); font-size: .85rem; font-weight: 600; }
    .pkg-feats { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; flex: 1; }
    .pkg-feats li { display: flex; align-items: center; gap: 9px; color: var(--gray-600); font-size: .88rem; }
    .pkg-feats li i { color: var(--success); font-size: .82rem; flex-shrink: 0; }
    .pkg-btn {
        display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%;
        border: none; cursor: pointer; font-family: inherit; font-weight: 800; font-size: .92rem;
        color: #fff; padding: 12px 16px; border-radius: 12px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        box-shadow: 0 10px 22px -12px rgba(37, 99, 235, .9); transition: transform .15s ease, filter .15s ease;
    }
    .pkg-btn:hover { transform: translateY(-2px); filter: brightness(1.05); }
    .pkg-plan.current .pkg-btn { background: linear-gradient(135deg, var(--success), #047857); box-shadow: 0 10px 22px -12px rgba(16,185,129,.9); }
    .pkg-note { display: flex; gap: 10px; align-items: flex-start; color: var(--gray-500); font-size: .82rem; margin-top: 18px; background: var(--gray-50); border: 1px dashed var(--gray-200); border-radius: 12px; padding: 12px 14px; }
    .pkg-note i { color: var(--primary); margin-top: 2px; }

    /* ── History ─────────────────────────────────────────── */
    .pkg-table { width: 100%; border-collapse: collapse; }
    .pkg-table th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: var(--gray-500); font-weight: 700; padding: 0 14px 12px; border-bottom: 1px solid var(--gray-200); }
    .pkg-table td { padding: 13px 14px; border-bottom: 1px solid var(--gray-100); color: var(--gray-700); font-size: .88rem; vertical-align: middle; }
    .pkg-table tr:last-child td { border-bottom: none; }
    .pkg-table td strong { color: var(--gray-900); }
    .pkg-empty { text-align: center; padding: 34px 10px; color: var(--gray-400); }
    .pkg-empty i { font-size: 2rem; display: block; margin-bottom: 10px; opacity: .5; }
    @media (max-width: 640px) {
        .pkg-hero { padding: 22px 18px; }
        .pkg-hero-title { font-size: 1.4rem; }
        .pkg-cd-tile { min-width: 66px; padding: 11px 6px; }
        .pkg-cd-num { font-size: 1.6rem; }
        .pkg-scroll { overflow-x: auto; }
    }
</style>

<div class="pkg-wrap">

    <?php if ($flash = getFlash()): ?>
        <div class="alert alert-<?php echo $flash['type']; ?>">
            <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'warning' ? 'triangle-exclamation' : 'exclamation-circle'); ?>"></i>
            <span><?php echo sanitize($flash['message']); ?></span>
        </div>
    <?php endif; ?>

    <?php
    // Arriving here via the feature gate (a module the plan does not include).
    $gateLabels = [
        'pos' => 'POS / বিলিং', 'products' => 'পণ্য', 'categories' => 'ক্যাটাগরি', 'variables' => 'ভ্যারিয়েবল',
        'stock' => 'স্টক', 'transfers' => 'ট্রান্সফার', 'suppliers' => 'সপ্লায়ার', 'sales' => 'বিক্রি',
        'returns' => 'রিটার্ন', 'reports' => 'রিপোর্ট', 'cashbook' => 'খরচ / Expense', 'customers' => 'কাস্টমার',
        'marketing' => 'মার্কেটিং', 'users' => 'ইউজার', 'stores' => 'স্টোর', 'staff' => 'স্টাফ',
        'roles' => 'রোল ও পারমিশন', 'barcode_settings' => 'বারকোড', 'vouchers' => 'ভাউচার',
        'ecommerce' => 'অনলাইন শপ',
        'google_contacts' => 'গুগল কন্টাক্ট',
    ];
    if (isset($_GET['gate'])):
        $g = (string)$_GET['gate'];
        $gName = $gateLabels[$g] ?? $g;
    ?>
        <div class="pkg-gate">
            <i class="fas fa-lock"></i>
            <div>
                <strong><?php echo sanitize($gName); ?></strong> ফিচারটি আপনার বর্তমান প্যাকেজে নেই।
                চালিয়ে যেতে নিচ থেকে প্ল্যান আপগ্রেড করুন।
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Current plan + live countdown ─────────────────── -->
    <div class="pkg-hero <?php echo $tone; ?>">
        <div class="pkg-hero-top">
            <div>
                <div class="pkg-hero-eyebrow">আপনার বর্তমান প্যাকেজ</div>
                <h2 class="pkg-hero-title">
                    <?php echo $status['state'] === 'none' ? 'কোনো প্যাকেজ নেননি' : sanitize($status['plan_name']); ?>
                    <?php if ($status['state'] !== 'none'): ?>
                        <span class="pkg-hero-badge"><?php echo $stateLabel[$status['state']]; ?></span>
                        <span class="pkg-hero-badge"><?php echo $status['cycle'] === 'yearly' ? 'বার্ষিক' : 'মাসিক'; ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($status['state'] === 'none'): ?>
                    <p class="pkg-hero-sub">সিস্টেম চলছে — নিচ থেকে একটি প্যাকেজ কিনে নিন।</p>
                <?php else: ?>
                    <p class="pkg-hero-sub"><i class="far fa-calendar-check"></i> মেয়াদ শেষ: <?php echo date('d M Y, h:i A', strtotime($status['expires_at'])); ?></p>
                <?php endif; ?>
            </div>
            <a href="#pkg-plans" class="pkg-hero-cta">
                <i class="fas <?php echo $status['state'] === 'none' ? 'fa-shopping-cart' : 'fa-sync-alt'; ?>"></i>
                <?php echo $status['state'] === 'none' ? 'প্যাকেজ নিন' : 'রিনু / পরিবর্তন'; ?>
            </a>
        </div>

        <?php if ($status['state'] !== 'none'): ?>
            <div class="pkg-cd" id="countdown">
                <?php $boxes = [['d', 'দিন'], ['h', 'ঘণ্টা'], ['m', 'মিনিট'], ['s', 'সেকেন্ড']];
                foreach ($boxes as $b): ?>
                    <div class="pkg-cd-tile">
                        <div class="pkg-cd-num" id="cd-<?php echo $b[0]; ?>">--</div>
                        <div class="pkg-cd-lbl"><?php echo $b[1]; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="pkg-cd-expired" id="cd-expired"><i class="fas fa-triangle-exclamation"></i> মেয়াদ শেষ হয়ে গেছে — এখনই রিনু করুন।</div>
        <?php endif; ?>
    </div>

    <!-- ── Plans ─────────────────────────────────────────── -->
    <div class="pkg-card" id="pkg-plans">
        <div class="pkg-card-head">
            <div>
                <h3 class="pkg-h">প্যাকেজসমূহ</h3>
                <p class="pkg-h-sub">আপনার দোকানের জন্য সঠিক প্ল্যানটি বেছে নিন</p>
            </div>
            <div class="pkg-seg" id="cycleToggle">
                <button type="button" data-cycle="monthly" class="cycle-btn on">মাসিক</button>
                <button type="button" data-cycle="yearly" class="cycle-btn">বার্ষিক</button>
            </div>
        </div>

        <div class="pkg-grid">
            <?php foreach ($plans as $plan):
                $slug = $plan['slug'];
                $action = subPlanAction($db, $ownerId, $slug);
                $isCurrent = ($status['plan'] === $slug && in_array($status['state'], ['active', 'expiring'], true));
                $btnLabel = [
                    'new'       => 'কিনুন',
                    'renew'     => 'রিনু করুন',
                    'upgrade'   => 'আপগ্রেড করুন',
                    'downgrade' => 'সুইচ করুন',
                ][$action] ?? 'কিনুন';
                $feats = $plan['features'] ?? [];
            ?>
                <div class="pkg-plan <?php echo $plan['popular'] ? 'popular' : ''; ?> <?php echo $isCurrent ? 'current' : ''; ?>">
                    <?php if ($plan['popular']): ?><span class="pkg-ribbon">জনপ্রিয়</span><?php endif; ?>
                    <div class="pkg-plan-top">
                        <span class="pkg-plan-name"><?php echo sanitize($plan['name']); ?></span>
                        <?php if ($isCurrent): ?><span class="badge badge-success">বর্তমান</span><?php endif; ?>
                    </div>
                    <div class="pkg-plan-tag"><?php echo sanitize($plan['tagline']); ?></div>
                    <div class="pkg-price">
                        <span class="cur">৳</span>
                        <span class="amt price-amount" data-monthly="<?php echo (int)$plan['monthly']; ?>" data-yearly="<?php echo (int)$plan['yearly']; ?>"><?php echo (int)$plan['monthly']; ?></span>
                        <span class="suf price-suffix">/মাস</span>
                    </div>
                    <ul class="pkg-feats">
                        <?php foreach ($feats as $f): ?>
                            <li><i class="fas fa-circle-check"></i> <?php echo sanitize($f); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form action="../checkout.php" method="post">
                        <input type="hidden" name="plan" value="<?php echo sanitize($slug); ?>">
                        <input type="hidden" name="cycle" value="monthly" class="cycle-input">
                        <button type="submit" class="pkg-btn"><i class="fas fa-credit-card"></i> <?php echo $btnLabel; ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pkg-note">
            <i class="fas fa-circle-info"></i>
            <span>একই প্ল্যান সক্রিয় অবস্থায় রিনু করলে মেয়াদ বর্তমান শেষ তারিখ থেকে যোগ হয়। আপগ্রেড / ডাউনগ্রেড করলে এখন থেকে নতুন মেয়াদ শুরু হয়।</span>
        </div>
    </div>

    <!-- ── Payment history ───────────────────────────────── -->
    <div class="pkg-card">
        <div class="pkg-card-head">
            <div>
                <h3 class="pkg-h">পেমেন্ট ইতিহাস</h3>
                <p class="pkg-h-sub">আপনার সব প্যাকেজ কেনা ও পেমেন্টের রেকর্ড</p>
            </div>
        </div>
        <div class="pkg-scroll">
            <table class="pkg-table">
                <thead>
                    <tr>
                        <th>তারিখ</th>
                        <th>প্ল্যান</th>
                        <th>সাইকেল</th>
                        <th>ধরন</th>
                        <th>পরিমাণ</th>
                        <th>স্ট্যাটাস</th>
                        <th>ইনভয়েস</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="7"><div class="pkg-empty"><i class="far fa-receipt"></i>এখনো কোনো পেমেন্ট রেকর্ড নেই।</div></td></tr>
                    <?php endif; ?>
                    <?php
                    $statusBadge = ['paid' => 'badge-success', 'pending' => 'badge-warning', 'failed' => 'badge-danger', 'cancelled' => 'badge-secondary'];
                    $actionBn = ['new' => 'নতুন', 'renew' => 'রিনু', 'upgrade' => 'আপগ্রেড', 'downgrade' => 'ডাউনগ্রেড'];
                    foreach ($payments as $p):
                        $pPlan = subPlan($p['plan_slug']);
                        $pName = $pPlan ? $pPlan['name'] : ucfirst($p['plan_slug']);
                    ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                            <td><strong><?php echo sanitize($pName); ?></strong></td>
                            <td><?php echo $p['billing_cycle'] === 'yearly' ? 'বার্ষিক' : 'মাসিক'; ?></td>
                            <td><?php echo $actionBn[$p['action']] ?? $p['action']; ?></td>
                            <td><strong>৳<?php echo number_format((float)$p['amount'], 2); ?></strong></td>
                            <td><span class="badge <?php echo $statusBadge[$p['status']] ?? 'badge-secondary'; ?>"><?php echo $p['status']; ?></span></td>
                            <td><?php echo $p['gateway_invoice_id'] ? sanitize($p['gateway_invoice_id']) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
(function () {
    // ── Live countdown from the server-provided expiry (epoch ms) ──
    var target = <?php echo $expiresMs; ?>;
    var els = {
        d: document.getElementById('cd-d'),
        h: document.getElementById('cd-h'),
        m: document.getElementById('cd-m'),
        s: document.getElementById('cd-s')
    };
    var expiredBox = document.getElementById('cd-expired');

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function tick() {
        if (!target || !els.d) { return; }
        var diff = target - Date.now();
        if (diff <= 0) {
            els.d.textContent = els.h.textContent = els.m.textContent = els.s.textContent = '00';
            if (expiredBox) { expiredBox.style.display = 'block'; }
            return;
        }
        els.d.textContent = pad(Math.floor(diff / 86400000));
        els.h.textContent = pad(Math.floor((diff % 86400000) / 3600000));
        els.m.textContent = pad(Math.floor((diff % 3600000) / 60000));
        els.s.textContent = pad(Math.floor((diff % 60000) / 1000));
        if (expiredBox) { expiredBox.style.display = 'none'; }
    }
    if (target && els.d) { tick(); setInterval(tick, 1000); }

    // ── Monthly / yearly toggle: swap shown price + the cycle each form sends ──
    var btns = document.querySelectorAll('.cycle-btn');
    function applyCycle(next) {
        document.querySelectorAll('.price-amount').forEach(function (el) {
            el.textContent = el.getAttribute('data-' + next);
        });
        document.querySelectorAll('.price-suffix').forEach(function (el) {
            el.textContent = next === 'yearly' ? '/বছর' : '/মাস';
        });
        document.querySelectorAll('.cycle-input').forEach(function (el) {
            el.value = next;
        });
        btns.forEach(function (b) {
            b.classList.toggle('on', b.getAttribute('data-cycle') === next);
        });
    }
    btns.forEach(function (b) {
        b.addEventListener('click', function () { applyCycle(b.getAttribute('data-cycle')); });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
