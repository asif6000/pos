<?php
/**
 * The side menu, in one place.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The web sidebar used to own this table and the staff app owned a second,
 * hand-copied version of it (NATIVE_TABS + ADMIN_SECTIONS in App.tsx). Two lists
 * drift: the web gained Google Contacts and the app never showed it, the web hid
 * Transfers while the app still offered it, and a phone only found out at sign-in
 * - if at all - because the app's copy was baked into the installed APK.
 *
 * Now the app asks mobile_api/menu.php for the same tree this file produces, so
 * one row here decides both screens: the label, the order, whether the entry is
 * visible at all, and - through 'native' - whether the app has its own screen for
 * it or opens the real web page in a WebView.
 *
 * THE FIELDS
 * ----------
 * 'perm'   the hasPermission() slug, which applies the shop's package *and* the
 *          role's own permissions. This is the only check a normal entry needs.
 * 'roles'  an explicit hasRole() list for the few entries that are not permission
 *          slugs at all (My Package).
 * 'page'   the page name without .php, which is what $currentPage holds in the web
 *          header, so it decides which row is highlighted.
 * 'href'   the web link relative to admin/ - the filename, which is also what the
 *          app passes to the WebView as ?page=.
 * 'icon'   FontAwesome's glyph; 'emoji' is the app's. Same meaning, two font sets.
 * 'native' the app screen that shows this page when the app has one of its own.
 *
 * A group is either a plain link (no children) or a parent that links to the
 * group's own page plus a child list. A group disappears when neither it nor any
 * of its children is allowed. When a parent is not allowed but a child is, the web
 * turns the parent row into a plain toggle and the app turns it into a heading -
 * so no page is ever reachable only by guessing its URL.
 *
 * Order and labels follow the approved design. Transfers sits under Inventory: the
 * page, its 'transfers' permission slug and the header's URL guard all existed
 * already, so this row is the only thing that was missing. It shows where the plan
 * includes the module and the role has it ticked, like every other row.
 */

