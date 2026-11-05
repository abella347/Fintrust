/* Developer pages (setup check + style guide). Local only. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;

    // Setup check: call the health endpoint through the shared API client.
    var ping = document.getElementById('ping-api');
    var out = document.getElementById('ping-result');
    if (ping && out) {
        ping.addEventListener('click', function () {
            FT.setBusy(ping, true);
            out.textContent = 'Calling…';
            FT.api('/api/health')
                .then(function (data) { out.textContent = JSON.stringify(data, null, 2); FT.toast('API is reachable', 'success'); })
                .catch(function (err) { out.textContent = err.status + ' ' + err.code + ': ' + err.message; FT.toast(err.message, 'error'); })
                .finally(function () { FT.setBusy(ping, false); });
        });
    }

    // Style guide demos
    document.querySelectorAll('[data-demo-toast]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.getAttribute('data-demo-toast');
            FT.toast({ success: 'Transfer of ₦15,000.50 sent', error: 'Incorrect transaction PIN', warning: 'You are close to your daily limit', info: 'New login from Chrome on Windows' }[type], type);
        });
    });

    var confirmBtn = document.getElementById('demo-confirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            FT.confirm({ title: 'Send ₦15,000.50 to Ben Adeyemi?', message: 'Wallet 302 145 6789. This cannot be undone.', confirmText: 'Send money' })
                .then(function (ok) { FT.toast(ok ? 'Confirmed' : 'Cancelled', ok ? 'success' : 'info'); });
        });
    }

    var busyBtn = document.getElementById('demo-busy');
    if (busyBtn) {
        busyBtn.addEventListener('click', function () {
            FT.setBusy(busyBtn, true);
            setTimeout(function () { FT.setBusy(busyBtn, false); }, 1500);
        });
    }

    var maskBtn = document.getElementById('demo-mask');
    var amount = document.getElementById('demo-balance');
    if (maskBtn && amount) {
        var real = amount.textContent;
        maskBtn.addEventListener('click', function () {
            var masked = amount.classList.toggle('is-masked');
            amount.textContent = masked ? '₦ • • • • • •' : real;
            maskBtn.setAttribute('aria-pressed', String(masked));
            maskBtn.setAttribute('aria-label', masked ? 'Show balance' : 'Hide balance');
        });
    }

    var fmtIn = document.getElementById('demo-kobo');
    var fmtOut = document.getElementById('demo-kobo-out');
    if (fmtIn && fmtOut) {
        var update = function () { fmtOut.textContent = FT.formatNaira(parseInt(fmtIn.value || '0', 10)); };
        fmtIn.addEventListener('input', update);
        update();
    }
});
