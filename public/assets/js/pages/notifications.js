/* Notifications: list, mark one or all as read, jump to the related transaction. */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;
    var list = document.querySelector('[data-list]');
    var markAll = document.querySelector('[data-mark-all]');
    var summary = document.querySelector('[data-summary]');

    function update(unread) {
        UI.setUnread(unread);
        markAll.hidden = unread === 0;
        summary.textContent = unread ? unread + ' unread' : 'You’re all caught up';
    }

    function markRead(ids) {
        return FT.api('/api/notifications/read', { method: 'POST', body: ids ? { ids: ids } : { all: true } })
            .then(function (r) { update(r.unread_count); return r; });
    }

    function load() {
        UI.skeletonList(list, 5);
        FT.api('/api/notifications?page=1').then(function (d) {
            list.removeAttribute('aria-busy');
            list.textContent = '';
            update(d.unread_count);
            if (!d.items.length) { list.appendChild(UI.empty({ title: 'No notifications', message: 'Transfers, deposits and security alerts will show up here.' })); return; }
            var ul = el('ul', { class: 'notif-list notif-list--full' });
            d.items.forEach(function (n) {
                var li = el('li', { class: 'notif' + (n.read ? '' : ' is-unread') },
                    el('span', { class: 'notif__dot', 'aria-hidden': 'true' }),
                    el('div', { class: 'notif__body' },
                        el('strong', { text: n.title }),
                        n.read ? null : el('span', { class: 'sr-only', text: ' (unread)' }),
                        el('p', { text: n.message }),
                        el('small', { text: FT.formatDate(n.created_at) })));
                var actions = el('div', { class: 'notif__actions' });
                if (n.transaction_reference) {
                    actions.appendChild(el('a', { class: 'btn btn-sm btn-ghost', href: FT.url('transactions?ref=' + encodeURIComponent(n.transaction_reference)), text: 'View' }));
                }
                if (!n.read) {
                    var btn = el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: 'Mark read', 'aria-label': 'Mark “' + n.title + '” as read' });
                    btn.addEventListener('click', function () {
                        FT.setBusy(btn, true);
                        markRead([n.id]).then(function () {
                            li.classList.remove('is-unread');
                            var sr = li.querySelector('.sr-only'); if (sr) sr.remove();
                            btn.remove();
                        }).catch(function (err) { FT.toast(err.message, 'error'); FT.setBusy(btn, false); });
                    });
                    actions.appendChild(btn);
                }
                li.appendChild(actions);
                ul.appendChild(li);
            });
            list.appendChild(ul);
        }).catch(function (err) { UI.errorState(list, err.message, load); });
    }

    markAll.addEventListener('click', function () {
        FT.setBusy(markAll, true);
        markRead(null).then(function () {
            list.querySelectorAll('.is-unread').forEach(function (li) { li.classList.remove('is-unread'); });
            list.querySelectorAll('.sr-only, .notif__actions button').forEach(function (n) { n.remove(); });
            FT.toast('All notifications marked as read', 'success');
        }).catch(function (err) { FT.toast(err.message, 'error'); })
          .finally(function () { FT.setBusy(markAll, false); });
    });

    load();
});
