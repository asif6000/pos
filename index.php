<?php
/**
 * POS System - the home page, and the front door of the whole site.
 *
 * Everything a visitor types lands here. Signed out, this URL IS the home page -
 * it used to redirect to /home.php, which cost every first-time visitor a round
 * trip, put a filename in the address bar of the site people are told to type,
 * and left the root itself looking like an empty folder to anything that crawls
 * it. Requiring the page instead of bouncing to it keeps every link on it
 * (auth/login.php, privacy.php, #pricing) resolving exactly as before, because
 * this file sits in the same directory.
 *
 * Signed in, this file decides which page the account should actually work on.
 *
 * That choice used to compare users.role against the literal strings 'admin' and
 * 'staff'. Two things were wrong with that:
 *
 *   1. It disagreed with the rest of the app. auth/login.php and the sidebars
 *      choose by permission (defaultLandingPage()), so a manager who had been
 *      granted the dashboard was put somewhere else here, and the Roles page and
 *      the menu were reading two different things.
 *   2. It had no fallback. A role matching neither literal fell to
 *      cashier/pos.php, which a cashier-only account can open but an account
 *      with no POS permission cannot - so it redirected into a page that
 *      immediately refused it.
 *
 * The redirect targets stay relative on purpose: this file IS the document root's
 * index.php, so 'admin/dashboard.php' resolves against '/' in production and
 * against '/admin/' in the XAMPP copy. Hardcoding either one breaks the other.
 */

require_once 'config/db.php';
startSecureSession();

// Signed out: show the home page here. It carries the login and register links,
// so there is no point bouncing a visitor away from either.
if (!isLoggedIn()) {
    require __DIR__ . '/home.php';
    exit;
}

$user = getCurrentUser();

// A real staff member gets their own page first - it is a personal view of one
// person's payroll rather than a menu - but only when a staff record exists,
// so an account given the 'staff' role from the Users page is not sent to a
// page that errors on a missing profile.
if (staffProfileExists($user)) {
    redirect('staff/dashboard.php');
}

// Otherwise the first page this role is actually permitted to open.
$landing = defaultLandingPage();
if ($landing !== null) {
    redirect('admin/' . $landing);
}

// The role can open nothing in this app. Send it to the dashboard, which is the
// one page that reports the situation in the interface rather than as a raw
// refusal, instead of picking a page that would also turn it away.
redirect('admin/dashboard.php');
