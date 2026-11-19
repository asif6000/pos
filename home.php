<?php
/**
 * POS System - Home page
 * The public front page: what the product is, what it costs, and how to sign up.
 *
 * Reachable two ways, deliberately: as https://the-shop/ through index.php, which
 * requires this file, and as https://the-shop/home.php, which is where the admin
 * panels' "renew / see plans" links and the payment gateway's return URLs point.
 * Everything on the page links relative to its own directory, so both work the
 * same in production (where this IS the document root) and under XAMPP (where it
 * sits one folder below it).
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/subscription.php';
require_once __DIR__ . '/config/legal-page.php';
startSecureSession();

// Logged-in visitors are NOT bounced away any more: the pricing section doubles
// as their billing page, where they can buy, renew, upgrade or downgrade. Signed-out
// visitors see the same page with buttons that lead to register/login.
$loggedIn  = isLoggedIn();
$subUser   = $loggedIn ? getCurrentUser() : null;
$subOwnerId = $loggedIn ? (int)($subUser['owner_id'] ?: $subUser['id']) : 0;
// Only the people who run the shop can charge for it.
$canBuy    = $loggedIn && hasRole(['admin', 'owner', 'manager']);
$subStatus = ['state' => 'none', 'plan' => null, 'plan_name' => null, 'cycle' => null, 'expires_at' => null, 'days_left' => null];
$planAction = ['starter' => 'guest', 'business' => 'guest', 'pro' => 'guest'];
$planLabel  = ['starter' => 'Get Started', 'business' => 'Get Started', 'pro' => 'Get Started'];
if ($loggedIn) {
    try {
        $sdb = getDB();
        subEnsureTables($sdb);
        $subStatus = subStatus($sdb, $subOwnerId);
        if ($canBuy) {
            $labelFor = [
                'new'       => 'Get Started',
                'renew'     => 'Renew this plan',
                'upgrade'   => 'Upgrade',
                'downgrade' => 'Switch to this',
            ];
            foreach (array_keys($planAction) as $slug) {
                $act = subPlanAction($sdb, $subOwnerId, $slug);
                $planAction[$slug] = $act;
                $planLabel[$slug]  = $labelFor[$act] ?? 'Get Started';
            }
        }
    } catch (Exception $e) {
        // Leave the defaults; the page still renders as a signed-out style view.
    }
}
$payMsg = isset($_GET['pay']) ? (string)$_GET['pay'] : '';

// The pricing grid is read out of config/plans.php rather than typed into the
// markup, because that file is what checkout.php charges from. Prices written by
// hand here are the one way this page could end up advertising a figure nobody
// will actually be billed for.
$plansCatalog = [];
$plansRaw = @include __DIR__ . '/config/plans.php';
if (is_array($plansRaw)) { $plansCatalog = $plansRaw; }

/** Taka amount in the shape the page shows: ৳4,999 */
function taka($amount)
{
    return '৳' . number_format((int)$amount);
}

// The support numbers live in one helper the privacy, terms and contact pages
// already share. A phone number typed into four files is a number that gets
// changed in two of them.
$support = legalSupport();

// "Request Demo" used to be a tel: link, which on a desktop browser with no
// telephony handler simply does nothing - the page's loudest button was dead for
// most visitors. WhatsApp opens in the browser and on the phone, and the message
// arrives already written, so the shop only has to hit send.
$demoWa = 'https://wa.me/' . $support['whatsapp'] . '?text=' . rawurlencode(
    'আসসালামু আলাইকুম, আমি ' . $support['product'] . '-এর একটি ডেমো দেখতে চাই। '
    . 'আমার দোকানের জন্য এটি কীভাবে চলবে, সে নিয়ে কথা বলতে পারি?');

/**
 * Where a signed-in visitor's "enter the panel" button leads.
 *
 * This used to branch on the literal role name: 'admin' went to admin/index.php -
 * a three-line file that only exists to say "dashboard.php" - and everyone else,
 * including a manager who runs the shop, went to the payroll page. A manager who
 * had not been granted Dashboard was therefore sent to a page that refused them,
 * and a cashier with POS was sent somewhere they do not belong.
 *
 * It now makes the same permission-based choice index.php makes for the site root,
 * so the button and the front door cannot disagree.
 */
