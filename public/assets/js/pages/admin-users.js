/* Admin: user search and management (details + actions open in a dialog). */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var rows = document.querySelector('[data-rows]');
    var pager = document.querySelector('[data-pager]');

    var filters = A.bindFilters(document.getElementById('filters'), function (q) {
        q.set('per_page', '15');
        A.loadingRows(rows, 7);
        FT.api('/api/admin/users?' + q).then(function (d) {
            if (!d.items.length) { A.emptyRow(rows, 7, 'No users match these filters.'); pager.hidden = true; return; }
            rows.textContent = '';
            d.items.forEach(function (u) {
                var view = el('button', { type: 'button', class: 'btn btn-sm btn-outline', text: 'View', 'aria-label': 'View ' + u.name });
                view.addEventListener('click', function () { A.openUser(u.public_id, function () { filters.run(false); }); });
                var staff = u.roles.indexOf('user') < 0;
                rows.appendChild(el('tr', {},
                    el('td', { 'data-label': 'User' }, el('div', { class: 'cell-stack' },
                        el('strong', { text: u.name }), el('small', { class: 'text-muted', text: u.email }),
                        staff ? el('small', {}, A.badge(u.roles.join(', '), 'badge--info')) : null)),
                    el('td', { 'data-label': 'Wallet', class: 'mono' }, u.wallet ? document.createTextNode(FT.formatWallet(u.wallet.wallet_number)) : document.createTextNode('—'),
                        u.wallet && u.wallet.status !== 'active' ? A.statusBadge(u.wallet.status) : null),
                    el('td', { 'data-label': 'Balance', class: 'num tabular', text: u.wallet ? FT.formatNaira(u.wallet.balance_kobo) : '—' }),
                    el('td', { 'data-label': 'Status' }, A.statusBadge(u.status), u.locked ? A.badge('locked', 'badge--warning') : null,
                        u.email_verified ? null : A.badge('unverified', 'badge--neutral')),
                    el('td', { 'data-label': 'Alerts' }, u.open_alerts ? A.badge(String(u.open_alerts) + ' open', 'badge--danger') : document.createTextNode('—')),
                    el('td', { 'data-label': 'Joined', class: 'nowrap', text: FT.formatDate(u.created_at, false) }),
                    el('td', { 'data-label': '' }, view)));
            });
            A.renderPager(pager, d.pagination, filters.setPage);
        }).catch(function (err) {
            if (err.fields) FT.showErrors(document.getElementById('filters'), err.fields);
            A.emptyRow(rows, 7, err.message);
        });
    });
    filters.run(false);
});
