<?php
/**
 * POS System - My Subscription
 * Shows the owner's subscription status and allows
 * renew / upgrade / downgrade of their plan (payment -> pending -> approval).
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'My Subscription');

$db = getDB();
$user = getCurrentUser();
$ownerId = $user['owner_id'] ?: $user['id'];

// ── AJAX Poll: check if subscription became active ────────────────────────────
if (isset($_GET['poll']) && $_GET['poll'] == '1') {
    header('Content-Type: application/json');
    $active = false;
    try {
        $ps = $db->prepare(
            "SELECT id FROM subscriptions
             WHERE owner_id = ? AND status = 'active'
               AND (end_date IS NULL OR end_date >= CURDATE())
             LIMIT 1"
        );
        $ps->execute([$ownerId]);
        $active = (bool)$ps->fetch();
    } catch (Exception $e) {
        $active = false;
    }
    echo json_encode(['active' => $active]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────


// Make sure plan_payments has the type column (safe migration)
try {
    $db->exec("ALTER TABLE plan_payments ADD COLUMN type ENUM('new','renew','upgrade','downgrade') DEFAULT 'new' AFTER owner_id");
} catch (Exception $e) {
    // column may already exist
}

// Current subscription + plan
$sub = null;
try {
    $stmt = $db->prepare(
        "SELECT s.*, sp.name AS plan_name, sp.price AS plan_price, sp.duration_days AS plan_days, sp.modules
         FROM subscriptions s
         JOIN subscription_plans sp ON sp.id = s.plan_id
         WHERE s.owner_id = ?
         ORDER BY s.id DESC
         LIMIT 1"
    );
    $stmt->execute([$ownerId]);
    $sub = $stmt->fetch();
} catch (Exception $e) {
    $sub = null;
}

// All active plans
$plans = [];
try {
    $plans = $db->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC, id ASC")->fetchAll();
} catch (Exception $e) {
    $plans = [];
}

// Load payment gateway settings (global, same as checkout)
$gateway = [];
try {
    $gq = $db->query("SELECT setting_key, setting_value FROM system_settings");
    while ($gr = $gq->fetch()) {
        $gateway[$gr['setting_key']] = $gr['setting_value'];
    }
} catch (Exception $e) {
    $gateway = [];
}
$bkashNumber = $gateway['bkash_number'] ?? '';
$nagadNumber = $gateway['nagad_number'] ?? '';
$paymentNote = $gateway['payment_note'] ?? '';

// Pending request (unapproved payment) for this owner
$pendingReq = null;
try {
    $stmt = $db->prepare(
        "SELECT * FROM plan_payments WHERE owner_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$ownerId]);
    $pendingReq = $stmt->fetch();
} catch (Exception $e) {
    $pendingReq = null;
}

$error = '';
$success = '';

// Handle renew / change plan (upgrade / downgrade)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $sender_number  = sanitize($_POST['sender_number'] ?? '');
    $transaction_id = sanitize($_POST['transaction_id'] ?? '');
    $targetPlanId   = (int)($_POST['plan_id'] ?? 0);

    if (!in_array($payment_method, ['bkash', 'nagad'], true)) {
        $error = 'Please select a valid payment method.';
    } elseif (empty($transaction_id)) {
        $error = 'Please enter your transaction ID.';
    } elseif ($action === 'change' && empty($plans)) {
        $error = 'No plans available.';
    } else {
        try {
            // Determine the target plan
            $targetPlan = null;
            if ($action === 'renew' && $sub) {
                $targetPlan = $sub;
            } elseif ($action === 'change') {
                foreach ($plans as $p) {
                    if ((int)$p['id'] === $targetPlanId) {
                        $targetPlan = $p;
                        break;
                    }
                }
            }

            if (!$targetPlan) {
                $error = 'Invalid plan selected.';
            } else {
                // Normalize plan fields (renew uses subscription join keys, change uses plans keys)
                $targetId   = (int)($targetPlan['id'] ?? $targetPlan['plan_id']);
                $targetName = $targetPlan['plan_name'] ?? $targetPlan['name'];
                $targetPrice = $targetPlan['price'] ?? $targetPlan['plan_price'];

                // Determine payment type
                $type = 'new';
                if ($action === 'renew') {
                    $type = 'renew';
                } elseif ($action === 'change' && $sub) {
                    $type = ((float)$targetPrice >= (float)$sub['plan_price']) ? 'upgrade' : 'downgrade';
                }

                $stmt = $db->prepare(
                    "INSERT INTO plan_payments
                        (plan_id, plan_name, amount, payment_method, sender_number, transaction_id, owner_id, type, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
                $stmt->execute([
                    $targetId,
                    $targetName,
                    $targetPrice,
                    $payment_method,
                    $sender_number ?: null,
                    $transaction_id,
                    $ownerId,
                    $type,
                ]);

                setFlash('success', 'Your ' . $type . ' request has been submitted. It will be active after admin approval.');
                redirect('subscription.php');
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <span><?php echo $error; ?></span></div>
<?php endif; ?>

<?php if (isset($_GET['expired'])): ?>
<!-- ══ EXPIRED BLOCK BANNER ══ -->
<div style="
    background: linear-gradient(135deg,#7f1d1d,#dc2626);
    color:#fff; border-radius:16px;
    padding:2rem 2.5rem; margin-bottom:1.5rem;
    text-align:center; position:relative; overflow:hidden;">
    <div style="position:absolute;inset:0;opacity:.07;background-image:radial-gradient(circle,#fff 1px,transparent 1px);background-size:20px 20px;pointer-events:none;"></div>
    <div style="font-size:3rem;margin-bottom:.75rem;">🔒</div>
    <h2 style="margin:0 0 .5rem;font-size:1.5rem;font-weight:800;">Subscription Expired!</h2>
    <p style="margin:0 0 1.25rem;opacity:.9;font-size:.95rem;">
        আপনার subscription মেয়াদ শেষ হয়ে গেছে। সব features বন্ধ হয়ে গেছে।<br>
        নিচে থেকে Renew করুন এবং admin approve করলেই সব আবার চালু হবে।
    </p>
    <div style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.15);border-radius:30px;padding:.5rem 1.25rem;font-size:.85rem;font-weight:700;">
        <span style="width:8px;height:8px;background:#fca5a5;border-radius:50%;display:inline-block;"></span>
        No active subscription found
    </div>
</div>
<?php endif; ?>

<div class="page-heading">
    <h2>My Subscription</h2>
    <p class="text-muted">View your plan status, renew, upgrade or downgrade your subscription.</p>
</div>

<?php if ($pendingReq): ?>
<!-- ══ PENDING APPROVAL SCREEN ══ -->
<div id="pendingBanner" style="
    background: linear-gradient(135deg,#fef3c7,#fff7ed);
    border:2px solid #fcd34d; border-radius:16px;
    padding:2rem 2.5rem; margin-bottom:1.5rem;
    text-align:center; position:relative; overflow:hidden;">
    <div style="position:absolute;inset:0;opacity:.04;background-image:radial-gradient(circle,#92400e 1px,transparent 1px);background-size:20px 20px;pointer-events:none;"></div>
    <div style="margin-bottom:1rem;">
        <div id="approvalIcon" style="width:72px;height:72px;background:#fcd34d;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:2rem;animation:pulse-ring 2s ease-in-out infinite;">⏳</div>
    </div>
    <h3 style="color:#92400e;margin:0 0 .5rem;font-size:1.3rem;">Payment Under Review</h3>
    <p style="color:#78350f;margin:0 0 1rem;font-size:.95rem;">
        Plan: <strong><?php echo htmlspecialchars($pendingReq['plan_name']); ?></strong>
        &nbsp;|&nbsp; TrxID: <code style="background:#fef9c3;padding:2px 6px;border-radius:4px;"><?php echo htmlspecialchars($pendingReq['transaction_id']); ?></code>
    </p>
    <div id="liveStatus" style="display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.7);border:1px solid #fcd34d;border-radius:30px;padding:.4rem 1rem;font-size:.85rem;color:#92400e;font-weight:600;">
        <span id="statusDot" style="width:8px;height:8px;background:#f59e0b;border-radius:50%;display:inline-block;animation:blink 1.2s ease-in-out infinite;"></span>
        <span id="statusText">Checking approval status...</span>
    </div>
    <p style="color:#a16207;font-size:.82rem;margin:1rem 0 0;"><i class="fas fa-info-circle"></i> Admin approve করলে automatically সব features unlock হবে।</p>
</div>
<style>
@keyframes pulse-ring{0%{box-shadow:0 0 0 0 rgba(252,211,77,.7)}70%{box-shadow:0 0 0 16px rgba(252,211,77,0)}100%{box-shadow:0 0 0 0 rgba(252,211,77,0)}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
@keyframes approved-pop{0%{transform:scale(.8);opacity:0}60%{transform:scale(1.15)}100%{transform:scale(1);opacity:1}}
</style>
<script>
(function(){
    let attempt=0, iv;
    function check(){
        attempt++;
        const st=document.getElementById('statusText');
        if(st) st.textContent='Checking... (attempt '+attempt+')';
        fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?poll=1',{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json()).then(data=>{
            if(data.active){
                clearInterval(iv);
                const icon=document.getElementById('approvalIcon'),banner=document.getElementById('pendingBanner'),dot=document.getElementById('statusDot');
                if(icon){icon.textContent='🎉';icon.style.background='#bbf7d0';icon.style.animation='approved-pop .5s ease';}
                if(banner){banner.style.background='linear-gradient(135deg,#d1fae5,#ecfdf5)';banner.style.borderColor='#6ee7b7';}
                if(dot){dot.style.background='#10b981';dot.style.animation='none';}
                if(st) st.textContent='✅ Approved! Redirecting...';
                setTimeout(()=>window.location.href='dashboard.php?approved=1',1500);
            } else { if(st) st.textContent='Waiting for admin approval...'; }
        }).catch(()=>{ if(st) st.textContent='Checking... (will retry)'; });
    }
    document.addEventListener('DOMContentLoaded',function(){ check(); iv=setInterval(check,8000); });
})();
</script>
<?php endif; ?>


<!-- ══ CURRENT PLAN CARD — Realtime Countdown ══ -->
<?php if ($sub): ?>
<?php
$isActive  = $sub['status'] === 'active' && ($sub['end_date'] === null || $sub['end_date'] >= date('Y-m-d'));
$isPending = $sub['status'] === 'pending';
$isExpired = !$isActive && !$isPending;
$endTs     = $sub['end_date'] ? strtotime($sub['end_date'] . ' 23:59:59') : null;
$daysLeft  = $endTs ? max(0, (int)ceil(($endTs - time()) / 86400)) : null;
$urgentClass = ($daysLeft !== null && $daysLeft <= 7 && $isActive);
?>

<div class="card" style="margin-bottom:1.5rem;overflow:hidden;">
    <div class="card-header" style="background:<?php echo $isExpired?'linear-gradient(135deg,#7f1d1d,#dc2626)':($urgentClass?'linear-gradient(135deg,#78350f,#f59e0b)':'linear-gradient(135deg,#1e1b4b,#4f46e5)'); ?>;color:#fff;border:none;padding:1.25rem 1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
            <div>
                <div style="font-size:.75rem;opacity:.75;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.25rem;">Current Plan</div>
                <div style="font-size:1.6rem;font-weight:800;"><?php echo htmlspecialchars($sub['plan_name']); ?></div>
            </div>
            <div style="text-align:right;">
                <?php if ($isActive): ?>
                    <div style="background:rgba(255,255,255,.15);border-radius:30px;padding:.35rem .9rem;font-size:.8rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;">
                        <span style="width:7px;height:7px;background:#4ade80;border-radius:50%;display:inline-block;animation:blink2 2s ease-in-out infinite;"></span>
                        ACTIVE
                    </div>
                <?php elseif ($isPending): ?>
                    <div style="background:rgba(255,255,255,.15);border-radius:30px;padding:.35rem .9rem;font-size:.8rem;font-weight:700;">⏳ PENDING</div>
                <?php else: ?>
                    <div style="background:rgba(255,255,255,.15);border-radius:30px;padding:.35rem .9rem;font-size:.8rem;font-weight:700;">❌ EXPIRED</div>
                <?php endif; ?>
                <div style="font-size:.8rem;opacity:.8;margin-top:.3rem;"><?php echo formatCurrency($sub['plan_price']); ?> / <?php echo (int)$sub['plan_days']; ?> days</div>
            </div>
        </div>
    </div>

    <div class="card-body" style="padding:1.5rem;">
        <!-- Date info row -->
        <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1.5rem;">
            <div>
                <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Start Date</div>
                <div style="font-weight:700;color:#1e293b;margin-top:.2rem;"><?php echo $sub['start_date'] ? date('d M Y', strtotime($sub['start_date'])) : '—'; ?></div>
            </div>
            <div>
                <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">End Date</div>
                <div style="font-weight:700;color:<?php echo $isExpired?'#dc2626':($urgentClass?'#d97706':'#1e293b'); ?>;margin-top:.2rem;">
                    <?php echo $sub['end_date'] ? date('d M Y', strtotime($sub['end_date'])) : '—'; ?>
                </div>
            </div>
            <?php if ($daysLeft !== null && $isActive): ?>
            <div>
                <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Days Remaining</div>
                <div style="font-weight:800;font-size:1.4rem;color:<?php echo $urgentClass?'#dc2626':'#4f46e5'; ?>;margin-top:.1rem;" id="daysLeft"><?php echo $daysLeft; ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($isActive && $endTs): ?>
        <!-- ══ REALTIME COUNTDOWN ══ -->
        <div style="background:<?php echo $urgentClass?'#fef2f2':'#f0f4ff'; ?>;border:1px solid <?php echo $urgentClass?'#fecaca':'#c7d2fe'; ?>;border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;">
            <div style="font-size:.78rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.75rem;text-align:center;">
                <i class="fas fa-clock"></i>
                <?php echo $urgentClass ? '⚠️ Expiring Soon — ' : ''; ?>Time Remaining
            </div>
            <div id="countdown" style="display:flex;justify-content:center;gap:1rem;flex-wrap:wrap;"></div>
        </div>

        <!-- Progress bar -->
        <?php
        $totalDays = $sub['plan_days'] ?: 30;
        $startTs   = strtotime($sub['start_date'] . ' 00:00:00');
        $totalSecs = $endTs - $startTs;
        $usedSecs  = time() - $startTs;
        $pct       = $totalSecs > 0 ? min(100, max(0, ($usedSecs / $totalSecs) * 100)) : 0;
        $barColor  = $pct > 85 ? '#ef4444' : ($pct > 60 ? '#f59e0b' : '#4f46e5');
        ?>
        <div style="margin-bottom:1.5rem;">
            <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-bottom:.35rem;">
                <span>Plan Usage</span>
                <span id="pctLabel"><?php echo round($pct); ?>% used</span>
            </div>
            <div style="height:10px;background:#e2e8f0;border-radius:99px;overflow:hidden;">
                <div id="progressBar" style="height:100%;width:<?php echo round($pct,2); ?>%;background:<?php echo $barColor; ?>;border-radius:99px;transition:width .5s ease;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#94a3b8;margin-top:.3rem;">
                <span><?php echo date('d M Y', strtotime($sub['start_date'])); ?></span>
                <span><?php echo date('d M Y', strtotime($sub['end_date'])); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isExpired && !$pendingReq): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Subscription expired. Please renew to restore access.</div>
        <?php endif; ?>

        <?php if (($isActive || $isExpired) && !$pendingReq): ?>
        <button class="btn btn-primary" onclick="showRenew()"><i class="fas fa-sync-alt"></i> Renew Plan</button>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <p class="text-muted">No active subscription. Choose a plan below to get started.</p>
    </div>
</div>
<?php endif; ?>


<!-- ══ UPGRADE / DOWNGRADE ══ -->
<?php if (!empty($plans)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header"><i class="fas fa-exchange-alt"></i> Upgrade / Downgrade Plan</div>
    <div class="card-body">
        <p class="text-muted">Select a different plan. Payment required — active after admin approval.</p>
        <form method="POST">
            <input type="hidden" name="action" value="change">
            <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                <div class="form-group" style="flex:1;min-width:200px;">
                    <label>Choose Plan</label>
                    <select name="plan_id" class="form-control" required>
                        <option value="">-- Select plan --</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo $p['id']; ?>" data-price="<?php echo (float)$p['price']; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> — <?php echo formatCurrency($p['price']); ?> / <?php echo (int)$p['duration_days']; ?> days
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Payment Method</label>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                    <label class="pay-option chg-opt" data-method="bkash" style="border:2px solid #0ea5e9;border-radius:8px;padding:.45rem 1rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
                        <input type="radio" name="payment_method" value="bkash" checked style="display:none;">
                        <span style="font-weight:700;">bKash</span>
                    </label>
                    <label class="pay-option chg-opt" data-method="nagad" style="border:1px solid #e2e8f0;border-radius:8px;padding:.45rem 1rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
                        <input type="radio" name="payment_method" value="nagad" style="display:none;">
                        <span style="font-weight:700;">Nagad</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <div style="background:#f8fafc;border:1px solid #eef0f4;border-radius:8px;padding:.65rem .9rem;font-size:.88rem;color:#334155;">
                    Send <strong><span class="chg-amount">—</span></strong> to:<br>
                    <strong class="chg-detail">bKash: <?php echo $bkashNumber ?: '01XXXXXXXXX'; ?></strong> (Personal)
                    <?php if ($paymentNote): ?><br><span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars($paymentNote); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="form-row" style="display:flex;gap:1rem;flex-wrap:wrap;">
                <div class="form-group" style="flex:1;min-width:200px;">
                    <label>Sender Number (optional)</label>
                    <input type="text" name="sender_number" class="form-control" placeholder="01XXXXXXXXX">
                </div>
                <div class="form-group" style="flex:1;min-width:200px;">
                    <label>Transaction ID (TrxID) *</label>
                    <input type="text" name="transaction_id" class="form-control" required placeholder="TrxID">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt"></i> Submit Change Request</button>
        </form>
    </div>
</div>
<?php endif; ?>


<!-- ══ RENEW MODAL ══ -->
<div class="modal-overlay" id="renewModal">
    <div class="modal" style="max-width:460px;">
        <div class="modal-header">
            <h3 class="modal-title">Renew Plan</h3>
            <button class="modal-close" onclick="closeRenew()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="renew">
            <div class="modal-body">
                <p class="text-muted" style="font-size:13px;">
                    Renewing extends your current plan
                    <?php if ($sub): ?>(<strong><?php echo htmlspecialchars($sub['plan_name']); ?></strong>, <?php echo formatCurrency($sub['plan_price']); ?> / <?php echo (int)$sub['plan_days']; ?> days)<?php endif; ?>
                    by the same duration.
                </p>
                <div class="form-group">
                    <label>Payment Method</label>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <label class="pay-option rn-opt" data-method="bkash" style="border:2px solid #0ea5e9;border-radius:8px;padding:.45rem 1rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
                            <input type="radio" name="payment_method" value="bkash" checked style="display:none;">
                            <span style="font-weight:700;">bKash</span>
                        </label>
                        <label class="pay-option rn-opt" data-method="nagad" style="border:1px solid #e2e8f0;border-radius:8px;padding:.45rem 1rem;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
                            <input type="radio" name="payment_method" value="nagad" style="display:none;">
                            <span style="font-weight:700;">Nagad</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <div style="background:#f8fafc;border:1px solid #eef0f4;border-radius:8px;padding:.65rem .9rem;font-size:.88rem;color:#334155;">
                        Send <strong><?php echo formatCurrency($sub['plan_price'] ?? 0); ?></strong> to:<br>
                        <strong class="rn-detail">bKash: <?php echo $bkashNumber ?: '01XXXXXXXXX'; ?></strong> (Personal)
                        <?php if ($paymentNote): ?><br><span style="font-size:.8rem;color:#64748b;"><?php echo htmlspecialchars($paymentNote); ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Sender Number (optional)</label>
                    <input type="text" name="sender_number" class="form-control" placeholder="01XXXXXXXXX">
                </div>
                <div class="form-group">
                    <label>Transaction ID (TrxID) *</label>
                    <input type="text" name="transaction_id" class="form-control" required placeholder="TrxID">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRenew()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Submit Renewal</button>
            </div>
        </form>
    </div>
</div>


<style>
@keyframes blink2{0%,100%{opacity:1}50%{opacity:.3}}
.cd-box{
    background:#fff;
    border:1px solid #e0e7ff;
    border-radius:10px;
    padding:.6rem .9rem;
    min-width:70px;
    text-align:center;
    box-shadow:0 2px 8px rgba(79,70,229,.08);
}
.cd-box .cd-num{
    font-size:1.9rem;
    font-weight:800;
    color:#4f46e5;
    line-height:1;
    font-variant-numeric:tabular-nums;
}
.cd-box .cd-label{
    font-size:.65rem;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:#94a3b8;
    margin-top:.25rem;
}
.cd-sep{
    font-size:1.8rem;
    font-weight:800;
    color:#c7d2fe;
    align-self:center;
    line-height:1;
    padding-bottom:.3rem;
}
/* urgent red style */
.urgent .cd-box { border-color:#fecaca; }
.urgent .cd-box .cd-num { color:#dc2626; }
</style>

<script>
<?php
$endTs       = $sub ? ($sub['end_date'] ? strtotime($sub['end_date'] . ' 23:59:59') : null) : null;
$startTs     = $sub ? strtotime($sub['start_date'] . ' 00:00:00') : null;
$totalSecs   = ($startTs && $endTs) ? max(1, $endTs - $startTs) : 1;
$bkashJs     = addslashes($bkashNumber ?: '01XXXXXXXXX');
$nagadJs     = addslashes($nagadNumber ?: '01XXXXXXXXX');
$renewPrice  = (float)($sub['plan_price'] ?? 0);
$currency    = CURRENCY;
$isActiveJs  = $isActive ? 'true' : 'false';
$urgentJs    = $urgentClass ? 'true' : 'false';
?>
const END_TS    = <?php echo $endTs    ?? 0; ?> * 1000;
const START_TS  = <?php echo $startTs  ?? 0; ?> * 1000;
const TOTAL_S   = <?php echo $totalSecs; ?> * 1000;
const IS_ACTIVE = <?php echo $isActiveJs; ?>;
const IS_URGENT = <?php echo $urgentJs; ?>;

// ── Realtime Countdown ─────────────────────────────────────────────────────
function buildCountdown(){
    const el = document.getElementById('countdown');
    if(!el || !END_TS || !IS_ACTIVE) return;

    function pad(n){ return String(n).padStart(2,'0'); }

    function tick(){
        const now  = Date.now();
        const diff = Math.max(0, END_TS - now);

        const totalSec  = Math.floor(diff / 1000);
        const days      = Math.floor(totalSec / 86400);
        const hours     = Math.floor((totalSec % 86400) / 3600);
        const minutes   = Math.floor((totalSec % 3600) / 60);
        const seconds   = totalSec % 60;

        const cls = IS_URGENT ? 'urgent' : '';

        el.innerHTML =
            `<div class="cd-box ${cls}"><div class="cd-num">${pad(days)}</div><div class="cd-label">Days</div></div>` +
            `<div class="cd-sep">:</div>` +
            `<div class="cd-box ${cls}"><div class="cd-num">${pad(hours)}</div><div class="cd-label">Hours</div></div>` +
            `<div class="cd-sep">:</div>` +
            `<div class="cd-box ${cls}"><div class="cd-num">${pad(minutes)}</div><div class="cd-label">Minutes</div></div>` +
            `<div class="cd-sep">:</div>` +
            `<div class="cd-box ${cls}"><div class="cd-num">${pad(seconds)}</div><div class="cd-label">Seconds</div></div>`;

        // Update days-left badge
        const dl = document.getElementById('daysLeft');
        if(dl) dl.textContent = days;

        // Update progress bar + label
        const used = Math.max(0, Date.now() - START_TS);
        const pct  = Math.min(100, (used / TOTAL_S) * 100);
        const bar  = document.getElementById('progressBar');
        const lbl  = document.getElementById('pctLabel');
        if(bar) bar.style.width = pct.toFixed(2) + '%';
        if(lbl) lbl.textContent = pct.toFixed(1) + '% used';

        if(diff <= 0){
            el.innerHTML = '<div style="color:#ef4444;font-weight:700;font-size:1.1rem;">Subscription Expired</div>';
            clearInterval(cdInterval);
        }
    }

    tick();
    const cdInterval = setInterval(tick, 1000);
}

// ── Payment gateway switching ──────────────────────────────────────────────
const gw = {
    bkash: '<?php echo $bkashJs; ?>',
    nagad: '<?php echo $nagadJs; ?>'
};
const CUR = '<?php echo $currency; ?>';

// Change plan form
document.querySelectorAll('.chg-opt').forEach(opt=>{
    opt.addEventListener('click',function(){
        this.querySelector('input').checked=true;
        document.querySelectorAll('.chg-opt').forEach(o=>o.style.borderWidth='1px');
        this.style.borderWidth='2px';
        updateChg();
    });
});
function updateChg(){
    const sel   = document.querySelector('select[name="plan_id"]');
    const price = sel&&sel.selectedOptions[0] ? parseFloat(sel.selectedOptions[0].dataset.price||0) : 0;
    const method= document.querySelector('input[name="payment_method"]:checked')?.value||'bkash';
    const amt   = document.querySelector('.chg-amount');
    const det   = document.querySelector('.chg-detail');
    if(amt) amt.textContent = price>0 ? CUR+price.toFixed(2) : '—';
    if(det) det.textContent = (method==='bkash'?'bKash':'Nagad')+': '+gw[method]+' (Personal)';
}
const planSel=document.querySelector('select[name="plan_id"]');
if(planSel){ planSel.addEventListener('change',updateChg); updateChg(); }

// Renew modal gateway switching
document.querySelectorAll('.rn-opt').forEach(opt=>{
    opt.addEventListener('click',function(){
        this.querySelector('input').checked=true;
        document.querySelectorAll('.rn-opt').forEach(o=>o.style.borderWidth='1px');
        this.style.borderWidth='2px';
        const method=this.dataset.method;
        const det=document.querySelector('.rn-detail');
        if(det) det.textContent=(method==='bkash'?'bKash':'Nagad')+': '+gw[method]+' (Personal)';
    });
});

// Modal
function showRenew(){ document.getElementById('renewModal').classList.add('active'); }
function closeRenew(){ document.getElementById('renewModal').classList.remove('active'); }
document.getElementById('renewModal').addEventListener('click',function(e){ if(e.target===this)closeRenew(); });

document.addEventListener('DOMContentLoaded', buildCountdown);
</script>

<a href="dashboard.php" class="btn btn-secondary" style="margin-top:.5rem;"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

<?php include 'includes/footer.php'; ?>
