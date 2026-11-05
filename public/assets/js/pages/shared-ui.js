(function () {
    'use strict';
    var FT = window.FinTrust;

    var ICON_PATHS = {
        'upload': '<path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
        'fingerprint': '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
        'scan-face': '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01"/><path d="M15 9h.01"/>',
        'smartphone': '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
        'in': '<path d="M17 7 7 17"/><path d="M17 17H7V7"/>',
        'out': '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        deposit: '<path d="M5 12h14"/><path d="M12 5v14"/>',
        failed: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        check: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        retry: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'
    };

    // Our own static SVG markup (never user data), so innerHTML is safe here.
    function svg(name, size) {
        var s = document.createElement('span');
        s.className = 'ui-icon';
        s.setAttribute('aria-hidden', 'true');
        s.innerHTML = '<svg class="icon" width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICON_PATHS[name] + '</svg>';
        return s.firstChild;
    }

    // El('p', {class: 'x'}, 'text', childNode...) - text is always textContent.
    function el(tag, attrs) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'class') node.className = attrs[k];
            else if (k === 'text') node.textContent = attrs[k];
            else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) node.setAttribute(k, attrs[k]);
        });
        for (var i = 2; i < arguments.length; i++) {
            var c = arguments[i];
            if (c === null || c === undefined) continue;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return node;
    }

    var STATUS_CLASS = { successful: 'badge--success', failed: 'badge--danger', pending: 'badge--warning', reversed: 'badge--info' };
    function badge(status) {
        return el('span', { class: 'badge ' + (STATUS_CLASS[status] || 'badge--neutral'), text: status.charAt(0).toUpperCase() + status.slice(1) });
    }

    var FAILURE_TEXT = {
        INSUFFICIENT_FUNDS: 'Insufficient balance',
        RECIPIENT_UNAVAILABLE: 'Recipient wallet could not receive funds',
        LIMIT_EXCEEDED: 'Transfer limit exceeded',
        INVALID_PIN: 'Incorrect PIN'
    };

    function title(t) {
        if (t.type === 'deposit') return 'Wallet funding';
        if (t.type === 'reversal') return 'Reversal';
        return (t.direction === 'in' ? 'From ' : 'To ') + (t.counterparty && t.counterparty.name ? t.counterparty.name : 'FinTrust user');
    }

    function amount(t) {
        var sign = t.status !== 'successful' ? '' : (t.direction === 'in' ? '+' : '−');
        return sign + FT.formatNaira(t.amount_kobo);
    }

    function amountClass(t) {
        if (t.status !== 'successful') return 'amount-muted';
        return t.direction === 'in' ? 'amount-in' : 'amount-out';
    }

    function txnIcon(t) {
        var name = t.status === 'failed' ? 'failed' : (t.type === 'deposit' ? 'deposit' : t.direction);
        var tone = t.status === 'failed' ? 'txn-icon--failed' : (t.direction === 'in' ? 'txn-icon--in' : 'txn-icon--out');
        return el('span', { class: 'txn-icon ' + tone }, svg(name, 18));
    }

    /* Compact list (dashboard, wallet) */
    function renderList(container, items, emptyOpts) {
        container.textContent = '';
        if (!items.length) { container.appendChild(empty(emptyOpts)); return; }
        var ul = el('ul', { class: 'txn-list' });
        items.forEach(function (t) {
            var btn = el('button', { type: 'button', class: 'txn-list__item', 'aria-label': title(t) + ', ' + amount(t) + ', ' + t.status + ', ' + FT.formatDate(t.created_at) + '. View details' },
                txnIcon(t),
                el('span', { class: 'txn-list__main' },
                    el('strong', { text: title(t) }),
                    el('small', { text: (t.description || (t.type === 'deposit' ? 'Simulated deposit' : '—')) + ' · ' + FT.formatDate(t.created_at) })
                ),
                el('span', { class: 'txn-list__side' },
                    el('strong', { class: amountClass(t), text: amount(t) }),
                    t.status !== 'successful' ? badge(t.status) : null
                )
            );
            btn.addEventListener('click', function () { openDetails(t); });
            ul.appendChild(el('li', {}, btn));
        });
        container.appendChild(ul);
    }

    /* Table rows (transactions page) */
    function renderTableRows(tbody, items) {
        tbody.textContent = '';
        items.forEach(function (t) {
            var refBtn = el('button', { type: 'button', class: 'link-btn mono', text: t.reference, 'aria-label': 'View details for ' + t.reference });
            refBtn.addEventListener('click', function () { openDetails(t); });
            tbody.appendChild(el('tr', {},
                el('td', { 'data-label': 'Details' }, el('div', { class: 'cell-party' }, txnIcon(t),
                    el('div', {}, el('strong', { text: title(t) }), el('small', { class: 'text-muted', text: t.description || '—' })))),
                el('td', { 'data-label': 'Reference' }, refBtn),
                el('td', { 'data-label': 'Type', text: t.type.charAt(0).toUpperCase() + t.type.slice(1) }),
                el('td', { 'data-label': 'Date', class: 'nowrap', text: FT.formatDate(t.created_at) }),
                el('td', { 'data-label': 'Status' }, badge(t.status)),
                el('td', { 'data-label': 'Amount', class: 'num' }, el('span', { class: amountClass(t), text: amount(t) }))
            ));
        });
    }

    /* Receipt / details dialog */
    function receiptRows(t) {
        var rows = [
            ['Reference', t.reference],
            ['Type', t.type.charAt(0).toUpperCase() + t.type.slice(1)],
            ['Status', t.status.charAt(0).toUpperCase() + t.status.slice(1)],
            ['Date & time', FT.formatDate(t.created_at) + ' (WAT)']
        ];
        if (t.type === 'deposit') rows.push(['Source', 'Simulated funding']);
        else rows.push([t.direction === 'in' ? 'Sender' : 'Recipient', (t.counterparty.name || '—') + (t.counterparty.wallet_number ? ' · ' + FT.formatWallet(t.counterparty.wallet_number) : '')]);
        rows.push(['Description', t.description || '—']);
        if (t.status === 'failed') rows.push(['Reason', FAILURE_TEXT[t.failure_reason] || t.failure_reason || 'Failed']);
        if (t.balance_after_kobo !== null && t.balance_after_kobo !== undefined) rows.push(['Balance after', FT.formatNaira(t.balance_after_kobo)]);
        return rows;
    }

    function openDetails(t) {
        var dialog = el('dialog', { class: 'modal modal--receipt', 'aria-labelledby': 'rcpt-title' });
        var dl = el('dl', { class: 'kv kv--receipt' });
        receiptRows(t).forEach(function (r) { dl.appendChild(el('dt', { text: r[0] })); dl.appendChild(el('dd', { text: r[1] })); });

        var copyBtn = el('button', { type: 'button', class: 'btn btn-sm btn-ghost', 'data-copy': t.reference }, svg('copy', 14), ' Copy reference');
        var printBtn = el('button', { type: 'button', class: 'btn btn-outline' , text: 'Print / Save PDF' });
        var dlBtn = el('button', { type: 'button', class: 'btn btn-outline', text: 'Download receipt' });
        var closeBtn = el('button', { type: 'button', class: 'btn btn-primary', text: 'Close' });

        dialog.appendChild(el('div', { class: 'modal__body receipt' },
            el('div', { class: 'receipt__brand' }, el('strong', { text: 'FinTrust' }), el('span', { text: 'Transaction receipt' })),
            el('div', { class: 'receipt__hero' },
                el('span', { class: 'receipt__status receipt__status--' + t.status }, svg(t.status === 'successful' ? 'check' : (t.status === 'failed' ? 'failed' : 'alert'), 28)),
                el('h2', { id: 'rcpt-title', text: title(t) }),
                el('p', { class: 'receipt__amount ' + amountClass(t), text: amount(t) }),
                badge(t.status)
            ),
            dl,
            el('p', { class: 'receipt__foot', text: 'Simulated transaction · FinTrust is a demonstration project and does not move real money.' }),
            copyBtn
        ));
        dialog.appendChild(el('div', { class: 'modal__actions' }, printBtn, dlBtn, closeBtn));

        printBtn.addEventListener('click', function () {
            document.body.classList.add('printing-receipt');
            window.print();
            document.body.classList.remove('printing-receipt');
        });
        dlBtn.addEventListener('click', function () { downloadReceipt(t); });
        closeBtn.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('close', function () { dialog.remove(); });
        // Click on the dark backdrop closes it too.
        dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

        document.body.appendChild(dialog);
        dialog.showModal();
        closeBtn.focus();
    }

    // Plain-text receipt generated in the browser (no server round trip needed).
    function downloadReceipt(t) {
        var lines = ['FINTRUST — TRANSACTION RECEIPT', '==============================', ''];
        lines.push('Amount: ' + FT.formatNaira(t.amount_kobo));
        receiptRows(t).forEach(function (r) { lines.push(r[0] + ': ' + r[1]); });
        lines.push('', 'Simulated transaction. FinTrust is a demonstration project and does not move real money.');
        var blob = new Blob([lines.join('\r\n')], { type: 'text/plain;charset=utf-8' });
        var a = el('a', { href: URL.createObjectURL(blob), download: 'FinTrust-receipt-' + t.reference + '.txt' });
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }

    /* States */
    function empty(opts) {
        opts = opts || {};
        var box = el('div', { class: 'empty-state empty-state--sm' },
            el('h3', { text: opts.title || 'Nothing here yet' }),
            el('p', { text: opts.message || '' }));
        if (opts.actionLabel) box.appendChild(el('a', { class: 'btn btn-primary btn-sm', href: opts.actionHref, text: opts.actionLabel }));
        return box;
    }

    function errorState(container, message, onRetry) {
        container.textContent = '';
        var retry = el('button', { type: 'button', class: 'btn btn-sm btn-outline' }, svg('retry', 14), ' Try again');
        retry.addEventListener('click', onRetry);
        container.appendChild(el('div', { class: 'error-state', role: 'alert' },
            svg('alert', 22), el('p', { text: message || 'Couldn’t load this. Check your connection.' }), retry));
    }

    function skeletonList(container, rows) {
        container.textContent = '';
        for (var i = 0; i < (rows || 4); i++) container.appendChild(el('span', { class: 'skeleton skeleton--row', 'aria-hidden': 'true' }));
        container.setAttribute('aria-busy', 'true');
    }

    function setUnread(count) {
        var dot = document.querySelector('[data-unread-count]');
        if (!dot) return;
        dot.hidden = !count;
        dot.textContent = count > 9 ? '9+' : String(count || '');
        var link = dot.closest('a');
        if (link) link.setAttribute('aria-label', 'Notifications' + (count ? ', ' + count + ' unread' : ''));
    }

    function refreshUnread() {
        FT.api('/api/notifications?page=1').then(function (d) { setUnread(d.unread_count); }).catch(function () { /* Badge is optional */ });
    }

    /* Balance card + receive dialog (dashboard & wallet) */
    var hidden = false;
    try { hidden = window.localStorage.getItem('ft-hide-balance') === '1'; } catch (e) { /* UI preference only */ }
    var lastBalance = null;

    function paintBalance() {
        var out = document.querySelector('[data-bind="balance"]');
        if (!out || lastBalance === null) return;
        out.textContent = hidden ? '₦ • • • • • •' : FT.formatNaira(lastBalance);
        out.classList.toggle('is-masked', hidden);
        var btn = document.querySelector('[data-balance-toggle]');
        if (btn) {
            btn.setAttribute('aria-pressed', String(hidden));
            btn.setAttribute('aria-label', hidden ? 'Show balance' : 'Hide balance');
            btn.querySelector('[data-eye="on"]').hidden = hidden;
            btn.querySelector('[data-eye="off"]').hidden = !hidden;
        }
    }

    // First time the balance appears it counts up from ₦0 (skipped when hidden or motion is reduced).
    function setBalance(kobo) {
        var first = lastBalance === null;
        lastBalance = kobo;
        var out = document.querySelector('[data-bind="balance"]');
        if (!first || hidden || !out || !(window.FTMotion && window.FTMotion.ok()) || kobo <= 0) { paintBalance(); return; }
        paintBalance();                       // Sets the eye button state; the number is animated below
        var start = null, duration = 1100;
        function frame(now) {
            if (start === null) start = now;
            var t = Math.min(1, (now - start) / duration);
            var eased = 1 - Math.pow(1 - t, 3);
            if (hidden || lastBalance !== kobo) { paintBalance(); return; } // User toggled or a new value arrived
            out.textContent = FT.formatNaira(Math.round(kobo * eased));
            if (t < 1) { window.requestAnimationFrame(frame); } else { paintBalance(); }
        }
        window.requestAnimationFrame(frame);
    }

    function bindWallet(wallet) {
        setBalance(wallet.balance_kobo);
        document.querySelectorAll('[data-bind="wallet-number"], [data-bind="receive-number"]').forEach(function (n) {
            n.textContent = FT.formatWallet(wallet.wallet_number);
        });
        document.querySelectorAll('[data-bind="wallet-copy"], [data-bind="receive-copy"]').forEach(function (b) {
            b.setAttribute('data-copy', wallet.wallet_number);
            b.disabled = false;
        });
        var status = document.querySelector('[data-bind="wallet-status"]');
        if (status) {
            status.hidden = wallet.status === 'active';
            status.className = 'badge balance-card__status ' + (wallet.status === 'active' ? 'badge--success' : 'badge--warning');
            status.textContent = wallet.status.charAt(0).toUpperCase() + wallet.status.slice(1);
        }
        var frozen = document.querySelector('[data-bind="frozen-alert"]');
        if (frozen) frozen.hidden = wallet.status === 'active';
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-balance-toggle]')) {
            hidden = !hidden;
            try { window.localStorage.setItem('ft-hide-balance', hidden ? '1' : '0'); } catch (err) { /* Ignore */ }
            paintBalance();
        }
        if (e.target.closest('[data-open-receive]')) {
            var d = document.getElementById('receive-dialog');
            if (d) d.showModal();
        }
        if (e.target.closest('[data-close-dialog]')) {
            e.target.closest('dialog').close();
        }
        var share = e.target.closest('[data-share-wallet]');
        if (share) {
            var number = document.querySelector('[data-bind="receive-copy"]').getAttribute('data-copy');
            navigator.share({ title: 'My FinTrust wallet', text: 'Send money to my FinTrust wallet: ' + number }).catch(function () { /* User cancelled */ });
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var share = document.querySelector('[data-share-wallet]');
        if (share && navigator.share) share.hidden = false; // Only where the device supports sharing
        var dialog = document.getElementById('receive-dialog');
        if (dialog) dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });
    });

    function niceStep(raw) {
        if (raw <= 0) return 100000;
        var mag = Math.pow(10, Math.floor(Math.log10(raw)));
        var n = raw / mag;
        return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * mag;
    }

    function shortNaira(kobo) {
        var n = kobo / 100;
        if (n >= 1e6) return '₦' + (n / 1e6).toFixed(n % 1e6 ? 1 : 0) + 'm';
        if (n >= 1e3) return '₦' + Math.round(n / 1e3) + 'k';
        return '₦' + n;
    }

    function groupedBars(box, tableBox, rows, opts) {
        box.textContent = '';
        var NS = 'http://www.w3.org/2000/svg';
        var W = Math.max(280, Math.round(box.clientWidth || 640));
        var H = W < 480 ? 220 : 260, padL = 56, padR = 8, padT = 16, padB = 32;
        var nSeries = opts.series.length;
        var max = 0;
        rows.forEach(function (r) { opts.series.forEach(function (sr) { max = Math.max(max, r[sr.key]); }); });
        // Nothing in this period: draw a ₦0–₦1k scale (not fractions of a naira) and say so.
        var empty = max === 0;
        if (empty) max = 100000;
        var step = niceStep(max / 4);
        var top = Math.ceil(max / step) * step;
        var plotW = W - padL - padR, plotH = H - padT - padB;
        var group = plotW / rows.length;
        var barW = Math.max(4, Math.min(26, (group - 12) / nSeries - 2));
        var y = function (v) { return padT + plotH - (v / top) * plotH; };
        var labelEvery = Math.ceil(rows.length / Math.max(1, Math.floor(plotW / 48)));

        function s(tag, attrs, text) {
            var n = document.createElementNS(NS, tag);
            Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
            if (text !== undefined) n.textContent = text;
            return n;
        }
        // Bars grow in on the first draw only (not again on every window resize).
        var intro = !box._ftDrawn && window.FTMotion && window.FTMotion.ok();
        box._ftDrawn = true;
        var svgEl = s('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart__svg' + (intro ? ' chart__svg--intro' : ''), role: 'group',
            'aria-label': (opts.ariaLabel || 'Bar chart') + ' Use the left and right arrow keys to move between bars.' });
        var bars = [];
        for (var g = 0; g <= top; g += step) {
            svgEl.appendChild(s('line', { x1: padL, x2: W - padR, y1: y(g), y2: y(g), class: g === 0 ? 'chart__axis' : 'chart__grid' }));
            svgEl.appendChild(s('text', { x: padL - 8, y: y(g) + 4, 'text-anchor': 'end', class: 'chart__tick' }, shortNaira(g)));
        }

        var tip = el('div', { class: 'chart__tip', role: 'status', hidden: 'hidden' });
        rows.forEach(function (r, i) {
            var cx = padL + group * i + group / 2;
            var startX = cx - (nSeries * (barW + 2)) / 2;
            opts.series.forEach(function (sr, j) {
                var value = r[sr.key];
                var x = startX + j * (barW + 2);
                var h = Math.max(value > 0 ? 2 : 0, padT + plotH - y(value));
                var yy = padT + plotH - h, rad = Math.min(4, barW / 2);
                var d = h > rad
                    ? 'M' + x + ',' + (yy + h) + 'V' + (yy + rad) + 'Q' + x + ',' + yy + ' ' + (x + rad) + ',' + yy + 'H' + (x + barW - rad) + 'Q' + (x + barW) + ',' + yy + ' ' + (x + barW) + ',' + (yy + rad) + 'V' + (yy + h) + 'Z'
                    : 'M' + x + ',' + (yy + h) + 'V' + yy + 'H' + (x + barW) + 'V' + (yy + h) + 'Z';
                var label = r.label + ': ' + sr.label + ' ' + FT.formatNaira(value);
                var bar = s('path', { d: d, class: 'chart__bar chart__bar--' + sr.cls, tabindex: bars.length ? '-1' : '0', role: 'img', 'aria-label': label });
                if (intro) bar.style.animationDelay = (i * 45 + j * 20) + 'ms';
                bars.push(bar);
                var hit = s('rect', { x: x - 1, y: padT, width: barW + 2, height: plotH, class: 'chart__hit' });
                function show() {
                    tip.hidden = false;
                    tip.textContent = '';
                    tip.appendChild(el('strong', { text: FT.formatNaira(value) }));
                    tip.appendChild(el('span', { text: sr.label + ' · ' + r.label }));
                    tip.style.left = Math.min(Math.max(x + barW / 2, 60), W - 60) + 'px';
                    tip.style.top = Math.max(yy - 8, 0) + 'px';
                    bar.classList.add('is-active');
                    hit.classList.add('is-active'); // Visible even when the bar is ₦0 (zero height)
                }
                function hide() { tip.hidden = true; bar.classList.remove('is-active'); hit.classList.remove('is-active'); }
                [bar, hit].forEach(function (n) { n.addEventListener('pointerenter', show); n.addEventListener('pointerleave', hide); });
                bar.addEventListener('focus', show);
                bar.addEventListener('blur', hide);
                svgEl.appendChild(hit);
                svgEl.appendChild(bar);
            });
            if (i % labelEvery === 0) {
                svgEl.appendChild(s('text', { x: cx, y: H - 10, 'text-anchor': 'middle', class: 'chart__tick' }, r.label));
            }
        });
        // Arrow keys move focus between bars; only the current bar is in the Tab order.
        svgEl.addEventListener('keydown', function (e) {
            var i = bars.indexOf(e.target);
            if (i < 0) return;
            var next = { ArrowRight: i + 1, ArrowDown: i + 1, ArrowLeft: i - 1, ArrowUp: i - 1, Home: 0, End: bars.length - 1 }[e.key];
            if (next === undefined) return;
            e.preventDefault();
            next = Math.max(0, Math.min(bars.length - 1, next));
            bars[i].setAttribute('tabindex', '-1');
            bars[next].setAttribute('tabindex', '0');
            bars[next].focus();
        });
        if (empty) {
            svgEl.appendChild(s('text', { x: padL + plotW / 2, y: padT + plotH / 2, 'text-anchor': 'middle', class: 'chart__empty' },
                'No transactions in this period'));
        }
        box.appendChild(svgEl);
        box.appendChild(tip);

        if (tableBox) {
            tableBox.textContent = '';
            var headRow = el('tr', {}, el('th', { scope: 'col', text: 'Period' }));
            opts.series.forEach(function (sr) { headRow.appendChild(el('th', { scope: 'col', class: 'num', text: sr.label })); });
            var tbody = el('tbody');
            rows.forEach(function (r) {
                var tr = el('tr', {}, el('th', { scope: 'row', text: opts.rowHeader ? opts.rowHeader(r) : r.label }));
                opts.series.forEach(function (sr) { tr.appendChild(el('td', { class: 'num', text: FT.formatNaira(r[sr.key]) })); });
                tbody.appendChild(tr);
            });
            tableBox.appendChild(el('table', { class: 'table' }, el('caption', { class: 'sr-only', text: opts.caption || '' }), el('thead', {}, headRow), tbody));
        }

        // Redraw at the new width when the window is resized.
        if (!box._ftResize) {
            var last = box.clientWidth, timer = null;
            box._ftResize = function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (Math.abs(box.clientWidth - last) > 8) { last = box.clientWidth; groupedBars(box, tableBox, box._ftRows, box._ftOpts); }
                }, 150);
            };
            window.addEventListener('resize', box._ftResize);
        }
        box._ftRows = rows;
        box._ftOpts = opts;
    }

    window.FTUI = {
        groupedBars: groupedBars, shortNaira: shortNaira,
        bindWallet: bindWallet, setBalance: setBalance,
        el: el, svg: svg, badge: badge, title: title, amount: amount, amountClass: amountClass, txnIcon: txnIcon,
        renderList: renderList, renderTableRows: renderTableRows, openDetails: openDetails, downloadReceipt: downloadReceipt,
        empty: empty, errorState: errorState, skeletonList: skeletonList, setUnread: setUnread, refreshUnread: refreshUnread,
        FAILURE_TEXT: FAILURE_TEXT
    };

    // Pages that load their own notification data call setUnread() themselves.
    document.addEventListener('DOMContentLoaded', function () {
        if (!document.body.classList.contains('own-unread')) refreshUnread();
    });
})();
