<?php
/**
 * POS System - Stock Transfers
 * Manage product transfers between stores
 */

require_once '../config/db.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Stock Transfers');

$db = getDB();
$currentUser = getCurrentUser();
$myStoreId = $currentUser['store_id']; 
$isAdmin = hasRole('admin');

// Get Stores - Filter by owner
$stmt = $db->prepare("SELECT * FROM stores WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$currentUser['owner_id']]);
$stores = $stmt->fetchAll();

// Get products for transfer (name/barcode for simple selector) - Filter by owner
$stmt = $db->prepare("SELECT id, name, barcode FROM products WHERE status = 'active' AND owner_id = ? ORDER BY name");
$stmt->execute([$currentUser['owner_id']]);
$products = $stmt->fetchAll();

// Get Transfers List - Filter by owner
$sql = "SELECT t.*, s1.name as from_store, s2.name as to_store, u.name as user_name 
        FROM transfers t 
        JOIN stores s1 ON t.from_store_id = s1.id 
        JOIN stores s2 ON t.to_store_id = s2.id 
        JOIN users u ON t.created_by = u.id
        WHERE u.owner_id = ?";
$params = [$currentUser['owner_id']];

// Filtering: If not admin, maybe only see transfers involving my store?
if (!$isAdmin && $myStoreId) {
    $sql .= " AND (t.from_store_id = ? OR t.to_store_id = ?)";
    $params[] = $myStoreId;
    $params[] = $myStoreId;
}

$sql .= " ORDER BY t.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transfers = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .transfer-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .transfer-items-container {
        border: 1px solid var(--border-color);
        border-radius: var(--border-radius);
        padding: 1rem;
        background: var(--gray-50);
        margin-bottom: 1rem;
    }
    .transfer-item-row {
        display: grid;
        grid-template-columns: 3fr 1fr 1fr auto;
        gap: 1rem;
        align-items: end;
        margin-bottom: 0.75rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px dashed var(--border-color);
    }
    .transfer-item-row:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <h2 style="margin-bottom: 0.5rem;">Stock Transfers</h2>
        <p class="text-muted">Move inventory between store locations</p>
    </div>
    <button class="btn btn-primary" onclick="openTransferModal()">
        <i class="fas fa-exchange-alt"></i> New Transfer
    </button>
</div>

