<?php
/**
 * POS System - Main Entry Point
 *
 * The front door. Everything a visitor types lands here, so this file decides
 * between the public landing page and the page the logged-in account should
 * actually work on.
 *
 * This used to pick the destination by comparing users.role against the literal
 * strings 'admin' and 'staff'. Two things were wrong with that:
 *
 *   1. It disagreed with the rest of the app. auth/login.php and all three
 *      sidebars choose by permission (defaultLandingPage()), so a manager who
 *      had been granted the dashboard was put somewhere else here, and the
 *      Roles page and the menu were reading two different things.
 *   2. It had no fallback. A role matching neither literal fell to
 *      cashier/pos.php, which a cashier-only account can open but an account
 *      with no POS permission cannot - so it redirected into a page that
 *      immediately refused it.
 *
 * The redirect target is also relative, which is only correct while this file
 * sits beside the page it names. A copy of admin/index.php (three lines, a bare
 * header("Location: dashboard.php")) landing at the document root instead sent
 * every visitor to /dashboard.php, which does not exist there - the whole site
 * answered 404 from its front door. The paths below are absolute from the
 * document root so the file works wherever it is deployed.
 */

require_once 'config/db.php';
startSecureSession();

// Signed out: the public page. It carries the login link, so there is no point
// bouncing a visitor to the login form and back again.
if (!isLoggedIn()) {
    redirect('landing.php');
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
