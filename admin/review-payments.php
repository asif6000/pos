<?php
/**
 * POS System - Review Plan Payments
 * Admin reviews pending plan payments and approves/rejects them.
 * On approval the user's subscription is activated and they can log in.
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !isSuperAdmin()) {
    redirect('../admin/login.php');
}

define('PAGE_TITLE', 'Payment Approvals');

$db = getDB();

// Process approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $payId  = (int)($_POST['payment_id'] ?? 0);

    if ($payId > 0) {
        try {
            // Fetch payment info including type and owner
            $stmt = $db->prepare("SELECT * FROM plan_payments WHERE id = ?");
            $stmt->execute([$payId]);
            $pay = $stmt->fetch();
            $ownerId = (int)($pay['owner_id'] ?? 0);

            if ($action === 'approve' && $pay) {
                $db->prepare("UPDATE plan_payments SET status = 'verified' WHERE id = ?")->execute([$payId]);

                if ($ownerId > 0) {
                    // Load the target plan
                    $pstmt = $db->prepare("SELECT id, duration_days FROM subscription_plans WHERE id = ?");
                    $pstmt->execute([(int)$pay['plan_id']]);
                    $plan = $pstmt->fetch();
                    $type = $pay['type'] ?? 'new';

                    // Find the owner's current subscription
                    $sstmt = $db->prepare("SELECT * FROM subscriptions WHERE owner_id = ? ORDER BY id DESC LIMIT 1");
                    $sstmt->execute([$ownerId]);
                    $curSub = $sstmt->fetch();

                    if ($type === 'renew' && $curSub && $plan) {
                        $baseEnd = ($curSub['end_date'] && $curSub['end_date'] > date('Y-m-d')) ? $curSub['end_date'] : date('Y-m-d');
                        $newEnd = date('Y-m-d', strtotime($baseEnd . ' +' . (int)$plan['duration_days'] . ' days'));
                        $db->prepare("UPDATE subscriptions SET status='active', end_date = ? WHERE id = ?")
                            ->execute([$newEnd, $curSub['id']]);
                        $db->prepare("UPDATE users SET status='active' WHERE id = ?")->execute([$ownerId]);
                        clearPlanModulesCache();
                        clearPermissionCache();
                        setFlash('success', 'Renewal approved. Subscription extended to ' . date('d M Y', strtotime($newEnd)) . '.');
                    } elseif (in_array($type, ['upgrade', 'downgrade', 'new']) && $plan) {
                        $start = date('Y-m-d');
                        $end = date('Y-m-d', strtotime('+' . (int)$plan['duration_days'] . ' days'));
                        if ($curSub) {
                            $db->prepare("UPDATE subscriptions SET plan_id = ?, status='active', start_date = ?, end_date = ? WHERE id = ?")
                                ->execute([$plan['id'], $start, $end, $curSub['id']]);
                        } else {
                            $db->prepare("INSERT INTO subscriptions (owner_id, plan_id, status, start_date, end_date) VALUES (?, ?, 'active', ?, ?)")
                                ->execute([$ownerId, $plan['id'], $start, $end]);
                        }
                        $db->prepare("UPDATE users SET status='active' WHERE id = ?")->execute([$ownerId]);
                        // Bump both caches so the user's next request / poll gets new features immediately
                        clearPlanModulesCache();
                        clearPermissionCache();
                        $planName = $pay['plan_name'] ?? 'new plan';
                        $typeLabel = ucfirst($type);
                        setFlash('success', "{$typeLabel} approved: {$planName}. New features are active immediately.");
                    } else {
                        setFlash('success', 'Payment approved.');
                    }
                } else {
                    setFlash('success', 'Payment approved.');
                }
            } elseif ($action === 'reject' && $pay) {
                $db->prepare("UPDATE plan_payments SET status = 'rejected' WHERE id = ?")->execute([$payId]);
                // Only deactivate for a brand-new subscription, not for renew/change of an existing active one
                if ($ownerId > 0 && ($pay['type'] ?? 'new') === 'new') {
                    $db->prepare("UPDATE subscriptions SET status = 'inactive' WHERE owner_id = ? AND status = 'pending'")->execute([$ownerId]);
                    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND role = 'admin'")->execute([$ownerId]);
                    setFlash('success', 'Payment rejected. User account deactivated.');
                } else {
                    setFlash('success', 'Payment rejected.');
                }
            }
        } catch (PDOException $e) {
            setFlash('danger', 'Database error. Please try again.');
        }
    }
    redirect('review-payments.php');
}

// List pending payments (with owner info, if linked)
$payments = [];
try {
    $stmt = $db->prepare(
        "SELECT p.id, p.owner_id, p.plan_name, p.amount, p.payment_method, p.sender_number,
                p.transaction_id, p.status, p.type, p.created_at,
                u.name AS owner_name, u.email AS owner_email
         FROM plan_payments p
         LEFT JOIN users u ON u.id = p.owner_id
         ORDER BY FIELD(p.status,'pending','verified','rejected'), p.created_at DESC"
    );
    $stmt->execute();
    $payments = $stmt->fetchAll();
} catch (Exception $e) {
    $payments = [];
}

include 'includes/header.php';
?>
<div class="page-heading">
    <h2>Payment Approvals</h2>
    <p class="text-muted">Approve a payment to activate the user's subscription so they can log in.</p>
</div>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<?php if (empty($payments)): ?>
    <div class="alert alert-info">No payments found.</div>
<?php else: ?>
<div class="card">
    <div class="card-header"><i class="fas fa-money-check-alt"></i> Payments</div>
    <div class="card-body table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Owner</th>
                    <th>Plan</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Sender</th>
                    <th>TrxID</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                    <tr>
                        <td><?php echo $p['id']; ?></td>
                        <td>
                            <?php echo htmlspecialchars($p['owner_name'] ?: '—'); ?>
                            <?php if ($p['owner_email']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($p['owner_email']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($p['plan_name']); ?></td>
                        <td>
                            <?php
                            $typeColors = ['new' => 'badge-info', 'renew' => 'badge-primary', 'upgrade' => 'badge-success', 'downgrade' => 'badge-secondary'];
                            $typeColor = $typeColors[$p['type']] ?? 'badge-secondary';
                            ?>
                            <span class="badge <?php echo $typeColor; ?>"><?php echo ucfirst($p['type'] ?? 'new'); ?></span>
                        </td>
                        <td><?php echo formatCurrency($p['amount']); ?></td>
                        <td><span class="badge badge-primary"><?php echo ucfirst($p['payment_method']); ?></span></td>
                        <td><?php echo htmlspecialchars($p['sender_number'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($p['transaction_id']); ?></td>
                        <td><?php echo date('d M Y H:i', strtotime($p['created_at'])); ?></td>
                        <td>
                            <?php if ($p['status'] === 'pending'): ?>
                                <span class="badge badge-warning">Pending</span>
                            <?php elseif ($p['status'] === 'verified'): ?>
                                <span class="badge badge-success">Approved</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'pending'): ?>
                                <div style="display:flex;gap:6px;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="owner_id" value="<?php echo (int)$p['owner_id'] ?? 0; ?>">
                                        <button class="btn btn-sm btn-success"
                                            <?php echo empty($p['owner_name']) ? 'disabled title="Owner not linked yet"' : ''; ?>>
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="owner_id" value="<?php echo (int)$p['owner_id'] ?? 0; ?>">
                                        <button class="btn btn-sm btn-danger"><i class="fas fa-times"></i> Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<a href="dashboard.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

<?php include 'includes/footer.php'; ?>