<!-- Transfers List -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transfers)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No transfers found</td></tr>
                <?php else: ?>
                    <?php foreach ($transfers as $t): ?>
                        <tr>
                            <td><strong><?php echo sanitize($t['reference_no']); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($t['created_at'])); ?></td>
                            <td>
                                <span class="badge badge-outline"><?php echo sanitize($t['from_store']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-outline"><?php echo sanitize($t['to_store']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $t['status'] === 'completed' ? 'success' : ($t['status'] === 'pending' ? 'warning' : 'danger'); ?>">
                                    <?php echo ucfirst($t['status']); ?>
                                </span>
                            </td>
                            <td><?php echo sanitize($t['user_name']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline" onclick="viewTransfer(<?php echo $t['id']; ?>)">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal-overlay" id="transferModal">
    <div class="modal" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">New Transfer</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="transferForm" onsubmit="processTransfer(event)">
                <div class="transfer-form-grid">
                    <div class="form-group">
                        <label class="form-label required">From Store (Source)</label>
                        <select id="fromStoreId" class="form-control" required onchange="updateProductStockVisibility()">
                            <?php if ($isAdmin): ?>
                                <option value="">Select Source Store</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>"><?php echo sanitize($store['name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- If filter store, only show my store? Or default to my store? -->
                                <!-- Assuming myStoreId is available -->
                                <?php foreach ($stores as $store): ?>
                                    <?php if ($store['id'] == $myStoreId): ?>
                                        <option value="<?php echo $store['id']; ?>" selected><?php echo sanitize($store['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">To Store (Destination)</label>
                        <select id="toStoreId" class="form-control" required>
                             <option value="">Select Destination Store</option>
                             <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>"><?php echo sanitize($store['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Transfer Items</label>
                    <div class="transfer-items-container" id="transferItemsList">
                        <!-- Items items will be added here -->
                        <div class="text-center text-muted" id="emptyItemsMsg">No items added</div>
                    </div>
                    
                    <div style="display: flex; gap: 1rem;">
                        <div style="flex: 3;">
                            <select id="productSelect" class="form-control">
                                <option value="">Select Product...</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-name="<?php echo sanitize($p['name']); ?>">
                                        <?php echo sanitize($p['name']) . ($p['barcode'] ? " ({$p['barcode']})" : ""); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex: 1;">
                            <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="addItemToTransfer()">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea id="transferNote" class="form-control" rows="2"></textarea>
                </div>

                <div style="text-align: right; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitTransferBtn">Complete Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const transferModal = document.getElementById('transferModal');
    let transferItems = [];

    function openTransferModal() {
        transferItems = [];
        renderTransferItems();
        document.getElementById('transferForm').reset();
        
        // Auto-select my store if not admin
        <?php if (!$isAdmin && $myStoreId): ?>
            document.getElementById('fromStoreId').value = '<?php echo $myStoreId; ?>';
            updateProductStockVisibility(); // Trigger stock check
        <?php endif; ?>
        
        transferModal.classList.add('active');
    }

    function closeModal() {
        transferModal.classList.remove('active');
    }

    async function addItemToTransfer() {
        const select = document.getElementById('productSelect');
        const productId = select.value;
        const productName = select.options[select.selectedIndex]?.dataset.name;
        const fromStoreId = document.getElementById('fromStoreId').value;

        if (!productId) {
            alert('Please select a product');
            return;
        }
        if (!fromStoreId) {
            alert('Please select a source store first');
            return;
        }

        // Check if already added
        if (transferItems.find(i => i.id == productId)) {
            alert('Product already added to transfer list');
            return;
        }

        // Check Available Stock via API
        try {
            const response = await fetch(`api/get-store-stock.php?store_id=${fromStoreId}&product_id=${productId}`);
            const data = await response.json();
            
            if (data.success) {
                const stock = data.stock;
                
                transferItems.push({
                    id: productId,
                    name: productName,
                    available: stock,
                    quantity: 1
                });
                renderTransferItems();
                select.value = ''; // Reset select
            } else {
                alert('Error fetching stock information');
            }
        } catch (e) {
            console.error(e);
            alert('Error fetching stock information');
        }
    }

    function removeItem(index) {
        transferItems.splice(index, 1);
        renderTransferItems();
    }

    function updateItemQuantity(index, qty) {
        qty = parseInt(qty);
        if (qty <= 0) {
            alert('Quantity must be greater than 0');
            renderTransferItems(); // Reset input
            return;
        }
        if (qty > transferItems[index].available) {
            alert('Cannot transfer more than available stock (' + transferItems[index].available + ')');
            // Allow them to set it but maybe warn visually? Or strict block. Strict block is safer.
            transferItems[index].quantity = transferItems[index].available;
        } else {
            transferItems[index].quantity = qty;
        }
        renderTransferItems(); // Re-render to show corrected value
    }

    function renderTransferItems() {
        const container = document.getElementById('transferItemsList');
        if (transferItems.length === 0) {
            container.innerHTML = '<div class="text-center text-muted" id="emptyItemsMsg">No items added</div>';
            return;
        }

        container.innerHTML = transferItems.map((item, index) => `
            <div class="transfer-item-row">
                <div>
                    <div><strong>${item.name}</strong></div>
                    <small class="text-muted">Available: ${item.available}</small>
                </div>
                <div>
                    <input type="number" class="form-control" value="${item.quantity}" min="1" max="${item.available}"
                        onchange="updateItemQuantity(${index}, this.value)">
                </div>
                <div style="text-align: right;">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    function updateProductStockVisibility() {
        // Reset items when store changes because stock availability changes
        if (transferItems.length > 0) {
            if (confirm('Changing source store will clear current items. Continue?')) {
                transferItems = [];
                renderTransferItems();
            } else {
                // Revert selection logic unimplemented for now, simple clear
               // Ideally revert the select value.
            }
        }
    }

    async function processTransfer(e) {
        e.preventDefault();
        
        const fromStore = document.getElementById('fromStoreId').value;
        const toStore = document.getElementById('toStoreId').value;
        const note = document.getElementById('transferNote').value;

        if (!fromStore || !toStore) {
            alert('Please select both stores');
            return;
        }
        if (fromStore === toStore) {
            alert('Source and Destination stores cannot be the same');
            return;
        }
        if (transferItems.length === 0) {
            alert('Please add items to transfer');
            return;
        }

        const btn = document.getElementById('submitTransferBtn');
        btn.disabled = true;
        btn.innerHTML = 'Processing...';

        try {
            const response = await fetch('api/process-transfer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    from_store_id: fromStore,
                    to_store_id: toStore,
                    items: transferItems,
                    note: note
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('Transfer completed successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + result.message);
                btn.disabled = false;
                btn.innerHTML = 'Complete Transfer';
            }
        } catch (error) {
            alert('Error processing transfer');
            btn.disabled = false;
            btn.innerHTML = 'Complete Transfer';
        }
    }

    function viewTransfer(id) {
        // TODO: Implement View Transfer Details Modal
        alert('View details for Transfer #' + id + ' (Coming Soon)');
    }
</script>

<?php include 'includes/footer.php'; ?>
