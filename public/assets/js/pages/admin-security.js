/* Admin: security monitoring - lockouts, suspensions, frozen wallets, failed logins. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var REASONS = { bad_password: 'Wrong password', unknown_email: 'Unknown email', locked: 'Account locked', suspended: 'Account suspended' };

    function list(name, items, line) {
        var ul = document.querySelector('[data-list="' + name + '"]');
        ul.textContent = '';
        if (!items.length) { ul.appendChild(el('li', { class: 'text-muted', text: 'None right now.' })); return; }
        items.forEach(function (x) { ul.appendChild(line(x)); });
    }

    function person(x) { return A.userLink({ public_id: x.public_id, name: x.name }); }

    function load() {
        FT.api('/api/admin/security').then(function (d) {
            list('locked_accounts', d.locked_accounts, function (x) {
                return el('li', {}, person(x), el('span', { class: 'text-muted', text: x.email }), el('small', { text: 'until ' + FT.formatDate(x.locked_until) }));
            });
            list('pin_locked', d.pin_locked, function (x) {
                return el('li', {}, person(x), el('span', { class: 'text-muted', text: x.email }), el('small', { text: 'until ' + FT.formatDate(x.locked_until) }));
            });
            list('suspended', d.suspended, function (x) {
                return el('li', {}, person(x), el('span', { class: 'text-muted', text: x.email }), el('small', { text: 'since ' + FT.formatDate(x.updated_at) }));
            });
            list('frozen_wallets', d.frozen_wallets, function (x) {
                return el('li', {}, el('span', { class: 'mono', text: FT.formatWallet(x.wallet_number) }), person(x), el('small', { text: 'since ' + FT.formatDate(x.updated_at) }));
            });

            var ips = document.querySelector('[data-rows="top_ips"]');
            if (!d.top_ips.length) A.emptyRow(ips, 4, 'No failed logins in the last 24 hours.');
            else {
                ips.textContent = '';
                d.top_ips.forEach(function (x) {
                    ips.appendChild(el('tr', { class: x.accounts >= 3 ? 'is-flagged' : '' },
                        el('td', { 'data-label': 'IP address', class: 'mono', text: x.ip_address }),
                        el('td', { 'data-label': 'Failures', class: 'num', text: String(x.failures) }),
                        el('td', { 'data-label': 'Accounts tried', class: 'num', text: String(x.accounts) }),
                        el('td', { 'data-label': 'Last attempt', text: FT.formatDate(x.last_attempt) })));
                });
            }

            var fails = document.querySelector('[data-rows="recent_failures"]');
            if (!d.recent_failures.length) A.emptyRow(fails, 5, 'No failed logins recorded.');
            else {
                fails.textContent = '';
                d.recent_failures.forEach(function (x) {
                    fails.appendChild(el('tr', {},
                        el('td', { 'data-label': 'Email tried' }, x.public_id ? A.userLink({ public_id: x.public_id, name: x.email }) : document.createTextNode(x.email)),
                        el('td', { 'data-label': 'Reason', text: REASONS[x.failure_reason] || x.failure_reason || '—' }),
                        el('td', { 'data-label': 'IP address', class: 'mono', text: x.ip_address }),
                        el('td', { 'data-label': 'Browser', text: x.user_agent.slice(0, 50) }),
                        el('td', { 'data-label': 'When', class: 'nowrap', text: FT.formatDate(x.attempted_at) })));
                });
            }
        }).catch(function (err) { FT.toast(err.message, 'error'); });
    }
    load();
});
