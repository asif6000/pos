<?php
/**
 * POS System - Variable Name
 *
 * The shop's vocabulary: named lists like Size, Colour and Unit, and the values
 * inside them. Products pick from these in the Add Product form, and every pick
 * is optional.
 *
 * Modals here are the project's own modal-overlay plus a .active class. Bootstrap
 * is not loaded anywhere in this project, so none of its markup is available.
 */

require_once '../config/db.php';
require_once '../config/product_variables.php';
startSecureSession();

if (!isLoggedIn() || !hasPermission('variables')) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Variable Name');

$db = getDB();

// Checked BEFORE productVariablesEnsureTables(), not after. That call does more
// than create the tables - it also seeds them, and the seed immediately runs
// "SELECT id FROM product_variables". On a host where the tables are genuinely
// absent the seed is what raised "Base table or view not found", so a guard
// placed after it never got a chance to run.
appRequireTables(['product_variables', 'product_variable_values', 'product_variable_map'], $db);

productVariablesEnsureTables($db);

// ── Handle form submissions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add a variable
    if ($action === 'add_variable') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            setFlash('danger', 'Variable name is required.');
        } else {
            $slug = productVariableSlug($name);
            $dupe = $db->prepare("SELECT id FROM product_variables WHERE slug = ? LIMIT 1");
            $dupe->execute([$slug]);
            if ($dupe->fetchColumn()) {
                // Two lists that differ only in case or spacing would produce the
                // same slug, and the Unit special case keys off it. Refuse rather
                // than quietly pointing both at the same thing.
                setFlash('danger', "A variable called '{$slug}' already exists.");
            } else {
                $max = $db->query("SELECT COALESCE(MAX(sort_order), -1) FROM product_variables")->fetchColumn();
                $db->prepare("INSERT INTO product_variables (name, slug, sort_order, status) VALUES (?,?,?,'active')")
                    ->execute([$name, $slug, (int)$max + 1]);
                setFlash('success', "Variable '{$name}' created. Add its values below.");
            }
        }

    // Rename a variable
    } elseif ($action === 'rename_variable') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            setFlash('danger', 'Variable name is required.');
        } else {
            $db->prepare("UPDATE product_variables SET name = ? WHERE id = ?")->execute([$name, $id]);
            setFlash('success', 'Variable renamed.');
        }

    // Add values to a variable
    } elseif ($action === 'add_values') {
        $id = (int)($_POST['variable_id'] ?? 0);
        // Accepts one per line, so a shop can paste a whole size run at once.
        $raw = $_POST['values'] ?? '';
        $lines = preg_split('/[\r\n,]+/', $raw);
        $max = $db->query("SELECT COALESCE(MAX(sort_order), -1) FROM product_variable_values
                           WHERE variable_id = " . (int)$id)->fetchColumn();
        $ins = $db->prepare("INSERT IGNORE INTO product_variable_values (variable_id, value, sort_order)
                             VALUES (?,?,?)");
        $added = 0;
        $order = (int)$max + 1;
        foreach ($lines as $line) {
            $v = trim($line);
            if ($v === '') { continue; }
            $ins->execute([$id, mb_substr($v, 0, 60), $order]);
            // rowCount() is on the statement, not on $db - $db->rowCount() is a
            // fatal, and it killed the request after the first insert, so a
            // multi-line paste silently saved one value and reported nothing.
            if ($ins->rowCount() > 0) { $added++; $order++; }
        }
        if ($added) {
            setFlash('success', $added . ' value(s) added.');
        } else {
            setFlash('warning', 'Nothing added - those values are already in the list.');
        }

    // Remove one value
    } elseif ($action === 'delete_value') {
        $valueId = (int)($_POST['value_id'] ?? 0);
        $varId = (int)($_POST['variable_id'] ?? 0);
        // Drop the map rows too, otherwise a product would keep pointing at a
        // value that no longer exists and the form would show it as selected
        // with nothing behind it.
        $db->prepare("DELETE FROM product_variable_map WHERE value_id = ?")->execute([$valueId]);
        $db->prepare("DELETE FROM product_variable_values WHERE id = ? AND variable_id = ?")
            ->execute([$valueId, $varId]);
        setFlash('success', 'Value removed.');

    // Delete a whole variable
    } elseif ($action === 'delete_variable') {
        $id = (int)($_POST['id'] ?? 0);
        $slug = $db->query("SELECT slug FROM product_variables WHERE id = " . (int)$id)->fetchColumn();
        if ($slug === 'unit') {
            // products.unit is a real column read by the POS and the invoice.
            // Deleting the list would empty the Unit dropdown and, on the next
            // product save, write '' into that column.
            setFlash('danger', 'The Unit list cannot be deleted - it is the unit column '
                . 'that products, invoices and reports all read.');
        } else {
            $db->prepare("DELETE FROM product_variable_map WHERE variable_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM product_variable_values WHERE variable_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM product_variables WHERE id = ?")->execute([$id]);
            setFlash('success', 'Variable and its values removed. Products that used it '
                . 'keep their other variables.');
        }
    }

    redirect('variables.php');
}

$variables = getProductVariables($db, true);

// How many products use each variable, so a delete is not a surprise.
$usage = [];
foreach ($variables as $v) {
    $usage[$v['id']] = (int)$db->query("SELECT COUNT(*) FROM product_variable_map
                                        WHERE variable_id = " . $v['id'])->fetchColumn();
}

$flash = getFlash();
require 'includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-info-circle"></i> <?php echo sanitize($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- Page Header -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; gap: 1rem; flex-wrap: wrap;">
    <p class="text-muted" style="margin: 0;">
        Size, Colour, Unit - ei type er list banano. Add Product form e
        product er jonno value choose kora jabe. Sob kichu optional.
    </p>
    <button class="btn btn-primary" onclick="openVarModal()">
        <i class="fas fa-plus"></i> New Variable
    </button>
</div>

<?php if (!$variables): ?>
    <div class="card">
        <div class="card-body text-center" style="padding: 3rem 1rem;">
            <i class="fas fa-sliders-h" style="font-size: 2.5rem; color: var(--gray-300);"></i>
            <p class="text-muted" style="margin-top: 1rem;">
                Ekhono kono variable nai. "New Variable" diye Size, Colour ba Unit banano.
            </p>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($variables as $v): ?>
    <div class="card" style="margin-bottom: 1.25rem;">
        <div class="card-header" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <div>
                <span class="card-title" style="margin: 0;"><?php echo sanitize($v['name']); ?></span>
                <small class="text-muted" style="display: block;">
                    <?php echo $usage[$v['id']]; ?> product using this
                    <?php if ($v['slug'] === 'unit'): ?>
                        &middot; <strong>built-in</strong> - oita product ar unit column-e chole
                    <?php endif; ?>
                </small>
            </div>
            <div style="margin-left: auto; display: flex; gap: 0.5rem;">
                <button class="btn btn-sm btn-outline" type="button"
                    onclick="renameVar(<?php echo $v['id']; ?>, <?php echo htmlspecialchars(json_encode($v['name']), ENT_QUOTES, 'UTF-8'); ?>)">
                    <i class="fas fa-pen"></i> Rename
                </button>
                <?php if ($v['slug'] !== 'unit'): ?>
                <button class="btn btn-sm btn-danger" type="button"
                    onclick="deleteVar(<?php echo $v['id']; ?>, <?php echo htmlspecialchars(json_encode($v['name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $usage[$v['id']]; ?>)">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body">
            <?php if (!$v['values']): ?>
                <p class="text-muted" style="margin: 0;">
                    Ekhono kono value nai - niche add korun.
                </p>
            <?php else: ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <?php foreach ($v['values'] as $val): ?>
                        <span style="display: inline-flex; align-items: center; gap: 0.4rem;
                                  font-size: 11.5px; background: var(--gray-100);
                                  border: 1px solid var(--gray-200); border-radius: 6px;
                                  padding: 4px 9px; color: var(--gray-700);">
                            <?php echo sanitize($val['value']); ?>
                            <form method="POST" style="display: inline;"
                                onsubmit="return confirm('<?php echo htmlspecialchars($val['value'], ENT_QUOTES, 'UTF-8'); ?> remove korben?');">
                                <input type="hidden" name="action" value="delete_value">
                                <input type="hidden" name="value_id" value="<?php echo (int)$val['id']; ?>">
                                <input type="hidden" name="variable_id" value="<?php echo (int)$v['id']; ?>">
                                <button type="submit" title="Remove" aria-label="Remove value"
                                    style="background: none; border: 0; cursor: pointer;
                                           color: var(--gray-400); padding: 0 0 0 0.15rem;
                                           line-height: 1; font-size: 11px;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap;">
                <input type="hidden" name="action" value="add_values">
                <input type="hidden" name="variable_id" value="<?php echo (int)$v['id']; ?>">
                <input type="text" name="values" class="form-control" style="flex: 1; min-width: 220px;"
                    placeholder="Value likhun, ba ek line e onek ta (comma / newline)">
                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> Add
                </button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<!-- New variable -->
<div class="modal-overlay" id="varModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">New Variable</h3>
            <button class="modal-close" onclick="closeVarModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_variable">
                <div class="form-group">
                    <label class="form-label required">Variable Name</label>
                    <input type="text" name="name" class="form-control" required maxlength="60"
                        placeholder="Size, Colour, Material...">
                    <small class="form-text">
                        Size / Colour / Unit chara onno kichu o dite paren, jemon
                        "Material" ba "Flavour".
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVarModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Rename -->
<div class="modal-overlay" id="renameModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">Rename Variable</h3>
            <button class="modal-close" onclick="closeRenameModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="rename_variable">
                <input type="hidden" name="id" id="renameId">
                <div class="form-group">
                    <label class="form-label required">Variable Name</label>
                    <input type="text" name="name" id="renameName" class="form-control" required maxlength="60">
                    <small class="form-text">
                        Naam bodlale products er value thik thakbe - shudhu label change hoy.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRenameModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal" style="max-width: 440px;">
        <div class="modal-header">
            <h3 class="modal-title">Delete Variable</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="delete_variable">
                <input type="hidden" name="id" id="deleteId">
                <p id="deleteText" style="margin: 0;"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openVarModal() {
        document.getElementById('varModal').classList.add('active');
    }
    function closeVarModal() {
        document.getElementById('varModal').classList.remove('active');
    }

    function renameVar(id, name) {
        document.getElementById('renameId').value = id;
        document.getElementById('renameName').value = name;
        document.getElementById('renameModal').classList.add('active');
    }
    function closeRenameModal() {
        document.getElementById('renameModal').classList.remove('active');
    }

    function deleteVar(id, name, usedBy) {
        var msg = '"' + name + '" delete korben? Tar sob value ar ';
        msg += usedBy > 0
            ? usedBy + ' product er ei value gulo remove hoye jabe. Product gulo thakbe, shudhu ei variable ta thabe na.'
            : 'kono product use korche na.';
        if (!confirm(msg)) { return; }
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteText').textContent = msg;
        document.getElementById('deleteModal').classList.add('active');
    }
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    // Clicking the backdrop closes, matching the rest of the project.
    ['varModal', 'renameModal', 'deleteModal'].forEach(function (id) {
        document.getElementById(id).addEventListener('click', function (e) {
            if (e.target === this) { this.classList.remove('active'); }
        });
    });
</script>

<?php require 'includes/footer.php'; ?>
