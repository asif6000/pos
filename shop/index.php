<?php
/**
 * The shop's public page - what a customer sees at /shop/?slug=<link>.
 *
 * This is the web twin of the customer app and it reads exactly what the app will
 * read: ecommercePublicCatalogue() returns only products the shopkeeper published,
 * with the price the shop chose for online and the stock of the branch that would
 * fulfil the order. Nothing here is editable by the visitor - the cart is a
 * convenience, and the totals the shop receives are recalculated on the server in
 * config/ecommerce.php when the order is placed.
 *
 * A customer signs in with nothing. Their identity is the order number plus the
 * phone they typed, which is how status lookups are answered in shop/api.php.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ecommerce.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string)$_GET['slug'])) : '';
$data = $slug !== '' ? ecommercePublicCatalogue($slug) : null;

$shop = $data ? $data['shop'] : null;
$products = $data ? $data['products'] : [];

$takea = function ($amount) {
    return '৳' . rtrim(rtrim(number_format((float)$amount, 2, '.', ''), '0'), '.');
};

// Names are Bengali on the shop page, and only ASCII search needs folding. mbstring
// is present on both hosts but is not guaranteed by PHP itself, so fall back rather
// than take down a public page over a lowercase call.
$lower = function ($text) {
    return function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
};
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $shop ? htmlspecialchars((string)$shop['name'], ENT_QUOTES, 'UTF-8') : 'Shop not found'; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --bg: #f6f8fb; --card: #fff; --ink: #16202e; --muted: #6b7a90;
        --line: #e3e8f0; --brand: #0b7a5b; --brand-ink: #fff; --warn: #b42318;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, "Noto Sans Bengali", sans-serif;
           background: var(--bg); color: var(--ink); }
    a { color: inherit; }
    .wrap { max-width: 1080px; margin: 0 auto; padding: 0 1rem 6rem; }
    header.shop { position: sticky; top: 0; z-index: 5; background: var(--card);
                  border-bottom: 1px solid var(--line); padding: .85rem 1rem; }
    .shop-inner { max-width: 1080px; margin: 0 auto; display: flex; gap: .8rem; align-items: center; flex-wrap: wrap; }
    .shop-name { font-size: 1.15rem; font-weight: 700; }
    .shop-logo { width: 46px; height: 46px; border-radius: 12px; object-fit: cover;
                 border: 1px solid var(--line); flex: 0 0 auto; }
    .shop-meta { color: var(--muted); font-size: .82rem; }
    .pill { margin-left: auto; background: #eef7f3; color: var(--brand); border: 1px solid #cfe9dd;
            border-radius: 999px; padding: .3rem .7rem; font-size: .8rem; }
    .pill.closed { background: #fdecea; color: var(--warn); border-color: #f7cfcf; }
    .toolbar { display: flex; gap: .6rem; flex-wrap: wrap; margin: 1.1rem 0 .6rem; }
    .toolbar input[type=search], .toolbar select {
        padding: .55rem .7rem; border: 1px solid var(--line); border-radius: 9px; background: #fff; font-size: .9rem; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .9rem; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: .8rem;
            display: flex; flex-direction: column; gap: .5rem; }
    .thumb { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; border-radius: 10px; background: #eef1f6; }
    .name { font-weight: 600; font-size: .95rem; line-height: 1.25; }
    .cat { color: var(--muted); font-size: .78rem; }
    .price { font-weight: 700; color: var(--brand); }
    .stock { font-size: .78rem; color: var(--muted); }
    .stock.out { color: var(--warn); }
    .row { display: flex; align-items: center; gap: .45rem; margin-top: auto; }
    .qty { width: 58px; padding: .4rem; border: 1px solid var(--line); border-radius: 8px; }
    .btn { border: 0; border-radius: 9px; padding: .55rem .8rem; font-weight: 600; cursor: pointer; font-size: .88rem; }
    .btn-brand { background: var(--brand); color: var(--brand-ink); }
    .btn-ghost { background: #eef1f6; color: var(--ink); }
    .empty { text-align: center; color: var(--muted); padding: 3rem 1rem; }
    .bar { position: fixed; left: 0; right: 0; bottom: 0; background: var(--card); border-top: 1px solid var(--line);
           box-shadow: 0 -6px 18px rgba(16,24,40,.08); }
    .bar-inner { max-width: 1080px; margin: 0 auto; padding: .7rem 1rem; display: flex; gap: .7rem; align-items: center; flex-wrap: wrap; }
    dialog { border: 0; border-radius: 16px; padding: 0; max-width: 520px; width: calc(100% - 2rem); }
    dialog::backdrop { background: rgba(12,18,28,.45); }
    .sheet { padding: 1.1rem; }
    .sheet h3 { margin: 0 0 .7rem; }
    .line { display: flex; justify-content: space-between; gap: .6rem; padding: .35rem 0; font-size: .9rem; }
    .field { display: block; margin: .5rem 0; }
    .field span { display: block; font-size: .8rem; color: var(--muted); margin-bottom: .2rem; }
    .field input, .field textarea { width: 100%; padding: .55rem .6rem; border: 1px solid var(--line); border-radius: 9px; font: inherit; }
    .note { font-size: .78rem; color: var(--muted); }
    .ok { background: #eef7f3; border: 1px solid #cfe9dd; color: var(--brand); border-radius: 10px; padding: .7rem; font-weight: 600; }
    .bad { background: #fdecea; border: 1px solid #f7cfcf; color: var(--warn); border-radius: 10px; padding: .7rem; font-size: .9rem; }
</style>
</head>
<body>

<?php if (!$shop): ?>
    <div class="wrap">
        <div class="empty">
            <i class="fas fa-store-slash" style="font-size:2rem;"></i>
            <p style="font-weight:600;margin-top:.6rem;">This shop link does not exist.</p>
            <p class="note">Ask the shop for its full link, or open
               <a href="../home.php" style="color:var(--brand);font-weight:600;">the POS home page</a>.</p>
        </div>
    </div>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

<header class="shop">
    <div class="shop-inner">
        <?php if (!empty($shop['cover'])): ?>
            <img class="shop-logo" src="<?php echo htmlspecialchars(assetUrl($shop['cover']), ENT_QUOTES, 'UTF-8'); ?>" alt="">
        <?php endif; ?>
        <div>
            <div class="shop-name"><?php echo htmlspecialchars((string)$shop['name'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="shop-meta">
                <?php if ($shop['area']): ?>Delivery: <?php echo htmlspecialchars((string)$shop['area'], ENT_QUOTES, 'UTF-8'); ?> &middot; <?php endif; ?>
                Delivery <?php echo $takea($shop['delivery_charge']); ?>
                <?php if ((float)$shop['free_over'] > 0): ?> &middot; free over <?php echo $takea($shop['free_over']); ?><?php endif; ?>
                <?php if ((float)$shop['min_order'] > 0): ?> &middot; minimum <?php echo $takea($shop['min_order']); ?><?php endif; ?>
            </div>
        </div>
        <div class="pill <?php echo $shop['is_open'] ? '' : 'closed'; ?>">
            <?php echo $shop['is_open'] ? 'Open now' : 'Closed for orders'; ?>
        </div>
    </div>
</header>

<div class="wrap">
    <div class="toolbar">
        <input type="search" id="q" placeholder="Product search" style="flex:1;min-width:180px;">
        <select id="cat"><option value="">All categories</option></select>
    </div>

    <?php if (!$products): ?>
        <div class="empty">
            <i class="fas fa-box-open" style="font-size:2rem;"></i>
            <p style="margin-top:.6rem;">This shop has not put any product online yet.</p>
        </div>
    <?php else: ?>
        <div class="grid" id="grid">
            <?php foreach ($products as $p): ?>
                <div class="card" data-name="<?php echo htmlspecialchars($lower($p['name']), ENT_QUOTES, 'UTF-8'); ?>"
                     data-cat="<?php echo htmlspecialchars((string)($p['category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                     data-stock="<?php echo (int)$p['stock']; ?>">
                    <?php if ($p['image']): ?>
                        <img class="thumb" src="<?php echo htmlspecialchars(assetUrl($p['image']), ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:#aab4c2;"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div class="name"><?php echo htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($p['category']): ?><div class="cat"><?php echo htmlspecialchars((string)$p['category'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                    <div class="price"><?php echo $takea($p['price']); ?></div>
                    <div class="stock <?php echo $p['in_stock'] ? '' : 'out'; ?>">
                        <?php echo $p['in_stock'] ? $p['stock'] . ' in stock' : 'Out of stock'; ?>
                    </div>
                    <div class="row">
                        <input class="qty" type="number" min="1" max="<?php echo max(1, (int)$p['stock']); ?>" value="1" data-id="<?php echo (int)$p['id']; ?>">
                        <button class="btn btn-brand" <?php echo $p['in_stock'] && $shop['is_open'] ? '' : 'disabled'; ?>
                                onclick="addToCart(<?php echo (int)$p['id']; ?>, this)">
                            <i class="fas fa-cart-plus"></i> Add
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="bar">
    <div class="bar-inner">
        <div><i class="fas fa-basket-shopping" style="color:var(--brand);"></i>
             <strong id="cartCount">0</strong> item(s)</div>
        <div style="font-weight:700;" id="cartTotal">৳0</div>
        <button class="btn btn-ghost" onclick="openCheckout()" style="margin-left:auto;">
            <i class="fas fa-file-invoice"></i> Place order (cash on delivery)
        </button>
    </div>
</div>

<dialog id="sheet">
    <form class="sheet" method="dialog" onsubmit="return false;">
        <h3>Your order</h3>
        <div id="cartLines"></div>
        <div class="line" style="border-top:1px solid var(--line);margin-top:.4rem;padding-top:.5rem;">
            <span>Delivery</span><strong id="dFee"></strong>
        </div>
        <div class="line"><span>Total</span><strong id="dTotal"></strong></div>

        <label class="field"><span>Your name *</span><input id="cName" required></label>
        <label class="field"><span>Phone number *</span><input id="cPhone" required inputmode="numeric" placeholder="01XXXXXXXXX"></label>
        <label class="field"><span>Address</span><textarea id="cAddress" rows="2"></textarea></label>
        <label class="field"><span>Message for the shop</span><input id="cNote"></label>

        <div id="msg"></div>
        <div class="row" style="margin-top:.8rem;">
            <button class="btn btn-ghost" onclick="document.getElementById('sheet').close()">Close</button>
            <button class="btn btn-brand" id="place" onclick="placeOrder()">
                <i class="fas fa-check"></i> Confirm order
            </button>
        </div>
        <p class="note" style="margin:.6rem 0 0;">The shop will call you to confirm. Nothing is charged now — you pay when it arrives.</p>
    </form>
</dialog>

<script>
const SHOP_SLUG = <?php echo json_encode($shop['slug'], JSON_UNESCAPED_UNICODE); ?>;
const API = 'api.php';
const DELIVERY = <?php echo (float)$shop['delivery_charge']; ?>;
const FREE_OVER = <?php echo (float)$shop['free_over']; ?>;
const money = n => '৳' + (Math.round(n * 100) / 100).toString().replace(/\.0+$/, '');
let cart = {};

// Category filter is built from what is actually on the page, so a category with
// no published product cannot appear as an empty option.
(function () {
    const sel = document.getElementById('cat');
    const seen = {};
    document.querySelectorAll('.card').forEach(function (card) {
        const cat = card.dataset.cat;
        if (cat && !seen[cat]) {
            seen[cat] = 1;
            const opt = document.createElement('option');
            opt.value = cat;
            opt.textContent = cat;
            sel.appendChild(opt);
        }
    });
})();

function filterGrid() {
    const q = document.getElementById('q').value.trim().toLowerCase();
    const cat = document.getElementById('cat').value;
    document.querySelectorAll('.card').forEach(function (card) {
        const okQ = q === '' || card.dataset.name.indexOf(q) !== -1;
        const okC = cat === '' || card.dataset.cat === cat;
        card.style.display = (okQ && okC) ? '' : 'none';
    });
}
document.getElementById('q').addEventListener('input', filterGrid);
document.getElementById('cat').addEventListener('change', filterGrid);

function addToCart(id, btn) {
    const card = btn.closest('.card');
    const qty = parseInt(card.querySelector('.qty').value || '1', 10);
    const max = parseInt(card.dataset.stock || '1', 10);
    if (!(qty > 0)) { return; }
    cart[id] = Math.min((cart[id] || 0) + qty, max);
    render();
}

function render() {
    let count = 0, subtotal = 0;
    Object.keys(cart).forEach(function (id) {
        count += cart[id];
        const card = document.querySelector('.qty[data-id="' + id + '"]').closest('.card');
        subtotal += parseFloat(card.querySelector('.price').textContent.replace(/[^\d.]/g, '')) * cart[id];
    });
    document.getElementById('cartCount').textContent = count;
    document.getElementById('cartTotal').textContent = money(subtotal);
    document.getElementById('cartLines').innerHTML = Object.keys(cart).map(function (id) {
        const card = document.querySelector('.qty[data-id="' + id + '"]').closest('.card');
        return '<div class="line"><span>' + card.querySelector('.name').textContent +
               ' ×' + cart[id] + '</span><span>' +
               money(parseFloat(card.querySelector('.price').textContent.replace(/[^\d.]/g, '')) * cart[id]) + '</span></div>';
    }).join('') || '<p class="note">Your cart is empty.</p>';
    const fee = (FREE_OVER > 0 && subtotal >= FREE_OVER) ? 0 : DELIVERY;
    document.getElementById('dFee').textContent = money(fee);
    document.getElementById('dTotal').textContent = money(subtotal + fee);
}

function openCheckout() {
    if (!Object.keys(cart).length) { alert('Add a product first.'); return; }
    render();
    document.getElementById('sheet').showModal();
}

function placeOrder() {
    const btn = document.getElementById('place');
    const msg = document.getElementById('msg');
    const items = Object.keys(cart).map(function (id) { return { id: parseInt(id, 10), qty: cart[id] }; });

    btn.disabled = true;
    msg.innerHTML = '';
    fetch(API + '?action=order&slug=' + encodeURIComponent(SHOP_SLUG), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items: items,
            name: document.getElementById('cName').value,
            phone: document.getElementById('cPhone').value,
            address: document.getElementById('cAddress').value,
            note: document.getElementById('cNote').value
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.success) {
            msg.innerHTML = '<div class="ok">Order ' + data.order.order_no + ' placed. Total ' + money(data.order.total) +
                            '. The shop will call you.</div>';
            cart = {};
            render();
            btn.style.display = 'none';
        } else {
            msg.innerHTML = '<div class="bad">' + ((data && data.message) || 'Could not place the order.') + '</div>';
        }
    })
    .catch(function () { msg.innerHTML = '<div class="bad">Network problem. Try again.</div>'; })
    .then(function () { btn.disabled = false; });
}
</script>
</body>
</html>
