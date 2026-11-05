/* Admin: audit log browser (read-only). */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var rows = document.querySelector('[data-rows]');
    var pager = document.querySelector('[data-pager]');
    var ACTOR_CLASS = { admin: 'badge--warning', user: 'badge--info', system: 'badge--neutral' };

    // {"status":"active"} -> {"status":"frozen","reason":"..."}  =>  "status: active → frozen · reason: ..."
    function describeChange(oldV, newV) {
        oldV = oldV || {}; newV = newV || {};
        var keys = Object.keys(Object.assign({}, oldV, newV));
        return keys.map(function (k) {
            var fmt = function (v) { return v === null || v === undefined ? '—' : (/_kobo$/.test(k) ? FT.formatNaira(v) : String(v)); };
            return k in oldV && k in newV && oldV[k] !== newV[k] ? k + ': ' + fmt(oldV[k]) + ' → ' + fmt(newV[k]) : k + ': ' + fmt(k in newV ? newV[k] : oldV[k]);
        }).join(' · ');
    }

    var filters = A.bindFilters(document.getElementById('filters'), function (q) {
        A.loadingRows(rows, 6);
        FT.api('/api/admin/audit-logs?' + q).then(function (d) {
            if (!d.items.length) { A.emptyRow(rows, 6, 'No audit entries match these filters.'); pager.hidden = true; return; }
            rows.textContent = '';
            d.items.forEach(function (a) {
                rows.appendChild(el('tr', {},
                    el('td', { 'data-label': 'When', class: 'nowrap', text: FT.formatDate(a.created_at) }),
                    el('td', { 'data-label': 'Actor' }, A.badge(a.actor_type, ACTOR_CLASS[a.actor_type]),
                        el('span', { class: 'text-sm', text: ' ' + (a.actor ? a.actor.email : 'FinTrust') })),
                    el('td', { 'data-label': 'Action', class: 'mono text-sm', text: a.action }),
                    el('td', { 'data-label': 'Target', class: 'text-sm', text: a.entity_type ? a.entity_type + ' #' + a.entity_id : '—' }),
                    el('td', { 'data-label': 'Change', class: 'text-sm audit-change', text: describeChange(a.old_values, a.new_values) || '—' }),
                    el('td', { 'data-label': 'IP', class: 'mono text-sm', text: a.ip_address || '—' })));
            });
            A.renderPager(pager, d.pagination, filters.setPage);
        }).catch(function (err) { A.emptyRow(rows, 6, err.message); });
    });
    filters.run(false);
});
