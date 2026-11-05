document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;

    var $ = function (sel) { return document.querySelector(sel); };
    var recentBox = $('[data-recent]');
    var notifBox = $('[data-notifications]');
    var chartBox = $('[data-chart]');
    var chartTable = $('[data-chart-table]');

    function greeting(name) {
        var hour = parseInt(new Date().toLocaleString('en-GB', { hour: 'numeric', hour12: false, timeZone: 'Africa/Lagos' }), 10);
        var part = hour < 12 ? 'Good morning' : (hour < 17 ? 'Good afternoon' : 'Good evening');
        return part + ', ' + name;
    }

    function load() {
        UI.skeletonList(recentBox, 5);
        UI.skeletonList(notifBox, 3);
        // Then(onOk, onFail): only API failures show the "try again" state;
        // a bug inside render() surfaces in the console instead of being disguised.
        FT.api('/api/dashboard').then(render, function (err) {
            [recentBox, notifBox, chartBox].forEach(function (box) { UI.errorState(box, err.message, load); });
        });
    }

    function render(d) {
        $('[data-bind="greeting"]').textContent = greeting(d.user.first_name);
        $('[data-bind="today"]').textContent = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Lagos' });
        UI.bindWallet(d.wallet);

        $('#stat-received').textContent = FT.formatNaira(d.stats.received_kobo);
        $('#stat-sent').textContent = FT.formatNaira(d.stats.sent_kobo);
        $('#stat-count').textContent = String(d.stats.transaction_count);
        var secure = d.user.email_verified && d.user.has_pin;
        $('#stat-security').textContent = secure ? 'Protected' : 'Action needed';
        var secCard = $('#stat-security').closest('.stat-card');
        secCard.classList.toggle('stat-card--success', secure);
        secCard.classList.toggle('stat-card--warning', !secure);
        var hint = secCard.querySelector('.stat-card__hint');
        hint.hidden = false;
        hint.textContent = secure ? 'Email verified · PIN set' : (!d.user.has_pin ? 'Set your transaction PIN' : 'Verify your email');

        [recentBox, notifBox].forEach(function (b) { b.removeAttribute('aria-busy'); });
        UI.renderList(recentBox, d.recent_transactions, {
            title: 'No transactions yet', message: 'Fund your wallet with simulated money to get started.',
            actionLabel: 'Add money', actionHref: FT.url('wallet#add-money')
        });
        renderNotifications(d.notifications);
        renderSecurity(d.user);
        renderChart(d.chart);
    }

    /* Notifications */
    function renderNotifications(n) {
        UI.setUnread(n.unread_count);
        notifBox.textContent = '';
        var markAll = $('[data-mark-all-read]');
        markAll.hidden = n.unread_count === 0;
        if (!n.items.length) { notifBox.appendChild(UI.empty({ title: 'You’re all caught up', message: 'New activity will appear here.' })); return; }
        var ul = el('ul', { class: 'notif-list' });
        n.items.forEach(function (item) {
            ul.appendChild(el('li', { class: 'notif' + (item.read ? '' : ' is-unread') },
                el('span', { class: 'notif__dot', 'aria-hidden': 'true' }),
                el('div', {},
                    el('strong', { text: item.title }),
                    item.read ? null : el('span', { class: 'sr-only', text: ' (unread)' }),
                    el('p', { text: item.message }),
                    el('small', { text: FT.formatDate(item.created_at) }))
            ));
        });
        notifBox.appendChild(ul);
    }

    $('[data-mark-all-read]').addEventListener('click', function (e) {
        var btn = e.currentTarget;
        FT.setBusy(btn, true);
        FT.api('/api/notifications/read', { method: 'POST', body: { all: true } })
            .then(function (r) {
                UI.setUnread(r.unread_count);
                notifBox.querySelectorAll('.is-unread').forEach(function (li) { li.classList.remove('is-unread'); });
                notifBox.querySelectorAll('.sr-only').forEach(function (s) { s.remove(); });
                btn.hidden = true;
                FT.toast('All notifications marked as read', 'success');
            })
            .catch(function (err) { FT.toast(err.message, 'error'); })
            .finally(function () { FT.setBusy(btn, false); });
    });

    /* Security */
    function renderSecurity(u) {
        var list = $('[data-security]');
        list.textContent = '';
        function row(ok, label, detail, href, action) {
            list.appendChild(el('li', { class: ok ? 'is-ok' : 'is-warn' },
                UI.svg(ok ? 'check' : 'alert', 20),
                el('div', {}, el('strong', { text: label }), el('small', { text: detail })),
                !ok && href ? el('a', { class: 'btn btn-sm btn-outline', href: href, text: action }) : null));
        }
        row(u.email_verified, 'Email address', u.email_verified ? 'Verified' : 'Not verified yet', FT.url('settings#profile'), 'Verify');
        row(u.has_pin, 'Transaction PIN', u.has_pin ? 'Set — required for every transfer' : 'Not set — transfers are disabled', FT.url('settings#pin'), 'Set PIN');
        row(true, 'Last login', u.last_login_at ? FT.formatDate(u.last_login_at) : 'This is your first session');
    }

    /* Chart: money in vs out (drawn by FTUI.groupedBars in shared-ui.js) */
    function renderChart(data) {
        UI.groupedBars(chartBox, chartTable, data, {
            ariaLabel: 'Bar chart of money in and money out for the last six months. Use the Show as table button for exact values.',
            caption: 'Money in and out per month',
            rowHeader: function (m) { return m.label + ' ' + m.month.slice(0, 4); },
            series: [
                { key: 'in_kobo', label: 'Money in', cls: 'in' },
                { key: 'out_kobo', label: 'Money out', cls: 'out' }
            ]
        });
    }

    $('[data-chart-table-toggle]').addEventListener('click', function (e) {
        var showing = chartTable.hidden;
        chartTable.hidden = !showing;
        e.currentTarget.setAttribute('aria-pressed', String(showing));
        e.currentTarget.textContent = showing ? 'Hide table' : 'Show as table';
    });

    load();
});
