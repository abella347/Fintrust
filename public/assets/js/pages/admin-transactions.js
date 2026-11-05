/* Admin: every transaction in the system, with filters and ledger detail. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var form = document.getElementById('filters');
    var rows = document.querySelector('[data-rows]');
    var pager = document.querySelector('[data-pager]');

    var filters = A.bindFilters(form, function (q) {
        var from = form.elements.from.value, to = form.elements.to.value;
        if (from && to && from > to) { FT.showErrors(form, { from: '“From” must be on or before “To”.' }); return; }
        A.loadingRows(rows, 7);
        FT.api('/api/admin/transactions?' + q).then(function (d) {
            if (!d.items.length) { A.emptyRow(rows, 7, 'No transactions match these filters.'); pager.hidden = true; return; }
            rows.textContent = '';
            d.items.forEach(function (t) {
                rows.appendChild(el('tr', { class: t.flagged ? 'is-flagged' : '' },
                    el('td', { 'data-label': 'Reference' }, A.txnLink(t.reference)),
                    el('td', { 'data-label': 'From' }, A.userLink(t.sender)),
                    el('td', { 'data-label': 'To' }, A.userLink(t.recipient)),
                    el('td', { 'data-label': 'Type', text: t.type }),
                    el('td', { 'data-label': 'Status' }, A.statusBadge(t.status), t.flagged ? A.badge('flagged', 'badge--danger') : null),
                    el('td', { 'data-label': 'Date', class: 'nowrap', text: FT.formatDate(t.created_at) }),
                    el('td', { 'data-label': 'Amount', class: 'num tabular', text: FT.formatNaira(t.amount_kobo) })));
            });
            A.renderPager(pager, d.pagination, filters.setPage);
        }).catch(function (err) {
            if (err.fields) FT.showErrors(form, err.fields);
            A.emptyRow(rows, 7, err.message);
        });
    });
    filters.run(false);
});