function navGroups()
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-gauge-high', 'emoji' => '📊',
         'href' => 'dashboard.php', 'page' => 'dashboard', 'perm' => 'dashboard', 'native' => 'dashboard'],

        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'fas fa-receipt', 'emoji' => '🧾',
         'href' => 'sales.php', 'page' => 'sales', 'perm' => 'sales', 'native' => 'sales',
         'children' => [
            ['label' => 'Returns', 'icon' => 'fas fa-undo', 'emoji' => '↩️',
             'href' => 'returns.php', 'page' => 'returns', 'perm' => 'returns', 'native' => 'returns'],
            ['label' => 'Lottery Sales', 'icon' => 'fas fa-ticket-alt', 'emoji' => '🎟',
             'href' => 'lottery-sales.php', 'page' => 'lottery-sales', 'perm' => 'sales'],
            ['label' => 'Expense', 'icon' => 'fas fa-book', 'emoji' => '💵',
             'href' => 'expense.php', 'page' => 'expense', 'perm' => 'cashbook'],
         ]],

        ['key' => 'pos', 'label' => 'POS', 'icon' => 'fas fa-cash-register', 'emoji' => '🛒',
         'href' => 'pos.php', 'page' => 'pos', 'perm' => 'pos', 'native' => 'pos'],

        ['key' => 'products', 'label' => 'Products', 'icon' => 'fas fa-box', 'emoji' => '📦',
         'href' => 'products.php', 'page' => 'products', 'perm' => 'products',
         'children' => [
            ['label' => 'Variable Name', 'icon' => 'fas fa-sliders', 'emoji' => '🔧',
             'href' => 'variables.php', 'page' => 'variables', 'perm' => 'variables'],
            ['label' => 'Categories', 'icon' => 'fas fa-tags', 'emoji' => '🏷',
             'href' => 'categories.php', 'page' => 'categories', 'perm' => 'categories'],
         ]],

        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'fas fa-warehouse', 'emoji' => '🏪',
         'href' => 'stock.php', 'page' => 'stock', 'perm' => 'stock',
         'children' => [
            ['label' => 'Transfers', 'icon' => 'fas fa-right-left', 'emoji' => '🔁',
             'href' => 'transfers.php', 'page' => 'transfers', 'perm' => 'transfers'],
         ]],

        ['key' => 'customers', 'label' => 'Customers', 'icon' => 'fas fa-users', 'emoji' => '👥',
         'href' => 'customers.php', 'page' => 'customers', 'perm' => 'customers',
         'children' => [
            ['label' => 'Google Contacts', 'icon' => 'fab fa-google', 'emoji' => '📇', 'tint' => '#4285F4',
             'href' => 'google-contacts.php', 'page' => 'google-contacts', 'perm' => 'google_contacts'],
         ]],

        // The purchase module lives under here: one permission slug (suppliers)
        // gates the whole group, so a role with the tick gets the lot and a role
        // without it sees no row at all - no half-visible menu.
        ['key' => 'suppliers', 'label' => 'Suppliers', 'icon' => 'fas fa-truck', 'emoji' => '🚚',
         'href' => 'suppliers.php', 'page' => 'suppliers', 'perm' => 'suppliers',
         'children' => [
            ['label' => 'Purchases', 'icon' => 'fas fa-truck-ramp-box', 'emoji' => '📥',
             'href' => 'purchases.php', 'page' => 'purchases', 'perm' => 'suppliers'],
            /* HIDDEN: the purchase list carries its own "New Purchase" button, so the
               menu row only repeated a step the user was already standing next to.
            ['label' => 'Add Purchase', 'icon' => 'fas fa-plus', 'emoji' => '➕',
             'href' => 'purchase-add.php', 'page' => 'purchase-add', 'perm' => 'suppliers'],
            */
            ['label' => 'Supplier Dues', 'icon' => 'fas fa-scale-unbalanced', 'emoji' => '⚖️',
             'href' => 'supplier-dues.php', 'page' => 'supplier-dues', 'perm' => 'suppliers'],
            /* HIDDEN: every ledger is one click from a purchase or a dues row, so it
               does not need a top-level seat.
            ['label' => 'Supplier Ledger', 'icon' => 'fas fa-book-open', 'emoji' => '📖',
             'href' => 'supplier-ledger.php', 'page' => 'supplier-ledger', 'perm' => 'suppliers'],
            */
            ['label' => 'Purchase Returns', 'icon' => 'fas fa-rotate-left', 'emoji' => '🔁',
             'href' => 'purchase-returns.php', 'page' => 'purchase-returns', 'perm' => 'suppliers'],
            ['label' => 'Purchase Report', 'icon' => 'fas fa-chart-column', 'emoji' => '📊',
             'href' => 'purchase-report.php', 'page' => 'purchase-report', 'perm' => 'suppliers'],
         ]],

        ['key' => 'sms', 'label' => 'SMS', 'icon' => 'fas fa-comment-sms', 'emoji' => '💬', 'tint' => '#93c5fd',
         'href' => 'sms-service.php', 'page' => 'sms-service', 'perm' => 'marketing',
         'children' => [
            ['label' => 'Marketing', 'icon' => 'fas fa-bullhorn', 'emoji' => '📣', 'tint' => '#25D366',
             'href' => 'marketing.php', 'page' => 'marketing', 'perm' => 'marketing'],
         ]],

        // The shop's own storefront. No 'native' screen exists for it in the staff
        // app yet, so the app opens the real web page in a WebView like every
        // other web-only module.
        ['key' => 'ecommerce', 'label' => 'Online Shop', 'icon' => 'fas fa-basket-shopping', 'emoji' => '🛍',
         'href' => 'ecommerce.php', 'page' => 'ecommerce', 'perm' => 'ecommerce'],

        // One cashbook for the whole shop: the drawer, the bank, the wallet
        // numbers. Expense stays under Sales because that is where the money was
        // spent; this is where it sits.
        ['key' => 'cashbook', 'label' => 'Cashbook', 'icon' => 'fas fa-book-open', 'emoji' => '📒',
         'href' => 'cashbook.php', 'page' => 'cashbook', 'perm' => 'cashbook'],

        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'fas fa-chart-bar', 'emoji' => '📈',
         'href' => 'reports.php', 'page' => 'reports', 'perm' => 'reports',
         'children' => [
            ['label' => 'Discount Report', 'icon' => 'fas fa-percent', 'emoji' => '✂️',
             'href' => 'discount-report.php', 'page' => 'discount-report', 'perm' => 'reports'],
         ]],

        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'fas fa-gear', 'emoji' => '⚙️',
         'href' => 'settings.php', 'page' => 'settings', 'perm' => 'settings',
         'children' => [
            ['label' => 'Users', 'icon' => 'fas fa-user-cog', 'emoji' => '🧑‍💼',
             'href' => 'users.php', 'page' => 'users', 'perm' => 'users'],
            ['label' => 'Stores', 'icon' => 'fas fa-store', 'emoji' => '🏬',
             'href' => 'stores.php', 'page' => 'stores', 'perm' => 'stores'],
            ['label' => 'Staff', 'icon' => 'fas fa-user-tie', 'emoji' => '👔',
             'href' => 'staff.php', 'page' => 'staff', 'perm' => 'staff'],
            ['label' => 'Roles & Permissions', 'icon' => 'fas fa-user-tag', 'emoji' => '🛡',
             'href' => 'roles.php', 'page' => 'roles', 'perm' => 'roles'],
            ['label' => 'My Package', 'icon' => 'fas fa-credit-card', 'emoji' => '💳',
             'href' => 'subscription.php', 'page' => 'subscription', 'roles' => ['admin', 'owner', 'manager']],
            ['label' => 'Barcode Settings', 'icon' => 'fas fa-barcode', 'emoji' => '🏷',
             'href' => 'barcode-settings.php', 'page' => 'barcode-settings', 'perm' => 'barcode_settings'],
            ['label' => 'Voucher Settings', 'icon' => 'fas fa-ticket', 'emoji' => '🎟',
             'href' => 'voucher-settings.php', 'page' => 'voucher-settings', 'perm' => 'vouchers'],
         ]],
    ];
}

