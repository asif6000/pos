/**
 * WhatsApp Inbox
 *
 * Reads and replies to customer conversations. The Marketing page composes
 * campaigns; this one is the shop's actual WhatsApp, so the priorities are
 * different - a reply has to feel instant, and an unread message has to be
 * noticed.
 *
 * How fresh data gets here:
 *   bridge buffer  ->  pull  ->  MySQL  ->  this page
 *
 * The bridge only ever holds what it has just seen, so the page drains it on a
 * timer and then reads everything back out of MySQL. That is deliberate: a
 * message is durable the moment it is stored, so closing this tab, or the bridge
 * being restarted, loses nothing.
 */
(function () {
    'use strict';

    var API = 'api/whatsapp-chat.php';

    var state = {
        chatId: '',
        threads: [],
        lastId: 0,        // newest message id rendered for the open chat
        seq: 0,           // bridge buffer cursor
        connected: false,
        sending: false,
        filter: '',
        leadsOnly: false,
        picked: {},
        bulkCampaign: 0,
        unreadTotal: 0,
        poll: null,
        statusPoll: null,
        // Paging for the Chats list. The server reports the true size of the
        // address book; threadsTotal is that number, not the rows on screen, so
        // the header does not shrink every time a page is added.
        threadsTotal: 0,
        threadsHasMore: false,
        threadsLoading: false
    };

    var POLL_MS = 5000;
    var THREAD_PAGE = 200;

    // ── helpers ──────────────────────────────────────────────────────────────

    function $(id) { return document.getElementById(id); }

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
        return fetch(API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Server response could not be read.' }; });
    }

    function get(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Server response could not be read.' }; });
    }

    /** Every message body is customer-supplied text, so it never goes in as HTML. */
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtTime(iso) {
        if (!iso) { return ''; }
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return ''; }
        var today = new Date();
        var sameDay = d.toDateString() === today.toDateString();
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        if (sameDay) { return hh + ':' + mm; }
        return d.getDate() + '/' + (d.getMonth() + 1) + ' ' + hh + ':' + mm;
    }

    function say(where, text, isError) {
        var el = $(where);
        if (!el) { return; }
        el.textContent = text || '';
        el.classList.toggle('is-error', !!isError);
    }

    // ── bridge status ────────────────────────────────────────────────────────

    /**
     * The QR image element, created if the page does not already have one.
     *
     * The panel is rendered server-side only when a QR is already pending, so on
     * a connected shop there is no <img> in the document at all. Unlinking is
     * exactly the action that has to work from that state, so the element is
     * built here rather than relying on a page reload - which is also the only
     * way the fresh code can appear without the shopkeeper doing anything.
     *
     * It goes into #wiQrSlot, which the server always renders at the end of the
     * link card. Building it against the card body instead would put a code
     * handed over after an unlink somewhere else on screen than the one that was
     * there at page load, and the shop would be looking at a panel that has
     * quietly moved. The card body stays as the fallback for a page that somehow
     * lacks the slot.
     */
    function qrImage() {
        var img = $('wiQrImg');
        if (img) { return img; }

        var slot = $('wiQrSlot');
        var body = slot || ($('wiLinkCard') ? $('wiLinkCard').querySelector('.card-body') : null);
        if (!body) { return null; }

        var panel = document.createElement('div');
        panel.className = 'wi-qr-panel';
        panel.id = 'wiQrPanel';

        img = document.createElement('img');
        img.alt = 'WhatsApp login QR code';
        img.className = 'wi-qr-img';
        img.id = 'wiQrImg';

        var cap = document.createElement('div');
        cap.className = 'wi-qr-cap';
        cap.innerHTML = 'Phone er WhatsApp e <strong>Linked devices</strong> kholo, ei QR ta scan korun. '
            + 'Code ta prai ek minute por naya hoy - screen e ja ase setai scan korun.';

        panel.appendChild(img);
        panel.appendChild(cap);
        body.appendChild(panel);
        return img;
    }

    /**
     * Keep the link state on screen.
     *
     * Polled separately from the message pull because it has to keep working
     * while the bridge is down.
     *
     * It also keeps the QR image current. WhatsApp Web issues a code that
     * expires in about a minute and then replaces it, so a QR shown once at
     * page load goes stale and the scan silently fails - which looks like the
     * shopkeeper's fault and is not. Swapping the src as the status response
     * changes the image is what makes the panel usable rather than decorative.
     */
    function pollStatus() {
        return get('api/marketing-status.php')
            .then(function (r) {
                if (!r) { return; }
                state.connected = !!r.connected;

                var card = $('wiLinkCard');
                card.classList.toggle('is-linked', !!r.connected);
                card.classList.toggle('is-offline', !r.connected);

                $('wiStatusText').textContent = r.connected ? 'Connected' : 'WhatsApp is not connected';

                // The QR is a data URL, so only assign when it actually differs -
                // reassigning an identical src on every poll makes the browser
                // reflow the image and, on a slow connection, flicker.
                // qrImage() builds the element on demand, so a shop that was
                // connected at page load still gets the code after an unlink.
                if (r.qr) {
                    var img = qrImage();
                    if (img && img.getAttribute('src') !== r.qr) {
                        img.setAttribute('src', r.qr);
                    }
                    var panel = $('wiQrPanel');
                    if (panel) { panel.classList.remove('d-none'); }
                } else {
                    // Bridge stopped offering a QR (it expired and the bridge
                    // restarted, say). Hide the stale one rather than leaving a
                    // code on screen that cannot work. Deliberately not removing
                    // the element: the next code reuses it.
                    var p2 = $('wiQrPanel');
                    if (p2) { p2.classList.add('d-none'); }
                }

                // The offline note is rendered server-side only when the bridge
                // was down at page load. Toggling it here keeps it honest if the
                // link drops while the page is open, which is the whole reason
                // this status is polled rather than set once.
                var note = $('wiOfflineNote');
                if (note) {
                    note.classList.toggle('d-none', !!r.connected || !!r.qr);
                }
            });
    }

    /**
     * Unlink the account, then wait for the bridge to hand back a new QR.
     *
     * Goes through the campaign API's logout_whatsapp action, which routes to
     * marketingBridgeCommand() rather than calling the bridge itself. That is
     * what makes the button work in agent mode too, where there is no URL to
     * dial: the command is parked in wa_agents and the bridge acts on it at its
     * next poll.
     *
     * The button is disabled while this runs because logout is not instant on
     * the bridge side - a second press would queue a second unlink against an
     * account that is already coming apart, which is how a "remove QR" ends up
     * looking like it did nothing.
     */
    function unlink() {
        var btn = $('wiUnlinkBtn');
        if (btn) { btn.disabled = true; }

        return bulkPost('logout_whatsapp', {}).then(function (r) {
            if (!r || !r.ok) {
                if (btn) { btn.disabled = false; }
                alert(r && r.error ? r.error : 'QR remove kora jay ni.');
                return pollStatus();
            }

            // Unlinking takes a moment, and the bridge only issues a fresh QR on
            // its next boot. Poll a few times rather than once, so the new code
            // lands on its own instead of needing a manual Refresh - and re-enable
            // the button on the way out, since the bridge may have been the only
            // thing that could be reached.
            //
            // "Arrived" is a src on the image, not its presence in the document:
            // qrImage() builds the element on the first poll that carries a code,
            // so before that the img does not exist and asking for it would create
            // an empty one and report success immediately.
            var tries = 0;
            var wait = function () {
                return pollStatus().then(function () {
                    var img = $('wiQrImg');
                    if (img && img.getAttribute('src')) { return true; }
                    tries += 1;
                    return tries < 10 ? delay(3000).then(wait) : false;
                });
            };
            return wait().then(function (got) {
                if (btn) { btn.disabled = false; }
                if (!got) {
                    alert('QR ashe ni. Bridge cholche kina check kore abar chapun.');
                }
            });
        });
    }

    // ── pull + threads ───────────────────────────────────────────────────────

    /**
     * Drain the bridge into the database.
     *
     * Reloading only happens when something was actually stored, so an idle shop
     * does not re-render the same list every five seconds.
     */
    function pull() {
        if (!state.connected) { return Promise.resolve(); }

        return post('pull', { since: state.seq }).then(function (r) {
            if (!r || !r.ok) { return; }
            state.seq = r.seq || state.seq;

            if (r.gap) {
                say('wiSendHint', 'Kichu message buffer theke haire geche. Chat e History button chalanor jonno.', true);
            }
            if (r.new > 0 || !state.threads.length) {
                return Promise.all([loadThreads(), state.chatId ? loadMessages() : Promise.resolve()]);
            }
            return null;
        });
    }

    function loadThreads() {
        // The list is always the narrow one: this shop's customers that are on
        // WhatsApp. The bridge also knows every number it holds a session with -
        // hundreds, for a real account - and listing those buries the handful of
        // people this screen is for.
        return get(API + '?action=threads&limit=' + THREAD_PAGE).then(function (r) {
            if (!r || !r.ok) { return; }
            state.threads = r.threads || [];
            state.threadsTotal = (typeof r.total === 'number') ? r.total : state.threads.length;
            state.threadsHasMore = !!r.has_more;
            state.threadsLoading = false;
            // The server's split of the whole address book. Counting the loaded
            // rows would report the lead and customer split of one page and call
            // it the whole shop.
            state.threadsStats = {
                leads: r.leads, customers: r.customers, chatted: r.chatted
            };
            renderThreads();
        });
    }

    /**
     * Fetch the next page and append it.
     *
     * The server used to trim the list to 200 and throw the rest away, so a shop
     * with 297 numbers on WhatsApp saw 200 and had no way to reach the other 97 -
     * the customers it had paid to find. It is paged now, and the header count
     * comes from the server's total of the whole address book rather than from
     * how many rows happen to be loaded, so the tally reads the truth even while
     * only the first page is on screen.
     */
    function loadMoreThreads() {
        if (state.threadsLoading || !state.threadsHasMore) { return; }
        state.threadsLoading = true;
        var offset = state.threads.length;
        get(API + '?action=threads&limit=' + THREAD_PAGE + '&offset=' + offset)
            .then(function (r) {
                state.threadsLoading = false;
                if (!r || !r.ok) { return; }
                var seen = {};
                state.threads.forEach(function (t) { seen[t.chat_id] = true; });
                (r.threads || []).forEach(function (t) {
                    if (!seen[t.chat_id]) { state.threads.push(t); }
                });
                if (typeof r.total === 'number') { state.threadsTotal = r.total; }
                state.threadsHasMore = !!r.has_more;
                state.threadsStats = {
                    leads: r.leads, customers: r.customers, chatted: r.chatted
                };
                renderThreads();
            })
            .catch(function () { state.threadsLoading = false; });
    }

    function renderThreads() {
        var list = $('wiThreadList');
        if (!list) { return; }

        var rows = state.threads.filter(function (t) {
            if (state.leadsOnly && !t.is_lead) { return false; }
            if (!state.filter) { return true; }
            var hay = ((t.label || '') + ' ' + t.phone_display + ' ' + (t.wa_name || '') + ' ' + t.preview).toLowerCase();
            return hay.indexOf(state.filter) !== -1;
        });

        // The header badge is the sum across everything, not just what the filter
        // is showing. Hiding unread counts behind a filter would be how a
        // customer gets missed.
        var total = 0;
        state.threads.forEach(function (t) { total += t.unread || 0; });
        updateUnreadBadge(total);

        // The tally reflects the whole address book, not the filtered view, so it
        // is rendered before the early return below.
        renderTally(rows.length);

        if (!rows.length) {
            list.innerHTML = '<div class="wi-empty">'
                + (state.threads.length
                    ? '<p>Ei filter e kichu nai.</p>'
                    : '<i class="fab fa-whatsapp wi-placeholder-icon"></i>'
                      + '<p>Ei shop er kono customer number e WhatsApp nai.</p>'
                      + '<p class="wi-empty-hint">Marketing page e <strong>Check numbers</strong> chalanor por, '
                      + 'ba notun customer add korun.</p>')
                + '</div>';
            return;
        }

        // The list is rebuilt from scratch, which would throw the shopkeeper
        // back to the top every time a message arrived. Keeping the scroll
        // offset means an incoming message never moves what they were reading.
        var keepScroll = list.scrollTop;
        var wasAtBottom = (list.scrollHeight - list.scrollTop - list.clientHeight) < 40;

        list.innerHTML = rows.map(function (t) {
            // The label is already the best name available: the shop's customer
            // record, else the name saved on the phone, else the number.
            var who = t.label || t.phone_display;
            var initial = (who || '?').trim().charAt(0).toUpperCase();
            // A chat with nothing in it says so, rather than showing an empty
            // row that looks like a message failed to load.
            var preview = t.has_messages
                ? esc(t.preview)
                : '<em>' + esc(t.preview) + '</em>';
            var sub = t.customer_name
                ? t.phone_display
                : (t.wa_name ? esc(t.wa_name) + ' &middot; ' + esc(t.phone_display) : esc(t.phone_display));

            return '<button type="button" class="wi-thread' + (t.chat_id === state.chatId ? ' is-active' : '')
                + (t.has_messages ? '' : ' is-empty') + '" data-chat="' + esc(t.chat_id) + '">'
                + '<span class="wi-pick-cell" role="button" tabindex="-1" data-pick="' + esc(t.chat_id) + '">'
                + '<input type="checkbox" class="wi-pick"' + (state.picked[t.chat_id] ? ' checked' : '')
                + (t.customer_id ? '' : ' disabled title="Ei number customer record nai - bulk pathay jay na"') + '>'
                + '</span>'
                + '<span class="wi-avatar' + (t.is_lead ? ' is-lead' : '') + '">' + esc(initial) + '</span>'
                + '<span class="wi-thread-main">'
                + '<span class="wi-thread-top">'
                + '<span class="wi-thread-name">' + esc(who) + waDot(t) + '</span>'
                + '<span class="wi-thread-time">' + esc(fmtTime(t.last_at)) + '</span>'
                + '</span>'
                + '<span class="wi-thread-bot">'
                + '<span class="wi-thread-prev">' + sub + ' &middot; ' + preview + '</span>'
                + (t.unread > 0 ? '<span class="wi-count">' + (t.unread > 99 ? '99+' : t.unread) + '</span>' : '')
                + '</span>'
                + '</span>'
                + '</button>';
        }).join('');

        list.scrollTop = wasAtBottom ? list.scrollHeight : keepScroll;

        Array.prototype.forEach.call(list.querySelectorAll('.wi-thread'), function (btn) {
            btn.addEventListener('click', function () {
                openThread(btn.getAttribute('data-chat'));
            });
        });

        // The pick box sits inside the row button, so its click is stopped here.
        // Without this, ticking a row also opens that conversation, which is
        // never what was meant and is very annoying when selecting a batch.
        Array.prototype.forEach.call(list.querySelectorAll('.wi-pick'), function (box) {
            box.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
            });
            box.addEventListener('change', function (e) {
                e.stopPropagation();
                var row = box.closest('.wi-thread');
                if (e.target.checked) {
                    state.picked[row.getAttribute('data-chat')] = true;
                } else {
                    delete state.picked[row.getAttribute('data-chat')];
                }
                renderBulkBar();
                syncSelectAll();
            });
        });

        syncSelectAll();
    }

    /** Reflect the row ticks in the header checkbox, including the partial state. */
    function syncSelectAll() {
        var box = $('wiSelectAll');
        if (!box) { return; }
        var selectable = state.threads.filter(function (t) { return !!t.customer_id; });
        var ticked = selectable.filter(function (t) { return state.picked[t.chat_id]; }).length;
        box.checked = selectable.length > 0 && ticked === selectable.length;
        // The honest third state between none and all, rather than a checkbox
        // that is simply unchecked while most rows are ticked.
        box.indeterminate = ticked > 0 && ticked < selectable.length;
    }

    function renderBulkBar() {
        var bar = $('wiBulkBar');
        var compose = $('wiBulkCompose');
        var ids = Object.keys(state.picked);
        if (bar) {
            bar.classList.toggle('d-none', ids.length === 0);
            $('wiPickedCount').textContent = ids.length + ' selected';
        }
        if (compose) { compose.classList.toggle('d-none', ids.length === 0); }
    }

    /**
     * Whether this number can actually receive a WhatsApp message.
     *
     * Three states, not two. "Never checked" is drawn as a faint question mark
     * rather than a red cross, because telling a shopkeeper a customer is
     * unreachable when the number was simply never tested would lose a real
     * sale. The cross is reserved for a number WhatsApp has actually said no to.
     */
    function waDot(t) {
        if (t.has_whatsapp === true) {
            return ' <i class="fab fa-whatsapp wi-wa-yes" title="WhatsApp e ache"></i>';
        }
        if (t.has_whatsapp === false) {
            return ' <i class="fa-solid fa-circle-xmark wi-wa-no" title="Ei number e WhatsApp nai"></i>';
        }
        if (t.customer_id) {
            return ' <i class="fa-solid fa-circle-question wi-wa-unknown" title="Check hoy nai"></i>';
        }
        return '';
    }

    /**
     * The count line above the chat list.
     *
     * Total on its own would be misleading. "142 numbers" reads like 142
     * customers, and the two numbers that actually change what a shopkeeper
     * does next are how many are already customers and how many have never been
     * contacted. So the split is the point, and the "showing X of Y" appears
     * only when a search or filter is actually hiding something - a count that
     * silently changes meaning with the filter is worse than no count.
     */
    function renderTally(shown) {
        var box = $('wiTally');
        if (!box) { return; }

        // The server's count of the whole address book, not the rows loaded so far.
        // Taking state.threads.length here is what made 297 numbers on WhatsApp
        // read as 200: only the first page was loaded and the tally counted it,
        // so a shop that had paid to find 297 customers was told it had 200.
        var total = state.threadsTotal || state.threads.length;
        var leads = 0;
        var chatted = 0;
        state.threads.forEach(function (t) {
            if (t.is_lead) { leads++; }
            if (t.has_messages) { chatted++; }
        });

        // With pages not all loaded, the lead and customer split is only known for
        // what is on screen. The server sends the real figures, so use them once a
        // page is in; before that the loaded rows are the best available answer.
        var s = state.threadsStats || {};
        leads    = (typeof s.leads === 'number') ? s.leads : leads;
        var customers = (typeof s.customers === 'number') ? s.customers : (total - leads);

        if (!total) {
            box.innerHTML = '<span class="wi-tally-seen">'
                + 'Ei shop er kono customer number e WhatsApp nai. Marketing page e '
                + 'Check numbers chalanor por.'
                + '</span>';
            return;
        }

        var html = '<span class="wi-tally-item is-total"><i class="fas fa-users"></i> '
            + total + ' number</span>'
            + '<span class="wi-tally-item is-cust"><i class="fas fa-user-check"></i> '
            + customers + ' customer</span>'
            + '<span class="wi-tally-item is-lead"><i class="fas fa-user-plus"></i> '
            + leads + ' new lead</span>';

        if (state.threadsHasMore) {
            html += '<button type="button" class="wi-tally-seen" id="wiMoreThreads">'
                + (state.threads.length) + ' dekhaychi, ar dekho'
                + '</button>';
        } else if (shown !== undefined && shown < total) {
            html += '<span class="wi-tally-seen">(' + shown + ' dekhay chi)</span>';
        }
        box.innerHTML = html;

        // The button is re-created on every render, so it is bound here rather
        // than once at page load. Delegated from the box so that survives.
        var more = $('wiMoreThreads');
        if (more) {
            more.addEventListener('click', loadMoreThreads);
        }
    }

    function updateUnreadBadge(total) {
        state.unreadTotal = total;
        var badge = $('wiUnreadBadge');
        if (!badge) {
            if (!total) { return; }
            var cardTitle = document.querySelector('.wi-side-head .card-title');
            if (!cardTitle) { return; }
            badge = document.createElement('span');
            badge.className = 'badge badge-danger wi-unread';
            badge.id = 'wiUnreadBadge';
            cardTitle.appendChild(badge);
        }
        badge.textContent = total;
        badge.classList.toggle('d-none', total === 0);
    }

    // ── conversation ─────────────────────────────────────────────────────────

    function openThread(chatId) {
        state.chatId = chatId;
        state.lastId = 0;
        $('wiMessages').innerHTML = '';
        $('wiMain').classList.add('wi-has-chat');
        $('wiChat').classList.remove('d-none');
        $('wiInput').focus();

        var t = state.threads.filter(function (x) { return x.chat_id === chatId; })[0];
        if (t) {
            $('wiChatName').textContent = t.label || t.phone_display;
            $('wiChatPhone').textContent = t.phone_display;
            $('wiChatCustBadge').textContent = 'Customer #' + t.customer_id;
            $('wiChatCustBadge').classList.toggle('d-none', !t.customer_id);
            $('wiChatLeadBadge').classList.toggle('d-none', !t.is_lead);
            $('wiSaveLeadBtn').classList.toggle('d-none', !t.is_lead);
        }

        // A chat with nothing in it needs saying out loud, or an empty white
        // panel looks like a bug rather than a conversation that never started.
        // Counted by class, not by children: the empty-state notice is itself a
        // child of the same box, so children.length would always be > 0.
        var empty = $('wiEmptyThread');
        function bubbleCount() {
            return $('wiMessages').querySelectorAll('.wi-msg').length;
        }
        if (empty) { empty.classList.toggle('d-none', bubbleCount() > 0); }

        loadMessages().then(function () {
            if (empty) { empty.classList.toggle('d-none', bubbleCount() > 0); }
            markRead();
            renderThreads();
        });
    }

    function loadMessages(after) {
        if (!state.chatId) { return Promise.resolve(); }
        var url = API + '?action=messages&chat_id=' + encodeURIComponent(state.chatId)
            + '&after=' + (after === undefined ? state.lastId : after);
        return get(url).then(function (r) {
            if (!r || !r.ok) { return; }
            (r.messages || []).forEach(function (m) {
                state.lastId = Math.max(state.lastId, m.id);
                appendMessage(m);
            });
            if (r.messages && r.messages.length) { scrollToEnd(); }
        });
    }

    function appendMessage(m) {
        var box = $('wiMessages');
        var out = m.direction === 'out';
        var body = m.is_revoked ? '<em>message deleted</em>' : esc(m.body).replace(/\n/g, '<br>');
        var div = document.createElement('div');
        div.className = 'wi-msg ' + (out ? 'is-out' : 'is-in') + (m.is_revoked ? ' is-revoked' : '');
        div.innerHTML = '<div class="wi-bubble">' + body
            + '<span class="wi-msg-time">' + esc(fmtTime(m.created_at)) + '</span></div>';
        box.appendChild(div);
    }

    function scrollToEnd() {
        var box = $('wiMessages');
        if (box) { box.scrollTop = box.scrollHeight; }
    }

    /** Clears the unread flag locally and tells WhatsApp, so ticks turn blue. */
    function markRead() {
        if (!state.chatId) { return; }
        post('mark_read', { chat_id: state.chatId }).then(function (r) {
            if (r && r.ok) {
                var t = state.threads.filter(function (x) { return x.chat_id === state.chatId; })[0];
                if (t) { t.unread = 0; }
            }
        });
    }

    // ── actions ──────────────────────────────────────────────────────────────

    function send() {
        if (state.sending || !state.chatId) { return; }
        var input = $('wiInput');
        var body = input.value.trim();
        if (!body) { return; }

        state.sending = true;
        $('wiSendBtn').disabled = true;
        say('wiSendHint', 'Petechhe…', false);

        post('send', { chat_id: state.chatId, body: body }).then(function (r) {
            state.sending = false;
            $('wiSendBtn').disabled = false;
            if (!r || !r.ok) {
                say('wiSendHint', (r && r.error) || 'Message patha jay ni.', true);
                return;
            }
            input.value = '';
            say('wiSendHint', '', false);
            // Read it back from the database rather than drawing an optimistic
            // bubble: the row that lands is the one WhatsApp accepted, so what
            // the shopkeeper sees is what actually went out.
            loadMessages().then(loadThreads);
        });
    }

    function backfill() {
        if (!state.chatId) { return; }
        var btn = $('wiBackfillBtn');
        btn.disabled = true;
        say('wiSendHint', 'Purono message nichhi…', false);
        post('backfill', { chat_id: state.chatId }).then(function (r) {
            btn.disabled = false;
            if (!r || !r.ok) {
                say('wiSendHint', (r && r.error) || 'History ana jay ni.', true);
                return;
            }
            say('wiSendHint', r.fetched ? (r.stored + ' message esheche.') : 'Ei chat e ar kono purono message nai.', false);
            // Reload the whole thread, since backfill inserts older rows whose ids
            // are below what we have already rendered.
            state.lastId = 0;
            $('wiMessages').innerHTML = '';
            loadMessages().then(loadThreads);
        });
    }

    // This project has no Bootstrap. Its modal is an overlay shown by adding
    // .active, so window.bootstrap.Modal was undefined and every call here
    // silently did nothing.
    function showLeadModal() {
        var el = $('wiLeadModal');
        if (el) { el.classList.add('active'); }
    }

    function hideLeadModal() {
        var el = $('wiLeadModal');
        if (el) { el.classList.remove('active'); }
    }

    function saveLead() {
        if (!state.chatId) { return; }
        var t = state.threads.filter(function (x) { return x.chat_id === state.chatId; })[0];
        $('wiLeadPhone').value = t ? t.phone_display : '';
        $('wiLeadName').value = (t && t.customer_name) || '';
        $('wiLeadName').focus();
        showLeadModal();
    }

    function doSaveLead() {
        var name = $('wiLeadName').value.trim();
        if (!name) { say('wiSendHint', 'Customer er naam lagbe.', true); return; }
        var btn = $('wiLeadSaveBtn');
        btn.disabled = true;
        post('save_lead', {
            chat_id: state.chatId,
            name: name,
            phone: $('wiLeadPhone').value
        }).then(function (r) {
            btn.disabled = false;
            if (!r || !r.ok) {
                say('wiSendHint', (r && r.error) || 'Save kora jay ni.', true);
                return;
            }
            hideLeadModal();
            say('wiSendHint', 'Customer save hoye geche.', false);
            loadThreads();
        });
    }

    // ── bulk send ─────────────────────────────────────────────────────────────
    //
    // Sends through the campaign engine rather than a second sender of its own.
    // The pacing in a bulk WhatsApp send is the safety-critical part - it is what
    // keeps a personal number from looking like a bot to WhatsApp - so it must
    // not be reimplemented here where it can drift away from the Marketing page.
    //
    // The campaign log also keys on customer_id, which is why a row with no
    // customer record cannot be bulk-sent: there is nothing for the send to be
    // recorded against. Those rows show a disabled tick rather than silently
    // failing later.
    var CAMPAIGN_API = 'api/marketing-campaign.php';
    var BATCH = 5;

    function bulkPost(action, data) {
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
        return fetch(CAMPAIGN_API, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, error: 'Server response could not be read.' }; });
    }

    function delay(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    /** The customer ids behind the current selection, in list order. */
    function pickedCustomerIds() {
        return state.threads
            .filter(function (t) { return state.picked[t.chat_id] && t.customer_id; })
            .map(function (t) { return t.customer_id; });
    }

    function openBulkSend() {
        var ids = pickedCustomerIds();
        if (!ids.length) { return; }

        var text = $('wiBulkText').value.trim();
        if (!text) {
            say('wiSendHint', ' prothom bar message likhun.', true);
            $('wiBulkText').focus();
            return;
        }

        // The count is named back before anything is sent. A bulk send to the
        // wrong number of people is not undoable, and the shopkeeper should be
        // able to stop it having read the same number this box shows.
        if (!confirm(ids.length + ' ta customer ke message pathaobe.\n\n' +
            'Ki copy shuru hobe? Bhul hole bondho kora jay.')) {
            return;
        }

        $('wiBulkSendBtn').disabled = true;
        $('wiBulkStopBtn').disabled = false;
        say('wiSendHint', 'Campaign banachhi…', false);

        bulkPost('create', {
            channel: 'whatsapp',
            name: 'Inbox theke bulk',
            message: text,
            customer_ids: ids
        }).then(function (c) {
            if (!c || !c.ok) {
                say('wiSendHint', (c && c.error) || 'Campaign banano jay ni.', true);
                $('wiBulkSendBtn').disabled = false;
                $('wiBulkStopBtn').disabled = true;
                return;
            }
            state.bulkCampaign = c.campaign_id;
            return bulkPost('start', { campaign_id: c.campaign_id }).then(function () {
                return runBulkLoop(c.campaign_id);
            });
        }).catch(function () {
            say('wiSendHint', 'Something went wrong. Abar chalanor por poribort korun.', true);
            $('wiBulkSendBtn').disabled = false;
            $('wiBulkStopBtn').disabled = true;
        });
    }

    function runBulkLoop(campaignId) {
        var stop = false;
        $('wiBulkStopBtn').onclick = function () {
            stop = true;
            bulkPost('cancel', { campaign_id: campaignId });
        };

        function step() {
            if (stop) { return Promise.resolve(); }
            return bulkPost('send', { campaign_id: campaignId, limit: BATCH }).then(function (r) {
                if (!r || !r.ok) {
                    say('wiSendHint', (r && r.error) || 'Pathaona thamte holo.', true);
                    return;
                }
                var p = r.campaign || {};
                say('wiSendHint',
                    'Patha hoye geche ' + (p.sent_count || 0) + '/' + (p.total || 0) +
                    (p.failed_count ? '  (fail ' + p.failed_count + ')' : ''), false);
                if (r.stopped) {
                    say('wiSendHint', 'Campaign shesh. Report Marketing page e dekhben.', false);
                    $('wiBulkSendBtn').disabled = false;
                    $('wiBulkStopBtn').disabled = true;
                    return;
                }
                // Same pacing shape as the Marketing page: a wait between batches
                // so the linked number never looks like it is machine-firing.
                return delay(3000).then(step);
            });
        }
        return step();
    }

    // ── init ─────────────────────────────────────────────────────────────────

    function init() {
        // The bridge is the source of truth for the link, and everything else
        // depends on it, so the first status call has to land before polling
        // starts or the first pull would be skipped as "not connected".
        pollStatus().then(function () {
            loadThreads();
            poll();
            state.statusPoll = setInterval(pollStatus, POLL_MS);
        });

        $('wiSendBtn').addEventListener('click', send);
        $('wiBackfillBtn').addEventListener('click', backfill);
        $('wiEmptyBackfillBtn').addEventListener('click', backfill);
        $('wiSaveLeadBtn').addEventListener('click', saveLead);
        $('wiLeadSaveBtn').addEventListener('click', doSaveLead);
        $('wiLeadClose').addEventListener('click', hideLeadModal);
        $('wiLeadCancel').addEventListener('click', hideLeadModal);
        $('wiLeadModal').addEventListener('click', function (e) {
            if (e.target === this) { hideLeadModal(); }
        });
        $('wiRefreshBtn').addEventListener('click', function () { pollStatus(); pull(); loadThreads(); });
        $('wiUnlinkBtn').addEventListener('click', function () { unlink(); });
        // The "Bridge setup" toggle and its listener were removed with the form.
        // The listener was not optional: $('wiSetupToggle') is null once the
        // button is gone, and calling addEventListener on that throws a TypeError
        // which would take every binding after it down with it - the reload
        // button, the search box, the whole send path - over a control that had
        // already been deleted from the markup.
        $('wiReloadBtn').addEventListener('click', function () { state.seq = 0; pull(); loadThreads(); });
        $('wiBackBtn').addEventListener('click', function () {
            $('wiMain').classList.remove('wi-has-chat');
            $('wiChat').classList.add('d-none');
        });

        $('wiSearch').addEventListener('input', function (e) {
            state.filter = e.target.value.trim().toLowerCase();
            renderThreads();
        });
        $('wiLeadsOnly').addEventListener('change', function (e) {
            state.leadsOnly = e.target.checked;
            renderThreads();
        });

        // Select all only reaches the rows that can actually be bulk-sent. A row
        // with no customer record has nothing for the campaign log to record
        // against, so ticking it would be a promise the send could not keep.
        $('wiSelectAll').addEventListener('change', function (e) {
            if (e.target.checked) {
                state.threads.forEach(function (t) {
                    if (t.customer_id) { state.picked[t.chat_id] = true; }
                });
            } else {
                state.picked = {};
            }
            renderThreads();
            renderBulkBar();
        });

        $('wiBulkSendBtn').addEventListener('click', openBulkSend);
        $('wiBulkSendBtn2').addEventListener('click', openBulkSend);
        $('wiBulkClearBtn').addEventListener('click', function () {
            state.picked = {};
            renderThreads();
            renderBulkBar();
        });

        $('wiInput').addEventListener('keydown', function (e) {
            // Enter sends, Shift+Enter breaks the line. A shopkeeper typing a
            // multi-line reply should not have to hold a modifier to add a line.
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        document.addEventListener('visibilitychange', function () {
            // A tab nobody is looking at does not need to ask the server
            // anything. It catches up as soon as it is opened again.
            if (document.hidden) {
                clearInterval(state.poll);
                state.poll = null;
            } else if (!state.poll) {
                pull();
                loadThreads();
                state.poll = setInterval(poll, POLL_MS);
            }
        });
    }

    function poll() {
        pull();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
