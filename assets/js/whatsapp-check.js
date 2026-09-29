/**
 * WhatsApp check for a freshly saved customer.
 *
 * The save itself never waits on this. A customer row is written first and the
 * POS carries on with the sale; the WhatsApp probe is fired afterwards and its
 * answer is cached on the row, so the marketing module can read it later without
 * asking WhatsApp a second time.
 *
 * Shared by admin/pos.php and cashier/pos.php. The endpoint comes in through a
 * data attribute because the two screens sit at different depths.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var endpoint = (script && script.dataset.checkEndpoint) || 'api/check-whatsapp.php';

    /**
     * Ask the server whether this customer's number is on WhatsApp.
     *
     * Never rejects and never throws: a failed check is a normal outcome here,
     * not an error worth interrupting a sale for. The callback receives
     * { state: 'yes' | 'no' | 'unknown', message }.
     */
    function verify(customerId, callback) {
        if (!customerId) { return; }

        var body = new FormData();
        body.append('customer_id', customerId);

        fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    callback({ state: 'unknown', message: (res && res.message) || 'Check failed.' });
                    return;
                }
                if (res.has_whatsapp === true) {
                    callback({ state: 'yes', message: res.phone + ' — WhatsApp ache.' });
                } else if (res.has_whatsapp === false) {
                    callback({ state: 'no', message: (res.phone || '') + ' — eo number e WhatsApp nai.' });
                } else {
                    callback({ state: 'unknown', message: res.message || 'WhatsApp check kora jayni.' });
                }
            })
            .catch(function () {
                callback({ state: 'unknown', message: 'WhatsApp check kora jayni.' });
            });
    }

    /** Small fixed toast, self-contained so the POS markup stays untouched */
    function toast(message, state) {
        var box = document.createElement('div');
        box.className = 'wa-toast wa-toast-' + state;
        box.textContent = message;
        document.body.appendChild(box);

        setTimeout(function () { box.classList.add('wa-toast-out'); }, 3200);
        setTimeout(function () { box.remove(); }, 3700);
    }

    /** Fire the check and report it, remembering the answer on the customer */
    function verifyAndReport(customer) {
        if (!customer) { return; }
        verify(customer.id, function (result) {
            customer.has_whatsapp = result.state === 'yes' ? 1 : (result.state === 'no' ? 0 : null);
            toast(result.message, result.state);
        });
    }

    window.PosWhatsApp = { verify: verify, verifyAndReport: verifyAndReport, toast: toast };
})();
