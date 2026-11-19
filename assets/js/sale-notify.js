/**
 * New-sale notifications for the web UI.
 *
 * WHAT THIS DOES
 * --------------
 * When a sale is rung up on the mobile app, whoever is looking at the web POS in
 * the same browser gets a desktop notification. The browser asks
 * admin/api/sales-poll.php every few seconds; the endpoint answers only with
 * sales newer than the id the browser already has.
 *
 * WHY POLLING
 * -----------
 * cPanel shared hosting cannot hold a WebSocket open, and Server-Sent Events
 * would pin one PHP worker per open tab until the tab is closed, which on a
 * shared host exhausts the account's process pool. A short poll is the only
 * shape that survives here, so that is what this is.
 *
 * PERMISSION
 * ----------
 * The browser will not show a notification without an explicit grant, and it may
 * not be granted silently. So:
 *
 *   - a small button appears; pressing it is the gesture that triggers the
 *     permission prompt
 *   - a dismissal is remembered, so nobody is nagged on every page load
 *   - a Notification that IS granted keeps working with no further interaction
 *
 * OWN SALES
 * ---------
 * The endpoint already drops the viewer's own sales. A cashier should not be
 * told about a sale they are ringing up on the screen in front of them.
 */
(function () {
    'use strict';

    var POLL_MS = 6000;
    var LS_KEY = 'pos.salenotify.dismissed';
    var HEAD_KEY = 'pos.salenotify.head';

    function supported() {
        return typeof window.Notification !== 'undefined';
    }

    function money(n) {
        var v = Number(n);
        return '৳' + (isFinite(v) ? v.toFixed(2) : '0.00');
    }

    function readHead() {
        try { return Number(sessionStorage.getItem(HEAD_KEY)) || 0; }
        catch (e) { return 0; }
    }

    function writeHead(id) {
        try { sessionStorage.setItem(HEAD_KEY, String(id)); } catch (e) {}
    }

    function dismissed() {
        try { return localStorage.getItem(LS_KEY) === '1'; } catch (e) { return false; }
    }

    function dismissForever() {
        try { localStorage.setItem(LS_KEY, '1'); } catch (e) {}
        hideButton();
    }

    // ── the opt-in button ────────────────────────────────────────────
    var btn = null;

    function ensureButton() {
        if (btn || dismissed()) return;
        if (Notification.permission !== 'default') return; // already answered

        btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '🔔 Enable sale alerts';
        btn.style.cssText = [
            'position:fixed', 'right:16px', 'bottom:16px', 'z-index:9999',
            'background:#e11d48', 'color:#fff', 'border:0',
            'padding:10px 16px', 'border-radius:999px',
            'font:600 13px Inter,system-ui,sans-serif', 'cursor:pointer',
            'box-shadow:0 4px 14px rgba(0,0,0,.2)'
        ].join(';');
        btn.addEventListener('click', function () {
            if (!supported()) return;
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    dismissForever();
                    // Tell them it is on, so a silent toggle is not mistaken
                    // for a broken feature.
                    new Notification('Sale alerts enabled', {
                        body: 'You will be notified when a sale is rung up.',
                        tag: 'pos-salenotify-ready'
                    });
                    start();
                } else {
                    btn.textContent = 'Alerts blocked in browser settings';
                }
            });
        });
        document.body.appendChild(btn);
    }

    function hideButton() {
        if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
        btn = null;
    }

    // ── polling ──────────────────────────────────────────────────────
    function notify(sale) {
        var lines = [money(sale.total) + '  •  ' + String(sale.method || '').toUpperCase()];
        if (sale.cashier) lines.push(sale.cashier);
        if (sale.customer) lines.push(sale.customer);

        try {
            var n = new Notification('New sale — ' + sale.invoice, {
                body: lines.join('\n'),
                tag: 'pos-sale-' + sale.id,   // one notification per sale, not a pile
                requireInteraction: false
            });
            n.onclick = function () {
                window.focus();
                window.location.href = 'sales.php';
                n.close();
            };
        } catch (e) {
            // Some browsers refuse a Notification outside a user gesture even
            // when permission was granted earlier. A silent no-op is the right
            // failure here - a page must never break over a decoration.
        }
    }

    var running = false;

    function tick() {
        if (!running || !supported() || Notification.permission !== 'granted') return;

        var since = readHead();
        fetch('api/sales-poll.php?since=' + encodeURIComponent(since), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                if (data.head) writeHead(data.head);
                if (!data.sales || !data.sales.length) return;
                data.sales.forEach(notify);
            })
            .catch(function () { /* unreachable server must not break the page */ });
    }

    function start() {
        if (running) return;
        running = true;
        // Seed the cursor on first run so history is never replayed.
        tick();
        setInterval(tick, POLL_MS);

        // Catch up on whatever was rung up while the tab sat in the background.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) tick();
        });
    }

    if (typeof document === 'undefined') return;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        if (!supported()) return;

        if (Notification.permission === 'granted') {
            start();
        } else if (Notification.permission === 'default') {
            ensureButton();
        }
        // 'denied' - do nothing. Repeatedly asking a browser that has said no
        // is how a shopkeeper learns to dismiss dialogs without reading them.
    }
})();