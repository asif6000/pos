/**
 * Marketing - WhatsApp & SMS
 *
 * The send loop is driven from the browser: it asks the API for a small batch,
 * renders the result, then asks for the next one. Keeps the PHP side stateless
 * and lets the shopkeeper stop at any moment.
 */
(function () {
    'use strict';

    var API_CAMPAIGN = 'api/marketing-campaign.php';
    var API_AUDIENCE = 'api/marketing-audience.php';
    var API_STATUS   = 'api/marketing-status.php';

    // ── State ────────────────────────────────────────────────────────────────
    var state = {
        // SMS only. The WhatsApp option is gone from this page; WhatsApp
        // sending lives in the inbox. Do not reintroduce the value here
        // without restoring the channel button and the QR card too, or the
        // campaign would go out as WhatsApp with no way to pick SMS.
        channel: 'sms',
        smsProvider: 'manual',
        serverIp: '',
        customers: [],
        totals: { all: 0, usable: 0, wa_yes: 0, wa_no: 0, unchecked: 0 },
        recipients: { rows: 0, people: 0 },
        selected: {},
        campaignId: 0,
        running: false,
        paused: false,
        syncing: false,
        stopSync: false,
        syncAutoDone: false,
        // Live refresh. See pollAudience() for why it is paused in as many
        // situations as it runs.
        liveTimer: null,
        livePaused: false,
        liveLoading: false,
        liveLastSig: ''
    };

    // ── Small helpers ───────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function toast(msg, type) {
        var existing = document.querySelector('.mk-toast');
        if (existing) { existing.remove(); }
        var el = document.createElement('div');
        el.className = 'mk-toast mk-toast-' + (type || 'info');
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('mk-out'); }, 3200);
        setTimeout(function () { el.remove(); }, 3700);
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) {
                v.forEach(function (item) { body.append(k + '[]', item); });
            } else if (v !== undefined && v !== null) {
                body.append(k, v);
            }
        });
        return fetch(API_CAMPAIGN, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Server response could not be read.' }; });
    }

    function get(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Server response could not be read.' }; });
    }

    function delay(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    // ── Channel ────────────────────────────────────────────────────────────
    /**
     * There is no longer a choice of channel, so this seeds the provider from
     * the markup and paints the one set of labels that go with SMS. The old
     * version bound a click handler per channel button; with a single button
     * left that handler could only ever set the state it already had.
     */
    function initChannels() {
        // The provider is decided server-side now, so seed it from the markup
        // before the first paint and let the tab label reflect it.
        var smsTab = document.querySelector('.mk-channel[data-channel="sms"]');
        if (smsTab && smsTab.dataset.smsProvider) {
            state.smsProvider = smsTab.dataset.smsProvider;
        }
        paintProvider(state.smsProvider);
        $('mkStartIcon').className = 'fas fa-comment-sms';
        $('mkStartLabel').textContent = 'Send SMS';
        $('mkPreviewTag').textContent = 'SMS';
        syncComposer();
    }

    // ── Message composer ────────────────────────────────────────────────────

    // Sample values so the preview shows a real looking message. Anything the
    // backend does not replace would reach the customer verbatim, so unknown
    // tokens are highlighted in the preview instead of going out unnoticed.
    function previewValues() {
        var card = $('mkComposeCard');
        var shop = card ? (card.dataset.shopName || '') : '';
        return {
            '{name}': 'Rahim',
            '{full_name}': 'Rahim Uddin',
            '{phone}': '01712345678',
            '{shop}': shop || 'apni',
            '{last_visit_days}': '47',
            '{last_visit}': '47 days ago'
        };
    }

    /** api/marketing-campaign.php caps SMS at 1000 characters. */
    function charLimit() { return 1000; }

    function syncCounter() {
        var n = $('mkMessage').value.length;
        var max = charLimit();
        $('mkCharCount').textContent = n;
        $('mkCharMax').textContent = max;

        var tone = n > max ? ' is-over' : (n > max * 0.9 ? ' is-warn' : '');
        $('mkCounter').className = 'mk-counter' + tone;
        var fill = $('mkMeterFill');
        fill.className = 'mk-meter-fill' + tone;
        fill.style.width = Math.min(100, (n / max) * 100) + '%';

        // SMS is billed per 160 character segment, so that is the real cost
        var hint = $('mkSegmentHint');
        if (n) {
            var seg = Math.ceil(n / 160);
            hint.textContent = seg + (seg === 1 ? ' SMS segment' : ' SMS segments');
        } else {
            hint.textContent = '';
        }
    }

    function renderPreview() {
        var bubble = $('mkPreviewBubble');
        var raw = $('mkMessage').value;
        if (!raw.trim()) {
            bubble.className = 'mk-bubble is-empty';
            bubble.textContent = 'Message lekho — ekhane live preview dekhben.';
            $('mkPreviewWarn').textContent = '';
            return;
        }

        var vals = previewValues();
        var html = esc(raw).replace(/\{([a-z0-9_]+)\}/gi, function (m, name) {
            var key = '{' + name.toLowerCase() + '}';
            if (vals[key] === undefined) { return '<mark class="mk-tok-bad">' + m + '</mark>'; }
            return esc(vals[key]);
        });

        bubble.className = 'mk-bubble';
        bubble.innerHTML = html;

        var bad = bubble.querySelectorAll('.mk-tok-bad').length;
        var warn = $('mkPreviewWarn');
        if (bad) {
            warn.textContent = bad + ' ta token chinte parbo na — customer e literally chole jabe.';
        } else {
            warn.textContent = '';
        }
    }

    function paintPresets() {
        var cur = $('mkMessage').value;
        document.querySelectorAll('.mk-preset').forEach(function (b) {
            b.classList.toggle('is-on', b.dataset.preset === cur);
        });
    }

    function syncComposer() {
        syncCounter();
        renderPreview();
        paintPresets();
    }

    function initComposer() {
        var msg = $('mkMessage');

        msg.addEventListener('input', syncComposer);

        // Tokens insert where the cursor is, so the shopkeeper keeps their sentence
        document.querySelectorAll('#mkTokenChips .mk-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                insertAtCursor(msg, chip.dataset.token);
                syncComposer();
            });
        });

        document.querySelectorAll('.mk-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                msg.value = btn.dataset.preset;
                syncComposer();
                msg.focus();
            });
        });

        $('mkClearMsgBtn').addEventListener('click', function () {
            msg.value = '';
            syncComposer();
            msg.focus();
        });

        $('mkSearch').addEventListener('input', debounce(loadAudience, 350));
        $('mkOnlyInactive').addEventListener('change', loadAudience);
        $('mkInactiveDays').addEventListener('change', function () {
            if ($('mkOnlyInactive').checked) { loadAudience(); }
        });

        $('mkShowUnverified').addEventListener('change', loadAudience);
        $('mkSyncBtn').addEventListener('click', function () { startSync(false); });
        $('mkSyncStopBtn').addEventListener('click', function () {
            state.stopSync = true;
            $('mkSyncText').textContent = 'Stopping after this batch…';
        });

        // The live refresh must never fight a person who is mid-gesture. Paused
        // while they type in the search box, so the results under the cursor do
        // not change without them asking.
        $('mkSearch').addEventListener('focus', function () { state.livePaused = true; });
        $('mkSearch').addEventListener('blur', function () { state.livePaused = false; });
        document.addEventListener('visibilitychange', function () {
            // A background tab cannot show an update anyway, and this stops a
            // laptop that was asleep overnight from firing a burst of requests
            // the moment it is opened again.
            state.livePaused = document.hidden;
        });

        $('mkSelectAllBtn').addEventListener('click', function () {
            state.customers.forEach(function (c) { state.selected[c.id] = true; });
            renderCustomers();
        });

        $('mkSelectNoneBtn').addEventListener('click', function () {
            state.selected = {};
            renderCustomers();
        });

        syncComposer();
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    // ── Audience list ───────────────────────────────────────────────────────
    /**
     * Everything the table is drawn from, as one comparable string.
     *
     * Used to decide whether a live poll found anything worth repainting. It
     * covers the per-row data, not just the counts, because two lists of the
     * same length with a different badge in the middle still have to repaint -
     * a count-only check would leave a stale "unchecked" row on screen after
     * somebody's number got confirmed.
     */
    function audienceSignature(rows, totals, recipients) {
        var body = (rows || []).map(function (c) {
            return c.id + ':' + (c.has_whatsapp === true ? '1' : (c.has_whatsapp === false ? '0' : '?'))
                + ':' + (c.last_visit || '-') + ':' + c.name + ':' + c.phone;
        }).join('|');
        var t = totals || {};
        var r = recipients || {};
        return body + '#' + [t.all, t.usable, t.wa_yes, t.wa_no, t.unchecked,
            r.rows, r.people].join(',');
    }

    /**
     * @param {boolean} silent  refresh without the spinner, for the live poll.
     *                          The skeleton is only ever shown for a load the
     *                          shopkeeper asked for; a table that blanks every
     *                          few seconds on its own reads as broken.
     */
    function loadAudience(silent) {
        // Inverted on the way in. The list shows confirmed numbers by default,
        // because a recipient is somebody reachable, and the checkbox asks for
        // the extra rows - the unconfirmed ones - rather than the other way
        // round. The server defaults the same way, so a caller that forgets to
        // pass this gets the safe list.
        var onlyWa = !($('mkShowUnverified') && $('mkShowUnverified').checked);

        var url = API_AUDIENCE + '?q=' + encodeURIComponent($('mkSearch').value.trim()) +
            '&only_inactive=' + ($('mkOnlyInactive').checked ? '1' : '0') +
            '&days=' + $('mkInactiveDays').value +
            '&wa=' + (onlyWa ? '1' : '0');

        if (!silent) {
            $('mkCustomerRows').innerHTML =
                '<tr><td colspan="4" class="text-center" style="padding:24px"><div class="spinner"></div></td></tr>';
        }

        state.liveLoading = true;

        get(url).then(function (res) {
            state.liveLoading = false;
            if (!res.ok) {
                // A failed live refresh must not wipe a list the shopkeeper is
                // looking at. Only a load they asked for reports the error.
                if (silent) { return; }
                $('mkCustomerRows').innerHTML =
                    '<tr><td colspan="4" class="text-center text-danger" style="padding:24px">' +
                    esc(res.error || 'Could not load customers.') + '</td></tr>';
                return;
            }

            var rows = res.customers || [];
            var totals = res.totals || state.totals;
            var recipients = res.recipients || state.recipients;
            var sig = audienceSignature(rows, totals, recipients);

            // The whole point of the live poll: an unchanged response touches
            // nothing. No re-render means no flicker, no lost scroll position
            // and no risk to a checkbox the shopkeeper is in the middle of
            // clicking, which is what a naive "refresh every N seconds" does to
            // a table someone is using.
            if (silent && sig === state.liveLastSig) { return; }

            state.customers = rows;
            state.totals = totals;
            state.recipients = recipients;
            state.liveLastSig = sig;

            renderWaBar();
            renderCustomers();
            // Here, not at boot: state.totals is what primeWaSync reads to size
            // the backlog, and it only exists once this block has run.
            primeWaSync();
        }).catch(function () {
            state.liveLoading = false;
        });
    }

    /**
     * Keep the list current while the page is open.
     *
     * This exists because the answer now arrives on its own. The bridge uploads
     * the WhatsApp address book every few seconds, and every number in it that
     * matches a saved customer confirms that customer, so the reachable set
     * grows while the shopkeeper is looking at the page. Before that, the only
     * way a row could change was for them to press the button themselves.
     *
     * It polls, because the POS cannot push to an open browser tab.
     *
     * The interval is a floor on how stale the list may get, not a promise of
     * how quickly it updates. Whether a change appears here in eight seconds or
     * in one minute depends on the bridge being connected and on how tightly
     * WhatsApp itself lets the address book be read, and this file has no way
     * to make that faster. The badge says "Live" and the refresh is simply what
     * it is - claiming more precision than the underlying transport can
     * deliver would be worse than not claiming anything.
     */
    function pollAudience() {
        // Anything that is mid-flight owns the screen. A refresh during a check
        // run would race its own batches and make the progress bar jump
        // backwards, and one during a send is not worth the risk at all.
        if (state.syncing || state.running || state.livePaused || state.liveLoading) { return; }
        if ($('mkSearch') === document.activeElement) { return; }

        loadAudience(true);
    }

    function startLiveRefresh() {
        if (state.liveTimer) { return; }
        state.liveTimer = setInterval(pollAudience, 8000);
    }

    // ── WhatsApp number sync ────────────────────────────────────────────────
    /**
     * Ask WhatsApp about every customer number we have not checked yet.
     *
     * The loop lives in the browser, one small batch per request, for the same
     * reason the send loop does: a single long request would be killed by the web
     * server's timeout, and the shopkeeper would have no way to stop. Each batch
     * writes its answers straight onto the customer rows, so progress survives a
     * page refresh and a re-run only fills the gaps.
     */
    function startSync(recheck) {
        if (state.syncing) { return; }
        state.syncing = true;
        state.stopSync = false;

        $('mkSyncBtn').classList.add('d-none');
        $('mkSyncStopBtn').classList.remove('d-none');
        $('mkSyncProgress').classList.remove('d-none');
        $('mkSyncLabel').textContent = 'Checking…';

        var cursor = 0;
        var totalYes = 0;
        var totalNo = 0;
        var totalUnknown = 0;
        var totalChecked = 0;
        var startTotal = (state.totals && state.totals.unchecked) || 0;
        // Whether the shopkeeper was looking at the whole list rather than the
        // confirmed subset, so the summary at the end only interrupts when the
        // newly confirmed numbers are the ones they were actually looking at.
        var wasFiltered = !($('mkShowUnverified') && $('mkShowUnverified').checked);

        function finish() {
            state.syncing = false;
            $('mkSyncBtn').classList.remove('d-none');
            $('mkSyncStopBtn').classList.add('d-none');
            $('mkSyncProgress').classList.add('d-none');
            $('mkSyncLabel').textContent = recheck ? 'Check again' : 'Check numbers';

            // Refresh the table either way: a stop still leaves the batches that
            // did finish visible, and rechecking changes every row's badge.
            loadAudience();

            if (!wasFiltered) { return; }
            toast(totalChecked + ' number check hoyeche — ' + totalYes + ' ta e WhatsApp ache.', 'success');
        }

        function step() {
            if (state.stopSync) { finish(); return; }

            $('mkSyncText').textContent = 'Checking… ' + totalChecked + ' done, ' +
                totalYes + ' ta WhatsApp e ache';

            // Progress bar fills as the original backlog is worked off, and sits
            // at 100% for a recheck where there is no backlog to count down.
            var pct = startTotal > 0
                ? Math.min(100, Math.round((totalChecked / startTotal) * 100))
                : 100;
            $('mkSyncFill').style.width = pct + '%';

            post('sync_whatsapp', { limit: 10, cursor: cursor, recheck: recheck ? 1 : 0 })
                .then(function (res) {
                    if (!res || !res.ok) {
                        state.syncing = false;
                        $('mkSyncProgress').classList.add('d-none');
                        $('mkSyncBtn').classList.remove('d-none');
                        $('mkSyncStopBtn').classList.add('d-none');
                        $('mkSyncLabel').textContent = 'Check numbers';
                        toast((res && res.error) || 'WhatsApp check kora jayni.', 'error');
                        return;
                    }

                    totalChecked += res.checked || 0;
                    totalYes += res.yes || 0;
                    totalNo += res.no || 0;
                    totalUnknown += res.unknown || 0;
                    cursor = res.next_cursor || cursor;

                    if (res.done || state.stopSync) {
                        $('mkSyncFill').style.width = '100%';
                        if (totalChecked === 0) {
                            toast('Check korar moto kono baki number nai.', 'info');
                        }
                        finish();
                        return;
                    }
                    step();
                })
                .catch(function () {
                    state.syncing = false;
                    $('mkSyncProgress').classList.add('d-none');
                    toast('Connection cut hoye geche. abar try korun.', 'error');
                });
        }

        step();
    }

    /**
     * The two lines above the table.
     *
     * The first is the reachable count, which is what a recipient list is for.
     * The second exists because the shopkeeper keeps comparing it to the number
     * of customers, and the two are different quantities that only ever match by
     * accident. A recipient row is written per send, per campaign, so the same
     * person is counted again every time they are targeted. Saying that here is
     * the difference between a number that looks like a bug and a number that
     * is simply a count of something else.
     */
    function renderWaBar() {
        var t = state.totals || { all: 0, usable: 0, wa_yes: 0, wa_no: 0, unchecked: 0 };
        var r = state.recipients || { rows: 0, people: 0 };
        var el = $('mkWaCount');
        if (!el) { return; }

        if (t.usable === 0) {
            el.textContent = 'Kono customer er number nai';
            el.className = 'mk-wa-none';
        } else if (t.unchecked > 0) {
            el.innerHTML = '<strong>' + t.wa_yes + '</strong> / ' + t.usable +
                ' number e WhatsApp ache &middot; <strong>' + t.unchecked + '</strong> ta check baki';
            el.className = 'mk-wa-partial';
        } else {
            el.innerHTML = '<strong>' + t.wa_yes + '</strong> / ' + t.usable +
                ' number e WhatsApp ache';
            el.className = t.wa_yes > 0 ? 'mk-wa-done' : 'mk-wa-none';
        }

        var note = $('mkWaNote');
        if (!note) { return; }

        var parts = [];
        parts.push('<b>' + t.all + '</b> customer &middot; <b>' + t.wa_yes + '</b> ta WhatsApp e ache (eitai recipient)');

        if (t.wa_no > 0) {
            parts.push('<b>' + t.wa_no + '</b> ta confirm achei WhatsApp nai');
        }
        if (t.unchecked > 0) {
            parts.push('<b>' + t.unchecked + '</b> ta check baki &mdash; bridge address book theke nijer thik confirm hoye jay');
        }

        if (r.rows > 0) {
            // The sentence that answers "why is this number bigger than the
            // customer count", in the shop's language rather than in SQL terms.
            parts.push('Campaign gulor total <b>' + r.rows + '</b> ta send row, '
                + 'tar moddhe <b>' + r.people + '</b> jena alada customer &mdash; '
                + 'ekjon customer ekadhik campaign e gaye row bodlay');
        }

        note.innerHTML = parts.join(' &middot; ');
    }

    /** Per-row badge: green tick = on WhatsApp, grey cross = not, nothing = unchecked. */
    function waBadge(c) {
        if (c.has_whatsapp === true) {
            return '<i class="fab fa-whatsapp mk-wa-yes" title="WhatsApp e ache' +
                (c.whatsapp_checked_at ? ' (checked ' + esc(c.whatsapp_checked_at) + ')' : '') +
                '"></i>';
        }
        if (c.has_whatsapp === false) {
            return '<i class="fas fa-circle-xmark mk-wa-no" title="Ei number e WhatsApp nai"></i>';
        }
        return '<i class="fas fa-circle-question mk-wa-unknown" title="Check hoy nai"></i>';
    }

    // ── Add numbers to the phone's contacts ──────────────────────────────────
    //
    // WhatsApp exposes no API for writing contacts, so this cannot add anything
    // itself. What it does is make the phone's own scanner do it: a vCard QR per
    // number, stepped through one at a time, because a QR holds exactly one
    // contact. For a whole list there is a .vcf download, which the phone's
    // contact importer handles in one step.
    //
    // The numbers come from the audience already on screen rather than a second
    // list, so "add to WhatsApp" and "send a campaign" cannot disagree about who
    // the customers are.
    var contactFlow = { cards: [], at: 0, loading: false };

    function openContactFlow() {
        var picked = Object.keys(state.selected)
            .map(function (id) {
                return state.customers.filter(function (c) { return String(c.id) === String(id); })[0];
            })
            .filter(Boolean)
            .filter(function (c) { return !!c.phone; });

        // Nothing selected should not mean an empty screen. Falling back to the
        // whole audience is what the shop actually wants from a button that says
        // "add to WhatsApp".
        var rows = picked.length ? picked : state.customers.filter(function (c) { return !!c.phone; });

        if (!rows.length) {
            $('mkContactList').innerHTML = '';
            $('mkContactEmpty').classList.remove('d-none');
            showContactModal();
            return;
        }

        $('mkContactEmpty').classList.add('d-none');
        $('mkContactList').innerHTML = '<div class="text-center text-muted" style="padding:18px">'
            + '<div class="spinner"></div><p>QR banachhi…</p></div>';
        contactFlow.loading = true;
        showContactModal();

        var body = new FormData();
        body.append('action', 'contact_qr');
        rows.slice(0, 60).forEach(function (c) { body.append('chat_ids[]', c.phone); });

        fetch('api/whatsapp-chat.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                contactFlow.loading = false;
                if (!r || !r.ok) {
                    $('mkContactList').innerHTML = '<div class="alert alert-warning mb-0">'
                        + esc((r && r.error) || 'QR banano jay ni.') + '</div>';
                    return;
                }
                contactFlow.cards = r.cards || [];
                contactFlow.at = 0;
                renderContactCard();
            })
            .catch(function () {
                contactFlow.loading = false;
                $('mkContactList').innerHTML =
                    '<div class="alert alert-warning mb-0">Server response pora jay ni.</div>';
            });
    }

    function showContactModal() {
        // This project has no Bootstrap. Its modal is an overlay shown by adding
        // .active, the same call the send-log modal uses.
        var el = $('mkContactsModal');
        if (el) { el.classList.add('active'); }
    }

    function hideContactModal() {
        var el = $('mkContactsModal');
        if (el) { el.classList.remove('active'); }
    }

    function renderContactCard() {
        var cards = contactFlow.cards;
        if (!cards.length) { return; }
        if (contactFlow.at >= cards.length) { contactFlow.at = 0; }
        var c = cards[contactFlow.at];

        $('mkContactList').innerHTML =
            '<div class="mk-contact-card">'
            + '<div class="mk-contact-pos">' + (contactFlow.at + 1) + ' / ' + cards.length + '</div>'
            + (c.qr
                ? '<img class="mk-contact-qr" src="' + esc(c.qr) + '" alt="QR for ' + esc(c.name) + '">'
                : '<div class="alert alert-warning">Ei number er QR banano jay ni.</div>')
            + '<div class="mk-contact-name">' + esc(c.name) + '</div>'
            + '<div class="mk-contact-phone">' + esc(c.phone) + '</div>'
            + '<a class="btn btn-sm btn-whatsapp mt-2" href="' + esc(c.wa_link) + '" target="_blank" rel="noopener">'
            + '<i class="fab fa-whatsapp"></i> WhatsApp e kholo</a>'
            + '</div>';

        $('mkContactPrevBtn').disabled = cards.length < 2;
        $('mkContactNextBtn').disabled = cards.length < 2;
    }

    function stepContact(delta) {
        if (!contactFlow.cards.length) { return; }
        contactFlow.at = (contactFlow.at + delta + contactFlow.cards.length) % contactFlow.cards.length;
        renderContactCard();
    }

    function initContactFlow() {
        $('mkAddContactsBtn').addEventListener('click', openContactFlow);
        $('mkContactNextBtn').addEventListener('click', function () { stepContact(1); });
        $('mkContactPrevBtn').addEventListener('click', function () { stepContact(-1); });

        // Wired to this modal explicitly rather than through [data-close-modal]:
        // the existing generic handler is hardcoded to close the send-log modal,
        // so relying on it would close the wrong thing.
        $('mkContactsClose').addEventListener('click', hideContactModal);
        $('mkContactsModal').addEventListener('click', function (e) {
            // A click on the backdrop itself, not on the dialog inside it.
            if (e.target === this) { hideContactModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hideContactModal(); }
        });
    }

    function renderCustomers() {
        var tbody = $('mkCustomerRows');
        var count = Object.keys(state.selected).length;
        $('mkSelectedCount').textContent = count + ' selected';

        if (state.customers.length === 0) {
            var unverified = $('mkShowUnverified') && $('mkShowUnverified').checked;
            tbody.innerHTML =
                '<tr><td colspan="4" class="text-center text-muted" style="padding:24px">' +
                (unverified
                    ? 'Ei filter e kono customer paoa jachhe na.'
                    : 'Ei filter e kono WhatsApp number pawa jachhe na. Bridge connected thakle nijer '
                      + 'address book theke confirm hoye jay &mdash; "Also show unconfirmed" chalanor '
                      + 'por dekhun, ba "Check numbers" press kore baki number check korun.') +
                '</td></tr>';
            return;
        }

        var html = state.customers.map(function (c) {
            var on = !!state.selected[c.id];
            var stale = c.last_visit_days;
            var staleCls = stale === null ? '' : (stale >= 90 ? 'mk-stale-90' : (stale >= 30 ? 'mk-stale-30' : ''));
            var lastVisit = c.last_visit
                ? '<span class="' + staleCls + '">' + c.last_visit + (stale !== null ? ' (' + stale + 'd)' : '') + '</span>'
                : '<span class="text-muted">Never</span>';

            return '<tr class="' + (on ? 'is-selected is-picked' : '') + '" data-id="' + c.id + '">' +
                '<td><input type="checkbox" class="mk-pick" data-id="' + c.id + '"' + (on ? ' checked' : '') + '></td>' +
                '<td>' + esc(c.name) + '</td>' +
                '<td class="mk-cust-phone">' + waBadge(c) + ' <span>' + esc(c.phone_display) + '</span></td>' +
                '<td>' + lastVisit + '</td>' +
                '</tr>';
        }).join('');

        tbody.innerHTML = html;

        tbody.querySelectorAll('.mk-pick').forEach(function (box) {
            box.addEventListener('change', function () {
                var id = box.dataset.id;
                if (box.checked) { state.selected[id] = true; } else { delete state.selected[id]; }
                var tr = box.closest('tr');
                tr.classList.toggle('is-selected', box.checked);
                tr.classList.toggle('is-picked', box.checked);
                $('mkSelectedCount').textContent = Object.keys(state.selected).length + ' selected';
            });
        });
    }

    // ── Bridge probe (only for the number sync) ──────────────────────────────
    //
    // The QR card that used to live here is gone, and with it initBridge,
    // pollStatus, renderStatus and the two QR polling timers - every one of them
    // dereferenced a QR element by id, so keeping any of it would throw on load.
    //
    // What survives is the part that was never about the QR: deciding whether the
    // audience's "Check numbers" can run at all. Without it the customer list
    // sits on stale "unchecked" counts and the button looks broken.
    /** Read the bridge state from PHP (PHP is what talks to the Node bridge) */
    function fetchBridgeStatus() {
        return fetch('api/marketing-status.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Could not reach the server.' }; });
    }

    /**
     * Ask once per page load whether WhatsApp is linked, and if so work through
     * the unchecked numbers. Guarded by syncAutoDone because loadAudience() runs
     * on every search keystroke, and a relink mid-session should not be able to
     * restart a loop that is already running.
     */
    function primeWaSync() {
        if (state.syncAutoDone) { return; }
        state.syncAutoDone = true;
        fetchBridgeStatus().then(function (data) {
            if (!data || !data.connected) { return; }
            if (state.totals && state.totals.unchecked > 0) {
                toast(state.totals.unchecked + ' ta number check korchi…', 'info');
                startSync(false);
            }
        });
    }
    // ── SMS gateway ─────────────────────────────────────────────────────────

    function initSmsSettings() {
        // Provider tiles
        $('mkProviders').addEventListener('click', function (e) {
            var tile = e.target.closest('.mk-provider');
            if (!tile || tile.classList.contains('is-on')) { return; }
            setProvider(tile.dataset.provider, true);
        });

        // API key reveal + copy
        document.querySelectorAll('.mk-secret-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var input = $(b.dataset.target);
                if (b.dataset.secret === 'copy') { copySecret(input, b); return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                b.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                b.setAttribute('aria-pressed', show ? 'true' : 'false');
                b.setAttribute('aria-label', show ? 'Hide API key' : 'Show API key');
            });
        });

        $('mkSmsApiKey').addEventListener('input', function () {
            syncKeyState();
            refreshBadge();
        });

        // Placeholder chips insert into whichever URL box was last touched
        document.querySelectorAll('.mk-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var target = state.lastUrlField === 'post' ? $('mkSmsPostUrl') : $('mkSmsGetUrl');
                insertAtCursor(target, chip.dataset.insert);
            });
        });
        ['mkSmsGetUrl', 'mkSmsPostUrl'].forEach(function (id) {
            $(id).addEventListener('focus', function () {
                state.lastUrlField = id === 'mkSmsPostUrl' ? 'post' : 'get';
            });
        });

        // Per-endpoint test buttons
        document.querySelectorAll('.mk-endpoint-test').forEach(function (btn) {
            btn.addEventListener('click', function () { testEndpoint(btn.dataset.test); });
        });

        // Advanced disclosure
        $('mkAdvancedToggle').addEventListener('click', function () {
            var box = $('mkAdvanced');
            var open = box.classList.toggle('is-open');
            var sub = box.querySelector('.mk-advanced-sub');
            if (sub) { sub.textContent = open ? '' : 'body format, field name, headers'; }
        });

        $('mkSmsMode').addEventListener('change', syncFieldState);
        $('mkSmsGetUrl').addEventListener('input', syncFieldState);
        $('mkSmsPostUrl').addEventListener('input', syncFieldState);
        $('mkBalanceBtn').addEventListener('click', function () { checkBalance(); });
        $('mkTestSendBtn').addEventListener('click', sendTestSms);
        $('mkSaveSmsBtn').addEventListener('click', saveSmsSettings);
        $('mkIpRefreshBtn').addEventListener('click', function () { loadServerIp(true); });
        $('mkIpCopyBtn').addEventListener('click', copyServerIp);

        paintProvider(state.smsProvider);
        syncFieldState();

        // Whitelisting the wrong address is the most confusing SMS failure there
        // is, so put the current address on screen straight away instead of
        // making the shop dig it out of an error message.
        if (state.smsProvider !== 'manual') { loadServerIp(false); }
    }

    function setProvider(provider, persist) {
        state.smsProvider = provider;
        paintProvider(provider);
        if (persist) {
            // The server fills in whatever this provider presets, without
            // clobbering URLs the shop already typed.
            post('settings', { marketing_sms_provider: provider }).then(function (res) {
                if (res.ok) { adoptSettings(res.settings || {}, true); }
                refreshBadge();
            });
        }
    }

    function paintProvider(provider) {
        document.querySelectorAll('.mk-provider').forEach(function (t) {
            t.classList.toggle('is-on', t.dataset.provider === provider);
        });

        var manual = (provider === 'manual');
        $('mkSmsGatewayBox').classList.toggle('d-none', manual);
        $('mkSmsManualHint').classList.toggle('d-none', !manual);
        $('mkSmsModeHint').textContent = manual ? 'Phone SMS app (free)' : providerLabel(provider);
        refreshBadge();
    }

    function providerLabel(key) {
        var tile = document.querySelector('.mk-provider[data-provider="' + key + '"] .mk-provider-name');
        return tile ? tile.textContent : key;
    }

    /** Status pill: ready only when there is a key and a usable endpoint */
    function refreshBadge() {
        var badge = $('mkGatewayBadge');
        if (state.smsProvider === 'manual') {
            badge.className = 'badge badge-secondary';
            badge.textContent = 'Phone app';
            return;
        }
        var hasKey = !!$('mkSmsApiKey').value.trim();
        var hasUrl = !!activeEndpoint();
        var unknown = hasUnknownToken($('mkSmsGetUrl').value) || hasUnknownToken($('mkSmsPostUrl').value);

        if (unknown) {
            badge.className = 'badge badge-danger';
            badge.textContent = 'Bad token';
        } else if (hasKey && hasUrl) {
            badge.className = 'badge badge-success';
            badge.textContent = 'Ready';
        } else {
            badge.className = 'badge badge-warning';
            badge.textContent = 'Incomplete';
        }
    }

    function smsEndpointValue() { return ''; }

    /** Read the value of the endpoint that would actually be used */
    function activeEndpoint() {
        return $('mkSmsPostUrl').value.trim() || $('mkSmsGetUrl').value.trim();
    }

    /** A {token} we do not know how to fill means the URL would be malformed */
    var BAD_TOKEN_MSG = 'Ei token ta chinte parbo na — shudhu {apikey}, {number}, '
                      + '{message}, {senderid} use korun.';

    function hasUnknownToken(url) {
        var rest = String(url || '');
        ['{apikey}', '{api_key}', '{number}', '{to}', '{message}', '{msg}',
         '{senderid}', '{sender_id}', '{sender}'].forEach(function (k) {
            rest = rest.split(k).join('');
        });
        return /\{[a-z0-9_]+\}/i.test(rest);
    }

    function syncFieldState() {
        var urls = $('mkSmsGetUrl').value + ' ' + $('mkSmsPostUrl').value;
        var hasSlots = /\{(number|to|message|msg)\}/.test(urls);

        syncKeyState();

        // Field names only matter when nothing travels in the URL
        ['mkSmsNumberKey', 'mkSmsMessageKey'].forEach(function (id) {
            var el = $(id);
            if (!el) { return; }
            el.disabled = hasSlots;
            el.style.opacity = hasSlots ? '.4' : '1';
        });

        // Surface a bad token right where the user typed it
        var bad = hasUnknownToken($('mkSmsGetUrl').value);
        var badPost = hasUnknownToken($('mkSmsPostUrl').value);
        setResult('mkGetResult', bad ? BAD_TOKEN_MSG : '', bad ? 'bad' : '');
        setResult('mkPostResult', badPost ? BAD_TOKEN_MSG : '', badPost ? 'bad' : '');

        refreshBadge();
    }

    /** "Saved / Not set" pill plus the copy button's empty state */
    function syncKeyState() {
        var input = $('mkSmsApiKey');
        if (!input) { return; }
        var has = !!input.value.trim();

        var pill = $('mkKeyStatus');
        if (pill) {
            pill.classList.toggle('is-set', has);
            $('mkKeyStatusText').textContent = has ? 'Saved' : 'Not set';
        }

        var copy = document.querySelector('.mk-secret-btn[data-secret="copy"]');
        if (copy) { copy.disabled = !has; }
    }

    /** navigator.clipboard is undefined over plain http, so keep a fallback */
    function copySecret(input, btn) {
        var val = input.value;
        if (!val) {
            toast('Ekhon kono key nai — prothome key ta bosiye nijei copy korun.', 'warn');
            input.focus();
            return;
        }

        var done = function () {
            btn.classList.add('is-done');
            btn.querySelector('i').className = 'fas fa-check';
            toast('API key copy hoye gelo.', 'success');
            setTimeout(function () {
                btn.classList.remove('is-done');
                btn.querySelector('i').className = 'fas fa-copy';
            }, 1600);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(val).then(done).catch(function () { legacyCopy(val, done); });
        } else {
            legacyCopy(val, done);
        }
    }

    function legacyCopy(val, done) {
        var tmp = document.createElement('textarea');
        tmp.value = val;
        tmp.setAttribute('readonly', '');
        tmp.style.position = 'fixed';
        tmp.style.opacity = '0';
        document.body.appendChild(tmp);
        tmp.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            toast('Copy kora jay ni — key ta nije select kore Ctrl+C press korun.', 'error');
        }
        tmp.remove();
    }

    function insertAtCursor(field, text) {
        if (!field) { return; }
        var start = field.selectionStart || field.value.length;
        var end = field.selectionEnd || field.value.length;
        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        field.focus();
        field.setSelectionRange(start + text.length, start + text.length);
        syncFieldState();
    }

    function setResult(id, text, cls) {
        var el = $(id);
        if (!el) { return; }
        el.textContent = text;
        el.className = 'mk-result' + (cls ? ' ' + cls : '');
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    function saveSmsSettings() {
        var btn = $('mkSaveSmsBtn');
        var hint = $('mkSaveHint');

        btn.disabled = true;
        hint.className = 'mk-save-hint';
        hint.textContent = 'Saving…';

        post('settings', {
            marketing_sms_provider: state.smsProvider,
            marketing_sms_api_key: $('mkSmsApiKey').value.trim(),
            marketing_sms_mode: $('mkSmsMode').value,
            marketing_sms_get_url: $('mkSmsGetUrl').value.trim(),
            marketing_sms_post_url: $('mkSmsPostUrl').value.trim(),
            marketing_sms_number_key: $('mkSmsNumberKey').value.trim(),
            marketing_sms_message_key: $('mkSmsMessageKey').value.trim(),
            marketing_sms_headers: $('mkSmsHeaders').value.trim(),
            marketing_sms_sender_id: $('mkSmsSenderId').value.trim()
        }).then(function (res) {
            btn.disabled = false;
            if (res.ok) {
                hint.className = 'mk-save-hint ok';
                hint.textContent = 'Saved just now';
                toast('Gateway settings saved.', 'success');
                adoptSettings(res.settings || {}, false);
                if (state.smsProvider !== 'manual') { checkBalance(true); }
            } else {
                hint.className = 'mk-save-hint bad';
                hint.textContent = res.error || 'Save failed';
                toast(res.error || 'Could not save settings.', 'error');
            }
        });
    }

    /** Copy server state back into the form */
    function adoptSettings(s, fillBlanks) {
        if (s.marketing_sms_provider) { state.smsProvider = s.marketing_sms_provider; }
        if (fillBlanks || $('mkSmsGetUrl').value.trim() === '') {
            $('mkSmsGetUrl').value = s.marketing_sms_get_url || '';
        }
        if (fillBlanks || $('mkSmsPostUrl').value.trim() === '') {
            $('mkSmsPostUrl').value = s.marketing_sms_post_url || '';
        }
        if (fillBlanks || !$('mkSmsSenderId')) {
            // no-op guard: field is absent on older cached markup
        } else if (fillBlanks || $('mkSmsSenderId').value.trim() === '') {
            $('mkSmsSenderId').value = s.marketing_sms_sender_id || '';
        }
        paintProvider(state.smsProvider);
        syncFieldState();
    }

    /** Send one real SMS using only the endpoint under test, saving nothing */
    function testEndpoint(which) {
        var phone = $('mkTestPhone').value.trim();
        var resultId = which === 'post' ? 'mkPostResult' : 'mkGetResult';
        var url = $(which === 'post' ? 'mkSmsPostUrl' : 'mkSmsGetUrl').value.trim();
        var btn = document.querySelector('.mk-endpoint-test[data-test="' + which + '"]');

        if (!url) { setResult(resultId, 'Ei box e URL nei.', 'bad'); return; }
        if (hasUnknownToken(url)) { setResult(resultId, 'Ei token ta chinte parbo na.', 'bad'); return; }
        if (!phone) {
            toast('Nicher box e apnar number likhun — test apnar number e jabe.', 'error');
            $('mkTestPhone').focus();
            return;
        }

        setResult(resultId, 'Sending…', 'run');
        btn.disabled = true;

        // test_url travels with the request, so the saved config is untouched
        post('sms_test', {
            phone: phone,
            test_url: url,
            test_method: which.toUpperCase(),
            message: 'POS gateway test. ' + new Date().toLocaleTimeString()
        }).then(function (res) {
            btn.disabled = false;
            if (res.ok) {
                setResult(resultId, 'Sent to ' + res.to + ' — phone e check korun.', 'ok');
            } else {
                setResult(resultId, res.error || 'Send failed.', 'bad');
            }
        });
    }

    function checkBalance(quiet) {
        var box = $('mkBalanceValue');
        box.textContent = 'Checking…';
        box.className = 'mk-metric-value';
        $('mkBalanceBtn').disabled = true;

        post('sms_balance', {}).then(function (res) {
            $('mkBalanceBtn').disabled = false;
            if (res.ok) {
                box.textContent = res.balance ? res.balance : 'OK';
                box.className = 'mk-metric-value ok';
                if (!quiet) { toast('Balance checked.', 'success'); }
            } else {
                box.textContent = 'Failed';
                box.className = 'mk-metric-value bad';
                if (!quiet) { toast(res.error || 'Balance check failed.', 'error'); }
            }
        });
    }

    /**
     * Show the public address the SMS gateway will leave from.
     *
     * This is the address the provider has to whitelist, and it is the one
     * value the shop cannot work out on its own: it is not the POS server's
     * local address, and on a home connection it changes whenever the router
     * reconnects. Hence a Check button rather than a saved value.
     */
    function loadServerIp(loud) {
        var box = $('mkIpValue');
        var btn = $('mkIpRefreshBtn');
        var copy = $('mkIpCopyBtn');

        box.textContent = 'Checking…';
        box.className = 'mk-ip-value run';
        btn.disabled = true;

        post('sms_ip', {}).then(function (res) {
            btn.disabled = false;
            if (res.ok) {
                state.serverIp = res.ip;
                box.textContent = res.ip;
                box.className = 'mk-ip-value';
                copy.disabled = false;
                $('mkIpNote').classList.remove('bad');
                if (loud) { toast('Server IP: ' + res.ip, 'success'); }
            } else {
                state.serverIp = '';
                box.textContent = 'Barte parchi na';
                box.className = 'mk-ip-value bad';
                copy.disabled = true;
                $('mkIpNote').classList.add('bad');
                if (loud) { toast(res.error || 'IP check failed.', 'error'); }
            }
        });
    }

    function copyServerIp() {
        if (!state.serverIp) { return; }
        var btn = $('mkIpCopyBtn');

        var done = function () {
            btn.classList.add('is-done');
            btn.querySelector('i').className = 'fas fa-check';
            toast('IP copy hoye gelo — Phonebook e paste kore den.', 'success');
            setTimeout(function () {
                btn.classList.remove('is-done');
                btn.querySelector('i').className = 'fas fa-copy';
            }, 1600);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(state.serverIp).then(done).catch(function () {
                legacyCopy(state.serverIp, done);
            });
        } else {
            legacyCopy(state.serverIp, done);
        }
    }

    function sendTestSms() {
        var phone = $('mkTestPhone').value.trim();
        if (!phone) { toast('Apnar number likhun.', 'error'); $('mkTestPhone').focus(); return; }
        if (!confirm('SMS ta apni nijer number e pathano hobe. Chalen?')) { return; }

        setResult('mkTestResult', 'Sending…', 'run');
        $('mkTestSendBtn').disabled = true;

        post('sms_test', { phone: phone }).then(function (res) {
            $('mkTestSendBtn').disabled = false;
            if (res.ok) {
                setResult('mkTestResult', 'Sent to ' + res.to + ' — phone e ese check korun.', 'ok');
                toast('Test SMS sent.', 'success');
            } else {
                setResult('mkTestResult', res.error || 'Send failed.', 'bad');
                toast(res.error || 'Test send failed.', 'error');
            }
        });
    }

    // ── The send loop ───────────────────────────────────────────────────────
    function initSending() {
        $('mkStartBtn').addEventListener('click', startCampaign);
        $('mkPauseBtn').addEventListener('click', function () {
            if (state.paused) { resumeCampaign(); } else { pauseCampaign(); }
        });
        $('mkCancelBtn').addEventListener('click', function () {
            if (!confirm('Campaign bondho korle baki message pathano hobe na. Chai?')) { return; }
            post('cancel', { campaign_id: state.campaignId }).then(function (res) {
                if (res.ok) {
                    finishSend('Cancelled');
                    toast('Campaign cancelled.', 'warn');
                }
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (b) {
            b.addEventListener('click', function () { $('mkLogModal').classList.remove('active'); });
        });
    }

    function startCampaign() {
        var message = $('mkMessage').value.trim();
        var ids = Object.keys(state.selected);

        if (!message) { toast('Message likhte hobe.', 'error'); $('mkMessage').focus(); return; }
        if (ids.length === 0) { toast('At least one customer select korte hobe.', 'error'); return; }

        if (!confirm('SMS campaign shuru hobe — ' + ids.length + ' recipient.\n'
            + '\n\nShuru korben?')) { return; }

        $('mkLogList').innerHTML = '';

        post('create', {
            channel: state.channel,
            name: $('mkCampaignName').value.trim(),
            message: message,
            customer_ids: ids
        }).then(function (res) {
            if (!res.ok) { toast(res.error || 'Campaign create hoyni.', 'error'); return; }
            state.campaignId = res.campaign_id;
            // The server drops anything it could not confirm as reachable. Say
            // so here rather than letting the picker and the send list quietly
            // disagree - a count that shrinks between the confirm dialog and
            // the run is otherwise impossible to explain.
            if (res.skipped > 0) {
                toast(res.skipped + ' ta number campaign e dhuknni - '
                    + 'WhatsApp confirm hoy nai.', 'info');
            }
            post('start', { campaign_id: state.campaignId }).then(function (r2) {
                if (!r2.ok) { toast(r2.error || 'Campaign start hoyni.', 'error'); return; }
                beginLoop(res.total);
            });
        });
    }

    function beginLoop(total) {
        state.running = true;
        state.paused = false;

        $('mkStartBtn').classList.add('d-none');
        $('mkPauseBtn').classList.remove('d-none');
        $('mkPauseBtn').innerHTML = '<i class="fas fa-pause"></i> Pause';
        $('mkCancelBtn').classList.remove('d-none');
        $('mkProgressBox').classList.remove('d-none');

        updateProgress(0, total, total);
        if (state.channel === 'sms' && state.smsProvider === 'manual') {
            toast('SMS phone app e pathabe — protita SMS e "Send" tap korte hobe.', 'info');
        }
        runBatch();
    }

    function runBatch() {
        if (!state.running) { return; }

        post('send', { campaign_id: state.campaignId, offset: 0, limit: 5 })
            .then(function (res) {
                if (!res.ok) {
                    finishSend('Error');
                    toast(res.error || 'Sending error.', 'error');
                    return;
                }
                if (res.stopped) {
                    if (res.error) { finishSend('Stopped'); toast(res.error, 'error'); return; }
                    finishSend('Done');
                    return;
                }

                (res.deliveries || []).forEach(appendLog);
                // Skipped recipients are finished too, so they have to count
                // towards the bar or it stalls short of 100%.
                var c = res.campaign;
                updateProgress(c.sent + c.failed + c.skipped, c.total, c.total);

                if (res.campaign.pending === 0) { finishSend('Done'); return; }
                runBatch();
            });
    }

    function pauseCampaign() {
        state.paused = true;
        $('mkPauseBtn').innerHTML = '<i class="fas fa-play"></i> Resume';
        post('pause', { campaign_id: state.campaignId }).then(function (res) {
            if (res.ok) { toast('Paused. Baki message ' + (Object.keys(state.selected).length) + ' er moddhe ache.', 'info'); }
        });
    }

    function resumeCampaign() {
        state.paused = false;
        $('mkPauseBtn').innerHTML = '<i class="fas fa-pause"></i> Pause';
        post('start', { campaign_id: state.campaignId }).then(function (res) {
            if (res.ok) { runBatch(); }
        });
    }

    function finishSend(label) {
        state.running = false;
        state.paused = false;

        $('mkStartBtn').classList.remove('d-none');
        $('mkPauseBtn').classList.add('d-none');
        $('mkCancelBtn').classList.add('d-none');

        $('mkProgressText').textContent = label;
        $('mkLogModal').classList.add('active');

        if (label === 'Done') { toast('Campaign complete.', 'success'); }
    }

    function updateProgress(done, total, grandTotal) {
        grandTotal = grandTotal || total;
        var pct = grandTotal > 0 ? Math.round((done / grandTotal) * 100) : 0;
        $('mkProgressFill').style.width = pct + '%';
        $('mkProgressText').textContent = done + ' / ' + grandTotal + ' sent (' + pct + '%)';
    }

    function appendLog(d) {
        var row = document.createElement('div');
        row.className = 'mk-log-row';
        // Three outcomes, not two: a number without WhatsApp is skipped by the
        // pre-send check, which is a different problem from a send that failed.
        var kind = d.ok ? 'ok' : (d.skipped ? 'skip' : 'bad');
        var icon = d.ok ? 'fa-check-circle' : (d.skipped ? 'fa-forward' : 'fa-times-circle');
        row.innerHTML =
            '<div class="mk-log-icon ' + kind + '">' +
            '<i class="fas ' + icon + '"></i></div>' +
            '<div class="mk-log-body">' +
            '<span class="mk-log-name">' + esc(d.name || '(no name)') + '</span> ' +
            '<span class="mk-log-num">' + esc(d.phone_display || d.phone) + '</span>' +
            (d.error ? '<div class="mk-log-err ' + kind + '">' + esc(d.error) + '</div>' : '') +
            '</div>';
        $('mkLogList').appendChild(row);
        row.scrollIntoView({ block: 'nearest' });
    }

    // ── WhatsApp link + login QR ────────────────────────────────────────────
    /**
     * The QR image element, created if the page does not already have one.
     *
     * The panel is rendered server-side only when a code is already pending, so
     * on a linked shop there is no <img> in the document at all. Remove QR is
     * exactly the action that has to work from that state, so the element is
     * built here rather than relying on a reload - which is also the only way a
     * fresh code can appear without the shopkeeper doing anything.
     *
     * It goes into #mkWaQrSlot, which the server always renders at the foot of
     * the card, so a code handed over after an unlink lands in the same place as
     * the one that was there at page load. The card body is the fallback for a
     * page that somehow lacks the slot.
     */
    function waQrImage() {
        var img = $('mkWaQrImg');
        if (img) { return img; }

        var slot = $('mkWaQrSlot');
        var body = slot || ($('mkWaLinkCard') ? $('mkWaLinkCard').querySelector('.card-body') : null);
        if (!body) { return null; }

        var panel = document.createElement('div');
        panel.className = 'wi-qr-panel';
        panel.id = 'mkWaQrPanel';

        img = document.createElement('img');
        img.alt = 'WhatsApp login QR code';
        img.className = 'wi-qr-img';
        img.id = 'mkWaQrImg';

        var cap = document.createElement('div');
        cap.className = 'wi-qr-cap';
        cap.innerHTML = 'Phone er WhatsApp e <strong>Linked devices</strong> kholo, ei QR ta scan korun. '
            + 'Code ta prai ek minute por naya hoy &mdash; screen e ja ase setai scan korun.';

        panel.appendChild(img);
        panel.appendChild(cap);
        body.appendChild(panel);
        return img;
    }

    /**
     * Keep the link state, and the code on it, current.
     *
     * This is what makes the card usable rather than decorative. WhatsApp Web
     * issues a code that expires in about a minute and then replaces it, so a QR
     * shown once at page load goes stale and the scan fails silently - which
     * looks like the shopkeeper's fault and is not. Swapping the src as the
     * status response changes the image is the fix; the on-screen code is always
     * the live one.
     *
     * Every few seconds, and only while the page is visible. A background tab on
     * a shop PC is usually a campaign left running, and polling it at full rate
     * for the rest of the day buys nothing - the code is only ever read by
     * somebody looking at the screen.
     */
    function pollWaStatus() {
        return get(API_STATUS).then(function (r) {
            if (!r || r.ok === false) { return; }

            var card = $('mkWaLinkCard');
            if (card) {
                card.classList.toggle('is-linked', !!r.connected);
                card.classList.toggle('is-offline', !r.connected);
            }

            // Whether the shop has a bridge at all is decided server-side and
            // rendered into the card, because it is a setup step and not
            // something a status poll can change. Reading it back here keeps
            // "not set up" from being overwritten with "not connected" five
            // seconds after load, which would point the shop at a QR that is
            // never going to arrive.
            var ready = !card || card.dataset.waReady === '1';

            var text = $('mkWaStatusText');
            if (text) {
                text.textContent = r.connected
                    ? 'Connected'
                    : (ready ? 'WhatsApp is not connected' : 'WhatsApp is not set up');
            }

            // "Live" is a claim about where new numbers come from, and it is
            // only true while the bridge is uploading. A disconnected bridge
            // still refreshes this list on schedule, but nothing new can arrive,
            // so the badge drops to say what is actually happening rather than
            // sitting there claiming a sync that is not running.
            var live = $('mkLiveBadge');
            if (live) {
                if (r.connected) {
                    live.classList.remove('d-none');
                    live.lastElementChild.textContent = 'Live';
                } else {
                    live.classList.remove('d-none');
                    live.lastElementChild.textContent = 'Not syncing';
                }
            }

            // The quiet line under the state. Which number is linked is the one
            // thing a shopkeeper actually wants to see once the dot turns green,
            // and the JID the bridge reports is not readable - the digits only.
            var sub = $('mkWaStatusSub');
            if (sub) {
                if (r.connected) {
                    var who = (r.me && (r.me.id || r.me.name)) || '';
                    who = String(who).replace(/@.*$/, '');
                    // Strip the country code back to the local 01XXXXXXXXX form the
                    // rest of this project prints numbers in.
                    if (/^880\d{10}$/.test(who)) { who = '0' + who.slice(3); }
                    sub.textContent = who
                        ? 'Apnar WhatsApp number: ' + who
                        : 'Apnar number link hoye geche';
                } else {
                    sub.textContent = ready
                        ? 'Apnar personal number link korte QR scan korte hobe'
                        : 'Bridge URL ar API token ekhono dewa hoye ni - Settings > WhatsApp Bridge';
                }
            }

            // Nothing to show while the bridge itself is missing. The card renders
            // a setup instruction in that state, and a poll must not paper over
            // it with an empty code panel.
            if (!ready) { return; }

            // Only assign when the code actually differs. The src is a base64
            // data URL, so reassigning an identical one on every poll makes the
            // browser reflow the image and, on a slow connection, flicker.
            var panel = $('mkWaQrPanel');
            if (r.qr) {
                var img = waQrImage();
                if (img && img.getAttribute('src') !== r.qr) {
                    img.setAttribute('src', r.qr);
                }
                if (panel) { panel.classList.remove('d-none'); }
            } else if (panel) {
                // The bridge stopped offering a code - it expired and the bridge
                // restarted, say. Hide the stale one rather than leaving a code on
                // screen that cannot work. Deliberately not removing the element:
                // the next code reuses it.
                panel.classList.add('d-none');
            }

            // The offline note is rendered server-side only when the bridge was
            // down at page load. Toggling it keeps it honest if the link drops
            // while the page is open, and hides it whenever a code is showing.
            var note = $('mkWaOfflineNote');
            if (note) {
                note.classList.toggle('d-none', !!r.connected || !!r.qr);
            }
        });
    }

    /**
     * Unlink the account, then wait for the bridge to hand back a new code.
     *
     * Goes through the campaign API's logout_whatsapp action, which routes to
     * marketingBridgeCommand() rather than calling the bridge itself. That is
     * what makes the button work in agent mode too, where there is no URL to
     * dial: the command is parked in wa_agents and the bridge acts on it at its
     * next poll.
     *
     * The button is disabled while this runs. Logout is not instant on the
     * bridge side, and a second press would queue a second unlink against an
     * account that is already coming apart - which is how a "Remove QR" ends up
     * looking like it did nothing.
     */
    function unlinkWa() {
        var btn = $('mkWaUnlinkBtn');
        if (btn) { btn.disabled = true; }

        return post('logout_whatsapp', {}).then(function (r) {
            if (!r || !r.ok) {
                if (btn) { btn.disabled = false; }
                toast((r && r.error) || 'QR remove kora jay ni.', 'error');
                return pollWaStatus();
            }

            // Unlinking takes a moment, and the bridge only issues a fresh code on
            // its next boot. Poll a few times rather than once, so the new code
            // lands on its own instead of needing a manual Refresh.
            //
            // "Arrived" is a src on the image, not the element's presence:
            // waQrImage() builds it on the first poll that carries a code, so
            // before that the img does not exist and asking for it would create
            // an empty one and report success immediately.
            var tries = 0;
            var wait = function () {
                return pollWaStatus().then(function () {
                    var img = $('mkWaQrImg');
                    if (img && img.getAttribute('src')) { return true; }
                    tries += 1;
                    return tries < 10 ? delay(3000).then(wait) : false;
                });
            };
            return wait().then(function (got) {
                if (btn) { btn.disabled = false; }
                if (!got) {
                    toast('QR ashe ni. Bridge cholche kina check kore abar chapun.', 'error');
                }
            });
        });
    }

    /**
     * Wire the connection card.
     *
     * Guarded on the element existing, so the poll is not started on a page that
     * does not carry the card. The interval is kept on the returned handle
     * rather than left to the garbage collector - a repeating timer with nothing
     * pointing at it can be collected, and the QR then quietly stops refreshing
     * on exactly the machine that needs it.
     */
    function initBridge() {
        var card = $('mkWaLinkCard');
        if (!card) { return; }

        var refresh = $('mkWaRefreshBtn');
        if (refresh) {
            refresh.addEventListener('click', function () {
                refresh.disabled = true;
                pollWaStatus().then(function () { refresh.disabled = false; });
            });
        }

        var unlink = $('mkWaUnlinkBtn');
        if (unlink) { unlink.addEventListener('click', unlinkWa); }

        window.mkWaTimer = setInterval(function () {
            if (document.hidden) { return; }
            pollWaStatus();
        }, 5000);

        // One immediate call, so a link that came up after the page was rendered
        // shows up without waiting out the first interval.
        pollWaStatus();
    }

    // ── Boot ────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initChannels();
        initComposer();
        initSmsSettings();
        initSending();
        initContactFlow();
        initBridge();       // WhatsApp link state + the rotating login QR
        loadAudience();   // this is what calls primeWaSync()

        // Started after the first load, not instead of it. The immediate call
        // above has to happen on its own so the table is populated the first
        // time the page opens; kicking the poll off in the same tick would
        // fire a second, silent request that could land first and leave the
        // page briefly showing the previous session's data.
        startLiveRefresh();
    });
})();
