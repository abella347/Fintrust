/* Admin: fraud alert queue - investigate, resolve or dismiss (with a note). */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust, UI = window.FTUI, A = window.AdminUI, el = UI.el;
    var list = document.querySelector('[data-list]');
    var pager = document.querySelector('[data-pager]');
    var summary = document.querySelector('[data-summary]');
    var canReview = false;

    function review(alert, status) {
        var needsNote = status !== 'investigating';
        var dlg = A.dialog(({ investigating: 'Start investigating', resolved: 'Resolve alert', dismissed: 'Dismiss alert' })[status]);
        var form = el('form', { id: 'review-form', novalidate: 'novalidate' });
        var ta = el('textarea', { class: 'input', id: 'review-note', name: 'note', maxlength: '500', 'aria-describedby': 'review-note-error' });
        form.appendChild(el('p', { class: 'text-muted', text: alert.rule_code + ' — ' + alert.reason }));
        form.appendChild(el('div', { class: 'form-group' },
            el('label', { for: 'review-note', text: needsNote ? 'What did you find? (required, saved in the audit log)' : 'Note (optional)' }), ta,
            el('p', { class: 'field-error', id: 'review-note-error', role: 'alert' })));
        dlg.body.appendChild(form);
        var cancel = el('button', { type: 'button', class: 'btn btn-ghost', text: 'Cancel' });
        var save = el('button', { type: 'submit', form: 'review-form', class: 'btn btn-primary', text: 'Save' });
        cancel.addEventListener('click', function () { dlg.dialog.close(); });
        dlg.actions.appendChild(cancel);
        dlg.actions.appendChild(save);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            FT.clearErrors(form);
            if (needsNote && ta.value.trim().length < 5) { FT.showErrors(form, { note: 'Explain the outcome (at least 5 characters).' }); return; }
            FT.setBusy(save, true);
            FT.api('/api/admin/fraud-alerts/' + alert.id, { method: 'PUT', body: { status: status, note: ta.value.trim() } })
                .then(function () { dlg.dialog.close(); FT.toast('Alert marked ' + status, 'success'); filters.run(false); })
                .catch(function (err) { FT.showErrors(form, err.fields || {}); if (!err.fields) FT.formAlert(form, err.message, 'error'); })
                .finally(function () { FT.setBusy(save, false); });
        });
        dlg.dialog.showModal();
        ta.focus();
    }

    function card(a) {
        var actions = el('div', { class: 'cluster' });
        var open = a.status === 'open' || a.status === 'investigating';
        if (canReview && open) {
            if (a.status === 'open') actions.appendChild(button('Investigate', 'btn-outline', function () { review(a, 'investigating'); }));
            actions.appendChild(button('Resolve', 'btn-primary', function () { review(a, 'resolved'); }));
            actions.appendChild(button('Dismiss', 'btn-ghost', function () { review(a, 'dismissed'); }));
        }
        var details = a.details ? Object.keys(a.details).map(function (k) {
            var v = a.details[k];
            return k.replace(/_kobo$/, '').replace(/_/g, ' ') + ': ' + (/_kobo$/.test(k) ? FT.formatNaira(v) : v);
        }).join(' · ') : '';
        return el('li', { class: 'alert-card alert-card--' + a.severity },
            el('div', { class: 'alert-card__top' },
                el('div', { class: 'cluster' }, A.severityBadge(a.severity), el('strong', { class: 'mono', text: a.rule_code }), A.statusBadge(a.status)),
                el('small', { class: 'text-muted', text: '#' + a.id + ' · ' + FT.formatDate(a.created_at) })),
            el('p', { class: 'alert-card__reason', text: a.reason }),
            details ? el('p', { class: 'text-sm text-muted mono', text: details }) : null,
            el('p', { class: 'text-sm' },
                el('span', { class: 'text-muted', text: 'Account: ' }), a.user ? A.userLink(a.user) : document.createTextNode('— (network-level alert)'),
                a.transaction_reference ? el('span', { class: 'text-muted', text: ' · Transaction: ' }) : null,
                a.transaction_reference ? A.txnLink(a.transaction_reference) : null),
            a.reviewer ? el('p', { class: 'text-sm alert-card__review' },
                el('strong', { text: a.reviewer + ': ' }), document.createTextNode((a.resolution_note || '(no note)') + (a.reviewed_at ? ' · ' + FT.formatDate(a.reviewed_at) : ''))) : null,
            actions.childNodes.length ? actions : null);
    }

    function button(text, cls, fn) {
        var b = el('button', { type: 'button', class: 'btn btn-sm ' + cls, text: text });
        b.addEventListener('click', fn);
        return b;
    }

    var filters = A.bindFilters(document.getElementById('filters'), function (q) {
        UI.skeletonList(list, 3);
        FT.api('/api/admin/fraud-alerts?' + q).then(function (d) {
            canReview = d.can_review;
            list.removeAttribute('aria-busy');
            list.textContent = '';
            summary.textContent = d.pagination.total + ' alert' + (d.pagination.total === 1 ? '' : 's') + (canReview ? '' : ' · view only');
            if (!d.items.length) list.appendChild(el('li', { class: 'empty-state empty-state--sm', text: 'No alerts match these filters.' }));
            d.items.forEach(function (a) { list.appendChild(card(a)); });
            A.renderPager(pager, d.pagination, filters.setPage);
        }).catch(function (err) { UI.errorState(list, err.message, function () { filters.run(false); }); });
    });
    filters.run(false);
});