function homeEntryLink($subUser)
{
    if (!$subUser) { return 'auth/register.php'; }
    if (staffProfileExists($subUser)) { return 'staff/dashboard.php'; }
    $landing = defaultLandingPage();
    if ($landing !== null) { return 'admin/' . $landing; }
    // Nothing is permitted: the dashboard is the one page that says so in the
    // interface instead of as a raw refusal.
    return 'admin/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AVA IT Solution POS System - Smart POS for Smart Business</title>
    <meta name="description" content="AVA IT Solution POS System — sales, inventory, customers, SMS and reports in one easy-to-use system for shops in Bangladesh.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('assets/css/hind-siliguri.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo_mark.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
    /* ============================================================
       AVA POS landing — navy / green / blue
       ------------------------------------------------------------
       Every page colour is a token, so the dark switch is a token
       swap instead of a second stylesheet. The hero, about band and
       footer are navy in both themes on purpose: they are brand
       surfaces, not page surfaces, and white text on them is fixed.
       ============================================================ */

    :root {
        --ink:      #0f1f33;
        --muted:    #5a6b80;
        --bg:       #ffffff;
        --bg-soft:  #f2f7fd;
        --panel:    #ffffff;
        --line:     #e3ebf5;
        --shadow:    0 18px 40px -24px rgba(10, 37, 69, .28);
        --shadow-sm: 0 10px 24px -18px rgba(10, 37, 69, .4);

        --navy:      #0b2545;
        --navy-deep: #071c33;
        --green:     #22c55e;
        --green-dk:  #16a34a;
        --blue:      #2563eb;
        --blue-dk:   #1d4ed8;

        --tile-blue:   #2563eb;
        --tile-green:  #22c55e;
        --tile-purple: #8b5cf6;
        --tile-orange: #f97316;
        --tile-pink:   #ec4899;
        --tile-teal:   #14b8a6;

        --radius: 16px;
        --maxw: 1200px;
    }

    html[data-theme="dark"] {
        --ink:      #e9f0f9;
        --muted:    #9db0c7;
        --bg:       #071527;
        --bg-soft:  #0b1c31;
        --panel:    #10233b;
        --line:     #1d3554;
        --shadow:    0 18px 40px -26px rgba(0, 0, 0, .8);
        --shadow-sm: 0 10px 24px -20px rgba(0, 0, 0, .85);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html { scroll-behavior: smooth; scroll-padding-top: 84px; }

    body {
        font-family: 'Hind Siliguri', 'Inter', system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--ink);
        line-height: 1.62;
        -webkit-font-smoothing: antialiased;
        overflow-x: hidden;
    }

    img { max-width: 100%; display: block; }
    a { color: inherit; text-decoration: none; }
    ul { list-style: none; }
    button { font: inherit; color: inherit; }

    .wrap { width: 100%; max-width: var(--maxw); margin: 0 auto; padding: 0 22px; }
    .sec { padding: 84px 0; }
    .sec-soft { background: var(--bg-soft); }

    .eyebrow {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 7px 15px; border-radius: 999px;
        font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
        background: rgba(37, 99, 235, .1); color: var(--blue);
        border: 1px solid rgba(37, 99, 235, .2);
    }

    .sec-head { text-align: center; max-width: 800px; margin: 0 auto 46px; }
    .sec-title { font-size: clamp(1.65rem, 3.4vw, 2.5rem); font-weight: 800; line-height: 1.28; margin: 16px 0 12px; }
    .sec-sub { color: var(--muted); font-size: 1.02rem; }

    /* ─── Buttons ─────────────────────────────────────────────────────────── */
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 9px;
        padding: 13px 24px; border-radius: 12px; border: 2px solid transparent;
        font-size: .95rem; font-weight: 700; cursor: pointer; white-space: nowrap;
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease, border-color .15s ease, color .15s ease;
    }
    .btn:hover { transform: translateY(-2px); }
    .btn-green { background: var(--green); color: #04210f; box-shadow: 0 12px 26px -14px rgba(34, 197, 94, .85); }
    .btn-green:hover { background: var(--green-dk); color: #fff; }
    .btn-blue { background: var(--blue); color: #fff; box-shadow: 0 12px 26px -14px rgba(37, 99, 235, .9); }
    .btn-blue:hover { background: var(--blue-dk); }
    .btn-ghost { background: transparent; color: var(--ink); border-color: var(--line); }
    .btn-ghost:hover { border-color: var(--blue); color: var(--blue); }
    .btn-on-navy { background: rgba(255, 255, 255, .1); color: #fff; border-color: rgba(255, 255, 255, .32); }
    .btn-on-navy:hover { background: rgba(255, 255, 255, .2); border-color: rgba(255, 255, 255, .55); color: #fff; }
    .btn-sm { padding: 9px 17px; font-size: .85rem; border-radius: 10px; }
    .btn-block { width: 100%; }

    /* ─── Navbar ──────────────────────────────────────────────────────────── */
    .lp-nav { position: sticky; top: 0; z-index: 60; background: var(--panel); border-bottom: 1px solid var(--line); }
    .lp-nav-inner {
        max-width: var(--maxw); margin: 0 auto; padding: 0 22px; height: 72px;
        display: flex; align-items: center; gap: 16px;
    }
    .brand { display: flex; align-items: center; gap: 11px; margin-right: auto; }
    /* The mark is 204x190, not square, so it is contained rather than covered -
       cover would crop the orbit on its right edge. */
    .brand img { width: 40px; height: 40px; border-radius: 10px; object-fit: contain; }
    .brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .brand-text b { font-size: 1rem; font-weight: 800; }
    .brand-text span { font-size: .72rem; color: var(--muted); font-weight: 600; }

    .nav-links { display: flex; align-items: center; gap: 2px; }
    .nav-links a {
        display: block; padding: 9px 13px; border-radius: 9px;
        font-size: .92rem; font-weight: 600; color: var(--muted);
        transition: color .15s, background .15s;
    }
    .nav-links a:hover { color: var(--ink); background: var(--bg-soft); }
    .nav-links a.active { color: var(--blue); }

    .nav-right { display: flex; align-items: center; gap: 10px; }
    .nav-toggle {
        display: none; width: 42px; height: 42px; border-radius: 11px;
        border: 1px solid var(--line); background: var(--bg-soft); cursor: pointer; font-size: 1.05rem;
    }

    /* ─── Hero ────────────────────────────────────────────────────────────── */
    .hero {
        position: relative; overflow: hidden; color: #fff; padding: 74px 0 104px;
        background:
            radial-gradient(1100px 520px at 86% -12%, rgba(37, 99, 235, .6), transparent 62%),
            linear-gradient(135deg, var(--navy-deep) 0%, var(--navy) 48%, #10406f 100%);
    }
    /* The band closes on a soft wave rather than a straight edge. */
    .hero::after {
        content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 76px;
        background: var(--bg);
        clip-path: polygon(0 62%, 18% 44%, 38% 66%, 61% 34%, 82% 58%, 100% 30%, 100% 100%, 0 100%);
    }
    .hero-inner {
        position: relative; z-index: 1;
        max-width: var(--maxw); margin: 0 auto; padding: 0 22px;
        display: grid; grid-template-columns: 1.02fr .98fr; gap: 52px; align-items: center;
    }
    .hero-badge {
        display: inline-flex; align-items: center; gap: 9px; margin-bottom: 22px;
        padding: 8px 16px; border-radius: 999px; font-size: .8rem; font-weight: 600; color: #dbeafe;
        background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .22);
    }
    .hero-badge i { color: var(--green); }
    .hero-title { font-size: clamp(2.1rem, 5vw, 3.45rem); font-weight: 900; line-height: 1.12; letter-spacing: -.5px; }
    .hero-sub { font-size: clamp(1.08rem, 2.2vw, 1.55rem); font-weight: 700; margin: 14px 0 16px; }
    .hero-sub em { font-style: normal; color: #4ade80; }
    .hero-desc { color: #b9cbe4; font-size: 1.02rem; max-width: 520px; }
    .hero-actions { display: flex; flex-wrap: wrap; gap: 14px; margin: 30px 0 26px; }
    .hero-trust { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 18px; color: #a9bedb; font-size: .86rem; }
    .hero-trust span { display: inline-flex; align-items: center; gap: 8px; }
    .hero-trust i { color: var(--green); }
    .hero-trust b { color: #e6eefb; font-weight: 600; }

    /* ─── Hero art: laptop + phone, drawn in CSS ─────────────────────────── */
    .hero-art { position: relative; display: flex; justify-content: center; }
    .laptop { width: 100%; max-width: 492px; }
    .laptop-screen {
        border: 9px solid #0d1b2a; border-radius: 14px 14px 4px 4px; background: #0d1b2a;
        overflow: hidden; box-shadow: 0 30px 60px -30px rgba(0, 0, 0, .8);
    }
    .laptop-base { height: 14px; margin: 0 -34px; border-radius: 0 0 16px 16px; background: linear-gradient(180deg, #25384f, #16283c); position: relative; }
    .laptop-base::after {
        content: ""; position: absolute; left: 50%; top: 0; transform: translateX(-50%);
        width: 92px; height: 5px; border-radius: 0 0 7px 7px; background: #0e1f31;
    }

    .mini { background: #f6f9ff; display: flex; flex-direction: column; font-size: 8px; color: #334155; }
    .mini-top { display: flex; align-items: center; gap: 5px; padding: 6px 8px; background: var(--navy); color: #fff; }
    .mini-top .dot { width: 6px; height: 6px; border-radius: 50%; background: #ef4444; }
    .mini-top .dot:nth-child(2) { background: #f59e0b; }
    .mini-top .dot:nth-child(3) { background: #22c55e; }
    .mini-top b { margin-left: 6px; font-size: 7px; font-weight: 600; color: #cbd5e1; }
    .mini-top .who { margin-left: auto; font-size: 6.5px; color: #94a3b8; }
    .mini-body { display: flex; min-height: 236px; }
    .mini-side { width: 62px; flex: none; background: #fff; border-right: 1px solid #e6edf6; padding: 7px 0; }
    .mini-side i { display: block; margin: 0 6px 3px; padding: 4px 6px; border-radius: 5px; font-size: 6.5px; font-style: normal; color: #64748b; }
    .mini-side i.on { background: var(--blue); color: #fff; font-weight: 700; }
    .mini-main { flex: 1; padding: 8px; display: flex; flex-direction: column; gap: 7px; }
    .mini-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; }
    .mini-stat { background: #fff; border: 1px solid #e6edf6; border-radius: 6px; padding: 5px 6px; }
    .mini-stat span { display: block; font-size: 5.5px; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
    .mini-stat b { font-size: 8.5px; color: #0f172a; }
    .mini-cards { display: grid; grid-template-columns: 1.4fr 1fr; gap: 5px; flex: 1; }
    .mini-card { background: #fff; border: 1px solid #e6edf6; border-radius: 6px; padding: 6px; }
    .mini-card h6 { font-size: 6.5px; color: #64748b; font-weight: 700; margin-bottom: 5px; }
    .mini-bars { display: flex; align-items: flex-end; gap: 4px; height: 64px; }
    .mini-bars span { flex: 1; border-radius: 2px 2px 0 0; background: linear-gradient(180deg, #60a5fa, #2563eb); }
    .mini-list li { display: flex; align-items: center; gap: 4px; padding: 3px 0; border-bottom: 1px dashed #eef2f7; }
    .mini-list li:last-child { border-bottom: 0; }
    .mini-av { width: 11px; height: 11px; border-radius: 50%; background: #dbeafe; color: #1d4ed8; font-size: 5.5px; display: grid; place-items: center; font-weight: 800; flex: none; }
    .mini-list em { font-style: normal; color: #334155; font-weight: 600; }
    .mini-list b { margin-left: auto; color: #0f172a; }

    .art-phone {
        position: absolute; right: -4px; bottom: -14px; width: 134px;
        border: 7px solid #0d1b2a; border-radius: 22px; background: #0d1b2a;
        box-shadow: 0 26px 44px -22px rgba(0, 0, 0, .85);
    }
    .art-phone-screen { background: #f8fbff; border-radius: 15px; overflow: hidden; }
    .art-phone-top { background: var(--navy); color: #fff; font-size: 7px; font-weight: 700; padding: 7px 8px 6px; display: flex; align-items: center; justify-content: space-between; }
    .art-phone-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; padding: 7px; }
    .art-tile { background: #fff; border: 1px solid #e6edf6; border-radius: 7px; padding: 8px 4px; text-align: center; }
    .art-tile i { display: block; font-size: 11px; margin-bottom: 3px; }
    .art-tile span { font-size: 6.5px; color: #334155; font-weight: 600; }
    .art-phone-bar { display: flex; justify-content: space-around; padding: 5px 0 7px; border-top: 1px solid #e6edf6; background: #fff; }
    .art-phone-bar i { font-size: 8px; color: #94a3b8; }
    .art-phone-bar i.on { color: var(--blue); }

    .art-plant { position: absolute; left: -18px; bottom: 10px; text-align: center; }
    .art-plant i { font-size: 54px; color: #16a34a; }
    .art-plant span { display: block; width: 38px; height: 24px; margin: -4px auto 0; border-radius: 0 0 12px 12px; background: #dbe3ec; }

    /* ─── Feature tiles ───────────────────────────────────────────────────── */
    .tiles {
        display: grid; grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 18px; padding: 46px 0 6px;
    }
    .tile { text-align: center; padding: 18px 12px 22px; border-radius: var(--radius); border: 1px solid transparent; transition: border-color .18s, transform .18s, background .18s; }
    .tile:hover { border-color: var(--line); background: var(--panel); transform: translateY(-4px); }
    .tile i {
        width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 16px;
        display: grid; place-items: center; font-size: 23px; color: #fff;
        box-shadow: 0 14px 26px -16px rgba(11, 37, 69, .55);
    }
    .tile b { display: block; font-size: .98rem; font-weight: 800; margin-bottom: 6px; }
    .tile p { font-size: .82rem; color: var(--muted); line-height: 1.5; }
    .tile-blue i   { background: var(--tile-blue); }
    .tile-green i  { background: var(--tile-green); }
    .tile-purple i { background: var(--tile-purple); }
    .tile-orange i { background: var(--tile-orange); }
    .tile-pink i   { background: var(--tile-pink); }
    .tile-teal i   { background: var(--tile-teal); }

    /* ─── Problems solved ─────────────────────────────────────────────────── */
    .prob-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
    .prob-card {
        position: relative; background: var(--panel); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 24px 24px 26px; box-shadow: var(--shadow-sm);
        transition: transform .18s, box-shadow .18s;
    }
    .prob-card:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .prob-card i.prob-ico {
        width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center;
        font-size: 19px; color: #fff; margin-bottom: 15px;
    }
    .prob-check {
        position: absolute; top: 20px; right: 20px; width: 26px; height: 26px; border-radius: 50%;
        background: rgba(34, 197, 94, .14); color: var(--green-dk); display: grid; place-items: center; font-size: 12px;
    }
    .prob-card h3 { font-size: 1.05rem; font-weight: 800; margin-bottom: 8px; }
    .prob-card p { font-size: .9rem; color: var(--muted); }

    /* ─── Subscription notices (billing page states) ─────────────────────── */
    .pay-alert {
        max-width: var(--maxw); margin: 0 auto 22px; padding: 14px 20px; border-radius: 12px;
        font-size: .92rem; font-weight: 600; border: 1px solid;
    }
    .pay-alert.err  { color: #b42318; background: rgba(239, 68, 68, .09); border-color: rgba(239, 68, 68, .3); }
    .pay-alert.warn { color: #a15c07; background: rgba(245, 158, 11, .1);  border-color: rgba(245, 158, 11, .32); }
    html[data-theme="dark"] .pay-alert.err  { color: #fca5a5; }
    html[data-theme="dark"] .pay-alert.warn { color: #fcd34d; }

    .sub-banner {
        max-width: var(--maxw); margin: 0 auto 26px; padding: 16px 20px; border-radius: 14px;
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        border: 1px solid var(--line); background: var(--panel); box-shadow: var(--shadow-sm);
    }
    .sub-banner .sb-text { display: flex; flex-direction: column; gap: 2px; margin-right: auto; }
    .sub-banner .sb-title { font-weight: 800; font-size: .98rem; }
    .sub-banner span:last-child { color: var(--muted); font-size: .88rem; }
    .sub-banner.ok   { border-color: rgba(34, 197, 94, .45); }
    .sub-banner.warn { border-color: rgba(245, 158, 11, .5); }
    .sub-banner.bad  { border-color: rgba(239, 68, 68, .45); }
    .sub-banner .sb-cta { padding: 9px 16px; border-radius: 10px; background: var(--navy); color: #fff; font-size: .85rem; font-weight: 700; }

    /* ─── Pricing ─────────────────────────────────────────────────────────── */
    .price-layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 40px; align-items: start; }
    .price-copy .sec-title { margin-top: 16px; }
    .price-copy p { color: var(--muted); margin: 12px 0 22px; }

    .billing-toggle { display: inline-flex; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid var(--line); border-radius: 999px; background: var(--panel); margin-bottom: 20px; }
    .bt-label { font-size: .82rem; font-weight: 700; color: var(--muted); cursor: pointer; }
    .bt-label.active { color: var(--ink); }
    .switch { width: 42px; height: 23px; border-radius: 999px; background: #cbd5e1; position: relative; cursor: pointer; transition: background .18s; flex: none; }
    .switch::after { content: ""; position: absolute; top: 3px; left: 3px; width: 17px; height: 17px; border-radius: 50%; background: #fff; transition: transform .18s; box-shadow: 0 1px 3px rgba(0, 0, 0, .3); }
    .switch.annual { background: var(--blue); }
    .switch.annual::after { transform: translateX(19px); }
    html[data-theme="dark"] .switch { background: #334155; }
    .save-pill { font-size: .72rem; font-weight: 800; color: var(--green-dk); background: rgba(34, 197, 94, .13); padding: 3px 9px; border-radius: 999px; }

    .plan-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; align-items: start; }
    .plan-card {
        position: relative; background: var(--panel); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 26px 22px 24px; box-shadow: var(--shadow-sm);
    }
    .plan-card.popular { border-color: var(--blue); box-shadow: 0 26px 50px -28px rgba(37, 99, 235, .6); }
    .plan-flag {
        position: absolute; top: 0; left: 0; right: 0; height: 30px; border-radius: 15px 15px 0 0;
        background: var(--blue); color: #fff; font-size: .72rem; font-weight: 800;
        display: grid; place-items: center; letter-spacing: .04em;
    }
    .plan-card.popular .plan-name { margin-top: 12px; }
    .plan-current {
        position: absolute; top: 14px; right: 14px; font-size: .68rem; font-weight: 800;
        color: var(--green-dk); background: rgba(34, 197, 94, .14); border: 1px solid rgba(34, 197, 94, .35);
        padding: 3px 9px; border-radius: 999px;
    }
    .plan-name { font-size: 1.15rem; font-weight: 900; }
    .plan-tag { font-size: .84rem; color: var(--muted); margin-top: 2px; }
    .plan-price { display: flex; align-items: baseline; gap: 6px; margin: 16px 0 4px; }
    .plan-price b { font-size: 2rem; font-weight: 900; letter-spacing: -.5px; }
    .plan-price span { font-size: .84rem; color: var(--muted); font-weight: 600; }
    .plan-note { font-size: .76rem; color: var(--green-dk); font-weight: 700; min-height: 18px; margin-bottom: 12px; }
    .plan-feats { border-top: 1px dashed var(--line); padding-top: 14px; margin-bottom: 20px; display: grid; gap: 8px; }
    .plan-feats li { display: flex; align-items: flex-start; gap: 9px; font-size: .87rem; color: var(--ink); }
    .plan-feats li i { color: var(--green); font-size: .8rem; margin-top: 5px; }
    .plan-btn {
        display: flex; align-items: center; justify-content: center; gap: 9px; width: 100%;
        padding: 12px 16px; border-radius: 11px; border: 2px solid var(--line);
        background: transparent; color: var(--ink); font-size: .92rem; font-weight: 800; cursor: pointer;
        transition: background .16s, border-color .16s, color .16s, transform .16s;
    }
    .plan-btn:hover { border-color: var(--blue); color: var(--blue); transform: translateY(-2px); }
    .plan-card.popular .plan-btn { background: var(--blue); border-color: var(--blue); color: #fff; }
    .plan-card.popular .plan-btn:hover { background: var(--blue-dk); border-color: var(--blue-dk); color: #fff; }
    .price-custom { text-align: center; color: var(--muted); font-size: .92rem; margin-top: 30px; }
    .price-custom a { color: var(--blue); font-weight: 700; }

    /* ─── Screenshots ─────────────────────────────────────────────────────── */
    .shot-tabs { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; margin-bottom: 30px; }
    .shot-tab {
        padding: 10px 18px; border-radius: 999px; border: 1px solid var(--line);
        background: var(--panel); font-size: .88rem; font-weight: 700; color: var(--muted); cursor: pointer;
        transition: border-color .16s, color .16s, background .16s;
    }
    .shot-tab:hover { color: var(--ink); }
    .shot-tab.on { background: var(--navy); border-color: var(--navy); color: #fff; }
    .shot-stage { display: grid; gap: 22px; }
    /* min-width:0 — a grid item's automatic minimum size is its min-content width,
       and the mock inside the frame has fixed-width columns, so without this the
       panel grew past a phone viewport and dragged the whole page sideways. */
    .shot-panel { display: none; min-width: 0; }
    .shot-panel.on { display: block; animation: shotIn .28s ease; }
    @keyframes shotIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    .shot-frame {
        max-width: 860px; margin: 0 auto; border: 1px solid var(--line); border-radius: 16px;
        overflow: hidden; background: var(--panel); box-shadow: var(--shadow);
    }
    .shot-chrome { display: flex; align-items: center; gap: 6px; padding: 10px 14px; background: var(--bg-soft); border-bottom: 1px solid var(--line); }
    .shot-chrome .dot { width: 9px; height: 9px; border-radius: 50%; background: #ef4444; }
    .shot-chrome .dot:nth-child(2) { background: #f59e0b; }
    .shot-chrome .dot:nth-child(3) { background: #22c55e; }
    .shot-url {
        margin-left: 10px; flex: 1; max-width: 340px; font-size: .74rem; color: var(--muted);
        background: var(--panel); border: 1px solid var(--line); border-radius: 7px; padding: 3px 10px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .shot-cap { text-align: center; color: var(--muted); font-size: .9rem; }
    /* the mock inside a screenshot is the hero's mini dashboard, scaled up */
    .shot-frame .mini { font-size: 11px; }
    .shot-frame .mini-top { padding: 9px 12px; }
    .shot-frame .mini-top b { font-size: 9px; }
    .shot-frame .mini-top .who { font-size: 8.5px; }
    .shot-frame .mini-top .dot { width: 8px; height: 8px; }
    .shot-frame .mini-side { width: 88px; padding: 10px 0; }
    .shot-frame .mini-side i { font-size: 9px; padding: 6px 9px; margin: 0 8px 5px; border-radius: 7px; }
    .shot-frame .mini-main { padding: 12px; gap: 10px; }
    .shot-frame .mini-body { min-height: 330px; }
    .shot-frame .mini-stat { padding: 8px 9px; border-radius: 9px; }
    .shot-frame .mini-stat span { font-size: 7px; }
    .shot-frame .mini-stat b { font-size: 12px; }
    .shot-frame .mini-card { padding: 10px; border-radius: 9px; }
    .shot-frame .mini-card h6 { font-size: 8.5px; margin-bottom: 8px; }
    .shot-frame .mini-bars { height: 120px; gap: 7px; }
    .shot-frame .mini-list li { padding: 6px 0; gap: 7px; }
    .shot-frame .mini-av { width: 17px; height: 17px; font-size: 8px; }
    .shot-frame .mini-list em { font-size: 10px; }
    .shot-frame .mini-list b { font-size: 10px; }
    .mini-prods { display: grid; grid-template-columns: repeat(4, 1fr); gap: 7px; }
    .mini-prod { background: #fff; border: 1px solid #e6edf6; border-radius: 8px; padding: 9px 4px; text-align: center; }
    .mini-prod i { display: block; font-size: 15px; color: var(--blue); margin-bottom: 5px; }
    .mini-prod b { display: block; font-size: 8.5px; color: #334155; }
    .mini-prod span { font-size: 8px; color: #94a3b8; }
    .mini-cart { width: 108px; flex: none; background: #fff; border-left: 1px solid #e6edf6; padding: 10px 8px; }
    .mini-cart h6 { font-size: 8.5px; color: #64748b; font-weight: 800; margin-bottom: 7px; }
    .mini-cart li { display: flex; justify-content: space-between; font-size: 8px; color: #334155; padding: 4px 0; border-bottom: 1px dashed #eef2f7; }
    .mini-cart .tot { margin-top: 7px; background: var(--green); color: #04210f; border: 0; justify-content: center; font-weight: 800; border-radius: 6px; padding: 6px; font-size: 8.5px; }
    .shot-app { display: flex; justify-content: center; padding: 26px 0 8px; background: var(--panel); border-radius: 16px; }

    /* ─── About band ──────────────────────────────────────────────────────── */
    .about-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 46px; align-items: center; }
    .about-list { display: grid; gap: 14px; }
    .about-item { display: flex; gap: 14px; align-items: flex-start; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
    .about-item i { width: 40px; height: 40px; flex: none; border-radius: 12px; display: grid; place-items: center; background: rgba(37, 99, 235, .1); color: var(--blue); font-size: 17px; }
    .about-item b { display: block; font-size: .98rem; }
    .about-item p { font-size: .87rem; color: var(--muted); }
    .about-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fact { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 20px 18px; text-align: center; }
    .fact b { display: block; font-size: 1.7rem; font-weight: 900; color: var(--blue); }
    .fact span { font-size: .82rem; color: var(--muted); }

    /* ─── CTA + Android app ───────────────────────────────────────────────── */
    .cta-grid { display: grid; grid-template-columns: .85fr 1.15fr .95fr; gap: 34px; align-items: center; }
    .cta-note { position: relative; text-align: center; }
    .cta-note .hand {
        font-size: 1.32rem; font-weight: 800; font-style: italic; color: var(--navy);
        transform: rotate(-4deg); display: inline-block; line-height: 1.35;
    }
    html[data-theme="dark"] .cta-note .hand { color: #7dd3fc; }
    .cta-note .arrow { display: block; margin: 6px auto 0; font-size: 26px; color: var(--blue); transform: scaleX(-1) rotate(18deg); }
    .cta-mid h2 { font-size: clamp(1.5rem, 3vw, 2.05rem); font-weight: 900; margin-bottom: 10px; }
    .cta-mid p { color: var(--muted); margin-bottom: 22px; }
    .cta-actions { display: flex; flex-wrap: wrap; gap: 12px; }

    .app-card { background: var(--bg-soft); border: 1px solid var(--line); border-radius: 20px; padding: 22px; text-align: center; }
    .app-card-head { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 6px; }
    .app-card-head i { font-size: 26px; color: var(--green); }
    .app-card-head b { font-size: 1.1rem; font-weight: 900; }
    .app-card > p { font-size: .87rem; color: var(--muted); margin-bottom: 16px; }
    .apk-btn {
        display: inline-flex; align-items: center; gap: 11px; padding: 11px 20px; border-radius: 12px;
        background: #0d1b2a; color: #fff; border: 1px solid #1f3349; text-align: left;
        transition: transform .15s, background .15s;
    }
    .apk-btn:hover { transform: translateY(-2px); background: #142639; }
    .apk-btn i { font-size: 24px; color: #4ade80; }
    .apk-btn span { display: flex; flex-direction: column; line-height: 1.2; }
    .apk-btn small { font-size: .62rem; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; }
    .apk-btn b { font-size: .95rem; font-weight: 800; }
    .apk-btn[aria-disabled="true"] { opacity: .62; cursor: default; }
    .apk-btn[aria-disabled="true"]:hover { transform: none; background: #0d1b2a; }
    .app-meta { font-size: .74rem; color: var(--muted); margin: 10px 0 0; min-height: 16px; }
    .app-note { font-size: .72rem; color: var(--muted); margin-top: 8px; }
    .apk-new {
        display: flex; align-items: flex-start; gap: 7px; margin: 9px 0 0; padding: 7px 9px;
        border-radius: 9px; background: rgba(34, 197, 94, .1); border: 1px solid rgba(34, 197, 94, .28);
        font-size: .72rem; line-height: 1.45; color: var(--navy); text-align: left;
    }
    html[data-theme="dark"] .apk-new { color: #86efac; background: rgba(34, 197, 94, .12); }
    .apk-new i { color: var(--green); margin-top: 2px; }
    .apk-stack { display: grid; gap: 14px; }
    .apk-cap { font-size: .78rem; font-weight: 800; color: var(--muted); margin: 0 0 7px; }
    .apk-cap i { color: var(--green); width: 16px; }
    .apk-stack .apk-btn { width: 100%; justify-content: flex-start; }

    /* ─── Marketplace (customer) app ──────────────────────────────────────── */
    .market-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 34px; align-items: center; }
    .market-copy h2 { font-size: clamp(1.35rem, 2.6vw, 1.8rem); font-weight: 900; margin-bottom: 10px; }
    .market-copy > p { color: var(--muted); margin-bottom: 20px; }
    .market-list { display: grid; gap: 14px; }
    .market-list > div { display: flex; gap: 12px; }
    .market-list > div > i { font-size: 17px; color: var(--green); margin-top: 4px; width: 20px; text-align: center; }
    .market-list b { display: block; font-size: .96rem; }
    .market-list span { font-size: .85rem; color: var(--muted); }

    /* Animated in-phone sale demo. The four scenes share one 12s clock and each
       owns a quarter of it, so the loop needs no JS at all - the observer only
       pauses it when the phone is scrolled out of view. */
    .appdemo { width: 214px; }
    .appdemo-frame { border: 8px solid #0d1b2a; border-radius: 26px; background: #0d1b2a; box-shadow: 0 26px 44px -24px rgba(0, 0, 0, .6); }
    .appdemo-screen { position: relative; background: #f8fbff; border-radius: 18px; overflow: hidden; height: 372px; }
    .appdemo-notch { position: absolute; top: 6px; left: 50%; transform: translateX(-50%); width: 62px; height: 5px; border-radius: 99px; background: #0d1b2a; z-index: 3; }
    .appdemo-status { display: flex; justify-content: space-between; padding: 15px 12px 6px; font-size: 9px; color: #64748b; font-weight: 700; }
    .scene { position: absolute; left: 0; right: 0; top: 34px; bottom: 46px; padding: 0 12px; opacity: 0; animation: sceneShow 12s linear infinite; }
    .scene[data-scene="1"] { animation-delay: 3s; }
    .scene[data-scene="2"] { animation-delay: 6s; }
    .scene[data-scene="3"] { animation-delay: 9s; }
    @keyframes sceneShow {
        0%   { opacity: 0; }
        1%   { opacity: 1; }
        23%  { opacity: 1; }
        26%  { opacity: 0; }
        100% { opacity: 0; }
    }
    .scene-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 9px; }
    .scene-head b { font-size: 12px; color: #0f172a; }
    .scene-chip { font-size: 8px; font-weight: 800; color: var(--blue); background: #dbeafe; padding: 2px 8px; border-radius: 99px; }
    .scene-search { display: flex; align-items: center; gap: 6px; font-size: 9px; color: #94a3b8; background: #fff; border: 1px solid #e6edf6; border-radius: 8px; padding: 7px 9px; margin-bottom: 9px; }
    .srow { display: flex; align-items: center; gap: 7px; background: #fff; border: 1px solid #e6edf6; border-radius: 9px; padding: 7px 8px; margin-bottom: 6px; }
    .srow .th { width: 24px; height: 24px; border-radius: 7px; background: #eff6ff; color: var(--blue); display: grid; place-items: center; font-size: 11px; flex: none; }
    .srow .nm { flex: 1; min-width: 0; }
    .srow .nm b { display: block; font-size: 9.5px; color: #0f172a; }
    .srow .nm span { display: block; font-size: 7.5px; color: #94a3b8; }
    .srow .pr { font-size: 9px; font-weight: 800; color: #334155; }
    .srow .add { width: 18px; height: 18px; border-radius: 6px; background: var(--green); color: #04210f; display: grid; place-items: center; font-size: 9px; }
    .stotal { display: flex; justify-content: space-between; align-items: center; margin-top: 9px; background: var(--navy); color: #fff; border-radius: 9px; padding: 9px 10px; font-size: 9.5px; font-weight: 800; }
    .stotal.pop { animation: totalPop 3s ease-in-out infinite; }
    @keyframes totalPop { 0%, 62% { transform: scale(.9); opacity: 0; } 72%, 100% { transform: scale(1); opacity: 1; } }
    .sghost { height: 26px; border-radius: 8px; background: #eef2f7; margin-bottom: 7px; }
    .sghost.short { width: 62%; }
    .spay { display: flex; align-items: center; gap: 8px; border-radius: 9px; padding: 10px; font-size: 10px; font-weight: 800; margin-bottom: 7px; background: var(--green); color: #04210f; }
    .spay.ghost { background: #fff; border: 1px solid #e6edf6; color: #334155; }
    .sproc { display: flex; align-items: center; justify-content: center; gap: 7px; font-size: 9px; color: #64748b; margin-top: 4px; }
    .spinner { width: 11px; height: 11px; border-radius: 50%; border: 2px solid #cbd5e1; border-top-color: var(--blue); animation: spin .8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .sdone { text-align: center; padding-top: 12px; }
    .sring { width: 46px; height: 46px; margin: 0 auto 9px; border-radius: 50%; background: rgba(34, 197, 94, .16); display: grid; place-items: center; }
    .sring i { font-size: 22px; color: var(--green-dk); }
    .sdone b { display: block; font-size: 12px; color: #0f172a; }
    .sdone > span { display: block; font-size: 8.5px; color: #94a3b8; margin-bottom: 10px; }
    .srec { background: #fff; border: 1px dashed #e2e8f0; border-radius: 9px; padding: 8px 10px; text-align: left; }
    .srec span { display: flex; justify-content: space-between; font-size: 8.5px; color: #475569; padding: 3px 0; }
    .srec span.big { border-top: 1px solid #eef2f7; margin-top: 4px; padding-top: 6px; font-weight: 900; color: #0f172a; font-size: 9.5px; }
    .cap { position: absolute; left: 0; right: 0; bottom: 0; height: 46px; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 8.5px; font-weight: 700; color: #475569; background: #fff; border-top: 1px solid #eef2f7; opacity: 0; animation: sceneShow 12s linear infinite; }
    .cap[data-cap="1"] { animation-delay: 3s; }
    .cap[data-cap="2"] { animation-delay: 6s; }
    .cap[data-cap="3"] { animation-delay: 9s; }
    .cap i { color: var(--blue); }
    .appdemo[data-playing="off"] .scene,
    .appdemo[data-playing="off"] .cap,
    .appdemo[data-playing="off"] .stotal.pop,
    .appdemo[data-playing="off"] .spinner { animation-play-state: paused; }

    /* ─── Footer ──────────────────────────────────────────────────────────── */
    footer.lp-foot { background: var(--navy-deep); color: #b9cbe4; padding: 40px 0 26px; }
    .foot-inner { display: flex; flex-wrap: wrap; align-items: center; gap: 20px 28px; }
    .foot-brand { display: flex; align-items: center; gap: 11px; margin-right: auto; }
    .foot-brand img { width: 38px; height: 38px; border-radius: 10px; object-fit: contain; }
    .foot-brand b { display: block; color: #fff; font-size: .98rem; }
    .foot-brand span { font-size: .74rem; }
    .foot-links { display: flex; flex-wrap: wrap; gap: 20px; font-size: .86rem; }
    .foot-links a:hover { color: #fff; }
    .foot-social { display: flex; gap: 12px; font-size: 16px; }
    .foot-social a:hover { color: #fff; }
    /* WhatsApp is the channel a visitor can actually reach a human on, so it
       gets a labelled pill with the number spelled out rather than another
       icon they would have to hover to identify. */
    .foot-wa { display: inline-flex; align-items: center; gap: 9px; background: rgba(37, 211, 102, .13); border: 1px solid rgba(37, 211, 102, .5); border-radius: 99px; padding: 8px 15px; color: #fff; font-size: .82rem; transition: background .18s ease, border-color .18s ease, color .18s ease; }
    .foot-wa i { font-size: 17px; color: #25D366; }
    .foot-wa b { font-weight: 600; letter-spacing: .2px; }
    .foot-wa:hover { background: #25D366; border-color: #25D366; color: #04321a; }
    .foot-wa:hover i { color: #04321a; }
    .foot-copy { width: 100%; border-top: 1px solid rgba(255, 255, 255, .1); margin-top: 22px; padding-top: 18px; font-size: .78rem; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; }

    /* ─── Responsive ──────────────────────────────────────────────────────── */
    @media (max-width: 1180px) {
        .tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .price-layout { grid-template-columns: 1fr; gap: 26px; }
        .price-copy { text-align: center; max-width: 620px; margin: 0 auto; }
        .cta-grid { grid-template-columns: 1fr 1fr; }
        .cta-note { display: none; }
    }

    @media (max-width: 980px) {
        .nav-links {
            position: absolute; top: 72px; left: 0; right: 0; flex-direction: column; align-items: stretch;
            gap: 2px; padding: 12px 18px 18px; background: var(--panel);
            border-bottom: 1px solid var(--line); box-shadow: var(--shadow);
            display: none;
        }
        .nav-links.open { display: flex; }
        .nav-toggle { display: grid; place-items: center; order: 3; }
        .hero-inner { grid-template-columns: 1fr; gap: 44px; }
        .hero-art { margin-top: 8px; }
        .about-grid { grid-template-columns: 1fr; }
        .prob-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .market-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 760px) {
        .sec { padding: 62px 0; }
        .plan-grid { grid-template-columns: 1fr; }
        .plan-card.popular { transform: none; }
        .cta-grid { grid-template-columns: 1fr; }
        .shot-frame .mini-body { min-height: 260px; }
    }

    @media (max-width: 560px) {
        /* Phone-width navbar: the brand name is long and both a theme toggle and
           a call to action have to stay reachable, so the wordmark drops (the logo
           and the footer both still name the product), Login moves out of the bar
           entirely — it is repeated in the footer — and only the free-trial button
           is kept. Without this the hamburger is pushed ~90px past the viewport. */
        .lp-nav-inner { padding: 0 12px; gap: 8px; }
        .nav-right { gap: 6px; }
        .nav-toggle { width: 38px; height: 38px; }
        .brand img { width: 34px; height: 34px; }
        .brand-text { display: none; }
        .nav-right .btn-hide-sm { display: none; }
        .nav-right .btn-green { padding: 9px 13px; font-size: .8rem; }
        .tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .prob-grid { grid-template-columns: 1fr; }
        .hero { padding: 52px 0 78px; }
        .hero-actions .btn { flex: 1 1 100%; }
        .art-plant { display: none; }
        .art-phone { width: 108px; right: -2px; }
        .laptop { max-width: 340px; }
        .laptop-base { margin: 0 -12px; }
        .about-facts { grid-template-columns: 1fr; }
        .foot-inner { flex-direction: column; align-items: flex-start; }
        .foot-brand { margin-right: 0; }
        .shot-frame .mini-side { display: none; }
        /* The mocks are decorative but still must not be cut in half: at phone
           width their 4-up grids and the fixed cart column are wider than the
           frame, so they re-flow instead of overflowing behind overflow:hidden. */
        .shot-frame .mini-prods { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .shot-frame .mini-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .shot-frame .mini-cart { width: 92px; }
    }

    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior: auto; }
        .scene, .cap, .stotal.pop, .spinner { animation: none !important; }
        .scene[data-scene="0"], .cap[data-cap="0"] { opacity: 1; }
    }

    </style>
    <script>
        /* Set the theme before first paint so a light-mode visitor never sees a
           dark flash. Reads the same key the toggle button writes. */
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('pos.theme') || 'light'; } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>

<body id="top">

<!-- ─── NAVBAR ─────────────────────────────────────────────────────────── -->
<nav class="lp-nav">
    <div class="lp-nav-inner">
        <button class="nav-toggle" id="navToggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="navLinks">
            <i class="fas fa-bars"></i>
        </button>

        <a href="#top" class="brand">
            <img src="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo_mark.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="AVA IT Solution">
            <span class="brand-text">
                <b>AVA IT Solution</b>
                <span>Smart Collection</span>
            </span>
        </a>

        <ul class="nav-links" id="navLinks">
            <li><a href="#top" class="active">Home</a></li>
            <li><a href="#features">Features</a></li>
            <li><a href="#pricing">Pricing</a></li>
            <li><a href="#screenshots">Screenshots</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#staff-app">Download App</a></li>
            <li><a href="contact.php">Contact</a></li>
        </ul>

        <div class="nav-right">
            <button class="theme-toggle btn btn-ghost btn-sm" id="themeToggle" type="button"
                    title="Toggle light / dark" aria-label="Toggle light or dark theme" style="width:38px;height:38px;padding:0;">
                <i class="fas fa-moon" id="themeIcon"></i>
            </button>
            <?php if ($loggedIn): ?>
                <a href="<?php echo htmlspecialchars(homeEntryLink($subUser), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-ghost btn-sm btn-hide-sm">
                    <i class="fas fa-tachometer-alt"></i> ড্যাশবোর্ড</a>
                <a href="#pricing" class="btn btn-green btn-sm">
                    <i class="fas fa-credit-card"></i> My Plan</a>
            <?php else: ?>
                <a href="auth/login.php" class="btn btn-ghost btn-sm btn-hide-sm">
                    <i class="fas fa-sign-in-alt"></i> Login</a>
                <a href="auth/register.php" class="btn btn-green btn-sm">
                    Start Free Trial</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ─── HERO ───────────────────────────────────────────────────────────── -->
<header class="hero">
    <div class="hero-inner">
        <div class="hero-left">
            <span class="hero-badge"><i class="fas fa-layer-group"></i> All-in-One Business Management</span>
            <h1 class="hero-title">AVA IT Solution<br>POS System</h1>
            <p class="hero-sub">Smart POS for <em>Smart Business</em></p>
            <p class="hero-desc">
                Manage your sales, inventory, customers and SMS from one screen &mdash;
                built for shops in Bangladesh, easy enough to start today.
            </p>
            <div class="hero-actions">
                <a href="<?php echo htmlspecialchars($demoWa, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-green" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Request Demo</a>
                <a href="<?php echo $loggedIn ? '#pricing' : 'auth/register.php'; ?>" class="btn btn-on-navy">
                    Get Started <i class="fas fa-arrow-right"></i></a>
                <a href="#staff-app" class="btn btn-on-navy">
                    <i class="fab fa-android"></i> Download App</a>
            </div>
            <div class="hero-trust">
                <span><i class="fas fa-circle-check"></i> <b>Easy to Use</b></span>
                <span><i class="fas fa-shield-halved"></i> <b>Secure &amp; Reliable</b></span>
                <span><i class="fas fa-headset"></i> <b>24/7 Support</b></span>
            </div>
        </div>

        <div class="hero-art">
            <div class="laptop">
                <div class="laptop-screen">
                    <!-- A CSS drawing of the real admin dashboard, not a photo, so
                         it stays sharp at any size and needs no image asset. -->
                    <div class="mini" aria-hidden="true">
                        <div class="mini-top">
                            <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                            <b>Smart Collection</b>
                            <span class="who">Dashboard</span>
                        </div>
                        <div class="mini-body">
                            <div class="mini-side">
                                <i class="on">Dashboard</i><i>Sales</i><i>Products</i><i>Customers</i>
                                <i>Stock</i><i>Reports</i><i>SMS</i><i>Settings</i>
                            </div>
                            <div class="mini-main">
                                <div class="mini-stats">
                                    <div class="mini-stat"><span>Today</span><b>৳12,450</b></div>
                                    <div class="mini-stat"><span>Total sales</span><b>356,200</b></div>
                                    <div class="mini-stat"><span>Products</span><b>250</b></div>
                                    <div class="mini-stat"><span>Customers</span><b>842</b></div>
                                </div>
                                <div class="mini-cards">
                                    <div class="mini-card">
                                        <h6>Sales overview</h6>
                                        <div class="mini-bars">
                                            <span style="height:34%"></span><span style="height:52%"></span>
                                            <span style="height:41%"></span><span style="height:68%"></span>
                                            <span style="height:55%"></span><span style="height:82%"></span>
                                            <span style="height:63%"></span><span style="height:94%"></span>
                                        </div>
                                    </div>
                                    <div class="mini-card">
                                        <h6>Recent sales</h6>
                                        <ul class="mini-list">
                                            <li><span class="mini-av">SH</span><em>Shahin</em><b>৳450</b></li>
                                            <li><span class="mini-av">RA</span><em>Rana</em><b>৳1,200</b></li>
                                            <li><span class="mini-av">KA</span><em>Karim</em><b>৳320</b></li>
                                            <li><span class="mini-av">MO</span><em>Mohan</em><b>৳860</b></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="laptop-base"></div>
            </div>

            <div class="art-plant" aria-hidden="true"><i class="fas fa-seedling"></i><span></span></div>

            <div class="art-phone" aria-hidden="true">
                <div class="art-phone-screen">
                    <div class="art-phone-top"><span>Smart Collection</span><i class="fas fa-ellipsis-vertical"></i></div>
                    <div class="art-phone-grid">
                        <div class="art-tile"><i class="fas fa-cash-register" style="color:#2563eb"></i><span>Sales</span></div>
                        <div class="art-tile"><i class="fas fa-box" style="color:#22c55e"></i><span>Products</span></div>
                        <div class="art-tile"><i class="fas fa-users" style="color:#f97316"></i><span>Customers</span></div>
                        <div class="art-tile"><i class="fas fa-chart-line" style="color:#8b5cf6"></i><span>Reports</span></div>
                    </div>
                    <div class="art-phone-bar">
                        <i class="fas fa-house on"></i><i class="fas fa-basket-shopping"></i>
                        <i class="fas fa-boxes-stacked"></i><i class="fas fa-user"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ─── FEATURE TILES ──────────────────────────────────────────────────── -->
<section id="features">
    <div class="wrap">
        <div class="tiles">
            <div class="tile tile-blue">
                <i class="fas fa-cart-shopping"></i>
                <b>Sales &amp; Billing</b>
                <p>Fast billing with cash, bKash and card in one tap.</p>
            </div>
            <div class="tile tile-green">
                <i class="fas fa-box"></i>
                <b>Inventory Management</b>
                <p>Track stock, get low-stock alerts, avoid overstock.</p>
            </div>
            <div class="tile tile-purple">
                <i class="fas fa-users"></i>
                <b>Customer Management</b>
                <p>Keep customer data, build loyalty, grow sales.</p>
            </div>
            <div class="tile tile-orange">
                <i class="fas fa-comment-sms"></i>
                <b>SMS Service</b>
                <p>Send bulk SMS with an integrated gateway.</p>
            </div>
            <div class="tile tile-pink">
                <i class="fas fa-tags"></i>
                <b>Price List &amp; Reports</b>
                <p>Manage the price list and get detailed reports.</p>
            </div>
            <div class="tile tile-teal">
                <i class="fas fa-mobile-screen"></i>
                <b>Android App</b>
                <p>Run the till and check the shop on the go.</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── PROBLEMS SOLVED ────────────────────────────────────────────────── -->
<section class="sec sec-soft" id="problems">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow"><i class="fas fa-circle-check"></i> Problems Solved</span>
            <h2 class="sec-title">আপনার ব্যবসার যেসব সমস্যার সমাধান দেবে Smart Collection</h2>
            <p class="sec-sub">আমাদের POS সফটওয়্যার ব্যবহার করে আপনি নিয়মিত ব্যবসায়িক সমস্যাগুলো সহজেই সমাধান করতে পারবেন।</p>
        </div>

        <div class="prob-grid">
            <div class="prob-card">
                <i class="fas fa-chart-line prob-ico" style="background:var(--tile-pink)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>স্টকের হিসাব রাখতে সমস্যা</h3>
                <p>মানুয়ালি হিসাব রাখার ঝামেলা শেষ। অটো স্টক আপডেট ও লো স্টক অ্যালার্ট পাবেন।</p>
            </div>
            <div class="prob-card">
                <i class="fas fa-clock prob-ico" style="background:var(--tile-orange)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>বিক্রয় ও হিসাবের ভুল</h3>
                <p>POS-এর ফাস্ট বিল, স্টক হিসাব এবং বিস্তারিত রিপোর্ট পাবেন।</p>
            </div>
            <div class="prob-card">
                <i class="fas fa-users prob-ico" style="background:var(--tile-purple)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>গ্রাহক ব্যবস্থাপনা দুর্বল</h3>
                <p>কাস্টমার ডাটা সংরক্ষণ, লয়্যালটি প্রোগ্রাম ও নিয়মিত যোগাযোগ সহজ হবে।</p>
            </div>
            <div class="prob-card">
                <i class="fas fa-comment-sms prob-ico" style="background:var(--tile-blue)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>SMS পাঠাতে সময় ও খরচ বেশি</h3>
                <p>ইন্টিগ্রেটেড SMS গেটওয়ে দিয়ে এক ক্লিকে হাজার হাজার মেসেজ পাঠান।</p>
            </div>
            <div class="prob-card">
                <i class="fas fa-database prob-ico" style="background:var(--tile-green)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>মূল্যতালিকা ও অফার পরিচালনা জটিল</h3>
                <p>পরিবর্তনশীল মূল্যতালিকা, ডিসকাউন্ট ও অফার সহজেই ম্যানেজ করুন।</p>
            </div>
            <div class="prob-card">
                <i class="fas fa-shield-halved prob-ico" style="background:var(--tile-pink)"></i>
                <span class="prob-check"><i class="fas fa-check"></i></span>
                <h3>নিরাপত্তা ও ডাটা সুরক্ষা</h3>
                <p>ক্লাউড/লোকাল ব্যাকআপ, ইউজার পারমিশন এবং নিরাপদ ডাটা সংরক্ষণ।</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── PRICING ────────────────────────────────────────────────────────── -->
<section class="sec" id="pricing">
    <?php if ($loggedIn): ?>
        <?php
        // One-time messages coming back from the gateway / checkout.
        $payAlert = null;
        if ($payMsg === 'error')      { $payAlert = ['err',  'পেমেন্ট শুরু করা যায়নি। আবার চেষ্টা করুন অথবা সাপোর্টে যোগাযোগ করুন।']; }
        elseif ($payMsg === 'cancelled') { $payAlert = ['warn', 'পেমেন্ট বাতিল করা হয়েছে। কোনো টাকা কাটা হয়নি।']; }
        elseif ($payMsg === 'forbidden') { $payAlert = ['err',  'শুধু owner/admin/manager প্ল্যান কিনতে পারেন।']; }
        elseif ($payMsg === 'badplan')   { $payAlert = ['err',  'অজানা প্ল্যান। নিচ থেকে একটি বেছে নিন।']; }
        if ($payAlert): ?>
            <div class="wrap"><div class="pay-alert <?php echo $payAlert[0]; ?>"><?php echo htmlspecialchars($payAlert[1], ENT_QUOTES, 'UTF-8'); ?></div></div>
        <?php endif; ?>

        <?php
        $expText = $subStatus['expires_at'] ? date('d M Y', strtotime($subStatus['expires_at'])) : '';
        $cycText = $subStatus['cycle'] === 'yearly' ? 'বার্ষিক' : 'মাসিক';
        $bannerClass = 'info'; $bannerHtml = '';
        if (!$canBuy) {
            $bannerClass = 'info';
            $bannerHtml = '<span class="sb-title">প্ল্যান</span><span>প্ল্যান কেনা/রিনু শুধু owner, admin বা manager করতে পারেন।</span>';
        } elseif ($subStatus['state'] === 'none') {
            $bannerClass = 'info';
            $bannerHtml = '<span class="sb-title">কোনো সক্রিয় প্ল্যান নেই</span><span>নিচ থেকে একটি প্ল্যান বেছে নিয়ে পেমেন্ট সম্পন্ন করুন — ভেরিফাই হলেই অটো অ্যাক্টিভ হয়ে যাবে।</span>';
        } elseif ($subStatus['state'] === 'active') {
            $bannerClass = 'ok';
            $bannerHtml = '<span class="sb-title">বর্তমান প্ল্যান: ' . htmlspecialchars($subStatus['plan_name'], ENT_QUOTES, 'UTF-8') . ' (' . $cycText . ')</span>'
                        . '<span>মেয়াদ ' . $expText . ' পর্যন্ত • ' . (int)$subStatus['days_left'] . ' দিন বাকি</span>';
        } elseif ($subStatus['state'] === 'expiring') {
            $bannerClass = 'warn';
            $bannerHtml = '<span class="sb-title">প্ল্যান শীঘ্রই শেষ হচ্ছে: ' . htmlspecialchars($subStatus['plan_name'], ENT_QUOTES, 'UTF-8') . '</span>'
                        . '<span>মেয়াদ ' . $expText . ' পর্যন্ত • মাত্র ' . (int)$subStatus['days_left'] . ' দিন বাকি — এখনই রিনু করুন।</span>';
        } else { // expired
            $bannerClass = 'bad';
            $bannerHtml = '<span class="sb-title">প্ল্যান মেয়াদ শেষ: ' . htmlspecialchars($subStatus['plan_name'], ENT_QUOTES, 'UTF-8') . '</span>'
                        . '<span>' . $expText . '-এ মেয়াদ শেষ হয়েছে। সিস্টেম চলছে, তবে নির্বিঘ্নে ব্যবহারের জন্য রিনু করুন।</span>';
        }
        ?>
        <div class="wrap">
            <div class="sub-banner <?php echo $bannerClass; ?>">
                <span class="sb-text"><?php echo $bannerHtml; ?></span>
                <?php if ($subStatus['state'] !== 'none' && $canBuy): ?>
                    <a class="sb-cta" href="<?php echo htmlspecialchars(homeEntryLink($subUser), ENT_QUOTES, 'UTF-8'); ?>">ড্যাশবোর্ড</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="wrap">
        <div class="price-layout">
            <div class="price-copy">
                <span class="eyebrow"><i class="fas fa-tags"></i> Affordable Plans</span>
                <h2 class="sec-title">বেছে নিন আপনার জন্য<br>সেরা প্ল্যান</h2>
                <p>আপনার ব্যবসার আকার ও চাহিদা অনুযায়ী আমরা তিনটি সুবিধাজনক প্ল্যান দিয়েছি। যেকোনো সময় আপগ্রেড, ডাউনগ্রেড বা বাতিল করতে পারবেন।</p>
                <div class="billing-toggle">
                    <span class="bt-label active" data-period="monthly">মাসিক</span>
                    <div class="switch" id="billingSwitch" role="switch" aria-checked="false" tabindex="0" aria-label="Toggle annual billing"></div>
                    <span class="bt-label" data-period="annual">বার্ষিক</span>
                    <span class="save-pill">~২ মাস ফ্রি</span>
                </div>
                <br>
                <a href="#features" class="btn btn-ghost">View All Features <i class="fas fa-arrow-right"></i></a>
            </div>

            <div>
                <div class="plan-grid">
                    <?php foreach (['starter', 'business', 'pro'] as $slug):
                        $p = $plansCatalog[$slug] ?? null;
                        if (!$p) { continue; }
                        $isPop = !empty($p['popular']);
                        $isCurrent = $loggedIn && $subStatus['plan'] === $slug
                                     && in_array($subStatus['state'], ['active', 'expiring'], true);
                    ?>
                    <div class="plan-card<?php echo $isPop ? ' popular' : ''; ?>">
                        <?php if ($isPop): ?>
                            <div class="plan-flag"><i class="fas fa-star"></i> Most Popular</div>
                        <?php endif; ?>
                        <?php if ($isCurrent): ?><div class="plan-current">Current</div><?php endif; ?>

                        <div class="plan-name"><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="plan-tag"><?php echo htmlspecialchars($p['tagline'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>

                        <div class="plan-price">
                            <b class="plan-amount"
                               data-monthly="<?php echo htmlspecialchars(taka($p['monthly']), ENT_QUOTES, 'UTF-8'); ?>"
                               data-annual="<?php echo htmlspecialchars(taka($p['yearly']), ENT_QUOTES, 'UTF-8'); ?>"
                            ><?php echo htmlspecialchars(taka($p['monthly']), ENT_QUOTES, 'UTF-8'); ?></b>
                            <span class="plan-period">/মাস</span>
                        </div>
                        <div class="plan-note"
                             data-save="<?php echo htmlspecialchars(taka($p['yearly']), ENT_QUOTES, 'UTF-8'); ?> /বছর — প্রায় ২ মাস ফ্রি"></div>

                        <ul class="plan-feats">
                            <?php foreach (($p['features'] ?? []) as $feat): ?>
                                <li><i class="fas fa-check"></i> <?php echo htmlspecialchars($feat, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if ($loggedIn && $canBuy): ?>
                            <form action="checkout.php" method="post">
                                <input type="hidden" name="plan" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="cycle" value="monthly" data-cycle-input>
                                <button type="submit" class="plan-btn">
                                    <?php echo htmlspecialchars($planLabel[$slug], ENT_QUOTES, 'UTF-8'); ?> <i class="fas fa-arrow-right"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars(homeEntryLink($subUser), ENT_QUOTES, 'UTF-8'); ?>" class="plan-btn">
                                Get Started <i class="fas fa-arrow-right"></i></a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p class="price-custom">
                    আলাদা কিছু লাগবে? <a href="contact.php">যোগাযোগ করুন</a> — আপনার ব্যবসার জন্য কাস্টম প্ল্যান।
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ─── SCREENSHOTS ────────────────────────────────────────────────────── -->
<section class="sec sec-soft" id="screenshots">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow"><i class="fas fa-images"></i> Screenshots</span>
            <h2 class="sec-title">সব স্ক্রিন এক নজরে</h2>
            <p class="sec-sub">ক্যাউন্টার থেকে রিপোর্ট পর্যন্ত — যেভাবে দিনভর ব্যবহার করবেন ঠিক সেইভাবে।</p>
        </div>

        <div class="shot-tabs" role="tablist">
            <button type="button" class="shot-tab on" data-shot="pos" role="tab" aria-selected="true">
                <i class="fas fa-cash-register"></i> Web POS
            </button>
            <button type="button" class="shot-tab" data-shot="dash" role="tab" aria-selected="false">
                <i class="fas fa-gauge-high"></i> Dashboard
            </button>
            <button type="button" class="shot-tab" data-shot="report" role="tab" aria-selected="false">
                <i class="fas fa-chart-column"></i> Reports
            </button>
        </div>

        <div class="shot-stage">
            <!-- Web POS -->
            <div class="shot-panel on" data-panel="pos" role="tabpanel">
                <div class="shot-frame">
                    <div class="shot-chrome"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
                        <span class="shot-url">smartercollection.shop/admin/pos.php</span></div>
                    <div class="mini" aria-hidden="true">
                        <div class="mini-body">
                            <div class="mini-side">
                                <i class="on">POS</i><i>Products</i><i>Sales</i><i>Stock</i><i>Customers</i><i>Reports</i>
                            </div>
                            <div class="mini-main">
                                <div class="mini-stats">
                                    <div class="mini-stat"><span>Subtotal</span><b>৳4,150</b></div>
                                    <div class="mini-stat"><span>Discount</span><b>৳150</b></div>
                                    <div class="mini-stat"><span>Paid</span><b>৳4,000</b></div>
                                    <div class="mini-stat"><span>Due</span><b>৳0</b></div>
                                </div>
                                <div class="mini-card" style="flex:1">
                                    <h6>Products &mdash; scan or tap to add</h6>
                                    <div class="mini-prods">
                                        <div class="mini-prod"><i class="fas fa-shirt"></i><b>T-Shirt</b><span>৳450</span></div>
                                        <div class="mini-prod"><i class="fas fa-socks"></i><b>Jeans</b><span>৳1,200</span></div>
                                        <div class="mini-prod"><i class="fas fa-shoe-prints"></i><b>Shoes</b><span>৳1,600</span></div>
                                        <div class="mini-prod"><i class="fas fa-clock"></i><b>Watch</b><span>৳2,000</span></div>
                                        <div class="mini-prod"><i class="fas fa-hat-cowboy"></i><b>Cap</b><span>৳300</span></div>
                                        <div class="mini-prod"><i class="fas fa-glasses"></i><b>Sunglass</b><span>৳700</span></div>
                                        <div class="mini-prod"><i class="fas fa-tag"></i><b>Belt</b><span>৳500</span></div>
                                        <div class="mini-prod"><i class="fas fa-bag-shopping"></i><b>Bag</b><span>৳950</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="mini-cart">
                                <h6>Cart (3)</h6>
                                <ul>
                                    <li><span>T-Shirt</span><b>৳450</b></li>
                                    <li><span>Jeans</span><b>৳1,200</b></li>
                                    <li><span>Watch</span><b>৳2,000</b></li>
                                    <li class="tot">Checkout ৳4,000</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="shot-cap">বারকোড স্ক্যান বা ট্যাপ করে বিল — এক স্ক্রিনেই।</p>
            </div>

            <!-- Dashboard -->
            <div class="shot-panel" data-panel="dash" role="tabpanel">
                <div class="shot-frame">
                    <div class="shot-chrome"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
                        <span class="shot-url">smartercollection.shop/admin/dashboard.php</span></div>
                    <div class="mini" aria-hidden="true">
                        <div class="mini-top"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
                            <b>Smart Collection</b><span class="who">Owner</span></div>
                        <div class="mini-body">
                            <div class="mini-side">
                                <i class="on">Dashboard</i><i>Sales</i><i>Products</i><i>Customers</i>
                                <i>Stock</i><i>Reports</i><i>SMS</i><i>Settings</i>
                            </div>
                            <div class="mini-main">
                                <div class="mini-stats">
                                    <div class="mini-stat"><span>Today</span><b>৳12,450</b></div>
                                    <div class="mini-stat"><span>Total sales</span><b>356,200</b></div>
                                    <div class="mini-stat"><span>Products</span><b>250</b></div>
                                    <div class="mini-stat"><span>Customers</span><b>842</b></div>
                                </div>
                                <div class="mini-cards">
                                    <div class="mini-card"><h6>Sales overview</h6>
                                        <div class="mini-bars">
                                            <span style="height:34%"></span><span style="height:52%"></span><span style="height:41%"></span>
                                            <span style="height:68%"></span><span style="height:55%"></span><span style="height:82%"></span>
                                            <span style="height:63%"></span><span style="height:94%"></span>
                                        </div>
                                    </div>
                                    <div class="mini-card"><h6>Recent sales</h6>
                                        <ul class="mini-list">
                                            <li><span class="mini-av">SH</span><em>Shahin</em><b>৳450</b></li>
                                            <li><span class="mini-av">RA</span><em>Rana</em><b>৳1,200</b></li>
                                            <li><span class="mini-av">KA</span><em>Karim</em><b>৳320</b></li>
                                            <li><span class="mini-av">MO</span><em>Mohan</em><b>৳860</b></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="shot-cap">আজকের বিক্রি, স্টক আর কাস্টমার — লগইন করতাই সামনে।</p>
            </div>

            <!-- Reports -->
            <div class="shot-panel" data-panel="report" role="tabpanel">
                <div class="shot-frame">
                    <div class="shot-chrome"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
                        <span class="shot-url">smartercollection.shop/admin/reports.php</span></div>
                    <div class="mini" aria-hidden="true">
                        <div class="mini-top"><span class="dot"></span><span class="dot"></span><span class="dot"></span>
                            <b>Reports</b><span class="who">This month</span></div>
                        <div class="mini-body">
                            <div class="mini-side">
                                <i>Daily</i><i class="on">Product</i><i>Customer</i><i>Stock</i><i>Expense</i><i>Cashbook</i>
                            </div>
                            <div class="mini-main">
                                <div class="mini-stats">
                                    <div class="mini-stat"><span>Revenue</span><b>৳3,86,200</b></div>
                                    <div class="mini-stat"><span>Profit</span><b>৳72,400</b></div>
                                    <div class="mini-stat"><span>Bills</span><b>1,204</b></div>
                                    <div class="mini-stat"><span>Returns</span><b>18</b></div>
                                </div>
                                <div class="mini-card" style="flex:1"><h6>Top products</h6>
                                    <ul class="mini-list">
                                        <li><span class="mini-av">T</span><em>T-Shirt</em><b>৳42,300</b></li>
                                        <li><span class="mini-av">J</span><em>Jeans</em><b>৳38,900</b></li>
                                        <li><span class="mini-av">S</span><em>Shoes</em><b>৳31,200</b></li>
                                        <li><span class="mini-av">W</span><em>Watch</em><b>৳24,700</b></li>
                                        <li><span class="mini-av">B</span><em>Bag</em><b>৳18,400</b></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="shot-cap">কোন পণ্য কত বিক্রি হলো, কত লাভ — CSV নামানো যায়।</p>
            </div>
        </div>
    </div>
</section>

<!-- ─── ABOUT ──────────────────────────────────────────────────────────── -->
<section class="sec" id="about">
    <div class="wrap">
        <div class="about-grid">
            <div>
                <span class="eyebrow"><i class="fas fa-building"></i> About</span>
                <h2 class="sec-title" style="margin-top:16px">AVA IT Solution &mdash; Smart Collection</h2>
                <p style="color:var(--muted);margin-bottom:22px">
                    বাংলাদেশের দোকান ও ব্যবসার জন্য তৈরি একটি সম্পূর্ণ POS সিস্টেম। বিলিং, স্টক, কাস্টমার,
                    SMS আর রিপোর্ট — সব এক জায়গায়, মোবাইল অ্যাপসহ।
                </p>
                <div class="about-list">
                    <div class="about-item">
                        <i class="fas fa-bolt"></i>
                        <div><b>মিনিটেই সেটআপ</b><p>অ্যাকাউন্ট খুলে পণ্য যোগ করুন, বিলিং শুরু হয়ে যাবে।</p></div>
                    </div>
                    <div class="about-item">
                        <i class="fas fa-mobile-screen"></i>
                        <div><b>ওয়েব + অ্যান্ড্রয়েড</b><p>কাউন্টারে কম্পিউটার, হাতে মোবাইল অ্যাপ — একই লগইন।</p></div>
                    </div>
                    <div class="about-item">
                        <i class="fas fa-headset"></i>
                        <div><b>সাপোর্ট</b><p>যেকোনো সমস্যায় ফোন করুন ০১৩৩১৭০৭৯০০ নম্বরে।</p></div>
                    </div>
                </div>
            </div>
            <div class="about-facts">
                <div class="fact"><b>10K+</b><span>দোকান ব্যবহার করছে</span></div>
                <div class="fact"><b>50K+</b><span>পণ্য ম্যানেজ হচ্ছে</span></div>
                <div class="fact"><b>1M+</b><span>লেনদেন প্রসেস হয়েছে</span></div>
                <div class="fact"><b>24/7</b><span>সাপোর্ট</span></div>
            </div>
        </div>
    </div>
</section>

<!-- ─── CTA + ANDROID APP ──────────────────────────────────────────────── -->
<section class="sec" id="staff-app">
    <div class="wrap">
        <div class="cta-grid">
            <div class="cta-note">
                <span class="hand">Grow Your<br>Business<br>with Smart<br>Collection</span>
                <i class="fas fa-arrow-right arrow"></i>
            </div>

            <div class="cta-mid">
                <h2>Ready to Transform Your Business?</h2>
                <p>আজই শুরু করুন আপনার নতুন ব্যবসার যাত্রা। ৭ দিন ফ্রি ট্রায়াল — কার্ড লাগবে না।</p>
                <div class="cta-actions">
                    <a href="<?php echo htmlspecialchars($demoWa, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-green" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Request Demo</a>
                    <a href="contact.php" class="btn btn-ghost"><i class="fas fa-envelope"></i> Contact Us</a>
                    <a href="auth/register.php" class="btn btn-blue"><i class="fas fa-rocket"></i> Start Free Trial</a>
                </div>
            </div>

            <div class="app-card">
                <div class="app-card-head"><i class="fab fa-android"></i><b>Android Apps</b></div>
                <p>যেখানেই থাকুন ব্যবসা ম্যানেজ করুন — একই লগইন, বারকোড স্ক্যানার ও LAN প্রিন্টারসহ।</p>

                <div class="apk-stack">
                    <div>
                        <p class="apk-cap"><i class="fas fa-user-tie"></i> দোকানির অ্যাপ (Staff)</p>
                        <a href="#" class="apk-btn" id="appdlBtn" aria-disabled="true">
                            <i class="fab fa-android"></i>
                            <span>
                                <small>Get it on</small>
                                <b id="appdlBtnText">Checking availability&hellip;</b>
                            </span>
                        </a>
                        <p class="app-meta" id="appdlMeta"></p>
                        <p class="apk-new">
                            <i class="fas fa-print"></i>
                            <span>নতুন: LAN থার্মাল প্রিন্টার (৫৮/৮০ মিমি) — বিক্রি সেভ হওয়ার পর বিল নিজে থেকেই প্রিন্ট হয়, দরকার হলে রিপ্রিন্টও করা যায়।</span>
                        </p>
                    </div>

                    <div>
                        <p class="apk-cap"><i class="fas fa-cart-shopping"></i> ক্রেতার অ্যাপ (Customer)</p>
                        <a href="#" class="apk-btn" id="marketdlBtnCta" aria-disabled="true">
                            <i class="fas fa-cart-shopping"></i>
                            <span>
                                <small>Get it on</small>
                                <b id="marketdlBtnTextCta">Checking availability&hellip;</b>
                            </span>
                        </a>
                        <p class="app-meta" id="marketdlMetaCta"></p>
                    </div>
                </div>

                <p class="app-note">
                    Android ব্রাউজার থেকে ইনস্টলের অনুমতি চাইবে — এটি স্বাভাবিক। ক্রেতার অ্যাপ তখনই কাজ করবে যখন দোকানটিতে Online Shop চালু থাকবে।
                </p>

                <div class="shot-app" style="margin-top:16px">
                    <div class="appdemo" id="appDemo" data-playing="on" aria-hidden="true">
                        <div class="appdemo-frame">
                            <div class="appdemo-screen">
                                <div class="appdemo-notch"></div>
                                <div class="appdemo-status"><span>9:41</span><span><i class="fas fa-signal"></i> <i class="fas fa-wifi"></i> <i class="fas fa-battery-three-quarters"></i></span></div>

                                <div class="scene" data-scene="0">
                                    <div class="scene-head"><b>New Sale</b><span class="scene-chip">Counter</span></div>
                                    <div class="scene-search"><i class="fas fa-magnifying-glass"></i> Search or scan a product&hellip;</div>
                                    <div class="srow"><span class="th"><i class="fas fa-shirt"></i></span><span class="nm"><b>T-Shirt</b><span>Cotton · Blue M</span></span><span class="pr">৳450</span><span class="add"><i class="fas fa-plus"></i></span></div>
                                    <div class="srow"><span class="th"><i class="fas fa-shoe-prints"></i></span><span class="nm"><b>Sneakers</b><span>Size 42</span></span><span class="pr">৳1,200</span><span class="add"><i class="fas fa-plus"></i></span></div>
                                    <div class="srow"><span class="th"><i class="fas fa-bag-shopping"></i></span><span class="nm"><b>Tote Bag</b><span>Natural</span></span><span class="pr">৳320</span><span class="add"><i class="fas fa-plus"></i></span></div>
                                    <div class="stotal pop"><span>Cart · 3 items</span><span>৳1,970</span></div>
                                </div>

                                <div class="scene" data-scene="1">
                                    <div class="scene-head"><b>Cart</b><span class="scene-chip">3 items</span></div>
                                    <div class="sghost"></div><div class="sghost"></div><div class="sghost short"></div>
                                    <div class="stotal"><span>Total</span><span>৳1,970</span></div>
                                </div>

                                <div class="scene" data-scene="2">
                                    <div class="scene-head"><b>Payment</b><span class="scene-chip">Due ৳1,970</span></div>
                                    <div class="spay"><i class="fas fa-money-bill-wave"></i> Cash</div>
                                    <div class="spay ghost"><i class="fas fa-mobile-screen"></i> bKash</div>
                                    <div class="spay ghost"><i class="fas fa-credit-card"></i> Card</div>
                                    <div class="sproc"><span class="spinner"></span> Processing payment&hellip;</div>
                                </div>

                                <div class="scene" data-scene="3">
                                    <div class="sdone">
                                        <span class="sring"><i class="fas fa-check"></i></span>
                                        <b>Sale complete</b><span>Paid in full</span>
                                        <div class="srec">
                                            <span><i>T-Shirt</i><b>450</b></span>
                                            <span><i>Sneakers</i><b>1,200</b></span>
                                            <span><i>Tote Bag</i><b>320</b></span>
                                            <span class="big"><i>Total</i><b>৳1,970</b></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="cap" data-cap="0"><i class="fas fa-barcode"></i> ১ · স্ক্যান বা সার্চ করে পণ্য যোগ করুন</div>
                                <div class="cap" data-cap="1"><i class="fas fa-basket-shopping"></i> ২ · কার্টের মোট দেখুন</div>
                                <div class="cap" data-cap="2"><i class="fas fa-hand-holding-dollar"></i> ৩ · পেমেন্ট নিন</div>
                                <div class="cap" data-cap="3"><i class="fas fa-circle-check"></i> ৪ · বিল প্রস্তুত</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ─── MARKETPLACE (CUSTOMER) APP ─────────────────────────────────────── -->
<section class="sec sec-soft" id="customer-app">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow"><i class="fas fa-cart-shopping"></i> Customer App</span>
            <h2 class="sec-title">আপনার দোকান এখন ক্রেতার হাতে</h2>
            <p class="sec-sub">POS-তে Online Shop চালু করলেই ক্রেতারা মোবাইল অ্যাপ থেকে পণ্য দেখে অর্ডার করবে।</p>
        </div>

        <div class="market-grid">
            <div class="market-copy">
                <h2>ক্রেতারা যা যা করবে</h2>
                <p>কোনো লগইন বা পেমেন্ট গেটওয়ে লাগবে না — অর্ডার সরাসরি আপনার অ্যাডমিনে ঢুকবে।</p>
                <div class="market-list">
                    <div>
                        <i class="fas fa-store"></i>
                        <div><b>দোকান বেছে নিন</b><span>যেসব দোকান অন লাইন পণ্য ছেড়েছে শুধু সেগুলোই তালিকায় দেখায়।</span></div>
                    </div>
                    <div>
                        <i class="fas fa-magnifying-glass"></i>
                        <div><b>পণ্য খুঁজুন</b><span>নাম বা বারকোড দিয়ে সার্চ, দাম-স্টক আর ছবি সাথে সাথে।</span></div>
                    </div>
                    <div>
                        <i class="fas fa-basket-shopping"></i>
                        <div><b>কার্ট করে অর্ডার</b><span>ঠিকানা ও ফোন নম্বর দিয়ে ক্যাশ অন ডেলিভারি অর্ডার পাঠায়।</span></div>
                    </div>
                    <div>
                        <i class="fas fa-truck-fast"></i>
                        <div><b>অর্ডার ট্র্যাক করুন</b><span>গৃহীত, প্রস্তুত বা বাতিল — প্রতিটি ধাপ অ্যাপেই দেখতে পাবে।</span></div>
                    </div>
                </div>
            </div>

            <div class="app-card">
                <div class="app-card-head"><i class="fas fa-mobile-screen"></i><b>Marketplace App</b></div>
                <p>ক্রেতাদের ফ্রি অ্যান্ড্রয়েড অ্যাপ — দোকানির অ্যাপ থেকে আলাদা।</p>

                <a href="#" class="apk-btn" id="marketdlBtn" aria-disabled="true">
                    <i class="fab fa-android"></i>
                    <span>
                        <small>Get it on</small>
                        <b id="marketdlBtnText">Checking availability&hellip;</b>
                    </span>
                </a>
                <p class="app-meta" id="marketdlMeta"></p>
                <p class="app-note">
                    অ্যাপটি ইনস্টল করার আগে ক্রেতাকে শুধু আপনার দোকানের অন লাইন শপ চালু করতে বলুন।
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ─── FOOTER ─────────────────────────────────────────────────────────── -->
<footer class="lp-foot" id="contact">
    <div class="wrap">
        <div class="foot-inner">
            <div class="foot-brand">
                <img src="<?php echo htmlspecialchars(assetUrl('assets/img/ava_logo_mark.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="AVA IT Solution">
                <div><b>AVA IT Solution</b><span>Smart Collection</span></div>
            </div>
            <nav class="foot-links">
                <a href="#top">Home</a>
                <a href="#features">Features</a>
                <a href="#pricing">Pricing</a>
                <a href="#screenshots">Screenshots</a>
                <a href="#about">About</a>
                <a href="contact.php">Contact</a>
                <a href="terms.php">Terms</a>
                <a href="privacy.php">Privacy</a>
                <a href="auth/login.php">Login</a>
            </nav>
            <a class="foot-wa" href="https://wa.me/<?php echo legalE($support['whatsapp']); ?>" target="_blank" rel="noopener">
                <i class="fab fa-whatsapp"></i>
                <span>WhatsApp Support <b><?php echo legalE($support['wa_display']); ?></b></span>
            </a>
            <div class="foot-social">
                <a href="tel:<?php echo legalE($support['tel']); ?>" title="Call us"><i class="fas fa-phone-alt"></i></a>
                <a href="mailto:<?php echo legalE($support['email']); ?>" title="Email"><i class="fas fa-envelope"></i></a>
                <a href="#" title="Facebook"><i class="fab fa-facebook"></i></a>
                <a href="#" title="YouTube"><i class="fab fa-youtube"></i></a>
                <a href="#" title="LinkedIn"><i class="fab fa-linkedin"></i></a>
            </div>
        </div>
        <div class="foot-copy">
            <span>&copy; 2026 AVA IT Solution. All rights reserved. &nbsp;বাংলাদেশ</span>
            <span><?php echo legalE($support['tel_display']); ?> &middot; <?php echo legalE($support['site']); ?></span>
        </div>
    </div>
</footer>

<script>
    // ── APK download buttons ────────────────────────────────────────────
    // Each app reads its own manifest, so publishing a new build needs no PHP
    // change - just drop the APK in and set "available": true. The staff app's
    // manifest.json is also polled by that app's own update check, so the
    // customer build gets a separate file rather than sharing it.
    // The customer APK is offered twice on this page (staff card + its own
    // section), so each manifest is fetched at most once per page load.
    var apkRequests = {};

    function apkInfo(manifest) {
        if (!apkRequests[manifest]) {
            apkRequests[manifest] = fetch(manifest, { cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    var a = data && data.android;
                    return (a && a.available && a.file) ? a : null;
                });
        }
        return apkRequests[manifest];
    }

    function wireApk(btnId, textId, metaId, manifest) {
        var btn = document.getElementById(btnId);
        var btnText = document.getElementById(textId);
        var meta = document.getElementById(metaId);
        if (!btn || !btnText || !meta) return;

        function setComingSoon(msg) {
            btn.setAttribute('aria-disabled', 'true');
            btn.removeAttribute('href');
            btn.removeAttribute('download');
            btnText.textContent = 'Coming soon';
            meta.textContent = msg || '';
        }

        function enable(file, info) {
            // Relative path, so this keeps working if the site moves.
            btn.setAttribute('href', 'downloads/' + file);
            btn.setAttribute('download', '');
            btn.setAttribute('aria-disabled', 'false');

            var label = 'Download APK';
            if (info && info.version) label += ' · v' + info.version;
            btnText.textContent = label;

            var bits = [];
            if (info && info.size) bits.push(info.size);
            if (info && info.minAndroid) bits.push('Android ' + info.minAndroid + '+');
            if (info && info.updated) bits.push('Updated ' + info.updated);
            meta.textContent = bits.join(' · ');
        }

        apkInfo(manifest)
            .then(function (a) {
                if (a) { enable(a.file, a); }
                else { setComingSoon('The Android build is being prepared.'); }
            })
            .catch(function () { setComingSoon('Could not check for the latest build.'); });
    }

    wireApk('appdlBtn', 'appdlBtnText', 'appdlMeta', 'downloads/manifest.json');
    wireApk('marketdlBtnCta', 'marketdlBtnTextCta', 'marketdlMetaCta', 'downloads/market-manifest.json');
    wireApk('marketdlBtn', 'marketdlBtnText', 'marketdlMeta', 'downloads/market-manifest.json');

    // Pause the in-phone sale demo when it is scrolled out of view: the loop is
    // pure CSS, so all this does is flip the attribute the stylesheet reads.
    (function () {
        var demo = document.getElementById('appDemo');
        if (!demo || !('IntersectionObserver' in window)) return;
        new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                demo.setAttribute('data-playing', e.isIntersecting ? 'on' : 'off');
            });
        }, { threshold: 0.15 }).observe(demo);
    })();

    // ── Pricing: monthly ↔ annual switch ────────────────────────────────
    // Prices live in data attributes so there is one source of truth and no
    // server round-trip. The annual figures are ~2 months free.
    (function () {
        var sw = document.getElementById('billingSwitch');
        if (!sw) return;
        var labels  = document.querySelectorAll('.bt-label');
        var amounts = document.querySelectorAll('.plan-amount');
        var periods = document.querySelectorAll('.plan-period');
        var notes   = document.querySelectorAll('.plan-note');
        var cycles  = document.querySelectorAll('[data-cycle-input]');

        function render(annual) {
            sw.classList.toggle('annual', annual);
            sw.setAttribute('aria-checked', annual ? 'true' : 'false');
            labels.forEach(function (l) {
                l.classList.toggle('active', (l.dataset.period === 'annual') === annual);
            });
            amounts.forEach(function (a) { a.textContent = annual ? a.dataset.annual : a.dataset.monthly; });
            periods.forEach(function (p) { p.textContent = annual ? '/বছর' : '/মাস'; });
            notes.forEach(function (n) { n.textContent = annual ? (n.dataset.save || '') : ''; });
            // Keep the checkout forms in sync with the visible prices so the
            // server charges for the cycle the buyer is actually looking at.
            cycles.forEach(function (c) { c.value = annual ? 'yearly' : 'monthly'; });
        }
        function toggle() { render(!sw.classList.contains('annual')); }

        sw.addEventListener('click', toggle);
        sw.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
        });
        labels.forEach(function (l) {
            l.addEventListener('click', function () { render(l.dataset.period === 'annual'); });
        });
        render(false);
    })();

    // ── Screenshots tabs ────────────────────────────────────────────────
    (function () {
        var tabs = document.querySelectorAll('.shot-tab');
        var panels = document.querySelectorAll('.shot-panel');
        if (!tabs.length) return;
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var key = tab.dataset.shot;
                tabs.forEach(function (t) {
                    t.classList.toggle('on', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                panels.forEach(function (p) { p.classList.toggle('on', p.dataset.panel === key); });
            });
        });
    })();

    // ── Theme: light ↔ dark toggle, remembered on this device ───────────
    (function () {
        var btn  = document.getElementById('themeToggle');
        var icon = document.getElementById('themeIcon');

        function apply(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            if (icon) { icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun'; }
            try { localStorage.setItem('pos.theme', theme); } catch (e) {}
        }

        var current = 'light';
        try { current = localStorage.getItem('pos.theme') || 'light'; } catch (e) {}
        apply(current);

        if (btn) {
            btn.addEventListener('click', function () {
                apply(document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
            });
        }
    })();

    // ── Mobile nav: hamburger opens the links as a dropdown ─────────────
    (function () {
        var toggle = document.getElementById('navToggle');
        var links  = document.getElementById('navLinks');
        if (!toggle || !links) { return; }
        function setOpen(open) {
            links.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            var i = toggle.querySelector('i');
            if (i) { i.className = open ? 'fas fa-times' : 'fas fa-bars'; }
        }
        toggle.addEventListener('click', function () { setOpen(!links.classList.contains('open')); });
        links.addEventListener('click', function (e) {
            if (e.target.closest('a')) { setOpen(false); }
        });
        document.addEventListener('click', function (e) {
            if (!links.contains(e.target) && !toggle.contains(e.target)) { setOpen(false); }
        });
    })();
</script>

</body>

</html>
