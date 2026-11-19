<?php
/**
 * Public page shell for the pages that are not the app: privacy, terms, contact.
 *
 * WHY A SHEFILE AND NOT THREE COPIES
 * ----------------------------------
 * home.php owns the design but keeps its CSS in one inline block, so a second
 * page written from scratch drifts the moment the landing is edited. These helpers
 * print the head, the top bar and the footer from one place against
 * assets/css/legal.css, which repeats the landing's tokens on purpose: a visitor
 * who opens the privacy policy from the footer should not feel they left the site.
 *
 * The pages that use this live beside home.php and index.php, so every link
 * here is relative - that is what keeps the same file correct at / in production
 * and at /admin/ in the XAMPP copy.
 */

require_once __DIR__ . '/db.php';

/**
 * The people and addresses a shop can actually reach. Kept in one place because
 * the same numbers appear on the landing footer, both legal pages and contact.php,
 * and a wrong support number on a legal page is worse than a wrong price.
 */
function legalSupport()
{
    return array(
        'company'   => 'AVA IT Solution',
        'product'   => 'Smart Collection POS',
        'site'      => 'smartercollection.shop',
        'whatsapp'  => '8801810902817',
        'wa_display'=> '01810902817',
        'tel'       => '+8801331707900',
        'tel_display' => '01331707900',
        'email'     => 'info@smartercollection.shop',
        'updated'   => '16 October 2026',
    );
}

/** Small escape helper so the pages below stay readable. */
function legalE($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/**
 * Print everything from the doctype down to the opening content column.
 *
 * @param string $title  Browser tab title.
 * @param string $active One of home|privacy|terms|contact, highlights the menu.
 * @param array  $hero   eyebrow, title, desc, and optionally stamp.
 */
function legalHead($title, $active, array $hero)
{
    $s = legalSupport();
    $mark = assetUrl('assets/img/ava_logo_mark.png');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo legalE($title); ?> &middot; <?php echo legalE($s['company']); ?></title>
    <meta name="description" content="<?php echo legalE(strip_tags($hero['desc'])); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo legalE(assetUrl('assets/css/legal.css')); ?>">
    <link rel="icon" type="image/png" href="<?php echo legalE($mark); ?>">
    <script>
        // Applied before the first paint so a dark-theme visitor never sees a
        // white flash. Same key the landing page writes.
        (function () {
            var t = 'light';
            try { t = localStorage.getItem('pos.theme') || 'light'; } catch (e) {}
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body>
<nav class="pn-nav">
    <div class="pn-nav-inner">
        <button class="pn-toggle" id="pnToggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="pnLinks">
            <i class="fas fa-bars"></i>
        </button>
        <a href="index.php" class="pn-brand">
            <img src="<?php echo legalE($mark); ?>" alt="<?php echo legalE($s['company']); ?>">
            <span><?php echo legalE($s['company']); ?><small><?php echo legalE($s['product']); ?></small></span>
        </a>
        <ul class="pn-links" id="pnLinks">
            <li><a href="index.php" class="<?php echo $active === 'home' ? 'active' : ''; ?>">Home</a></li>
            <li><a href="index.php#pricing" class="<?php echo ''; ?>">Pricing</a></li>
            <li><a href="terms.php" class="<?php echo $active === 'terms' ? 'active' : ''; ?>">Terms</a></li>
            <li><a href="privacy.php" class="<?php echo $active === 'privacy' ? 'active' : ''; ?>">Privacy</a></li>
            <li><a href="contact.php" class="<?php echo $active === 'contact' ? 'active' : ''; ?>">Contact</a></li>
        </ul>
        <div class="pn-actions">
            <button class="btn btn-ghost btn-sm btn-icon" id="pnTheme" type="button"
                    title="Toggle light / dark" aria-label="Toggle light or dark theme">
                <i class="fas fa-moon" id="pnThemeIcon"></i>
            </button>
            <a href="auth/login.php" class="btn btn-ghost btn-sm btn-hide-sm">Login</a>
            <a href="auth/register.php" class="btn btn-green btn-sm">Start Free Trial</a>
        </div>
    </div>
</nav>

<header class="pn-hero">
    <div class="wrap">
        <span class="eyebrow"><i class="fas fa-file-contract"></i> <?php echo legalE($hero['eyebrow']); ?></span>
        <h1><?php echo legalE($hero['title']); ?></h1>
        <p><?php echo legalE($hero['desc']); ?></p>
        <?php if (!empty($hero['stamp'])): ?>
        <p class="stamp"><b>Last updated:</b> <?php echo legalE($hero['stamp']); ?></p>
        <?php endif; ?>
    </div>
</header>

<main class="pn-main">
    <div class="wrap">
    <?php
}

/** Close the content column, print the footer and the two behaviours. */
function legalFoot()
{
    $s = legalSupport();
    ?>
    </div>
</main>

<footer class="pn-foot">
    <div class="wrap">
        <div class="pn-foot-inner">
            <div><b><?php echo legalE($s['company']); ?></b> &middot; <?php echo legalE($s['product']); ?></div>
            <nav>
                <a href="index.php">Home</a>
                <a href="terms.php">Terms &amp; Conditions</a>
                <a href="privacy.php">Privacy Policy</a>
                <a href="contact.php">Contact</a>
                <a href="auth/login.php">Login</a>
            </nav>
        </div>
        <div class="copy">
            <span>&copy; <?php echo date('Y'); ?> <?php echo legalE($s['company']); ?>. Bangladesh.</span>
            <span><?php echo legalE($s['tel_display']); ?> &middot; <?php echo legalE($s['site']); ?></span>
        </div>
    </div>
</footer>

<script>
    (function () {
        var tgl = document.getElementById('pnToggle');
        var links = document.getElementById('pnLinks');
        if (tgl && links) {
            tgl.addEventListener('click', function () {
                var open = links.classList.toggle('open');
                tgl.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        var btn = document.getElementById('pnTheme');
        var icon = document.getElementById('pnThemeIcon');
        function paint() {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (icon) icon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        }
        if (btn) {
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('pos.theme', next); } catch (e) {}
                paint();
            });
        }
        paint();
    })();
</script>
</body>
</html>
    <?php
}
