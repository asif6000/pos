<?php
/**
 * POS System - Subscription Plans
 * Create and manage subscription packages (plans)
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
requirePermission();

if (!hasPermission('plans')) {
    setFlash('danger', 'You do not have permission to access subscription plans.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Subscription Plans');

$db = getDB();
$user = getCurrentUser();
$owner_id = $user['owner_id'] ?? $user['id'];

// Modules that can be granted by a plan (maps to permission slugs)
$moduleList = [
    'dashboard'        => 'Dashboard',
    'pos'              => 'POS / Billing',
    'products'         => 'Products',
    'categories'       => 'Categories',
    'variables'        => 'Variables (Sizes & Colors)',
    'stock'            => 'Stock Management',
    'transfers'        => 'Transfers',
    'sales'            => 'Sales List',
    'sales_delete'     => 'Delete Sales',
    'returns'          => 'Returns',
    'reports'          => 'Reports',
    'cashbook'         => 'Expense / Cashbook',
    'customers'        => 'Customers',
    'users'            => 'Users',
    'stores'           => 'Stores',
    'plans'            => 'Subscription Plans',
    'staff'            => 'Staff',
    'roles'            => 'Roles & Permissions',
    'settings'         => 'Settings',
    'barcode_settings' => 'Barcode Settings',
    'vouchers'         => 'Vouchers',
];

// Create table if it doesn't exist yet (safe migration)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        duration_days INT NOT NULL DEFAULT 30,
        description TEXT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB");
} catch (PDOException $e) {
    // best-effort
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = sanitize($_POST['name'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $duration    = (int)($_POST['duration_days'] ?? 30);
        $description = sanitize($_POST['description'] ?? '');
        $features    = sanitize($_POST['features'] ?? '');
        $status      = sanitize($_POST['status'] ?? 'active');
        $storeLimit  = max(1, (int)($_POST['store_limit'] ?? 1));
        $modules     = isset($_POST['modules']) && is_array($_POST['modules'])
            ? implode(',', array_map('sanitize', $_POST['modules']))
            : '';

        if (empty($name)) {
            setFlash('danger', 'Plan name is required.');
        } elseif ($price < 0) {
            setFlash('danger', 'Price cannot be negative.');
        } elseif ($duration <= 0) {
            setFlash('danger', 'Duration must be greater than zero.');
        } elseif (!in_array($status, ['active', 'inactive'])) {
            setFlash('danger', 'Invalid status.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO subscription_plans (name, price, duration_days, description, features, modules, store_limit, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $price, $duration, $description ?: null, $features ?: null, $modules, $storeLimit, $status]);
                    clearPlanModulesCache();
                    setFlash('success', 'Subscription plan created successfully!');
                } else {
                    $stmt = $db->prepare("UPDATE subscription_plans SET name=?, price=?, duration_days=?, description=?, features=?, modules=?, store_limit=?, status=? WHERE id=?");
                    $stmt->execute([$name, $price, $duration, $description ?: null, $features ?: null, $modules, $storeLimit, $status, $id]);
                    clearPlanModulesCache();
                    setFlash('success', 'Subscription plan updated successfully! Active customers will receive the changes automatically.');
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
        redirect('plans.php');
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE subscription_plans SET status = IF(status='active','inactive','active') WHERE id=?")
            ->execute([$id]);
        clearPlanModulesCache();
        setFlash('success', 'Plan status updated.');
        redirect('plans.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM subscription_plans WHERE id=?")->execute([$id]);
        clearPlanModulesCache();
        setFlash('success', 'Plan deleted.');
        redirect('plans.php');
    }
}

// Edit prefill
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editPlan = null;
if ($editId) {
    $stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = ?");
    $stmt->execute([$editId]);
    $editPlan = $stmt->fetch();
}

// List plans
$plans = $db->query("SELECT * FROM subscription_plans ORDER BY status DESC, created_at DESC, id DESC")->fetchAll();

include 'includes/header.php';
?>

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
<div style="margin-bottom: 1.5rem;">
    <h2 style="margin-bottom: 0.25rem;">Subscription Plans</h2>
    <p class="text-muted">Create and manage subscription packages for your business</p>
</div>

<!-- Add / Edit Plan Form -->
<div class="card" style="margin-bottom: 1.75rem;">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-<?php echo $editPlan ? 'edit' : 'plus-circle'; ?>"></i>
            <?php echo $editPlan ? 'Edit Plan' : 'Add Plan'; ?>
        </h3>
        <?php if ($editPlan): ?>
            <a href="plans.php" class="btn btn-sm btn-secondary">Cancel</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
            <input type="hidden" name="action" value="<?php echo $editPlan ? 'edit' : 'add'; ?>">
            <?php if ($editPlan): ?>
                <input type="hidden" name="id" value="<?php echo $editPlan['id']; ?>">
            <?php endif; ?>

            <div class="form-group" style="flex: 2; min-width: 200px;">
                <label class="form-label required">Plan Name</label>
                <input type="text" name="name" class="form-control"
                    value="<?php echo $editPlan ? sanitize($editPlan['name']) : ''; ?>"
                    placeholder="e.g. Basic, Standard, Premium" required>
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label class="form-label required">Price (৳)</label>
                <input type="number" name="price" class="form-control" min="0" step="0.01"
                    value="<?php echo $editPlan ? $editPlan['price'] : ''; ?>" placeholder="0.00" required>
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label class="form-label required">Duration (days)</label>
                <input type="number" name="duration_days" class="form-control" min="1" step="1"
                    value="<?php echo $editPlan ? $editPlan['duration_days'] : '30'; ?>" placeholder="30" required>
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label class="form-label required">Store Limit</label>
                <input type="number" name="store_limit" class="form-control" min="1" max="999" step="1"
                    value="<?php echo $editPlan ? (int)($editPlan['store_limit'] ?? 1) : '1'; ?>"
                    placeholder="1" required>
                <small class="text-muted">সর্বোচ্চ কতটি store (unlimited = 999)</small>
            </div>

            <div class="form-group" style="flex: 1; min-width: 160px;">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="active" <?php echo (!$editPlan || $editPlan['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($editPlan && $editPlan['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>

            <div class="form-group" style="flex: 3; min-width: 250px;">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control"
                    value="<?php echo $editPlan ? sanitize($editPlan['description']) : ''; ?>"
                    placeholder="Short description of the plan">
            </div>

            <div class="form-group" style="flex: 3; min-width: 250px;">
                <label class="form-label">Features <span class="text-muted">(one per line)</span></label>
                <textarea name="features" class="form-control" rows="2"
                    placeholder="e.g. Up to 3 stores&#10;5 user accounts&#10;Advanced reports"><?php echo $editPlan ? sanitize($editPlan['features']) : ''; ?></textarea>
            </div>

            <div class="form-group" style="flex-basis: 100%;">
                <label class="form-label">Plan Modules / Access <span class="text-muted">(pages this plan unlocks — others will be blocked)</span></label>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php
                    $selMods = $editPlan ? explode(',', $editPlan['modules'] ?? '') : [];
                    foreach ($moduleList as $slug => $label):
                        $checked = in_array($slug, $selMods) ? 'checked' : '';
                    ?>
                        <label style="display:inline-flex; align-items:center; gap:6px; background:#f8fafc; border:1px solid #eef0f4; padding:6px 10px; border-radius:8px; font-size:0.82rem; cursor:pointer;">
                            <input type="checkbox" name="modules[]" value="<?php echo $slug; ?>" <?php echo $checked; ?>> <?php echo $label; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group" style="flex: 0 0 auto;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $editPlan ? 'Update Plan' : 'Save Plan'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Plans List -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> All Plans
        </h3>
        <span class="badge badge-primary"><?php echo count($plans); ?> plans</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Plan Name</th>
                        <th>Price</th>
                        <th>Duration</th>
                        <th>Store Limit</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                No plans yet. Use the form above to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td><strong><?php echo sanitize($plan['name']); ?></strong></td>
                                <td><?php echo formatCurrency($plan['price']); ?></td>
                                <td>
                                    <?php echo $plan['duration_days']; ?> days
                                    <span class="text-muted">
                                        (<?php echo round($plan['price'] / $plan['duration_days'], 2); ?>/day)
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-primary">
                                        <i class="fas fa-store"></i>
                                        <?php echo (int)($plan['store_limit'] ?? 1); ?> store<?php echo ($plan['store_limit'] ?? 1) > 1 ? 's' : ''; ?>
                                    </span>
                                </td>
                                <td><?php echo $plan['description'] ? sanitize($plan['description']) : '-'; ?></td>
                                <td>
                                    <?php if ($plan['status'] === 'active'): ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-pause-circle"></i> Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <a href="plans.php?edit=<?php echo $plan['id']; ?>" class="btn btn-sm btn-secondary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Toggle status for this plan?');">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" title="Activate/Deactivate">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this plan?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
