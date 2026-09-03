<?php
/**
 * POS System - Pricing
 * Display subscription plans as pricing cards
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

if (!hasPermission('plans')) {
    setFlash('danger', 'You do not have permission to access pricing.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Pricing');

$db = getDB();

// List plans (active first)
$plans = $db->query("SELECT * FROM subscription_plans ORDER BY status DESC, price ASC, id DESC")->fetchAll();

// Decide which plan to highlight as "popular" (cheapest active, or none)
$popularId = null;
foreach ($plans as $p) {
    if ($p['status'] === 'active') { $popularId = $p['id']; break; }
}

include 'includes/header.php';
?>

<!-- Page Header -->
<div style="margin-bottom: 1.5rem;">
    <h2 style="margin-bottom: 0.25rem;">Pricing</h2>
    <p class="text-muted">Choose the subscription plan that fits your business</p>
</div>

<?php if (empty($plans)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <span>No plans available yet. <a href="plans.php">Create a plan</a> to get started.</span>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; align-items: stretch;">
        <?php foreach ($plans as $plan): ?>
            <?php
            $isPopular = $plan['id'] === $popularId;
            $active = $plan['status'] === 'active';
            $perDay = $plan['duration_days'] > 0 ? round((float)$plan['price'] / (int)$plan['duration_days'], 2) : 0;
            ?>
            <div style="
                background: #fff;
                border: <?php echo $isPopular ? '2px solid #4f46e5' : '1px solid #eef0f4'; ?>;
                border-radius: 1.25rem;
                padding: 1.75rem 1.5rem;
                box-shadow: <?php echo $isPopular ? '0 20px 40px -12px rgba(79,70,229,0.35)' : '0 4px 14px rgba(15,23,42,0.06)'; ?>;
                display: flex;
                flex-direction: column;
                position: relative;
                opacity: <?php echo $active ? '1' : '0.6'; ?>;
            ">
                <?php if ($isPopular): ?>
                    <span style="position:absolute; top:-12px; left:50%; transform:translateX(-50%); background:#4f46e5; color:#fff; font-size:0.72rem; font-weight:700; padding:0.3rem 0.9rem; border-radius:999px; letter-spacing:0.04em;">
                        POPULAR
                    </span>
                <?php endif; ?>

                <div style="font-size:1.15rem; font-weight:800; color:#0f172a; margin-bottom:0.25rem;">
                    <?php echo sanitize($plan['name']); ?>
                </div>
                <div style="font-size:0.8rem; color:#94a3b8; margin-bottom:1.1rem; text-transform:uppercase; letter-spacing:0.05em;">
                    <?php echo $active ? 'Available' : 'Not available'; ?>
                </div>

                <div style="display:flex; align-items:flex-end; gap:0.25rem; margin-bottom:0.2rem;">
                    <span style="font-size:2.1rem; font-weight:800; color:#0f172a; line-height:1;">
                        <?php echo formatCurrency($plan['price']); ?>
                    </span>
                </div>
                <div style="font-size:0.82rem; color:#64748b; margin-bottom:1.1rem;">
                    per <?php echo $plan['duration_days']; ?> days
                    <span style="color:#94a3b8;">(<?php echo formatCurrency($perDay); ?>/day)</span>
                </div>

                <?php
                $featList = array_filter(array_map('trim', explode("\n", $plan['features'] ?? '')));
                if (!empty($featList)):
                ?>
                    <ul style="list-style:none; padding:0; margin:0 0 1.25rem; flex:1; font-size:0.82rem; color:#475569;">
                        <?php foreach ($featList as $feat): ?>
                            <li style="display:flex; gap:8px; padding:4px 0;"><i class="fas fa-check-circle" style="color:#22c55e; margin-top:2px;"></i> <?php echo sanitize($feat); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($plan['description']): ?>
                    <p style="font-size:0.85rem; color:#475569; margin:0 0 1.25rem; flex:1;">
                        <?php echo sanitize($plan['description']); ?>
                    </p>
                <?php else: ?>
                    <div style="flex:1;"></div>
                <?php endif; ?>

                <a href="plans.php?edit=<?php echo $plan['id']; ?>" class="btn btn-<?php echo $isPopular ? 'primary' : 'secondary'; ?>" style="width:100%; text-align:center;">
                    <i class="fas fa-edit"></i> Manage
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div style="margin-top: 1.75rem; text-align:center;">
    <a href="plans.php" class="btn btn-outline">
        <i class="fas fa-cog"></i> Manage Plans
    </a>
</div>

<?php include 'includes/footer.php'; ?>