/**
 * Can this account see this row? 'roles' wins when it is present, otherwise the
 * permission slug decides - which is the package check and the role check together.
 */
function navItemVisible(array $item)
{
    if (!empty($item['roles'])) {
        return hasRole($item['roles']);
    }
    if (!empty($item['perm'])) {
        return hasPermission($item['perm']);
    }
    return true;
}

/**
 * The menu with everything this account may not see already removed, in the shape
 * the staff app renders. A parent that is not allowed but has allowed children
 * stays on as a heading, because the children have to live somewhere.
 *
 * @return array<int,array{key:string,label:string,emoji:string,page:?string,native:?string,openable:bool,children:array}>
 */
function navMobileMenu(array $groups = null)
{
    if ($groups === null) {
        $groups = navGroups();
    }

    $item = function (array $row, $allowHeading) {
        $out = [
            'label'    => (string)$row['label'],
            'emoji'    => isset($row['emoji']) ? (string)$row['emoji'] : '•',
            // The filename is what the WebView navigates to; it already exists as a
            // real file in admin/, which auth/app_sso.php re-checks before it opens.
            'page'     => basename((string)$row['href']),
            'native'   => isset($row['native']) ? (string)$row['native'] : null,
            'openable' => $allowHeading,
        ];
        return $out;
    };

    $out = [];
    foreach ($groups as $g) {
        $kids = [];
        foreach (isset($g['children']) ? $g['children'] : [] as $c) {
            if (navItemVisible($c)) {
                $kids[] = $item($c, true);
            }
        }

        $selfOk = navItemVisible($g);
        if (!$selfOk && !$kids) {
            continue;
        }

        $row = $item($g, $selfOk);
        $row['key'] = (string)$g['key'];
        // A heading the account cannot open has no screen to go to, native or web.
        if (!$selfOk) {
            $row['page']   = null;
            $row['native'] = null;
        }
        $row['children'] = $kids;
        $out[] = $row;
    }
    return $out;
}

/**
 * Every page this account's menu actually offers, flat. The app treats it as the
 * list the WebView may be pointed at, so a phone cannot be talked into opening a
 * page the sidebar does not even show.
 */
function navMobilePages(array $groups = null)
{
    $pages = [];
    foreach (navMobileMenu($groups) as $row) {
        if ($row['page'] !== null) {
            $pages[] = $row['page'];
        }
        foreach ($row['children'] as $c) {
            if ($c['page'] !== null) {
                $pages[] = $c['page'];
            }
        }
    }
    return array_values(array_unique($pages));
}
