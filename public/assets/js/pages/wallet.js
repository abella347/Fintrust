document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;
    var $ = function (s) { return document.querySelector(s); };

    var form = $('#deposit-form');
    var amountInput = form.querySelector('[name="amount"]');
    var activity = $('[data-activity]');
    var limits = null;
    var walletStatus = 'active';

    var depositKey = FT.newIdempotencyKey();

    function loadWallet() {
        return FT.api('/api/wallet').then(function (w) {
            walletStatus = w.status;
            limits = w.limits;
            UI.bindWallet(w);
            var dl = $('[data-wallet-details]');
            dl.textContent = '';
            [['Wallet number', FT.formatWallet(w.wallet_number)], ['Status', UI.badge(w.status)],
             ['Currency', 'Nigerian Naira (' + w.currency + ')'], ['Opened', FT.formatDate(w.created_at, false)]]
                .forEach(function (r) { dl.appendChild(el('dt', { text: r[0] })); dl.appendChild(el('dd', {}, r[1])); });
            var canDeposit = w.status === 'active' && w.email_verified;
            form.querySelectorAll('input, button').forEach(function (n) { n.disabled = !canDeposit; });
            if (!w.email_verified) FT.formAlert(form, 'Verify your email address (see Settings) before adding money.', 'warning');
            renderLimits(w.limits);
        }).catch(function (err) {
            UI.errorState($('[data-limits]'), err.message, loadWallet);
        });
    }

    function renderLimits(l) {
        var box = $('[data-limits]');
        box.textContent = '';
        var used = Math.min(100, Math.round(l.sent_today_kobo / l.transfer_daily_limit_kobo * 100));
        var meter = el('div', { class: 'meter', role: 'meter', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': String(used), 'aria-label': 'Daily transfer limit used' }, el('span'));
        meter.firstChild.style.width = used + '%';
        box.appendChild(el('p', { class: 'limit-head' },
            el('span', { text: 'Sent today' }),
            el('strong', { text: FT.formatNaira(l.sent_today_kobo) + ' of ' + FT.formatNaira(l.transfer_daily_limit_kobo) })));
        box.appendChild(meter);
        var dl = el('dl', { class: 'kv mt-4' });
        [['Minimum transfer', FT.formatNaira(l.transfer_min_kobo)], ['Maximum per transfer', FT.formatNaira(l.transfer_max_single_kobo)],
         ['Daily transfer limit', FT.formatNaira(l.transfer_daily_limit_kobo)], ['Maximum deposit', FT.formatNaira(l.deposit_max_single_kobo)]]
            .forEach(function (r) { dl.appendChild(el('dt', { text: r[0] })); dl.appendChild(el('dd', { text: r[1] })); });
        box.appendChild(dl);
    }

    function loadActivity() {
        UI.skeletonList(activity, 4);
        FT.api('/api/transactions?per_page=5').then(function (d) {
            activity.removeAttribute('aria-busy');
            UI.renderList(activity, d.items, { title: 'No activity yet', message: 'Your deposits and transfers will appear here.' });
        }).catch(function (err) { UI.errorState(activity, err.message, loadActivity); });
    }

    // Quick-amount chips fill the input.
    document.querySelectorAll('[data-quick-amount]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            amountInput.value = Number(chip.getAttribute('data-quick-amount')).toLocaleString('en-NG');
            amountInput.dispatchEvent(new Event('input'));
            amountInput.focus();
        });
    });

    FT.handleForm(form, {
        validate: function (v) {
            if (!FT.rules.amount(v.amount)) return { amount: 'Enter an amount like 5000 or 5000.50.' };
            var kobo = FT.amountToKobo(v.amount);
            var max = limits ? limits.deposit_max_single_kobo : 100000000;
            if (kobo < 10000 || kobo > max) return { amount: 'Deposits must be between ₦100.00 and ' + FT.formatNaira(max) + '.' };
            return {};
        },
        request: function (v) {
            return FT.api('/api/wallet/deposit', { method: 'POST', body: { amount: v.amount.replace(/[,\s₦]/g, '') }, headers: { 'Idempotency-Key': depositKey } });
        },
        onSuccess: function (data) {
            depositKey = FT.newIdempotencyKey(); // Definite answer received: next deposit is a new attempt
            UI.setBalance(data.balance_kobo);
            form.reset();
            FT.toast(FT.formatNaira(data.transaction.amount_kobo) + ' added to your wallet', 'success');
            loadActivity();
        },
        onError: function (err) {
            // Network failures keep the same key so a retry can't double-credit.
            if (err.status >= 400 && err.status < 500) depositKey = FT.newIdempotencyKey();
        }
    });

    // Arriving from "Add Money" on the dashboard: wallet#add-money
    if (location.hash === '#add-money') {
        setTimeout(function () { amountInput.focus(); }, 300);
    }
    if (location.hash === '#receive') {
        document.getElementById('receive-dialog').showModal();
    }

    loadWallet();
    loadActivity();
});
