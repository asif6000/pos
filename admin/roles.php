<?php
/**
 * POS System - Role & Permission Management
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn() || !hasRole('admin')) {
    redirect('../auth/login.php');
}
requirePermission();

define('PAGE_TITLE', 'Roles & Permissions');
$db = getDB();

// All available permissions with labels
$allPermissions = [
    'dashboard'        => ['label' => 'Dashboard',         'icon' => 'fa-tachometer-alt', 'group' => 'Main'],
    'pos'              => ['label' => 'POS / Billing',      'icon' => 'fa-cash-register',   'group' => 'Main'],
    'products'         => ['label' => 'Products',           'icon' => 'fa-box',             'group' => 'Inventory'],
    'categories'       => ['label' => 'Categories',         'icon' => 'fa-tags',            'group' => 'Inventory'],
    'stock'            => ['label' => 'Stock Management',   'icon' => 'fa-warehouse',       'group' => 'Inventory'],
    'transfers'        => ['label' => 'Transfers',          'icon' => 'fa-exchange-alt',    'group' => 'Inventory'],
    'sales'            => ['label' => 'Sales List',         'icon' => 'fa-receipt',         'group' => 'Sales'],
    'sales_delete'     => ['label' => 'Delete Sales',       'icon' => 'fa-trash',           'group' => 'Sales'],
    'returns'          => ['label' => 'Returns',            'icon' => 'fa-undo',            'group' => 'Sales'],
    'reports'          => ['label' => 'Reports',            'icon' => 'fa-chart-bar',       'group' => 'Sales'],
    'cashbook'         => ['label' => 'Expense',           'icon' => 'fa-book',            'group' => 'Sales'],
    'customers'        => ['label' => 'Customers',          'icon' => 'fa-users',           'group' => 'Management'],
    'users'            => ['label' => 'Users',              'icon' => 'fa-user-cog',        'group' => 'Management'],
    'stores'           => ['label' => 'Stores',             'icon' => 'fa-store',           'group' => 'Management'],
    'plans'            => ['label' => 'Subscription Plans',  'icon' => 'fa-box-open',        'group' => 'Management'],
    'staff'            => ['label' => 'Staff',              'icon' => 'fa-user-tie',       'group' => 'Management'],
    'roles'            => ['label' => 'Roles & Permissions','icon' => 'fa-user-tag',        'group' => 'Management'],
    'settings'         => ['label' => 'Settings',           'icon' => 'fa-cog',             'group' => 'Management'],
    'barcode_settings' => ['label' => 'Barcode Settings',   'icon' => 'fa-barcode',         'group' => 'Management'],
    'vouchers'         => ['label' => 'Vouchers',           'icon' => 'fa-ticket-alt',      'group' => 'Management'],
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    // ── Add / Edit role ──────────────────────────────────────────────────────
    if ($action === 'add' || $action === 'edit') {
        $name        = sanitize($_POST['name'] ?? '');
        $slug        = sanitize($_POST['slug'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $status      = sanitize($_POST['status'] ?? 'active');

        if (empty($name) || empty($slug)) {
            setFlash('danger', 'Name and Slug are required.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO roles (name, slug, description, status) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $slug, $description, $status]);
                    setFlash('success', 'Role created successfully!');
                } else {
                    $stmt = $db->prepare("UPDATE roles SET name=?, slug=?, description=?, status=? WHERE id=?");
                    $stmt->execute([$name, $slug, $description, $status, $id]);
                    setFlash('success', 'Role updated successfully!');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    setFlash('danger', 'Role name or slug already exists.');
                } else {
                    setFlash('danger', 'Database error: ' . $e->getMessage());
                }
            }
        }

    // ── Save permissions ─────────────────────────────────────────────────────
    } elseif ($action === 'save_permissions') {
        $roleSlug    = sanitize($_POST['role_slug'] ?? '');
        $permissions = $_POST['permissions'] ?? [];

        if (empty($roleSlug)) {
            setFlash('danger', 'Invalid role.');
        } elseif ($roleSlug === 'admin') {
            setFlash('warning', 'Admin role always has full access — permissions cannot be restricted.');
        } else {
            try {
                // Create table if it doesn't exist yet (safe migration)
                $db->exec("CREATE TABLE IF NOT EXISTS role_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    role_slug VARCHAR(100) NOT NULL,
                    permission VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_role_perm (role_slug, permission),
                    INDEX idx_role_slug (role_slug)
                ) ENGINE=InnoDB");

                // Delete existing permissions for this role
                $db->prepare("DELETE FROM role_permissions WHERE role_slug = ?")->execute([$roleSlug]);

                // Insert selected permissions
                $validPerms = array_keys($allPermissions);
                $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES (?, ?)");
                foreach ($permissions as $perm) {
                    if (in_array($perm, $validPerms)) {
                        $stmt->execute([$roleSlug, $perm]);
                    }
                }

                // Clear permission cache for all sessions and bump version for realtime
                clearPermissionCache();
                setFlash('success', "Permissions for '{$roleSlug}' saved successfully! Changes take effect immediately for all users.");
            } catch (PDOException $e) {
                setFlash('danger', 'Error saving permissions: ' . $e->getMessage());
            }
        }

    // ── Delete role ──────────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("SELECT slug FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch();

        if ($role && in_array($role['slug'], ['admin'])) {
            setFlash('danger', 'Cannot delete system default roles.');
        } else {
            try {
                if ($role) {
                    $db->prepare("DELETE FROM role_permissions WHERE role_slug = ?")->execute([$role['slug']]);
                }
                $db->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
                clearPermissionCache();
                setFlash('success', 'Role deleted successfully!');
            } catch (PDOException $e) {
                setFlash('danger', 'Error deleting role.');
            }
        }
    }

    redirect('roles.php');
}

// Load all roles
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// Load current permissions per role
$permsByRole = [];
try {
    $rows = $db->query("SELECT role_slug, permission FROM role_permissions")->fetchAll();
    foreach ($rows as $row) {
        $permsByRole[$row['role_slug']][] = $row['permission'];
    }
} catch (Exception $e) {
    // Table might not exist yet — handled gracefully
}

// Group permissions for display
$grouped = [];
foreach ($allPermissions as $key => $meta) {
    $grouped[$meta['group']][$key] = $meta;
}

include 'includes/header.php';
?>

<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <h2 style="margin-bottom: 0.25rem;">Roles & Permissions</h2>
        <p class="text-muted">Create roles and control which pages each role can access</p>
    </div>
    <button class="btn btn-primary" onclick="openRoleModal('add')">
        <i class="fas fa-plus"></i> Add Role
    </button>
</div>

<!-- Roles Table -->
<div class="card" style="margin-bottom: 2rem;">
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                    <?php
                    $perms = $permsByRole[$role['slug']] ?? [];
                    $permCount = ($role['slug'] === 'admin') ? count($allPermissions) : count($perms);
                    $totalCount = count($allPermissions);
                    ?>
                    <tr>
                        <td><strong><?php echo sanitize($role['name']); ?></strong></td>
                        <td><code><?php echo sanitize($role['slug']); ?></code></td>
                        <td><?php echo sanitize($role['description']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $role['status'] === 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst($role['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($role['slug'] === 'admin'): ?>
                                <span class="badge badge-success">Full Access (<?php echo $totalCount; ?>/<?php echo $totalCount; ?>)</span>
                            <?php else: ?>
                                <span class="badge badge-<?php echo $permCount > 0 ? 'primary' : 'warning'; ?>">
                                    <?php echo $permCount; ?>/<?php echo $totalCount; ?> permissions
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-sm btn-outline" onclick='openRoleModal("edit", <?php echo json_encode($role); ?>)' title="Edit Role">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($role['slug'] !== 'admin'): ?>
                                    <button class="btn btn-sm btn-primary" onclick='openPermModal("<?php echo $role['slug']; ?>", "<?php echo htmlspecialchars($role['name']); ?>")'  title="Set Permissions">
                                        <i class="fas fa-key"></i> Permissions
                                    </button>
                                <?php endif; ?>
                                <a href="users.php?action=add&role=<?php echo $role['slug']; ?>" class="btn btn-sm btn-outline" title="Create User with this Role">
                                    <i class="fas fa-user-plus"></i>
                                </a>
                                <?php if (!in_array($role['slug'], ['admin'])): ?>
                                    <button class="btn btn-sm btn-danger" onclick="deleteRole(<?php echo $role['id']; ?>, '<?php echo sanitize($role['name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Permission Matrix Quick View -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-shield-alt"></i> Permission Matrix</h3>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: auto;">
        <table class="table" style="min-width: 600px;">
            <thead>
                <tr>
                    <th style="min-width: 160px;">Permission</th>
                    <?php foreach ($roles as $role): ?>
                        <th style="text-align: center;"><?php echo sanitize($role['name']); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped as $groupName => $groupPerms): ?>
                    <tr style="background: #f8fafc;">
                        <td colspan="<?php echo count($roles) + 1; ?>" style="font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; padding: 0.5rem 1rem;">
                            <?php echo $groupName; ?>
                        </td>
                    </tr>
                    <?php foreach ($groupPerms as $permKey => $permMeta): ?>
                        <tr>
                            <td>
                                <i class="fas <?php echo $permMeta['icon']; ?>" style="color: #6b7280; width: 16px;"></i>
                                <?php echo $permMeta['label']; ?>
                            </td>
                            <?php foreach ($roles as $role): ?>
                                <td style="text-align: center;">
                                    <?php
                                    $hasIt = ($role['slug'] === 'admin') || in_array($permKey, $permsByRole[$role['slug']] ?? []);
                                    ?>
                                    <?php if ($hasIt): ?>
                                        <i class="fas fa-check-circle" style="color: #10b981; font-size: 16px;"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle" style="color: #e5e7eb; font-size: 16px;"></i>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══ Role Add/Edit Modal ═══ -->
<div class="modal-overlay" id="roleModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="roleModalTitle">Add Role</h3>
            <button class="modal-close" onclick="closeRoleModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="roleFormAction" value="add">
                <input type="hidden" name="id" id="roleId">
                <div class="form-group">
                    <label class="form-label required">Role Name</label>
                    <input type="text" name="name" id="roleName" class="form-control" required placeholder="e.g. Sales Manager">
                </div>
                <div class="form-group">
                    <label class="form-label required">Slug</label>
                    <input type="text" name="slug" id="roleSlug" class="form-control" required placeholder="e.g. sales_manager">
                    <small class="form-text">Lowercase, no spaces. Used internally for permission checks.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="roleDescription" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="roleStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRoleModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Role</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Permissions Modal ═══ -->
<div class="modal-overlay" id="permModal">
    <div class="modal" style="max-width: 620px;">
        <div class="modal-header">
            <h3 class="modal-title" id="permModalTitle">Set Permissions</h3>
            <button class="modal-close" onclick="closePermModal()">&times;</button>
        </div>
        <form method="POST" id="permForm">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="role_slug" id="permRoleSlug">
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: 1rem; font-size: 13px;">
                    <i class="fas fa-info-circle"></i>
                    Check the pages/features this role can access. Changes take effect immediately for all users.
                </p>

                <?php foreach ($grouped as $groupName => $groupPerms): ?>
                    <div style="margin-bottom: 1.25rem;">
                        <div style="font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-bottom: 0.5rem; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb;">
                            <?php echo $groupName; ?>
                        </div>
                        <div class="form-grid" style="gap: 0.4rem;">
                            <?php foreach ($groupPerms as $permKey => $permMeta): ?>
                                <label style="display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.5rem; border-radius: 6px; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                                    <input type="checkbox" name="permissions[]" value="<?php echo $permKey; ?>"
                                        class="perm-checkbox"
                                        data-key="<?php echo $permKey; ?>"
                                        style="width: 15px; height: 15px; cursor: pointer;">
                                    <i class="fas <?php echo $permMeta['icon']; ?>" style="color: #6b7280; width: 14px; font-size: 13px;"></i>
                                    <span style="font-size: 13px;"><?php echo $permMeta['label']; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(true)">
                        <i class="fas fa-check-square"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(false)">
                        <i class="fas fa-square"></i> Deselect All
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePermModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Permissions</button>
            </div>
        </form>
    </div>
</div>

<!-- Permissions data for JS -->
<script>
const allPermsByRole = <?php echo json_encode($permsByRole); ?>;

// ── Role Modal ────────────────────────────────────────────────────────────────
function openRoleModal(action, role) {
    document.getElementById('roleFormAction').value = action;
    document.getElementById('roleModalTitle').textContent = action === 'add' ? 'Add Role' : 'Edit Role';

    if (action === 'add') {
        document.getElementById('roleId').value = '';
        document.getElementById('roleName').value = '';
        document.getElementById('roleSlug').value = '';
        document.getElementById('roleDescription').value = '';
        document.getElementById('roleStatus').value = 'active';
        document.getElementById('roleSlug').readOnly = false;
    } else {
        document.getElementById('roleId').value = role.id;
        document.getElementById('roleName').value = role.name;
        document.getElementById('roleSlug').value = role.slug;
        document.getElementById('roleDescription').value = role.description || '';
        document.getElementById('roleStatus').value = role.status;
        document.getElementById('roleSlug').readOnly = ['admin'].includes(role.slug);
    }
    document.getElementById('roleModal').classList.add('active');
}
function closeRoleModal() { document.getElementById('roleModal').classList.remove('active'); }

// Auto-generate slug
document.getElementById('roleName').addEventListener('input', function() {
    if (document.getElementById('roleFormAction').value === 'add') {
        document.getElementById('roleSlug').value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
    }
});

// ── Permission Modal ──────────────────────────────────────────────────────────
function openPermModal(roleSlug, roleName) {
    document.getElementById('permRoleSlug').value = roleSlug;
    document.getElementById('permModalTitle').textContent = 'Permissions: ' + roleName;

    const currentPerms = allPermsByRole[roleSlug] || [];
    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.checked = currentPerms.includes(cb.dataset.key);
    });

    document.getElementById('permModal').classList.add('active');
}
function closePermModal() { document.getElementById('permModal').classList.remove('active'); }

function toggleAll(state) {
    document.querySelectorAll('.perm-checkbox').forEach(cb => cb.checked = state);
}

// ── Delete Role ───────────────────────────────────────────────────────────────
function deleteRole(id, name) {
    if (confirm('Delete role "' + name + '"? This will also remove all its permissions.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modals on overlay click
document.getElementById('roleModal').addEventListener('click', function(e) { if(e.target===this) closeRoleModal(); });
document.getElementById('permModal').addEventListener('click', function(e) { if(e.target===this) closePermModal(); });
</script>

<?php include 'includes/footer.php'; ?>
