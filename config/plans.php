<?php
/**
 * Plan catalogue - the single source of truth for what a package costs.
 *
 * WHY PRICES LIVE HERE AND NOT IN THE PAGE
 * ----------------------------------------
 * The landing page shows these prices, but the amount actually charged is read
 * from THIS file on the server when a checkout is created. A price that came
 * from the browser could be edited to ৳1 before submitting, so the client copy
 * is display-only and this array is authoritative. Change a price here and both
 * the checkout charge and (via home.php) the shown price move together.
 *
 * Values mirror the pricing section rendered on home.php.
 *
 * 'perms' is the machine-readable feature gate - the list of permission keys the
 * plan unlocks. hasPermission() consults it (see config/db.php) so a shop can only
 * reach the modules its plan includes; upgrading adds the next tier's keys.
 * Cumulative ladder: free ⊂ starter ⊂ business ⊂ pro. 'dashboard' and 'settings'
 * are always allowed regardless, so an account can never lock itself out of its
 * own plan page.
 *
 * THE TWO HALVES OF AN ACCESS DECISION
 * ------------------------------------
 * A permission key on a plan says the package SELLS that module; a tick on the
 * Roles page says this particular staff account may use it. Both have to be yes -
 * planAllows() runs first inside hasPermission(), so an admin can tick anything
 * their package covers and only what it covers, and a ticked box stops working the
 * day the shop drops to a plan that does not include it. That is the rule the shop
 * owner asked for: the package decides the ceiling, the role decides who under it.
 *
 * 'store_limit' is the same ceiling for branches, and it is a number rather than a
 * permission because "২ টি দোকান" cannot be said by a yes/no flag. null (or the key
 * being absent) means no cap. Enforced in admin/stores.php via planStoreLimit().
 */

return [
    'free' => [
        'slug'       => 'free',
        'name'       => 'Free Trial',
        'tagline'    => '৭ দিন ফ্রি ট্রায়াল',
        'monthly'    => 0,
        'yearly'     => 0,
        'popular'    => false,
        'trial_days' => 7,
        'hidden'     => true,   // auto-granted at signup, not sold on the pricing grid
        'store_limit' => 1,
        // 'returns' and 'variables' are here because both belong to a sale that is
        // already being rung on the trial till: a till that can take an order but
        // cannot undo part of it, and a product form that offers Size/Colour boxes
        // the trial shop has no screen to fill in, reads as a broken product rather
        // than as an upgrade. The trial's ceiling is its seven days and one store,
        // not a missing row in the side menu.
        'perms'      => ['dashboard', 'settings', 'pos', 'products', 'categories', 'sales', 'returns', 'customers', 'barcode_settings', 'variables'],
    ],
    'starter' => [
        'slug'     => 'starter',
        'name'     => 'Starter',
        'tagline'  => 'ছোট দোকান',
        'monthly'  => 299,
        'yearly'   => 2999,
        'popular'  => false,
        'store_limit' => 1,
        // 'returns' is here because a small shop sends goods back too - it was a
        // Business-only key, which meant a Starter till could record a sale and
        // then not undo any part of it.
        'features' => ['১ টি দোকান', 'POS / বিলিং', 'পণ্য ও স্টক', 'বিক্রি ব্যবস্থাপনা', 'পণ্য রিটার্ন', 'কাস্টমার', 'বেসিক রিপোর্ট', 'মোবাইল অ্যাপ'],
        'perms'    => ['dashboard', 'settings', 'pos', 'products', 'categories', 'sales', 'returns', 'customers', 'stock', 'variables', 'reports', 'google_contacts', 'barcode_settings'],
    ],
    'business' => [
        'slug'     => 'business',
        'name'     => 'Business',
        'tagline'  => 'মাঝারি দোকান',
        'monthly'  => 499,
        'yearly'   => 4999,
        'popular'  => true,
        // Two branches: 'stores' unlocks the page and the limit says how far it goes.
        'store_limit' => 2,
        'features' => ['স্টার্টারের সবকিছু', '২ টি দোকান ব্যবস্থাপনা', 'উন্নত রিপোর্ট', 'খরচ / Expense', 'স্টাফ', 'ইউজার ও রোল / পারমিশন', 'বারকোড', 'সপ্লায়ার ও ট্রান্সফার', 'অনলাইন শপ'],
        // 'users' and 'roles' moved down from Pro. The package gate in
        // hasPermission() runs before the admin bypass, so while 'roles' sat only on
        // Pro every cheaper shop - owner included - was refused the Roles &
        // Permissions page outright. Ticking what their own staff may open was then
        // impossible at any price below 799, which is what "staff role permission
        // kaj korcena" turned out to be. Selling who may SELL stays with the plan;
        // deciding who may manage is no longer a paid feature.
        //
        // 'ecommerce' is the online shop: products published from the POS and orders
        // that come back into it. It is sold as a module, so it lives in 'perms'
        // like every other one and a role has to be ticked for it as well.
        'perms'    => ['dashboard', 'settings', 'pos', 'products', 'categories', 'sales', 'customers', 'stock', 'variables', 'reports', 'google_contacts',
                       'suppliers', 'transfers', 'returns', 'cashbook', 'staff', 'barcode_settings', 'sales_delete',
                       'stores', 'users', 'roles', 'ecommerce'],
    ],
    'pro' => [
        'slug'     => 'pro',
        'name'     => 'Pro',
        'tagline'  => 'বড় / একাধিক দোকান',
        'monthly'  => 799,
        'yearly'   => 7999,
        'popular'  => false,
        // No key: unlimited branches, which is what Pro is sold on.
        'features' => ['বিজনেসের সবকিছু', 'সীমাহীন দোকান', 'প্রোডাক্ট সিরিয়াল', 'মার্কেটিং', 'ভাউচার', 'অনলাইন শপ', 'প্রায়োরিটি সাপোর্ট'],
        'perms'    => ['dashboard', 'settings', 'pos', 'products', 'categories', 'sales', 'customers', 'stock', 'variables', 'reports', 'google_contacts',
                       'suppliers', 'transfers', 'returns', 'cashbook', 'staff', 'barcode_settings', 'sales_delete',
                       'stores', 'users', 'roles', 'vouchers', 'marketing', 'ecommerce'],
    ],
];
