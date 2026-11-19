<?php
/**
 * POS System - Supplier Management
 *
 * A standalone supplier directory: who the shop buys from, and how to reach
 * them. Deliberately NOT linked to products yet - this is the contact list the
 * owner asked for, kept separate so it can grow into purchases later without a
 * rewrite.
 */

require_once '../config/db.php';
require_once '../config/csrf.php';
require_once '../config/purchases.php';
startSecureSession();

requirePermission('suppliers');

define('PAGE_TITLE', 'Suppliers');
$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'] ?: $currentUser['id'];
$actor = [
    'owner_id' => $ownerId,
    'store_id' => $currentUser['store_id'] ? (int)$currentUser['store_id'] : null,
    'user_id'  => $currentUser['id'],
    'name'     => $currentUser['name'],
];

// Create the table on first use so a XAMPP install works without a manual
// import. On a cPanel user without CREATE this is swallowed and the migration
// file (config/migration_suppliers.sql) is the way to make it. See appSafeDdl().
appSafeDdl($db, "CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    company VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(120) NULL,
    address TEXT NULL,
    note TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_status (status),
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB");

appRequireTables(['suppliers'], $db);
purchaseEnsureTables($db);


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    if ($action === 'add' || $action === 'edit') {
        if ($name === '') {
            setFlash('danger', 'Supplier name is required.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare(
                        "INSERT INTO suppliers (name, company, phone, status, owner_id)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$name, $company, $phone, $status, $ownerId]);
                    setFlash('success', 'Supplier added successfully!');
                } else {
                    // Scoped to this owner so one shop cannot edit another's row by guessing an id.
                    $stmt = $db->prepare(
                        "UPDATE suppliers SET name=?, company=?, phone=?, status=?
                         WHERE id=? AND owner_id=?"
                    );
                    $stmt->execute([$name, $company, $phone, $status, $id, $ownerId]);
                    setFlash('success', 'Supplier updated successfully!');
                }
                header('Location: suppliers.php');
                exit;
            } catch (Exception $e) {
                setFlash('danger', 'Error saving supplier: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'delete_confirm') {
        csrfRequire('suppliers.php', ['suppliers.php']);

        $stmt = $db->prepare("SELECT id, name FROM suppliers WHERE id = ? AND owner_id = ?");
        $stmt->execute([$id, $ownerId]);
        $supplier = $stmt->fetch();
        if (!$supplier) {
            setFlash('danger', 'That supplier no longer exists.');
            redirect('suppliers.php');
        }

        $plan = supplierDeletePlan($db, $id, $ownerId);
        if ($plan['return_count'] > 0) {
            // A credit note already moved stock its bill put in, so the bill cannot
            // come off the books until the return is cancelled. Say so before the
            // transaction rather than half-way through it.
            setFlash('danger', 'This supplier has ' . $plan['return_count'] . ' purchase return'
                . ($plan['return_count'] === 1 ? '' : 's')
                . ' on it - cancel those first, they moved stock of their own.');
        } else {
            try {
                // Each bill deleted here takes its cashbook lines with it, and the
                // cashbook's runtime migration is DDL, which cannot run once this
                // transaction is open.
                ensureCashbookWriteReady();
                $db->beginTransaction();
                // Same path the Purchases page takes for one bill: stock reversed,
                // the payments on it and their cashbook rows removed, audit kept.
                foreach ($plan['bills'] as $bill) {
                    purchaseDelete($db, $actor, (int)$bill['id']);
                }
                foreach ($plan['advances'] as $advance) {
                    purchasePaymentDelete($db, $actor, (int)$advance['id']);
                }
                // Products point at their supplier by id only; left alone, a product
                // would keep naming a supplier that no longer exists.
                $db->prepare("UPDATE products SET supplier_id = NULL WHERE supplier_id = ? AND owner_id = ?")
                   ->execute([$id, $ownerId]);
                $db->prepare("DELETE FROM suppliers WHERE id = ? AND owner_id = ?")->execute([$id, $ownerId]);
                $db->commit();

                setFlash('success', 'Supplier deleted'
                    . (count($plan['bills']) || count($plan['advances'])
                        ? ' - ' . count($plan['bills']) . ' purchase bill(s) and '
                          . count($plan['advances']) . ' on-account payment(s) taken off the books.'
                        : '.'));
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('supplier delete failed: ' . $e->getMessage());
                setFlash('danger', $e->getMessage() ?: 'The supplier could not be deleted. Please try again.');
            }
        }
    }
    redirect('suppliers.php');
}

// Search
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $db->prepare(
        "SELECT * FROM suppliers
          WHERE owner_id = ? AND (name LIKE ? OR company LIKE ? OR phone LIKE ? OR email LIKE ?)
          ORDER BY name ASC"
    );
    $like = '%' . $search . '%';
    $stmt->execute([$ownerId, $like, $like, $like, $like]);
} else {
    $stmt = $db->prepare("SELECT * FROM suppliers WHERE owner_id = ? ORDER BY name ASC");
    $stmt->execute([$ownerId]);
}
$suppliers = $stmt->fetchAll();

// The delete button lands here first: nothing is removed until the owner sees the
// list and confirms it, because a supplier's bills are the shop's money history.
$pendingDelete = null;
$deleteId = (int)($_GET['delete'] ?? 0);
if ($deleteId) {
    $stmt = $db->prepare("SELECT id, name, company FROM suppliers WHERE id = ? AND owner_id = ?");
    $stmt->execute([$deleteId, $ownerId]);
    $target = $stmt->fetch();
    if ($target) {
        $pendingDelete = $target;
        $pendingDelete['plan'] = supplierDeletePlan($db, $deleteId, $ownerId);
        $pendingDelete['bill_total'] = 0.0;
        $pendingDelete['bill_due'] = 0.0;
        $pendingDelete['advance_total'] = 0.0;
        foreach ($pendingDelete['plan']['bills'] as $bill) {
            $pendingDelete['bill_total'] += (float)$bill['grand_total'];
            $pendingDelete['bill_due'] += (float)$bill['due_amount'];
        }
        foreach ($pendingDelete['plan']['advances'] as $advance) {
            $pendingDelete['advance_total'] += (float)$advance['amount'];
        }
    }
}

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo sanitize($flash['message']); ?></span>
    </div>
<?php endif; ?>

<?php if ($pendingDelete): ?>
<div class="card" style="margin-bottom: 1.25rem; border-color:#f59e0b;">
    <div class="card-body">
        <h3 style="margin-bottom: 0.5rem;">
            <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
            Delete "<?php echo sanitize($pendingDelete['name']); ?>" and its books?
        </h3>
        <?php $plan = $pendingDelete['plan']; ?>
        <?php if ($plan['return_count'] > 0): ?>
            <p class="text-muted" style="margin-bottom: 0.75rem;">
                This supplier has <?php echo (int)$plan['return_count']; ?> purchase return<?php echo $plan['return_count'] === 1 ? '' : 's'; ?>
                that moved stock of their own, so it cannot be deleted yet. Cancel those returns first.
            </p>
            <a href="suppliers.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        <?php elseif (empty($plan['bills']) && empty($plan['advances'])): ?>
            <p class="text-muted" style="margin-bottom: 0.75rem;">
                Nothing is on the books against this supplier, so deleting it changes no stock and no money.
            </p>
            <form method="POST" style="display: inline;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_confirm">
                <input type="hidden" name="id" value="<?php echo (int)$pendingDelete['id']; ?>">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete supplier</button>
                <a href="suppliers.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php else: ?>
            <p class="text-muted" style="margin-bottom: 0.75rem;">
                Deleting the supplier also takes these off the books - the same as deleting each bill on the
                Purchases page. Stock they added is given back, payments and their cashbook entries are removed,
                and the audit trail keeps the record of what went.
            </p>
            <?php if (!empty($plan['bills'])): ?>
                <div style="margin-bottom: 0.75rem;">
                    <strong>Purchase bills (<?php echo count($plan['bills']); ?>)</strong>
                    <?php foreach ($plan['bills'] as $bill): ?>
                        <div style="padding-left: 0.75rem; border-left: 3px solid #e5e7eb; margin-top: 0.35rem;">
                            <strong><?php echo sanitize($bill['invoice_no']); ?></strong>
                            &middot; <?php echo sanitize($bill['purchase_date']); ?>
                            &middot; <?php echo formatCurrency($bill['grand_total']); ?>
                            <?php if ((float)$bill['due_amount'] > 0.009): ?>
                                <span style="color:#dc2626;">due <?php echo formatCurrency($bill['due_amount']); ?></span>
                            <?php endif; ?>
                            <?php if ($bill['store_name']): ?>
                                <span class="text-muted">&middot; <?php echo sanitize($bill['store_name']); ?></span>
                            <?php endif; ?>
                            <?php if ((int)$bill['stock_applied'] === 1): ?>
                                <span class="text-muted">&middot; stock goes back</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-muted" style="margin-top: 0.4rem;">
                        Total <?php echo formatCurrency($pendingDelete['bill_total']); ?>
                        <?php if ($pendingDelete['bill_due'] > 0.009): ?>
                            &middot; still owed <?php echo formatCurrency($pendingDelete['bill_due']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($plan['advances'])): ?>
                <div style="margin-bottom: 0.75rem;">
                    <strong>On-account payments (<?php echo count($plan['advances']); ?>)</strong>
                    <?php foreach ($plan['advances'] as $advance): ?>
                        <div style="padding-left: 0.75rem; border-left: 3px solid #e5e7eb; margin-top: 0.35rem;">
                            <?php echo sanitize($advance['payment_date']); ?>
                            &middot; <?php echo formatCurrency($advance['amount']); ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-muted" style="margin-top: 0.4rem;">
                        Total <?php echo formatCurrency($pendingDelete['advance_total']); ?>
                    </div>
                </div>
            <?php endif; ?>
            <form method="POST" style="display: inline;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_confirm">
                <input type="hidden" name="id" value="<?php echo (int)$pendingDelete['id']; ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete supplier and its books
                </button>
                <a href="suppliers.php" class="btn btn-secondary">Cancel</a>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
        <h2 style="margin-bottom: 0.5rem;">Suppliers</h2>
        <p class="text-muted">Manage who you purchase stock from</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 0.5rem;">
            <input type="text" name="q" class="form-control" placeholder="Search name, company, phone…"
                   value="<?php echo sanitize($search); ?>" style="min-width: 220px;">
            <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i></button>
        </form>
        <button class="btn btn-primary" onclick="openModal('add')">
            <i class="fas fa-plus"></i> Add Supplier
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="6" class="text-center">No suppliers found.</td></tr>
                <?php endif; ?>
                <?php foreach ($suppliers as $sup): ?>
                    <tr>
                        <td><strong><?php echo sanitize($sup['name']); ?></strong></td>
                        <td><?php echo sanitize($sup['company']); ?></td>
                        <td><?php echo sanitize($sup['phone']); ?></td>
                        <td><?php echo sanitize($sup['email']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $sup['status'] === 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($sup['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-outline" onclick='editSupplier(<?php echo json_encode($sup); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a class="btn btn-sm btn-danger" href="suppliers.php?delete=<?php echo (int)$sup['id']; ?>"
                                   title="Delete supplier">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="supplierModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Supplier</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="supplierId">

                <div class="form-group">
                    <label class="form-label required">Supplier Name</label>
                    <input type="text" name="name" id="supplierName" class="form-control" required placeholder="e.g. Rahim Traders">
                </div>

                <div class="form-group">
                    <label class="form-label">Company</label>
                    <input type="text" name="company" id="supplierCompany" class="form-control" placeholder="Company / business name">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="supplierPhone" class="form-control" placeholder="Contact number">
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="supplierStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
    const supplierModal = document.getElementById('supplierModal');

    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Supplier' : 'Edit Supplier';
        document.getElementById('supplierId').value = '';
        document.getElementById('supplierName').value = '';
        document.getElementById('supplierCompany').value = '';
        document.getElementById('supplierPhone').value = '';
        document.getElementById('supplierStatus').value = 'active';
        supplierModal.classList.add('active');
    }

    function closeModal() {
        supplierModal.classList.remove('active');
    }

    function editSupplier(sup) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').textContent = 'Edit Supplier';
        document.getElementById('supplierId').value = sup.id;
        document.getElementById('supplierName').value = sup.name || '';
        document.getElementById('supplierCompany').value = sup.company || '';
        document.getElementById('supplierPhone').value = sup.phone || '';
        document.getElementById('supplierStatus').value = sup.status || 'active';
        supplierModal.classList.add('active');
    }
</script>

<?php include 'includes/footer.php'; ?>
