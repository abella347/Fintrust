/* Admin overview: GET /api/admin/statistics fills every card. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var $ = function (s) { return document.querySelector(s); };

    function stat(id, value, hint) {
        $(id).textContent = value;
        var h = $(id).parentNode.querySelector('.stat-card__hint');
        h.hidden = !hint;
        h.textContent = hint || '';
    }

    function txnRow(t) {
        return el('tr', {},
            el('td', { 'data-label': 'Reference' }, A.txnLink(t.reference)),
            el('td', { 'data-label': 'From → To' }, A.userLink(t.sender), document.createTextNode(' → '), A.userLink(t.recipient)),
            el('td', { 'data-label': 'Type', text: t.type }),
            el('td', { 'data-label': 'Status' }, A.statusBadge(t.status), t.flagged ? A.badge('flagged', 'badge--danger') : null),
            el('td', { 'data-label': 'Date', class: 'nowrap', text: FT.formatDate(t.created_at) }),
            el('td', { 'data-label': 'Amount', class: 'num tabular', text: FT.formatNaira(t.amount_kobo) }));
    }

    function load(fresh) {
        A.loadingRows($('[data-rows]'), 6);
        FT.api('/api/admin/statistics' + (fresh ? '?fresh=1' : '')).then(function (d) {
            // Figures are cached on the server for up to 60 seconds (the ledger checks read every row).
            $('[data-generated]').textContent = 'Figures as of ' + FT.formatDate(d.generated_at);
            // Ledger health: the three double-entry invariants, checked live.
            var L = d.ledger;
            var ok = L.unbalanced_transactions === 0 && L.wallets_out_of_step === 0 && L.system_total_kobo === 0;
            var health = $('[data-health]');
            health.textContent = '';
            health.className = 'health ' + (ok ? 'health--ok' : 'health--bad');
            health.appendChild(UI.svg(ok ? 'check' : 'alert', 20));
            health.appendChild(el('p', {},
                el('strong', { text: ok ? 'Ledger balanced' : 'Ledger needs attention' }),
                document.createTextNode(' · ' + L.unbalanced_transactions + ' unbalanced transactions · ' + L.wallets_out_of_step +
                    ' wallets out of step · all wallets sum to ' + FT.formatNaira(L.system_total_kobo))));

            stat('#st-users', String(d.users.total), d.users.suspended + ' suspended · ' + d.users.new_7_days + ' new this week');
            stat('#st-wallets', String(d.wallets.active), d.wallets.frozen + ' frozen · ' + FT.formatNaira(d.wallets.customer_balances_kobo) + ' held');
            stat('#st-volume', FT.formatNaira(d.transactions.volume_kobo), FT.formatNaira(d.transactions.volume_30d_kobo) + ' in the last 30 days');
            var total = d.transactions.successful + d.transactions.failed;
            stat('#st-txns', String(total), d.transactions.successful + ' successful · ' + d.transactions.failed + ' failed');
            stat('#st-fraud', String(d.fraud.open), d.fraud.critical + ' critical · ' + d.fraud.high + ' high');
            stat('#st-logins', String(d.security.failed_logins_24h), d.security.locked_accounts + ' accounts locked · ' + d.security.pin_locked + ' PINs locked');

            UI.groupedBars($('[data-chart]'), $('[data-chart-table]'), d.daily, {
                ariaLabel: 'Bar chart of daily transfer and deposit volume for the last 14 days. Use Show as table for exact values.',
                caption: 'Daily volume',
                rowHeader: function (r) { return r.date; },
                series: [{ key: 'transfers_kobo', label: 'Transfers', cls: 'out' }, { key: 'deposits_kobo', label: 'Deposits', cls: 'in' }]
            });

            var list = $('[data-alerts]');
            list.textContent = '';
            if (!d.recent_alerts.length) list.appendChild(el('li', { class: 'text-muted', text: 'No open alerts.' }));
            d.recent_alerts.forEach(function (a) {
                list.appendChild(el('li', { class: 'alert-item alert-item--' + a.severity },
                    el('div', { class: 'cluster' }, A.severityBadge(a.severity), el('strong', { class: 'mono text-sm', text: a.rule_code })),
                    el('p', { text: a.reason }),
                    el('small', { class: 'text-muted' }, a.user ? A.userLink(a.user) : document.createTextNode('No account'), document.createTextNode(' · ' + FT.formatDate(a.created_at)))));
            });

            var rows = $('[data-rows]');
            rows.textContent = '';
            d.recent_transactions.forEach(function (t) { rows.appendChild(txnRow(t)); });
        }, function (err) {
            UI.errorState($('[data-health]'), err.message, function () { load(true); });
        });
    }

    $('[data-refresh]').addEventListener('click', function (e) {
        var b = e.currentTarget;
        FT.setBusy(b, true);
        load(true);
        setTimeout(function () { FT.setBusy(b, false); }, 600);
    });

    $('[data-chart-table-toggle]').addEventListener('click', function (e) {
        var t = $('[data-chart-table]');
        t.hidden = !t.hidden;
        e.currentTarget.setAttribute('aria-pressed', String(!t.hidden));
        e.currentTarget.textContent = t.hidden ? 'Show as table' : 'Hide table';
    });
    load(false);
});
