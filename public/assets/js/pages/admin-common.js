(function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;

    var SEVERITY_CLASS = { critical: 'badge--danger', high: 'badge--danger', medium: 'badge--warning', low: 'badge--info' };
    var STATUS_CLASS = {
        active: 'badge--success', successful: 'badge--success', resolved: 'badge--success',
        suspended: 'badge--danger', failed: 'badge--danger', closed: 'badge--neutral',
        frozen: 'badge--warning', pending: 'badge--warning', investigating: 'badge--warning',
        open: 'badge--info', reversed: 'badge--info', dismissed: 'badge--neutral'
    };

    function badge(text, cls) { return el('span', { class: 'badge ' + (cls || 'badge--neutral'), text: text.charAt(0).toUpperCase() + text.slice(1) }); }
    function statusBadge(s) { return badge(s, STATUS_CLASS[s]); }
    function severityBadge(s) { return badge(s, SEVERITY_CLASS[s]); }

    /* Filters <-> URL, pagination */

    function bindFilters(form, onChange) {
        var params = new URLSearchParams(location.search);
        Array.prototype.forEach.call(form.elements, function (f) {
            if (!f.name) return;
            if (f.type === 'checkbox') f.checked = params.get(f.name) === '1';
            else if (params.has(f.name)) f.value = params.get(f.name);
        });
        var page = Math.max(1, parseInt(params.get('page') || '1', 10) || 1);

        function query() {
            var q = new URLSearchParams();
            Array.prototype.forEach.call(form.elements, function (f) {
                if (!f.name) return;
                if (f.type === 'checkbox') { if (f.checked) q.set(f.name, '1'); }
                else if (f.value.trim()) q.set(f.name, f.value.trim());
            });
            if (page > 1) q.set('page', String(page));
            return q;
        }
        function run(resetPage) {
            if (resetPage) page = 1;
            var q = query();
            history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
            FT.clearErrors(form);
            onChange(q);
        }
        form.addEventListener('submit', function (e) { e.preventDefault(); run(true); });
        form.addEventListener('reset', function () { setTimeout(function () { run(true); }, 0); });
        form.querySelectorAll('select, input[type=checkbox]').forEach(function (s) { s.addEventListener('change', function () { run(true); }); });
        return {
            run: run,
            setPage: function (p) { page = p; run(false); },
            page: function () { return page; }
        };
    }

    function renderPager(nav, p, onPage) {
        nav.textContent = '';
        nav.hidden = p.total_pages <= 1;
        var prev = el('button', { type: 'button', class: 'btn btn-sm btn-outline', text: 'Previous' });
        var next = el('button', { type: 'button', class: 'btn btn-sm btn-outline', text: 'Next' });
        prev.disabled = p.page <= 1;
        next.disabled = p.page >= p.total_pages;
        prev.addEventListener('click', function () { onPage(p.page - 1); });
        next.addEventListener('click', function () { onPage(p.page + 1); });
        nav.appendChild(prev);
        nav.appendChild(el('span', { text: 'Page ' + p.page + ' of ' + p.total_pages + ' · ' + p.total + ' total' }));
        nav.appendChild(next);
    }

    function loadingRows(tbody, cols) {
        tbody.textContent = '';
        for (var i = 0; i < 5; i++) {
            tbody.appendChild(el('tr', { 'aria-hidden': 'true' }, el('td', { colspan: String(cols) }, el('span', { class: 'skeleton skeleton--text' }))));
        }
    }

    function emptyRow(tbody, cols, text) {
        tbody.textContent = '';
        tbody.appendChild(el('tr', {}, el('td', { colspan: String(cols), class: 'table-empty', text: text })));
    }

    /* Dialogs */

    function dialog(titleText, wide) {
        var d = el('dialog', { class: 'modal' + (wide ? ' modal--wide' : ''), 'aria-labelledby': 'adm-dlg-title' });
        var body = el('div', { class: 'modal__body' }, el('h2', { id: 'adm-dlg-title', text: titleText }));
        var actions = el('div', { class: 'modal__actions' });
        d.appendChild(body);
        d.appendChild(actions);
        d.addEventListener('close', function () { d.remove(); });
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
        document.body.appendChild(d);
        return { dialog: d, body: body, actions: actions };
    }

    function askReason(opts) {
        return new Promise(function (resolve) {
            var dlg = dialog(opts.title);
            var form = el('form', { novalidate: 'novalidate', id: 'reason-form' });
            var ta = el('textarea', { class: 'input', id: 'reason-input', name: 'reason', maxlength: '255', required: 'required', 'aria-describedby': 'reason-input-error' });
            form.appendChild(el('p', { class: 'text-muted', text: opts.message || '' }));
            form.appendChild(el('div', { class: 'form-group' },
                el('label', { for: 'reason-input', text: opts.label || 'Reason (saved in the audit log)' }), ta,
                el('p', { class: 'field-error', id: 'reason-input-error', role: 'alert' })));
            dlg.body.appendChild(form);
            var cancel = el('button', { type: 'button', class: 'btn btn-ghost', text: 'Cancel' });
            var ok = el('button', { type: 'submit', form: 'reason-form', class: 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary'), text: opts.confirmText || 'Confirm' });
            dlg.actions.appendChild(cancel);
            dlg.actions.appendChild(ok);
            var answered = false;
            cancel.addEventListener('click', function () { dlg.dialog.close(); });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var v = ta.value.trim();
                if (v.length < 5) { FT.showErrors(form, { reason: 'Give a reason of at least 5 characters.' }); return; }
                answered = true;
                resolve(v);
                dlg.dialog.close();
            });
            dlg.dialog.addEventListener('close', function () { if (!answered) resolve(null); });
            dlg.dialog.showModal();
            ta.focus();
        });
    }

    function kv(rows) {
        var dl = el('dl', { class: 'kv' });
        rows.forEach(function (r) {
            if (r === null) return;
            dl.appendChild(el('dt', { text: r[0] }));
            dl.appendChild(r[1] instanceof Node ? el('dd', {}, r[1]) : el('dd', { text: r[1] === null || r[1] === undefined || r[1] === '' ? '—' : String(r[1]) }));
        });
        return dl;
    }

    function section(title, child) {
        return el('section', { class: 'dlg-section' }, el('h3', { text: title }), child);
    }

    function userLink(user) {
        if (!user || !user.public_id) return document.createTextNode(user && user.name ? user.name : '—');
        var b = el('button', { type: 'button', class: 'link-btn', text: user.name || 'View user' });
        b.addEventListener('click', function () { openUser(user.public_id); });
        return b;
    }

    function txnLink(reference) {
        if (!reference) return document.createTextNode('—');
        var b = el('button', { type: 'button', class: 'link-btn mono', text: reference });
        b.addEventListener('click', function () { openTxn(reference); });
        return b;
    }

    /** Full customer profile with admin actions. onChange() is called after any change. */
    function openUser(publicId, onChange) {
        var dlg = dialog('Loading…', true);
        UI.skeletonList(dlg.body, 4);
        var close = el('button', { type: 'button', class: 'btn btn-primary', text: 'Close' });
        close.addEventListener('click', function () { dlg.dialog.close(); });
        dlg.dialog.showModal();

        function render() {
            FT.api('/api/admin/users/' + encodeURIComponent(publicId)).then(function (d) {
                var u = d.user;
                dlg.body.textContent = '';
                dlg.actions.textContent = '';
                var head = el('div', { class: 'dlg-head' },
                    el('h2', { id: 'adm-dlg-title', text: u.name }),
                    el('div', { class: 'cluster' }, statusBadge(u.status),
                        u.locked ? badge('login locked', 'badge--warning') : null,
                        u.email_verified ? badge('verified', 'badge--success') : badge('unverified', 'badge--warning')));
                dlg.body.appendChild(head);
                dlg.body.appendChild(kv([
                    ['Email', u.email], ['Phone', u.phone], ['Roles', u.roles.join(', ')],
                    ['Joined', FT.formatDate(u.created_at, false)], ['Last login', u.last_login_at ? FT.formatDate(u.last_login_at) : 'Never'],
                    ['Transaction PIN', u.pin_set ? (u.pin_locked_until && new Date(u.pin_locked_until) > new Date() ? 'Set · locked until ' + FT.formatDate(u.pin_locked_until) : 'Set') : 'Not set'],
                    ['Failed logins (current)', String(u.failed_logins)]
                ]));
                if (u.wallet) {
                    dlg.body.appendChild(section('Wallet', kv([
                        ['Number', FT.formatWallet(u.wallet.wallet_number)], ['Balance', FT.formatNaira(u.wallet.balance_kobo)], ['Status', statusBadge(u.wallet.status)]
                    ])));
                }
                var t = el('ul', { class: 'mini-list' });
                d.transactions.forEach(function (x) {
                    t.appendChild(el('li', {}, txnLink(x.reference), el('span', { text: x.type + ' · ' + FT.formatDate(x.created_at) }),
                        statusBadge(x.status), el('strong', { class: 'tabular', text: FT.formatNaira(x.amount_kobo) })));
                });
                dlg.body.appendChild(section('Recent transactions', d.transactions.length ? t : el('p', { class: 'text-muted', text: 'None yet.' })));
                var a = el('ul', { class: 'mini-list' });
                d.alerts.forEach(function (x) {
                    a.appendChild(el('li', {}, severityBadge(x.severity), el('span', { text: x.rule_code + ' — ' + x.reason }), statusBadge(x.status)));
                });
                dlg.body.appendChild(section('Fraud alerts', d.alerts.length ? a : el('p', { class: 'text-muted', text: 'No alerts.' })));
                var l = el('ul', { class: 'mini-list' });
                d.logins.forEach(function (x) {
                    l.appendChild(el('li', {}, badge(x.successful ? 'success' : 'failed', x.successful ? 'badge--success' : 'badge--danger'),
                        el('span', { text: x.ip_address + ' · ' + x.user_agent.slice(0, 60) }), el('span', { class: 'text-muted', text: FT.formatDate(x.attempted_at) })));
                });
                dlg.body.appendChild(section('Recent logins', d.logins.length ? l : el('p', { class: 'text-muted', text: 'No logins recorded.' })));

                // Actions (only the ones this staff member may perform)
                var isCustomer = u.roles.indexOf('user') >= 0 && u.roles.length === 1;
                if (d.can_manage.users && isCustomer) {
                    var suspend = u.status === 'active';
                    dlg.actions.appendChild(actionButton(suspend ? 'Suspend account' : 'Reactivate account', suspend, function () {
                        return askReason({ title: suspend ? 'Suspend ' + u.name + '?' : 'Reactivate ' + u.name + '?', danger: suspend,
                            message: suspend ? 'They will be signed out immediately and can’t log in until reactivated.' : 'They will be able to log in again.',
                            confirmText: suspend ? 'Suspend' : 'Reactivate' })
                            .then(function (reason) {
                                if (!reason) return null;
                                return FT.api('/api/admin/users/' + encodeURIComponent(publicId) + (suspend ? '/suspend' : '/reactivate'), { method: 'POST', body: { reason: reason } });
                            });
                    }, suspend ? 'Account suspended' : 'Account reactivated'));
                }
                if (d.can_manage.wallets && u.wallet) {
                    var freeze = u.wallet.status === 'active';
                    dlg.actions.appendChild(actionButton(freeze ? 'Freeze wallet' : 'Unfreeze wallet', freeze, function () {
                        return askReason({ title: (freeze ? 'Freeze' : 'Unfreeze') + ' wallet ' + FT.formatWallet(u.wallet.wallet_number) + '?', danger: freeze,
                            message: freeze ? 'Transfers, deposits and incoming payments stop until it is unfrozen.' : 'The customer can move money again.',
                            confirmText: freeze ? 'Freeze' : 'Unfreeze' })
                            .then(function (reason) {
                                if (!reason) return null;
                                return FT.api('/api/admin/wallets/' + u.wallet.wallet_number + (freeze ? '/freeze' : '/unfreeze'), { method: 'POST', body: { reason: reason } });
                            });
                    }, freeze ? 'Wallet frozen' : 'Wallet unfrozen'));
                }
                dlg.actions.appendChild(close);
            }).catch(function (err) { UI.errorState(dlg.body, err.message, render); dlg.actions.appendChild(close); });
        }

        function actionButton(label, danger, run, doneText) {
            var b = el('button', { type: 'button', class: 'btn ' + (danger ? 'btn-danger' : 'btn-outline'), text: label });
            b.addEventListener('click', function () {
                run().then(function (res) {
                    if (!res) return;
                    FT.toast(doneText, 'success');
                    render();
                    if (onChange) onChange();
                }).catch(function (err) { FT.toast(err.message, 'error'); });
            });
            return b;
        }
        render();
    }

    /** Transaction with both parties and its ledger entries. */
    function openTxn(reference) {
        var dlg = dialog('Transaction ' + reference, true);
        UI.skeletonList(dlg.body, 4);
        var close = el('button', { type: 'button', class: 'btn btn-primary', text: 'Close' });
        close.addEventListener('click', function () { dlg.dialog.close(); });
        dlg.actions.appendChild(close);
        dlg.dialog.showModal();
        FT.api('/api/admin/transactions/' + encodeURIComponent(reference)).then(function (t) {
            dlg.body.textContent = '';
            dlg.body.appendChild(el('div', { class: 'dlg-head' },
                el('h2', { id: 'adm-dlg-title', class: 'mono', text: t.reference }),
                el('div', { class: 'cluster' }, statusBadge(t.status), t.flagged ? badge('flagged', 'badge--danger') : null)));
            dlg.body.appendChild(el('p', { class: 'receipt__amount', text: FT.formatNaira(t.amount_kobo) }));
            dlg.body.appendChild(kv([
                ['Type', t.type], ['From', userLink(t.sender)], ['From wallet', FT.formatWallet(t.sender.wallet_number)],
                ['To', userLink(t.recipient)], ['To wallet', FT.formatWallet(t.recipient.wallet_number)],
                ['Description', t.description], t.failure_reason ? ['Failure reason', t.failure_reason] : null,
                ['Date', FT.formatDate(t.created_at)], ['IP address', t.ip_address]
            ]));
            var tbody = el('tbody');
            t.ledger.forEach(function (l) {
                tbody.appendChild(el('tr', {},
                    el('td', { text: l.entry_type }), el('td', { class: 'mono', text: FT.formatWallet(l.wallet_number) + (l.wallet_type === 'system' ? ' (system)' : '') }),
                    el('td', { class: 'num', text: FT.formatNaira(l.amount_kobo) }), el('td', { class: 'num', text: FT.formatNaira(l.balance_after_kobo) })));
            });
            var table = el('div', { class: 'table-wrap' }, el('table', { class: 'table' },
                el('thead', {}, el('tr', {}, el('th', { text: 'Entry' }), el('th', { text: 'Wallet' }), el('th', { class: 'num', text: 'Amount' }), el('th', { class: 'num', text: 'Balance after' }))),
                tbody));
            dlg.body.appendChild(section('Ledger entries', t.ledger.length ? table
                : el('p', { class: 'text-muted', text: 'No ledger entries: this transaction moved no money.' })));
        }).catch(function (err) { UI.errorState(dlg.body, err.message, function () { dlg.dialog.close(); openTxn(reference); }); });
    }

    window.AdminUI = {
        badge: badge, statusBadge: statusBadge, severityBadge: severityBadge,
        bindFilters: bindFilters, renderPager: renderPager, loadingRows: loadingRows, emptyRow: emptyRow,
        dialog: dialog, askReason: askReason, kv: kv, openUser: openUser, openTxn: openTxn, userLink: userLink, txnLink: txnLink
    };
})();
