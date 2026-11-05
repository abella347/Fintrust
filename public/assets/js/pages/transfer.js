document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;
    var $ = function (s) { return document.querySelector(s); };

    var details = $('#transfer-details');
    var pinForm = $('#transfer-pin');
    var walletInput = details.querySelector('[name="recipient_wallet_number"]');
    var amountInput = details.querySelector('[name="amount"]');
    var descInput = details.querySelector('[name="description"]');
    var recipientBox = $('[data-recipient]');

    var state = { wallet: null, recipient: null, amountKobo: 0, values: null, key: null, lastResult: null };

    /* Navigation */
    function go(step) {
        document.querySelectorAll('[data-step]').forEach(function (s) { s.hidden = s.getAttribute('data-step') !== String(step); });
        document.querySelectorAll('[data-step-indicator]').forEach(function (li) {
            var n = parseInt(li.getAttribute('data-step-indicator'), 10);
            li.classList.toggle('is-current', n === step);
            li.classList.toggle('is-done', n < step);
            if (n === step) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        });
        var heading = document.querySelector('[data-step="' + step + '"] h2');
        if (heading && step > 1) heading.focus();
        if (step === 3) setTimeout(function () {
            var target = method() === 'pin' ? pinForm.querySelector('[name="pin"]') : pinForm.querySelector('[name="method"]:checked');
            if (target) target.focus();
        }, 50);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.querySelectorAll('[data-back]').forEach(function (b) {
        b.addEventListener('click', function () {
            var to = parseInt(b.getAttribute('data-back'), 10);
            if (to === 1) state.key = null; // Editing = new attempt
            go(to);
        });
    });

    /* Load wallet + limits */
    function loadWallet() {
        FT.api('/api/wallet').then(function (w) {
            state.wallet = w;
            enableBiometric(w.has_biometric);
            // Don't offer your own wallet as a demo recipient.
            document.querySelectorAll('[data-fill-wallet="' + w.wallet_number + '"]').forEach(function (chip) { chip.hidden = true; });
            $('[data-bind="available"]').textContent = FT.formatNaira(w.balance_kobo);
            document.getElementById('f-amount-help').textContent =
                'Min ' + FT.formatNaira(w.limits.transfer_min_kobo) + ' · max ' + FT.formatNaira(w.limits.transfer_max_single_kobo) +
                ' per transfer · ' + FT.formatNaira(Math.max(0, w.limits.transfer_daily_limit_kobo - w.limits.sent_today_kobo)) + ' left today.';
            // The server enforces all of these too; this just explains it up front.
            var blocker = w.status !== 'active' ? 'Your wallet is ' + w.status + '. Transfers are paused.'
                : !w.email_verified ? 'Verify your email address before sending money (Settings → Personal information).'
                : !w.has_pin ? 'Set your 4-digit transaction PIN in Settings before sending money.'
                : null;
            if (blocker) {
                FT.formAlert(details, blocker, 'warning');
                details.querySelectorAll('input, button').forEach(function (n) { n.disabled = true; });
                var box = details.querySelector('[data-form-alert] .alert p');
                if (box && w.status === 'active') {
                    box.appendChild(document.createTextNode(' '));
                    box.appendChild(UI.el('a', { href: FT.url(w.email_verified ? 'settings#pin' : 'settings#profile'), text: 'Open settings' }));
                }
            }
        }).catch(function (err) { FT.formAlert(details, err.message, 'error'); });
    }

    /* Recipient lookup (confirm the name before sending) */
    var lookupTimer = null;
    var lookupSeq = 0;
    function setRecipient(r, message, isError) {
        state.recipient = r;
        recipientBox.hidden = !(r || message);
        recipientBox.className = 'recipient' + (isError ? ' recipient--error' : (r ? ' recipient--ok' : ''));
        recipientBox.textContent = '';
        if (r) {
            recipientBox.appendChild(UI.svg('check', 18));
            recipientBox.appendChild(el('span', {}, el('strong', { text: r.account_name }), ' · ' + FT.formatWallet(r.wallet_number)));
        } else if (message) {
            recipientBox.appendChild(el('span', { text: message }));
        }
    }

    function lookup() {
        var number = walletInput.value.replace(/\D/g, '');
        if (number.length !== 10) { setRecipient(null); return Promise.resolve(null); }
        var seq = ++lookupSeq;
        setRecipient(null, 'Checking wallet…');
        return FT.api('/api/wallet/lookup?wallet_number=' + encodeURIComponent(number)).then(function (r) {
            if (seq !== lookupSeq) return null; // A newer lookup superseded this one
            setRecipient(r);
            walletInput.removeAttribute('aria-invalid');
            document.getElementById('f-recipient_wallet_number-error').textContent = '';
            return r;
        }).catch(function (err) {
            if (seq !== lookupSeq) return null;
            setRecipient(null, (err.fields && err.fields.recipient_wallet_number) || err.message, true);
            return null;
        });
    }

    // Demo wallet chips (local development only): fill the number and look it up.
    document.querySelectorAll('[data-fill-wallet]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            walletInput.value = chip.getAttribute('data-fill-wallet');
            walletInput.dispatchEvent(new Event('input', { bubbles: true }));
            details.querySelector('[name="amount"]').focus();
        });
    });

    walletInput.addEventListener('input', function () {
        // Keep digits only, and group as 302 145 6789 for readability
        var digits = walletInput.value.replace(/\D/g, '').slice(0, 10);
        walletInput.value = FT.formatWallet(digits) === '—' ? '' : (digits.length === 10 ? FT.formatWallet(digits) : digits);
        clearTimeout(lookupTimer);
        state.recipient = null;
        lookupTimer = setTimeout(lookup, 350);
    });

    /* Step 1 submit */
    details.addEventListener('submit', function (e) {
        e.preventDefault();
        FT.clearErrors(details);
        var v = FT.formValues(details);
        var problems = {};
        var number = v.recipient_wallet_number.replace(/\D/g, '');
        if (!FT.rules.walletNumber(number)) problems.recipient_wallet_number = 'Enter a 10-digit wallet number.';
        var kobo = FT.rules.amount(v.amount) ? FT.amountToKobo(v.amount) : null;
        var w = state.wallet;
        if (kobo === null) problems.amount = 'Enter an amount like 5000 or 5000.50.';
        else if (w && kobo < w.limits.transfer_min_kobo) problems.amount = 'The minimum transfer is ' + FT.formatNaira(w.limits.transfer_min_kobo) + '.';
        else if (w && kobo > w.limits.transfer_max_single_kobo) problems.amount = 'The maximum per transfer is ' + FT.formatNaira(w.limits.transfer_max_single_kobo) + '.';
        else if (w && kobo > w.balance_kobo) problems.amount = 'This is more than your available balance (' + FT.formatNaira(w.balance_kobo) + ').';
        if (v.description.length > 140) problems.description = 'Keep it under 140 characters.';
        if (FT.showErrors(details, problems)) return;

        var button = details.querySelector('[type="submit"]');
        FT.setBusy(button, true);
        (state.recipient && state.recipient.wallet_number === number ? Promise.resolve(state.recipient) : lookup())
            .then(function (r) {
                if (!r) { FT.showErrors(details, { recipient_wallet_number: recipientBox.textContent || 'Check the wallet number.' }); return; }
                state.values = { recipient_wallet_number: number, amount: v.amount.replace(/[,\s₦]/g, ''), description: v.description };
                state.amountKobo = kobo;
                renderReview();
                go(2);
            })
            .finally(function () { FT.setBusy(button, false); });
    });

    function kvList(target, rows) {
        target.textContent = '';
        rows.forEach(function (r) { target.appendChild(el('dt', { text: r[0] })); target.appendChild(el('dd', { text: r[1] })); });
    }

    function renderReview() {
        $('[data-bind="review-amount"]').textContent = FT.formatNaira(state.amountKobo);
        kvList($('[data-bind="review-list"]'), [
            ['To', state.recipient.account_name],
            ['Wallet number', FT.formatWallet(state.recipient.wallet_number)],
            ['Amount', FT.formatNaira(state.amountKobo)],
            ['Fee', '₦0.00'],
            ['Total debit', FT.formatNaira(state.amountKobo)],
            ['Description', state.values.description || '—'],
            ['Balance after', FT.formatNaira(state.wallet.balance_kobo - state.amountKobo)]
        ]);
        $('[data-bind="pin-amount"]').textContent = FT.formatNaira(state.amountKobo);
        $('[data-bind="pin-name"]').textContent = state.recipient.account_name;
        updateSendButton();
    }

    /* PIN or Face ID / fingerprint */
    var bioReady = false;   // This account has a registered device and this browser supports it
    function method() {
        var picked = pinForm.querySelector('[name="method"]:checked');
        return bioReady && picked ? picked.value : 'pin';
    }
    function updateSendButton() {
        var amount = state.amountKobo ? ' ' + FT.formatNaira(state.amountKobo) : '';
        $('[data-bind="send-button"]').textContent = method() === 'biometric' ? 'Approve and send' + amount : 'Send' + amount;
        pinForm.querySelector('[data-pin-block]').hidden = method() !== 'pin';
        pinForm.querySelector('[data-bio-block]').hidden = method() !== 'biometric';
    }
    pinForm.querySelectorAll('[name="method"]').forEach(function (r) {
        r.addEventListener('change', function () { FT.clearErrors(pinForm); updateSendButton(); });
    });
    function enableBiometric(hasDevice) {
        if (!hasDevice || !window.FTBio) return;
        window.FTBio.available().then(function (ok) {
            if (!ok) return;
            bioReady = true;
            pinForm.querySelector('[data-auth-method]').hidden = false;
            pinForm.querySelector('[name="method"][value="biometric"]').checked = true;   // Preferred when set up
            updateSendButton();
        });
    }

    $('[data-goto="3"]').addEventListener('click', function () {
        if (!state.key) state.key = FT.newIdempotencyKey(); // The attempt starts here
        pinForm.reset();                                     // Clears any old PIN...
        if (bioReady) pinForm.querySelector('[name="method"][value="biometric"]').checked = true; // ...but keep Face ID as the default
        updateSendButton();
        FT.clearErrors(pinForm);
        go(3);
    });

    /* Send */
    var pinInput = pinForm.querySelector('[name="pin"]');
    pinInput.addEventListener('input', function () { pinInput.value = pinInput.value.replace(/\D/g, '').slice(0, 4); });

    FT.handleForm(pinForm, {
        validate: function (v) {
            if (method() === 'biometric') return {};
            return FT.rules.pin(v.pin) ? {} : { pin: 'Enter your 4-digit PIN.' };
        },
        request: function (v) {
            var send = function (auth) {
                return FT.api('/api/transactions/transfer', {
                    method: 'POST',
                    headers: { 'Idempotency-Key': state.key },
                    body: Object.assign({}, state.values, auth)
                });
            };
            if (method() !== 'biometric') return send({ pin: v.pin });
            // 1. one-time challenge tied to this transfer  2. face/finger prompt  3. send with the signature
            return FT.api('/api/transactions/transfer/options', { method: 'POST', headers: { 'Idempotency-Key': state.key }, body: state.values })
                .then(function (options) { return window.FTBio.get(options); })
                .then(function (approval) { return send({ webauthn: approval }); });
        },
        onSuccess: function (data) {
            pinInput.value = '';            // Never keep the PIN around
            state.key = null;
            state.lastResult = data.transaction;
            state.wallet.balance_kobo = data.balance_kobo;
            showResult(data.transaction, data.balance_kobo);
            go(4);
        },
        onError: function (err) {
            pinInput.value = '';
            var aboutTransfer = ['INSUFFICIENT_FUNDS', 'LIMIT_EXCEEDED', 'WALLET_NOT_FOUND', 'WALLET_UNAVAILABLE', 'SELF_TRANSFER', 'VALIDATION_FAILED'].indexOf(err.code) >= 0;
            if (aboutTransfer && !(err.fields && err.fields.pin)) {
                state.key = null;
                go(1);
                FT.showErrors(details, err.fields || {});
                FT.formAlert(details, err.message, 'error');
                loadWallet();
                return true;
            }
            if (err.code === 'PIN_LOCKED') {
                pinForm.querySelectorAll('input, [type="submit"]').forEach(function (n) { n.disabled = true; });
            }
            return false;
        }
    });

    /* Step 4 */
    function showResult(t, balance) {
        $('[data-bind="result-amount"]').textContent = FT.formatNaira(t.amount_kobo);
        $('[data-bind="result-to"]').textContent = 'sent to ' + t.counterparty.name;
        kvList($('[data-bind="result-list"]'), [
            ['Status', 'Successful'],
            ['Reference', t.reference],
            ['Recipient', t.counterparty.name + ' · ' + FT.formatWallet(t.counterparty.wallet_number)],
            ['Date & time', FT.formatDate(t.created_at)],
            ['Description', t.description || '—'],
            ['New balance', FT.formatNaira(balance)]
        ]);
    }
    $('[data-result-receipt]').addEventListener('click', function () { UI.downloadReceipt(state.lastResult); });
    $('[data-result-details]').addEventListener('click', function () { UI.openDetails(state.lastResult); });
    $('[data-new-transfer]').addEventListener('click', function () {
        details.reset();
        setRecipient(null);
        FT.clearErrors(details);
        $('[data-bind="available"]').textContent = FT.formatNaira(state.wallet.balance_kobo);
        go(1);
        walletInput.focus();
    });

    // Prefill from a link such as transfer?to=3098765432
    var to = new URLSearchParams(location.search).get('to');
    if (to && /^\d{10}$/.test(to)) { walletInput.value = FT.formatWallet(to); lookup(); }

    loadWallet();
});
