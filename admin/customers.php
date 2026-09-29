<?php
/**
 * POS System - Customer Management
 */

require_once '../config/db.php';
require_once '../config/google_contacts.php';
// marketingNormalizePhone() and waAgentCheckCustomer() live behind these two.
// They are required in their own right rather than left to be pulled in by
// something else, which is how a screen ends up calling a function that is not
// defined on it and dying with a fatal the shop only ever sees as a blank page.
require_once '../config/marketing.php';
require_once '../config/wa_agent.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Customers');

$db = getDB();
$currentUser = getCurrentUser();

// Handle CSV export for Google Contacts
if (isset($_GET['export']) && $_GET['export'] === 'google_contacts') {
    $stmt = $db->prepare("SELECT name, phone, email, address FROM customers WHERE owner_id = ? ORDER BY name ASC");
    $stmt->execute([$currentUser['owner_id']]);
    $allCust = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pos_customers_google_contacts.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Given Name', 'Family Name', 'E-mail 1 - Type', 'E-mail 1 - Value', 'Phone 1 - Type', 'Phone 1 - Value', 'Address 1 - Type', 'Address 1 - Formatted', 'Group Membership']);
    foreach ($allCust as $c) {
        $parts = explode(' ', trim($c['name']), 2);
        fputcsv($out, [
            $c['name'],
            $parts[0] ?? '',
            $parts[1] ?? '',
            'Work',
            $c['email'] ?? '',
            'Mobile',
            $c['phone'] ?? '',
            'Home',
            $c['address'] ?? '',
            'POS Customers'
        ]);
    }
    fclose($out);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $rawPhone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        // Stored in the international form the Marketing page and every send path
        // already use, so one customer is one row whichever screen created it.
        $phone = marketingNormalizePhone($rawPhone);

        if (empty($name)) {
            setFlash('danger', 'Customer name is required.');
        } elseif (trim($rawPhone) !== '' && $phone === '') {
            setFlash('danger', 'Phone number ta bujha jay ni.');
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address, owner_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $phone, $email ?: null, $address, $currentUser['owner_id']]);
                    $newId = $db->lastInsertId();

                    // Sync to Google Contacts
                    $googleSync = syncCustomerToGoogleContacts([
                        'id'      => $newId,
                        'name'    => $name,
                        'phone'   => $phone,
                        'email'   => $email,
                        'address' => $address
                    ], $currentUser['owner_id']);

                    if (!empty($googleSync['success'])) {
                        setFlash('success', 'Customer added & synced to Google Contacts!');
                    } else {
                        setFlash('success', 'Customer added successfully!');
                    }
                } else {
                    // owner_id was missing from this WHERE. A customer id is
                    // guessable, so any signed-in shop could rewrite any other
                    // shop's customer name, number and address simply by posting
                    // its id.
                    $stmt = $db->prepare("UPDATE customers SET name=?, phone=?, email=?, address=? WHERE id=? AND owner_id=?");
                    $stmt->execute([$name, $phone, $email ?: null, $address, $id, $currentUser['owner_id']]);
                    if ($stmt->rowCount() === 0) {
                        // No such row, or one belonging to another shop. The two
                        // must look identical from out here, or the second case
                        // becomes a way to probe for which ids exist. $newId is
                        // left at 0, which is what stops the check below from
                        // running against a row that is not ours.
                        setFlash('danger', 'Customer ta nai ba apnar nai.');
                        $newId = 0;
                    } else {
                        setFlash('success', 'Customer updated successfully!');
                        $newId = $id;
                    }
                }

                // A changed number is a different number, so the old badge is
                // cleared before the new one is asked about - otherwise a customer
                // is shown as reachable on a number that has been replaced.
                //
                // Eight seconds, not twenty. This is inside a page load, and a
                // shared host can end a long request before it answers - which
                // would throw away the check and the message alike. The badge is
                // left unchecked rather than guessed at, and Marketing -> Check
                // numbers fills it in.
                if ($phone !== '' && $newId > 0) {
                    $db->prepare("UPDATE customers SET has_whatsapp = NULL, whatsapp_checked_at = NULL
                                   WHERE id = ? AND owner_id = ?")
                        ->execute([$newId, $currentUser['owner_id']]);
                    try {
                        $wa = waAgentCheckCustomer($db, $currentUser['owner_id'], $newId, 8);
                        if ($wa['ok']) {
                            setFlash($wa['exists'] ? 'success' : 'warning',
                                $wa['exists'] ? 'WhatsApp number ta paoa gelo.' : 'Ei number e WhatsApp nai.');
                        } elseif ($wa['reason'] !== 'agent mode off') {
                            setFlash('warning', 'WhatsApp check hoye ni - Marketing page theke Check numbers chalan.');
                        }
                    } catch (Throwable $e) {
                        error_log('customer whatsapp check: ' . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                setFlash('danger', 'Database error. Please try again.');
            }
        }
    } elseif ($action === 'sync_google') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND owner_id = ?");
        $stmt->execute([$id, $currentUser['owner_id']]);
        $cust = $stmt->fetch();
        if ($cust) {
            $res = syncCustomerToGoogleContacts($cust, $currentUser['owner_id']);
            if ($res['success']) {
                setFlash('success', 'Customer successfully synced to Google Contacts!');
            } else {
                setFlash('danger', 'Google sync failed: ' . $res['message']);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Set customer_id to NULL in sales instead of deleting
            $stmt = $db->prepare("UPDATE sales SET customer_id = NULL WHERE customer_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('success', 'Customer deleted successfully!');
        } catch (PDOException $e) {
            setFlash('danger', 'Error deleting customer.');
        }
    }
    redirect('customers.php');
}

// Search - Filter by owner
$search = sanitize($_GET['search'] ?? '');

/**
 * How the list is ordered.
 *
 * Defaults to the biggest spenders first, because this list is mostly used to
 * find somebody worth looking after, and the most recently added row is rarely
 * that. The other orders are kept because a list that can only be ordered one
 * way stops being a list and becomes a report.
 *
 * The value is matched against a fixed set, never interpolated into the SQL.
 */
$sort = $_GET['sort'] ?? 'top';
$sortSql = [
    'new'    => 'c.created_at DESC',
    'name'   => 'c.name ASC',
    'orders' => 'total_orders DESC, c.name ASC',
    'spent'  => 'total_spent DESC, c.name ASC',
][$sort] ?? 'total_spent DESC, total_orders DESC, c.name ASC';
$sql = "SELECT c.*,
        (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as total_orders,
        (SELECT COALESCE(SUM(total), 0) FROM sales WHERE customer_id = c.id) as total_spent
        FROM customers c WHERE c.owner_id = ?";
$params = [$currentUser['owner_id']];

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    // Append, never replace. Overwriting $params here dropped the owner_id
    // binding set above, so the statement ended up with four placeholders and
    // three values and PDO threw "Invalid parameter number" - a fatal error that
    // took the whole page down whenever anyone searched for a customer.
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY $sortSql";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

/**
 * Who counts as a VIP, decided in PHP rather than in SQL.
 *
 * The top three by total spend, and only among customers who have actually
 * spent something - a crown above a row of dashes would be a lie. Deciding it
 * here rather than in the query means the same rule can be reused by the header
 * count and by anything added later, and it is the one place to change it if
 * the shop wants a different number of VIPs.
 */
const VIP_COUNT = 3;
$vipRanks = [];
$rank = 0;
foreach ($customers as $i => $c) {
    if ((float)$c['total_spent'] > 0) {
        $rank++;
        if ($rank <= VIP_COUNT) { $vipRanks[(int)$c['id']] = $rank; }
    }
}
$vipTotal = 0;
foreach ($customers as $c) { if ((float)$c['total_spent'] > 0) { $vipTotal++; } }

/**
 * Headline numbers for the cards above the list.
 *
 * Scoped to the same owner as the list, and that is deliberate rather than
 * incidental: a count that disagrees with the rows underneath it is worse than
 * no count. Customers with owner_id NULL are invisible here and always have been
 * - they are not "missing from the total", they belong to no shop.
 */
$stats = [
    'customers'  => 0,
    'new_month'  => 0,
    'with_phone' => 0,
    'orders'     => 0,
    'revenue'    => 0.0,
    'buyers'     => 0,   // customers who have actually bought
    'repeat'     => 0,   // customers with more than one order
];
// False if any summary query failed. The cards then show a dash rather than a
// number, so a broken query cannot masquerade as a real total.
$statsOk = false;

try {
    $s = $db->prepare("SELECT
            COUNT(*) AS total,
            SUM(TRIM(COALESCE(phone, '')) <> '') AS with_phone,
            SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS new_month
        FROM customers WHERE owner_id = ?");
    $s->execute([$currentUser['owner_id']]);
    $r = $s->fetch() ?: [];
    $stats['customers']  = (int)($r['total'] ?? 0);
    $stats['with_phone'] = (int)($r['with_phone'] ?? 0);
    $stats['new_month']  = (int)($r['new_month'] ?? 0);

    // Sales attributed to a customer. Walk-in sales have customer_id NULL and
    // are counted on the dashboard instead; mixing them in here would make this
    // card a different number from the one the shop already sees elsewhere.
    $s2 = $db->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(total), 0) AS revenue
                        FROM sales
                        WHERE owner_id = ? AND customer_id IS NOT NULL");
    $s2->execute([$currentUser['owner_id']]);
    $r2 = $s2->fetch() ?: [];
    $stats['orders']  = (int)($r2['orders'] ?? 0);
    $stats['revenue'] = (float)($r2['revenue'] ?? 0);

    // How many of the customers on this page have actually spent anything, and
    // how many came back more than once. A list where almost nobody has bought
    // is a real thing worth seeing rather than hiding.
    // How many of the customers on this page have actually spent anything, and
    // how many came back more than once. A list where almost nobody has bought
    // is a real thing worth seeing rather than hiding.
    //
    // The alias is `returning`, not `repeat`: REPEAT is a reserved control-flow
    // keyword in MariaDB, so `AS repeat` is a syntax error there. The broad
    // catch below turned that error into a confident "0", which is worse than
    // showing nothing - hence $statsOk, which makes the card render a dash.
    // Two plain queries rather than one LEFT JOIN with a CASE per row. The join
    // needed an alias for the second count, and both `repeat` and `returning` are
    // reserved words in MariaDB 10.4 - so it did not run at all, and the broad
    // catch below turned that into a confident "0". Hence $statsOk, which makes
    // the card render a dash rather than a number that is simply untrue.
    $s3 = $db->prepare("SELECT COUNT(DISTINCT customer_id) AS buyers
                        FROM sales WHERE owner_id = ? AND customer_id IS NOT NULL");
    $s3->execute([$currentUser['owner_id']]);
    $stats['buyers'] = (int)$s3->fetchColumn();

    $s4 = $db->prepare("SELECT COUNT(*) FROM (
                            SELECT customer_id FROM sales
                            WHERE owner_id = ? AND customer_id IS NOT NULL
                            GROUP BY customer_id HAVING COUNT(*) > 1
                        ) AS more_than_one");
    $s4->execute([$currentUser['owner_id']]);
    $stats['repeat'] = (int)$s4->fetchColumn();

    $statsOk = true;
} catch (Exception $e) {
    // The cards sit on top of a working list, so a failed summary must not take
    // the page down. But it must not display a number either, because "0 buyers"
    // reads as a fact about the shop when it is really a broken query. Leaving
    // $statsOk false makes the affected card render a dash: visibly wrong rather
    // than quietly wrong.
    $statsOk = false;
}
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
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem;">
    <p class="text-muted" style="margin: 0;">Manage your customer database</p>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="google-contacts.php" class="btn btn-outline" title="Configure Google Contacts Sync">
            <i class="fab fa-google" style="color: #4285F4;"></i> Google Contacts
        </a>
        <a href="customers.php?export=google_contacts" class="btn btn-secondary" title="Export CSV for Google Contacts">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <button class="btn btn-primary" onclick="openModal('add')">
            <i class="fas fa-plus"></i> Add Customer
        </button>
    </div>
</div>

<!-- Headline numbers. Placed above the search so the shop can see the shape of
     its customer base before it starts searching inside it. -->
<div class="mkpi-row">
    <div class="mkpi">
        <div class="mkpi-label"><i class="fas fa-users"></i> Total Customers</div>
        <div class="mkpi-value"><?php echo number_format($stats['customers']); ?></div>
        <div class="mkpi-sub">
            <?php if ($stats['new_month'] > 0): ?>
                <span class="up">+<?php echo (int)$stats['new_month']; ?></span> ei mash e
            <?php else: ?>
                ei mash e notun nai
            <?php endif; ?>
        </div>
    </div>

    <div class="mkpi">
        <div class="mkpi-label"><i class="fas fa-receipt"></i> Customer Orders</div>
        <div class="mkpi-value"><?php echo number_format($stats['orders']); ?></div>
        <div class="mkpi-sub">
            <?php if ($stats['orders'] > 0): ?>
                avg <?php echo formatCurrency($stats['revenue'] / $stats['orders']); ?> per order
            <?php else: ?>
                kono customer order nai
            <?php endif; ?>
        </div>
    </div>

    <div class="mkpi">
        <div class="mkpi-label"><i class="fas fa-sack-dollar"></i> Customer Sales</div>
        <div class="mkpi-value"><?php echo formatCurrency($stats['revenue']); ?></div>
        <div class="mkpi-sub">walk-in ar e count hoy nai</div>
    </div>

    <div class="mkpi">
        <div class="mkpi-label"><i class="fas fa-user-check"></i> Buyers</div>
        <div class="mkpi-value">
            <?php echo $statsOk ? number_format($stats['buyers']) : '&mdash;'; ?>
        </div>
        <div class="mkpi-sub">
            <?php if (!$statsOk): ?>
                gonoona jay ni
            <?php elseif ($stats['customers'] > 0): ?>
                <?php echo (int)$stats['repeat']; ?> jara ekbar beshi esheche
            <?php else: ?>
                ekhono kono customer nai
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Search -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                <input type="text" name="search" class="form-control" placeholder="Search by name, phone, or email..."
                    value="<?php echo $search; ?>">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
            <a href="customers.php" class="btn btn-secondary"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="card-header cust-sortbar">
        <div class="cust-sort-links">
            <?php
            // The search term is carried through every sort link, so changing the
            // order does not silently throw away a search.
            $qs = static function ($key) use ($search) {
                $p = ['sort' => $key];
                if ($search !== '') { $p['search'] = $search; }
                return 'customers.php?' . http_build_query($p);
            };
            $tabs = [
                'top'    => 'Top customers',
                'spent'  => 'Highest spend',
                'orders' => 'Most orders',
                'new'    => 'Newest',
                'name'   => 'Name A-Z',
            ];
            foreach ($tabs as $key => $label):
                $on = ($sort === $key);
                ?>
                <a class="cust-sort<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo $qs($key); ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>
        <span class="cust-vip-note">
            <i class="fa-solid fa-crown"></i>
            VIP = top <?php echo VIP_COUNT; ?> by total spend<?php
                echo $vipTotal > 0 ? ' &middot; ' . $vipTotal . ' customer kinyeche' : '';
            ?>
        </span>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <?php /* Email is deliberately not a column. The shop asked for
                             it to come off the table, and only the display was
                             dropped: the address is still stored, still saved
                             from the add/edit form, and still matched by the
                             search box. Deleting the data as well would have been
                             a different request, and a destructive one. */ ?>
                    <th>Address</th>
                    <th>Google</th>
                    <th>Orders</th>
                    <th>Total Spent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted">No customers found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr<?php echo isset($vipRanks[(int)$customer['id']]) ? ' class="is-vip"' : ''; ?>>
                            <td>
                                <div class="cust-name">
                                    <?php if (isset($vipRanks[(int)$customer['id']])): ?>
                                        <span class="cust-vip" title="VIP customer - top by total spend">
                                            <i class="fa-solid fa-crown"></i>
                                            <span class="cust-vip-rank"><?php echo $vipRanks[(int)$customer['id']]; ?></span>
                                        </span>
                                    <?php endif; ?>
                                    <strong><?php echo sanitize($customer['name']); ?></strong>
                                </div>
                            </td>
                            <td>
                                <?php echo sanitize($customer['phone'] ?: '-'); ?>
                            </td>
                            <td class="text-muted">
                                <?php echo sanitize($customer['address'] ?: '-'); ?>
                            </td>
                            <td>
                                <?php if (!empty($customer['google_synced_at'])): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; font-size: 0.75rem; padding: 4px 8px; border-radius: 4px;" title="Synced: <?php echo $customer['google_synced_at']; ?>">
                                        <i class="fab fa-google"></i> Synced
                                    </span>
                                <?php else: ?>
                                    <form method="POST" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="sync_google">
                                        <input type="hidden" name="id" value="<?php echo $customer['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="padding: 2px 7px; font-size: 0.72rem; color: #4b5563;" title="Sync to Google Contacts">
                                            <i class="fab fa-google"></i> Sync
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-primary">
                                    <?php echo $customer['total_orders']; ?>
                                </span></td>
                            <td><strong>
                                    <?php echo formatCurrency($customer['total_spent']); ?>
                                </strong></td>
                            <td>
                                <div class="table-actions">
                                    <a href="customer-history.php?id=<?php echo $customer['id']; ?>"
                                        class="btn btn-sm btn-outline" title="View History">
                                        <i class="fas fa-history"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline"
                                        onclick='editCustomer(<?php echo json_encode($customer); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger"
                                        onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo sanitize($customer['name']); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="customerModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Customer</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="customerId">

                <div class="form-group">
                    <label class="form-label required">Customer Name</label>
                    <input type="text" name="name" id="customerName" class="form-control" value="SC " required>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" id="customerPhone" class="form-control" placeholder="01XXXXXXXXX" autocomplete="off">
                    <div class="cust-dup" id="customerPhoneDup"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" id="customerEmail" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="customerAddress" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script src="<?php echo htmlspecialchars(assetUrl('assets/js/customer-dup-check.js'), ENT_QUOTES, 'UTF-8'); ?>"
        data-lookup-endpoint="api/customer-lookup.php"></script>
<script>
    function openModal(action) {
        document.getElementById('formAction').value = action;
        document.getElementById('modalTitle').textContent = action === 'add' ? 'Add Customer' : 'Edit Customer';
        document.getElementById('customerModal').classList.add('active');
        if (action === 'add') {
            document.getElementById('customerId').value = '';
            document.getElementById('customerName').value = 'SC ';
            document.getElementById('customerPhone').value = '';
            document.getElementById('customerEmail').value = '';
            document.getElementById('customerAddress').value = '';
            setTimeout(function() {
                const nameInput = document.getElementById('customerName');
                if (nameInput) {
                    nameInput.focus();
                    nameInput.setSelectionRange(nameInput.value.length, nameInput.value.length);
                }
            }, 100);
        }
    }

    function closeModal() {
        document.getElementById('customerModal').classList.remove('active');
    }

    function editCustomer(customer) {
        openModal('edit');
        document.getElementById('customerId').value = customer.id;
        document.getElementById('customerName').value = customer.name;
        document.getElementById('customerPhone').value = customer.phone || '';
        document.getElementById('customerEmail').value = customer.email || '';
        document.getElementById('customerAddress').value = customer.address || '';
    }

    function deleteCustomer(id, name) {
        if (confirm('Delete customer "' + name + '"? Their sales history will be preserved.')) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }

    document.getElementById('customerModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    // Live "already saved" hint on the phone field. excludeId is read at lookup
    // time rather than captured, so editing a customer never reports that
    // customer's own number back as a duplicate of itself.
    window.PosCustomerDup.attach(
        document.getElementById('customerPhone'),
        document.getElementById('customerPhoneDup'),
        { excludeId: function () { return document.getElementById('customerId').value; } }
    );

    // This form posts to the page rather than to an API, so the confirm has to
    // sit on the submit event. Same rule as the POS: warn, then let the
    // shopkeeper decide - one number can belong to two people.
    document.querySelector('#customerModal form').addEventListener('submit', function (e) {
        const input = document.getElementById('customerPhone');
        if (!input || !input.value.trim()) { return; }

        window.PosCustomerDup.find(input.value, document.getElementById('customerId').value,
            function (res) {
                if (!res.matches.length) { return; }   // nothing wrong, let it post
                const who = res.matches.map(m => m.name).join(', ');
                const msg = res.matches.some(m => m.orphaned)
                    ? 'Ei number ta already save ache (' + who + ').\n\nKono shop er sathe jode noy.\n\nEktar beshi row banbe. Thik chole gele OK kore ese nao, noyto Cancel kore din.'
                    : 'Ei number ta already save ache (' + who + ').\n\nEktar beshi row banbe. Thik chole gele OK kore ese nao, noyto Cancel kore din.';
                if (!confirm(msg)) { e.preventDefault(); }
            });
    });
</script>

<?php include 'includes/footer.php'; ?>