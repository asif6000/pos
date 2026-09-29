/**
 * Duplicate-number hint for the customer phone field.
 *
 * While a number is being typed, asks the server whether it is already saved and
 * says so underneath the field. Nothing is blocked here - some numbers really do
 * belong to two people (a family sharing one handset), and the shopkeeper is the
 * only one who can tell. It reports, it does not decide.
 *
 * The endpoint arrives through a data attribute because the three screens that
 * have this field sit at different depths under the web root.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var endpoint = (script && script.dataset.lookupEndpoint) || 'api/customer-lookup.php';

    var MIN_DIGITS = 7;   // matches the server: below this it is a prefix, not a number

    function digits(s) { return String(s == null ? '' : s).replace(/[^0-9]/g, ''); }

    /**
     * Look a number up.
     *
     * Never rejects: a lookup that fails is not worth interrupting typing for.
     * Calls back with { matches: [...] } and an empty list on any problem.
     */
    function find(phone, excludeId, callback) {
        var d = digits(phone);
        if (d.length < MIN_DIGITS) { callback({ matches: [] }); return; }

        var url = endpoint + '?phone=' + encodeURIComponent(phone) +
            (excludeId ? '&exclude_id=' + encodeURIComponent(excludeId) : '');

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                callback(res && res.ok ? res : { matches: [] });
            })
            .catch(function () { callback({ matches: [] }); });
    }

    /**
     * Wire a phone input up to a hint element.
     *
     * @param input    the phone field
     * @param hint     the element the message is written into
     * @param opts     { excludeId: function|number, onChange: function(matches) }
     */
    function attach(input, hint, opts) {
        opts = opts || {};
        if (!input || !hint) { return; }

        var timer = null;
        var lastMatches = [];
        // Guards against a slow reply overwriting a newer one, which would leave
        // the hint describing a number the field no longer holds.
        var seq = 0;

        function render(matches) {
            if (!matches.length) {
                hint.textContent = '';
                hint.className = hint.className.replace(/\s*is-dup\S*/g, '');
                return;
            }

            var names = matches.map(function (m) { return m.name; });
            var unique = names.filter(function (n, i) { return names.indexOf(n) === i; });
            var label = unique.length === 1
                ? unique[0]
                : unique.length + ' jon';

            var orphan = matches.filter(function (m) { return m.orphaned; });
            var tail = orphan.length
                ? ' (kono shop er sathe jode hoy nai)'
                : '';

            hint.className = hint.className.replace(/\s*is-dup\S*/g, '') +
                (orphan.length ? ' is-dup-orphan' : ' is-dup');
            hint.innerHTML = '<i class="fas fa-circle-exclamation"></i> Ei number agei save ache — <strong>' +
                label + '</strong>' + tail + '. Obosthay nije name save korun.';
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var mine = ++seq;
            timer = setTimeout(function () {
                find(input.value, opts.excludeId, function (res) {
                    if (mine !== seq) { return; }   // a newer keystroke won
                    lastMatches = res.matches || [];
                    render(lastMatches);
                    if (opts.onChange) { opts.onChange(lastMatches); }
                });
            }, 350);
        });

        // Cleared on blur too: leaving a stale warning on screen after the field
        // has moved on reads as a claim about the wrong number.
        input.addEventListener('blur', function () {
            clearTimeout(timer);
            if (lastMatches.length) { render(lastMatches); }
        });

        input.addEventListener('change', function () {
            find(input.value, opts.excludeId, function (res) {
                lastMatches = res.matches || [];
                render(lastMatches);
            });
        });

        return {
            matches: function () { return lastMatches; }
        };
    }

    window.PosCustomerDup = { find: find, attach: attach };
})();
