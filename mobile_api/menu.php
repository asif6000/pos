<?php
/**
 * Mobile API - the side menu, as this account sees it.
 *
 * WHY THIS EXISTS
 * ---------------
 * The app used to carry its own copy of the back-office menu in App.tsx. That
 * copy could only ever be as good as the release it was built into: a page added
 * to the web sidebar stayed invisible on every phone until a new APK, a permission
 * revoked yesterday kept its button until the user signed out, and the two lists
 * had already drifted (the app offered Stock Transfers, which the web menu does
 * not have).
 *
 * So the menu is fetched from the same config/nav-menu.php the web sidebar renders,
 * filtered by the same hasPermission() call, and re-fetched while the app is open.
 * What a phone shows is now what the shop's package and the account's role say
 * today, not what they said when the APK was built.
 *
 * WHAT THE CALLER GETS
 * --------------------
 * 'menu'                the visible tree, in the web's order. 'native' marks the
 *                       rows the app draws itself; anything else opens the real web
 *                       page in the app's WebView through 'page'.
 * 'pages'               the same rows as a flat whitelist, so the app can refuse to
 *                       open a page the menu never offered.
 * 'permissions'         the raw map, still sent so an older app build keeps working
 *                       alongside this endpoint.
 * 'shop_name'           the name the web sidebar prints in its brand block, read
 *                       from the same settings row admin/includes/header.php uses,
 *                       so the phone says what the laptop says. Null when the shop
 *                       has not filled it in - the app then shows its own fallback.
 * 'permissions_version' the bump counter clearPermissionCache() raises when the
 *                       owner ticks a box on the Roles page. The app compares it
 *                       against the last one it saw and re-fetches on a difference,
 *                       which is what makes a revoked page disappear without the
 *                       phone being signed out.
 */

require_once '../config/mobile_auth.php';
require_once '../config/nav-menu.php';
mobileCors();

$auth = mobileAuth(null, function () {
    // Inside mobileAuth's open-session window on purpose: navMobileMenu() and
    // mobilePermissionMap() both end at hasPermission(), which after the close
    // would read a fresh, empty web session and report every page as locked.
    return [
        'menu'        => navMobileMenu(),
        'pages'       => navMobilePages(),
        'permissions' => mobilePermissionMap(),
    ];
});
$opened = $auth['data'];

$version = null;
try {
    $st = getDB()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'permissions_version' LIMIT 1");
    $st->execute();
    $version = $st->fetchColumn();
} catch (Throwable $e) {
    $version = null;
}

// The brand block of the web sidebar shows the shop's own name, which lives in the
// per-owner settings table. An installed 1.3.4 phone polls this endpoint already,
// so shipping the name here means the drawer can match the web without a new APK.
$shopName = null;
try {
    $st = getDB()->prepare("SELECT setting_value FROM settings WHERE owner_id = ? AND setting_key = 'shop_name' LIMIT 1");
    $st->execute([$auth['owner_id']]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && trim((string)$v) !== '') {
        $shopName = trim((string)$v);
    }
} catch (Throwable $e) {
    $shopName = null;
}

echo json_encode([
    'success'             => true,
    'menu'                => $opened['menu'],
    'pages'               => $opened['pages'],
    'permissions'         => $opened['permissions'],
    'role'                => $auth['role'],
    'is_admin'            => $auth['is_admin'],
    'plan'                => currentPlanSlug(),
    'store_id'            => $auth['store_id'],
    'shop_name'           => $shopName,
    'permissions_version' => $version === null || $version === false ? null : (string)$version,
    'server_time'         => time(),
]);
