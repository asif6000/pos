/**
 * Marketing - SMS campaigns
 *
 * The send loop is driven from the browser: it asks the API for a small batch,
 * renders the result, then asks for the next one. Keeps the PHP side stateless
 * and lets the shopkeeper stop at any moment.
 */
(function () {
    'use strict';

    var API_CAMPAIGN = 'api/marketing-campaign.php';
    var API_AUDIENCE = 'api/marketing-audience.php';
    var API_BALANCE  = 'api/sms-balance.php';

    // ── State ────────────────────────────────────────────────────────────────
    var state = {
        channel: 'sms',
        smsProvider: 'manual',
        serverIp: '',
        customers: [],
        totals: { all: 0, usable: 0 },
        recipients: { rows: 0, people: 0 },
        selected: {},
        // Batch selection: ten at a time. batchLog records which ids each press
        // of Next handed over, so Back can take back exactly one press and can
        // never disturb a checkbox the shopkeeper ticked by hand.
        batchSize: 10,
        batchLog: [],
        campaignId: 0,
        running: false,
        paused: false,
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

    // Sample values so the preview shows a real looking message. The backend
    // fills every token it knows and drops the rest, so the preview does the
    // same: what is in this bubble is what reaches the phone.
    function previewValues() {
        var card = $('mkComposeCard');
        var shop = card ? (card.dataset.shopName || '') : '';
        return {
            '{name}': 'Rahim',
            '{full_name}': 'Rahim Uddin',
            '{phone}': '01712345678',
            '{shop}': shop,
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

    /**
     * The same clean-up config/sms_gateway.php applies before an SMS leaves.
     *
     * The shop pays per segment and the customer reads the result, so the server
     * is the gate: a word a stale browser build wrote into the box ("undefined")
     * and a token nobody can fill are both removed there. The preview runs the
     * same rules so the bubble is the message rather than a promise about it.
     */
    function scrubMessage(text) {
        return String(text)
            .replace(/\bundefined\b|\bNaN\b/g, '')
            .replace(/[ \t]+(?=[,.;:!?])/g, '')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
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
        var bad = 0;
        var out = scrubMessage(raw).replace(/\{([a-z0-9_ ]+)\}/gi, function (m, name) {
            var key = '{' + name.toLowerCase() + '}';
            if (vals[key] === undefined) {
                // Unfillable, so it is dropped - exactly as the send path drops it.
                bad++;
                return '';
            }
            return vals[key];
        });

        bubble.className = 'mk-bubble';
        bubble.textContent = scrubMessage(out);

        var warn = $('mkPreviewWarn');
        warn.textContent = bad
            ? bad + ' ta token chinte parbo na — segulo message theke remove hoye jabe.'
            : '';
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
            // The batch history is about how the list was walked, so it stops
            // being true the moment something other than Next selects rows.
            state.batchLog = [];
            renderCustomers();
        });

        $('mkSelectNoneBtn').addEventListener('click', function () {
            state.selected = {};
            state.batchLog = [];
            renderCustomers();
        });

        $('mkBatchNextBtn').addEventListener('click', selectNextBatch);
        $('mkBatchPrevBtn').addEventListener('click', undoLastBatch);

        $('mkSelectTotalBtn').addEventListener('click', selectEveryCustomer);

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
     * same length with a different name in the middle still have to repaint -
     * a count-only check would leave a stale row on screen after a customer
     * was edited.
     */
    function audienceSignature(rows, totals, recipients) {
        var body = (rows || []).map(function (c) {
            return c.id + ':' + (c.last_visit || '-') + ':' + c.name + ':' + c.phone;
        }).join('|');
        var t = totals || {};
        var r = recipients || {};
        return body + '#' + [t.all, t.usable, r.rows, r.people].join(',');
    }

    /**
     * @param {boolean} silent  refresh without the spinner, for the live poll.
     *                          The skeleton is only ever shown for a load the
     *                          shopkeeper asked for; a table that blanks every
     *                          few seconds on its own reads as broken.
     */
    function loadAudience(silent) {
        var url = API_AUDIENCE + '?q=' + encodeURIComponent($('mkSearch').value.trim()) +
            '&only_inactive=' + ($('mkOnlyInactive').checked ? '1' : '0') +
            '&days=' + $('mkInactiveDays').value;

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

            renderCustomers();
        }).catch(function () {
            state.liveLoading = false;
        });
    }

    /**
     * Keep the list current while the page is open, because the POS cannot push
     * to an open browser tab. If the customer list is edited in another tab, the
     * change shows up here within the polling interval.
     *
     * The interval is a floor on how stale the list may get, not a promise of
     * how quickly it updates.
     */
    function pollAudience() {
        // Anything that is mid-flight owns the screen. A refresh during a send
        // is not worth the risk at all.
        if (state.running || state.livePaused || state.liveLoading) { return; }
        if ($('mkSearch') === document.activeElement) { return; }

        loadAudience(true);
    }

    function startLiveRefresh() {
        if (state.liveTimer) { return; }
        state.liveTimer = setInterval(pollAudience, 8000);
    }

// ── Batch selection ─────────────────────────────────────────────────────────
    //
    // Ten rows at a time, rather than every row at once. "Select all" is still
    // there for the shopkeeper who genuinely means the whole list, but walking a
    // long list ten rows at a time is how the shopkeeper actually sees which
    // numbers a campaign is about to spend credits on.
    //
    // The step order is the order the table is drawn in, and it always takes the
    // first rows that are still unselected, so Next cannot hand back the same rows
    // twice and cannot skip past a gap the filters left.

    /** Rows Next could still hand over: on screen, and not already selected. */
    function batchCandidates() {
        return state.customers.filter(function (c) { return !state.selected[c.id]; });
    }

    /**
     * Bring the two buttons and the progress line in line with the selection.
     *
     * Called from every place the selection changes, not just from Next, so that
     * ticking the last row by hand also disables Next instead of leaving a button
     * that does nothing.
     */
    function renderBatch() {
        var total = state.customers.length;
        // Counted from the rows actually on screen rather than from state.selected:
        // the selection deliberately outlives a search or a filter change, so a
        // number chosen under one filter is still chosen under the next one and
        // must not be counted as pending.
        var onScreen = batchCandidates().length;

        var prev = $('mkBatchPrevBtn');
        if (prev) { prev.disabled = state.batchLog.length === 0; }

        var next = $('mkBatchNextBtn');
        if (next) {
            if (onScreen === 0) {
                next.disabled = true;
                next.innerHTML = '<i class="fas fa-check"></i> Shob select hoye geche';
            } else {
                next.disabled = false;
                // The count is on the button because a leftover of three rows means
                // the list is nearly done, and "Next 10" would oversell it.
                next.innerHTML = 'Next ' + Math.min(state.batchSize, onScreen) +
                    '<i class="fas fa-chevron-right"></i>';
            }
        }

        var note = $('mkBatchNote');
        if (note) {
            var picked = Object.keys(state.selected).length;
            // "Select total" can pick people who are not on screen at all, so the
            // count of what is selected and the count of what is on screen are two
            // different numbers and saying only the second one would understate a
            // campaign by hundreds.
            var offscreen = picked - (total - onScreen);

            if (total === 0) {
                note.textContent = offscreen > 0
                    ? picked + ' ta customer select ache, kintu ei filter e to ekta-i deoa jacche na. '
                      + 'Search ba filter reset kore list dekho.'
                    : '';
            } else if (onScreen === 0) {
                note.textContent = 'Ei list e ' + total + ' ta number, shob-ta-i selected. '
                    + 'Baki number dekh-te search ba filter bodlaiyen.'
                    + (offscreen > 0 ? ' Ar ' + offscreen + ' ta list er baire select ache.' : '');
            } else {
                note.textContent = 'Ei list e ' + total + ' ta number ache. Next dabile '
                    + Math.min(state.batchSize, onScreen) + ' ta kore select korbe — '
                    + onScreen + ' ta baki, matro '
                    + Math.ceil(onScreen / state.batchSize) + ' bar click lagbe.'
                    + (offscreen > 0 ? ' Ar ' + offscreen + ' ta list er baire select ache.' : '');
            }
        }

        return { total: total, onScreen: onScreen, picked: Object.keys(state.selected).length };
    }

    /**
     * Bring the first row of a batch into view, without scrolling if it already is.
     *
     * Done here rather than in renderCustomers(), because renderCustomers() also
     * runs on the live poll - scrolling there would yank the list out from under
     * somebody halfway down reading a name.
     */
    function revealBatch(ids) {
        if (!ids.length) { return; }
        var row = document.querySelector('#mkCustomerRows tr[data-id="' + ids[0] + '"]');
        if (row && row.scrollIntoView) {
            try {
                row.scrollIntoView({ block: 'nearest' });
            } catch (e) {
                row.scrollIntoView(); // Older browsers reject the options object
            }
        }
    }

    function selectNextBatch() {
        var batch = batchCandidates().slice(0, state.batchSize);
        // Nothing left on screen: leave the selection alone and let renderBatch()
        // keep the button disabled, rather than clearing somebody's work.
        if (!batch.length) { renderCustomers(); return; }

        var ids = batch.map(function (c) { return c.id; });
        ids.forEach(function (id) { state.selected[id] = true; });
        state.batchLog.push(ids);

        renderCustomers();
        revealBatch(ids);
    }

    function undoLastBatch() {
        var ids = state.batchLog.pop();
        if (!ids) { renderCustomers(); return; }
        ids.forEach(function (id) { delete state.selected[id]; });
        renderCustomers();
        revealBatch(ids);
    }

    /**
     * Select every customer who has a number, whatever the filters are doing.
     *
     * "Select all on screen" can only reach the rows the list currently holds, and
     * that list is filtered by search, by the inactive-days toggle and is capped
     * at 500 rows. So for a shop with more customers than any of those allow,
     * there is otherwise no way to say "all of them" - which is the one thing a
     * shopkeeper means by the Total in the header.
     *
     * The ids come from the server rather than from state.customers, so this is not
     * the same thing as selecting the visible rows: it deliberately crosses the
     * search box and the inactive filter.
     */
    function selectEveryCustomer() {
        var btn = $('mkSelectTotalBtn');
        btn.disabled = true;
        var label = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading';

        get(API_AUDIENCE + '?ids_only=1').then(function (res) {
            btn.disabled = false;
            btn.innerHTML = label;

            if (!res.ok) {
                toast(res.error || 'Customer list nite parlam na.', 'error');
                return;
            }

            var ids = res.ids || [];
            if (!ids.length) {
                toast('Ei shop e phone number ache emon kono customer nai.', 'warn');
                return;
            }

            // How many of them are not on screen, because that is the whole point of
            // this button and the shopkeeper should be told they are about to
            // campaign people they cannot see listed.
            var visible = {};
            state.customers.forEach(function (c) { visible[c.id] = true; });
            var offscreen = ids.filter(function (id) { return !visible[id]; }).length;

            if (!confirm(ids.length + ' ta customer select korbe'
                + (offscreen > 0 ? '\n\n' + offscreen + ' ta ekhon list e nai (search ba filter e),'
                    + ' kintu tara-o select hobe.' : '')
                + '\n\nChalan?')) {
                return;
            }

            ids.forEach(function (id) { state.selected[id] = true; });
            // Not a walk down the list, so there is no batch to go back to.
            state.batchLog = [];
            renderCustomers();
            toast(ids.length + ' ta customer select holo.', 'success');
        });
    }

    function renderCustomers() {
        var tbody = $('mkCustomerRows');
        $('mkSelectedCount').textContent = Object.keys(state.selected).length + ' selected';

        if (state.customers.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="4" class="text-center text-muted" style="padding:24px">' +
                'Ei filter e kono customer paoa jachhe na.' +
                '</td></tr>';
            // On this path too: a search that comes back empty must not leave
            // the Next button live with rows behind it that are not on screen.
            renderBatch();
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
                '<td class="mk-cust-phone"><span>' + esc(c.phone_display) + '</span></td>' +
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
                // The button's disabled state depends on the selection, so
                // ticking a box by hand has to refresh it too - otherwise Next
                // stays live with nothing left to hand over.
                renderBatch();
            });
        });

        renderBatch();
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

        // Scoped to [data-insert]: the composer's data-token chips match
        // .mk-chip too, and their missing dataset value would be written into
        // the URL box as the literal word "undefined".
        document.querySelectorAll('.mk-chip[data-insert]').forEach(function (chip) {
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
        // The gateway card was removed from marketing.php, so these nodes may not
        // exist on the page anymore. Guard them: initChannels() paints the provider
        // on boot, and a null here would throw and take the whole page's JS down.
        var box = $('mkSmsGatewayBox');
        if (box) { box.classList.toggle('d-none', manual); }
        var hint = $('mkSmsManualHint');
        if (hint) { hint.classList.toggle('d-none', !manual); }
        var mode = $('mkSmsModeHint');
        if (mode) { mode.textContent = manual ? 'Phone SMS app (free)' : providerLabel(provider); }
        refreshBadge();
    }

    function providerLabel(key) {
        var tile = document.querySelector('.mk-provider[data-provider="' + key + '"] .mk-provider-name');
        return tile ? tile.textContent : key;
    }

    /** Status pill: ready only when there is a key and a usable endpoint */
    function refreshBadge() {
        var badge = $('mkGatewayBadge');
        // The gateway card (and its status pill) no longer render on marketing.php.
        if (!badge) { return; }
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
        // A missing dataset value would otherwise be written into the field as
        // the literal word "undefined" and go out to every customer's phone.
        if (typeof text !== 'string' || text === '') { return; }
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
        var kind = d.ok ? 'ok' : 'bad';
        var icon = d.ok ? 'fa-check-circle' : 'fa-times-circle';
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

    // ── SMS balance ──────────────────────────────────────────────────────────
    /**
     * The remaining credit is not in this database, it is at the gateway, and
     * asking for it is a blocking HTTP request - so the chip renders empty and
     * fills in afterwards. The API key never comes near the browser:
     * api/sms-balance.php does the asking and sends back only the number.
     */
    function paintBalance(state, value, why) {
        var chip = $('mkBalance');
        if (!chip) { return; }
        chip.setAttribute('data-state', state);
        $('mkBalanceValue').textContent = value;
        if (why) { chip.setAttribute('title', why); }
    }

    function loadBalance(force) {
        var chip = $('mkBalance');
        if (!chip) { return; }
        var btn = $('mkBalanceRefresh');
        if (btn) { btn.classList.add('is-busy'); }
        paintBalance('load', '…', 'Gateway balance check hocche…');

        get(API_BALANCE + (force ? '?refresh=1' : '')).then(function (d) {
            if (btn) { btn.classList.remove('is-busy'); }
            var at = d.checked_at ? ' · ' + d.checked_at + ' e' : '';

            if (d.ok && d.balance !== '') {
                paintBalance('ok', d.balance + ' SMS', 'Gateway e baki credit' + at);
                return;
            }
            if (d.ok) {
                paintBalance('off', '—', 'Gateway balance ta pore na' + at);
                return;
            }
            var why = d.error || 'Balance pore na.';
            // No gateway at all is a normal state, not a fault, so it does not
            // get the amber chip an unreachable provider does.
            paintBalance(/Gateway set kora nei/i.test(why) ? 'off' : 'warn', '—', why);
            if (force) { toast(why, 'warn'); }
        });
    }

    function initBalance() {
        if (!$('mkBalance')) { return; }
        var btn = $('mkBalanceRefresh');
        if (btn) { btn.addEventListener('click', function () { loadBalance(true); }); }
        loadBalance(false);
    }

    // ── Boot ────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initChannels();
        initComposer();
        // The SMS gateway settings card was removed from marketing.php; the
        // provider is now configured elsewhere and SMS credits live on the
        // dedicated SMS Service page. initSmsSettings() only ever wired that
        // card's inputs, so it is no longer called - its targets no longer exist.
        initSending();
        initContactFlow();
        initBalance();      // gateway credit, fetched after the page painted
        loadAudience();

        // Started after the first load, not instead of it. The immediate call
        // above has to happen on its own so the table is populated the first
        // time the page opens; kicking the poll off in the same tick would
        // fire a second, silent request that could land first and leave the
        // page briefly showing the previous session's data.
        startLiveRefresh();
    });
})();
