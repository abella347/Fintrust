document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var $ = function (s) { return document.querySelector(s); };

    var form = $('#filters');
    var rows = $('[data-rows]');
    var tableWrap = $('[data-table-wrap]');
    var stateBox = $('[data-state]');
    var pager = $('[data-pagination]');
    var FIELDS = ['search', 'type', 'direction', 'status', 'from', 'to'];
    var PER_PAGE = 10;
    var page = 1;
    var request = 0;

    function readUrl() {
        var q = new URLSearchParams(location.search);
        FIELDS.forEach(function (f) { if (form.elements[f]) form.elements[f].value = q.get(f) || ''; });
        page = Math.max(1, parseInt(q.get('page') || '1', 10) || 1);
        return q.get('ref');
    }

    function currentQuery() {
        var q = new URLSearchParams();
        FIELDS.forEach(function (f) { var v = form.elements[f].value.trim(); if (v) q.set(f, v); });
        if (page > 1) q.set('page', String(page));
        return q;
    }

    function load(pushUrl) {
        var q = currentQuery();
        if (pushUrl) history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
        q.set('per_page', String(PER_PAGE));
        var mine = ++request;

        stateBox.textContent = '';
        tableWrap.hidden = false;
        rows.textContent = '';
        for (var i = 0; i < 5; i++) {
            var tr = UI.el('tr', { 'aria-hidden': 'true' });
            tr.appendChild(UI.el('td', { colspan: '6' }, UI.el('span', { class: 'skeleton skeleton--text' })));
            rows.appendChild(tr);
        }
        $('[data-summary]').textContent = 'Loading…';

        FT.api('/api/transactions?' + q.toString()).then(function (d) {
            if (mine !== request) return; // A newer search started; ignore stale results
            var p = d.pagination;
            if (!d.items.length) {
                tableWrap.hidden = true;
                pager.hidden = true;
                var filtered = FIELDS.some(function (f) { return form.elements[f].value; });
                stateBox.textContent = '';
                stateBox.appendChild(UI.empty(filtered
                    ? { title: 'No matching transactions', message: 'Try different filters, or reset them to see everything.' }
                    : { title: 'No transactions yet', message: 'Fund your wallet to make your first simulated transfer.', actionLabel: 'Add money', actionHref: FT.url('wallet#add-money') }));
                $('[data-summary]').textContent = '0 results';
                return;
            }
            UI.renderTableRows(rows, d.items);
            var first = (p.page - 1) * p.per_page + 1;
            $('[data-summary]').textContent = 'Showing ' + first + '–' + (first + d.items.length - 1) + ' of ' + p.total;
            pager.hidden = p.total_pages <= 1;
            $('[data-page-label]').textContent = 'Page ' + p.page + ' of ' + p.total_pages;
            pager.querySelector('[data-page="prev"]').disabled = p.page <= 1;
            pager.querySelector('[data-page="next"]').disabled = p.page >= p.total_pages;
            page = p.page;
        }).catch(function (err) {
            if (mine !== request) return;
            tableWrap.hidden = true;
            pager.hidden = true;
            $('[data-summary]').textContent = '';
            if (err.fields && Object.keys(err.fields).length) {
                FT.showErrors(form, err.fields);
                stateBox.appendChild(UI.empty({ title: 'Check your filters', message: err.message }));
            } else {
                UI.errorState(stateBox, err.message, function () { load(false); });
            }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        FT.clearErrors(form);
        if (form.elements.from.value && form.elements.to.value && form.elements.from.value > form.elements.to.value) {
            FT.showErrors(form, { from: '“From” must be on or before “To”.' });
            return;
        }
        page = 1;
        load(true);
    });
    form.addEventListener('reset', function () {
        setTimeout(function () { FT.clearErrors(form); page = 1; load(true); }, 0);
    });
    // Selects apply immediately; typing in search waits until the user pauses.
    ['type', 'direction', 'status'].forEach(function (f) {
        form.elements[f].addEventListener('change', function () { page = 1; load(true); });
    });
    var typing = null;
    form.elements.search.addEventListener('input', function () {
        clearTimeout(typing);
        typing = setTimeout(function () { page = 1; load(true); }, 400);
    });

    pager.addEventListener('click', function (e) {
        var b = e.target.closest('[data-page]');
        if (!b || b.disabled) return;
        page += b.getAttribute('data-page') === 'next' ? 1 : -1;
        load(true);
        $('#results-title').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Transactions?ref=FT... opens that receipt directly (used by notifications).
    var ref = readUrl();
    load(false);
    if (ref && /^[A-Z0-9]{6,32}$/.test(ref)) {
        FT.api('/api/transactions/' + encodeURIComponent(ref)).then(UI.openDetails).catch(function (err) { FT.toast(err.message, 'error'); });
    }
});
