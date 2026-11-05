(function () {
    'use strict';

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    };
    var APP_URL = meta('app-url').replace(/\/+$/, '');

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // Money arrives from the API as integer kobo; format without floats.
    function formatNaira(kobo) {
        var n = Number(kobo);
        if (!Number.isFinite(n)) return '₦0.00';
        var negative = n < 0;
        n = Math.abs(Math.trunc(n));
        var naira = Math.floor(n / 100).toLocaleString('en-NG');
        var rest = String(n % 100).padStart(2, '0');
        return (negative ? '-' : '') + '₦' + naira + '.' + rest;
    }

    function newIdempotencyKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    function ApiError(message, status, code, fields) {
        this.name = 'ApiError';
        this.message = message;
        this.status = status;
        this.code = code || 'ERROR';
        this.fields = fields || {};
    }
    ApiError.prototype = Object.create(Error.prototype);

    function api(path, options) {
        options = options || {};

        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, options.timeout || 20000);

        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': meta('csrf-token')
        }, options.headers || {});

        var init = {
            method: options.method || 'GET',
            headers: headers,
            credentials: 'same-origin',   // Send the session cookie, only to our own site
            signal: controller.signal
        };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }

        return fetch(APP_URL + path, init)
            .then(function (response) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    if (response.ok && payload && payload.success) {
                        return payload.data;
                    }
                    var err = payload && payload.error ? payload.error : {};
                    var message = (payload && payload.message) || 'Request failed (' + response.status + ').';
                    // Only a lost session goes back to the login page; a wrong password typed
                    // into a form (also 401, code INVALID_PASSWORD) is shown on the form instead.
                    if (response.status === 401 && err.code === 'UNAUTHENTICATED' && options.redirectOn401 !== false) {
                        window.location.href = APP_URL + '/login?expired=1';
                    }
                    throw new ApiError(message, response.status, err.code, err.fields);
                });
            })
            .catch(function (error) {
                if (error instanceof ApiError) throw error;
                if (error && error.name === 'AbortError') {
                    throw new ApiError('The server took too long to respond. Check your connection and try again.', 0, 'TIMEOUT');
                }
                throw new ApiError('Network error. Check your connection and try again.', 0, 'NETWORK');
            })
            .finally(function () { clearTimeout(timeout); });
    }

    var TOAST_ICONS = {
        success: '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
        error: '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
        warning: '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
        info: '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
    };

    function toast(message, type) {
        type = TOAST_ICONS[type] ? type : 'info';
        var region = document.querySelector('[data-toast-region]');
        if (!region) return;
        var el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = TOAST_ICONS[type] + '<p></p>';
        el.querySelector('p').textContent = message;  // textContent: never interpreted as HTML
        region.appendChild(el);
        setTimeout(function () {
            el.classList.add('is-leaving');
            el.addEventListener('animationend', function () { el.remove(); }, { once: true });
        }, type === 'error' ? 6000 : 4000);
    }

    function setBusy(button, busy) {
        if (!button) return;
        if (busy) {
            button.setAttribute('aria-busy', 'true');
            button.disabled = true;
        } else {
            button.removeAttribute('aria-busy');
            button.disabled = false;
        }
    }

    function confirmDialog(opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var dialog = document.createElement('dialog');
            dialog.className = 'modal';
            dialog.setAttribute('aria-labelledby', 'ft-confirm-title');
            dialog.innerHTML =
                '<form method="dialog">' +
                '<div class="modal__body"><h2 id="ft-confirm-title"></h2><p class="mb-0"></p></div>' +
                '<div class="modal__actions">' +
                '<button class="btn btn-ghost" value="cancel" type="submit"></button>' +
                '<button class="btn" value="confirm" type="submit"></button>' +
                '</div></form>';
            dialog.querySelector('h2').textContent = opts.title || 'Are you sure?';
            dialog.querySelector('p').textContent = opts.message || '';
            var buttons = dialog.querySelectorAll('button');
            buttons[0].textContent = opts.cancelText || 'Cancel';
            buttons[1].textContent = opts.confirmText || 'Confirm';
            buttons[1].classList.add(opts.danger ? 'btn-danger' : 'btn-primary');
            document.body.appendChild(dialog);
            dialog.addEventListener('close', function () {
                resolve(dialog.returnValue === 'confirm');
                dialog.remove();
            });
            dialog.showModal();
            buttons[1].focus();
        });
    }

    function initPublicNav() {
        var toggle = document.querySelector('[data-nav-toggle]');
        var nav = document.getElementById('site-nav');
        if (!toggle || !nav) return;

        function setOpen(open) {
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            nav.classList.toggle('is-open', open);
        }
        toggle.addEventListener('click', function () {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });
        // Close after choosing a link, on Escape, or when resizing to desktop.
        nav.addEventListener('click', function (e) { if (e.target.closest('a')) setOpen(false); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.classList.contains('is-open')) { setOpen(false); toggle.focus(); }
        });
        window.matchMedia('(min-width: 1024px)').addEventListener('change', function () { setOpen(false); });
    }

    function initSidebar() {
        var sidebar = document.getElementById('app-sidebar');
        if (!sidebar) return;
        var openBtn = document.querySelector('[data-sidebar-open]');
        var backdrop = document.querySelector('.sidebar-backdrop');

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            if (backdrop) backdrop.hidden = !open;
            document.body.classList.toggle('no-scroll', open);
            if (openBtn) openBtn.setAttribute('aria-expanded', String(open));
            if (open) {
                var first = sidebar.querySelector('a, button');
                if (first) first.focus();
            } else if (openBtn) {
                openBtn.focus();
            }
        }
        if (openBtn) openBtn.addEventListener('click', function () { setOpen(true); });
        document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
            el.addEventListener('click', function () { setOpen(false); });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false);
        });
        window.matchMedia('(min-width: 1024px)').addEventListener('change', function (mq) {
            if (mq.matches) { sidebar.classList.remove('is-open'); if (backdrop) backdrop.hidden = true; document.body.classList.remove('no-scroll'); }
        });
    }

    function initThemeToggle() {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var root = document.documentElement;
                var current = root.getAttribute('data-theme') ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                var next = current === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                try { window.localStorage.setItem('ft-theme', next); } catch (e) { /* Ignore */ }
            });
        });
    }

    // <button data-toggle-password="password-input-id">
    function initPasswordToggles() {
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            btn.setAttribute('aria-pressed', 'false');
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', String(show));
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.querySelectorAll('[data-eye]').forEach(function (icon) {
                    icon.hidden = icon.getAttribute('data-eye') !== (show ? 'off' : 'on');
                });
            });
        });
    }

    function initDismissibleAlerts() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-dismiss="alert"]');
            if (btn) btn.closest('.alert').remove();
        });
    }

    // <button data-copy="3021456789">
    function initCopyButtons() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy]');
            if (!btn) return;
            var text = btn.getAttribute('data-copy');
            // Fallback for older browsers / embedded views that refuse the Clipboard API.
            var legacyCopy = function () {
                var ta = document.createElement('textarea');
                ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); toast('Copied to clipboard', 'success'); } catch (err) { toast('Copy failed — select the number and copy it manually', 'error'); }
                ta.remove();
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () { toast('Copied to clipboard', 'success'); }, legacyCopy);
            } else {
                legacyCopy();
            }
        });
    }

    // <button data-confirm="Freeze this wallet?"> inside a form: ask before submitting.
    function initConfirmForms() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var trigger = e.submitter && e.submitter.hasAttribute('data-confirm') ? e.submitter : form.querySelector('[data-confirm]');
            if (!trigger || form.dataset.confirmed === '1') return;
            e.preventDefault();
            confirmDialog({ title: trigger.getAttribute('data-confirm'), message: trigger.getAttribute('data-confirm-message') || '', danger: trigger.hasAttribute('data-danger') })
                .then(function (ok) {
                    if (ok) { form.dataset.confirmed = '1'; form.requestSubmit(trigger); }
                });
        });
    }

    // Named inputs -> plain object. Checkboxes give true/false. The CSRF field
    // is skipped because api() already sends the token as a header.
    function formValues(form) {
        var out = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.name === '_csrf' || el.disabled) return;
            if (el.type === 'checkbox') out[el.name] = el.checked;
            else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
            else out[el.name] = el.value.trim();
        });
        return out;
    }

    function clearErrors(form) {
        form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
        form.querySelectorAll('.field-error').forEach(function (el) { el.textContent = ''; });
        var box = form.querySelector('[data-form-alert]');
        if (box) box.innerHTML = '';
    }

    // Fields: { name: message } from client checks or the API's error.fields
    function showErrors(form, fields) {
        var first = null;
        Object.keys(fields || {}).forEach(function (name) {
            var input = form.querySelector('[name="' + CSS.escape(name) + '"]');
            if (!input) return;
            input.setAttribute('aria-invalid', 'true');
            var slot = document.getElementById(input.id + '-error');
            if (slot) slot.textContent = fields[name];
            if (!first) first = input;
        });
        if (first) first.focus();
        return !!first;
    }

    // Banner at the top of a form for errors that aren't about one field.
    function formAlert(form, message, type) {
        var box = form.querySelector('[data-form-alert]');
        if (!box) { toast(message, type); return; }
        type = type || 'error';
        var cls = { error: 'alert--error', success: 'alert--success', warning: 'alert--warning', info: 'alert--info' }[type] || 'alert--info';
        box.innerHTML = '<div class="alert ' + cls + '" role="' + (type === 'error' ? 'alert' : 'status') + '">' +
            (TOAST_ICONS[type] || TOAST_ICONS.info) + '<p></p></div>';
        box.querySelector('p').textContent = message;
    }

    function handleForm(form, opts) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(form);
            var values = formValues(form);
            var problems = opts.validate ? opts.validate(values) : {};
            if (showErrors(form, problems)) return;

            var button = e.submitter || form.querySelector('[type="submit"]');
            setBusy(button, true);
            opts.request(values)
                .then(function (data) { return opts.onSuccess(data, values); })
                .catch(function (err) {
                    if (!(err instanceof ApiError)) throw err;
                    var hasFieldErrors = showErrors(form, err.fields);
                    if (opts.onError && opts.onError(err, values) === true) return;
                    if (!hasFieldErrors || err.code !== 'VALIDATION_FAILED') formAlert(form, err.message, 'error');
                })
                .finally(function () { setBusy(button, false); });
        });
    }

    // Shared validation rules - the server re-checks every one of these.
    var rules = {
        email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); },
        phone: function (v) { return /^(\+234|0)[789][01]\d{8}$/.test(v.replace(/\s/g, '')); },
        name: function (v) { return /^[\p{L}][\p{L}' -]{0,59}$/u.test(v); },
        walletNumber: function (v) { return /^\d{10}$/.test(v.replace(/\s/g, '')); },
        pin: function (v) { return /^\d{4}$/.test(v); },
        // "1500", "1,500.50" - same grammar as Money::toKobo() on the server
        amount: function (v) { return /^\d{1,9}(\.\d{1,2})?$/.test(String(v).replace(/[,\s₦]/g, '')); },
        passwordChecks: function (v) {
            return {
                length: v.length >= 10,
                upper: /[A-Z]/.test(v),
                lower: /[a-z]/.test(v),
                digit: /\d/.test(v),
                symbol: /[^A-Za-z0-9]/.test(v)
            };
        }
    };

    // Amount string -> kobo integer without floats (for display only).
    function amountToKobo(v) {
        var m = /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(String(v).replace(/[,\s₦]/g, ''));
        if (!m) return null;
        return parseInt(m[1], 10) * 100 + (m[2] ? parseInt((m[2] + '0').slice(0, 2), 10) : 0);
    }

    // ISO UTC -> "10 Oct 2026, 2:45 pm" in Nigeria time.
    function formatDate(iso, withTime) {
        if (!iso) return '—';
        var d = new Date(iso);
        if (isNaN(d)) return '—';
        var o = { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Lagos' };
        if (withTime !== false) { o.hour = 'numeric'; o.minute = '2-digit'; }
        return d.toLocaleString('en-GB', o);
    }

    function formatWallet(n) { return n ? String(n).replace(/^(\d{3})(\d{3})(\d{4})$/, '$1 $2 $3') : '—'; }

    window.FinTrust = {
        handleForm: handleForm,
        formValues: formValues,
        clearErrors: clearErrors,
        showErrors: showErrors,
        formAlert: formAlert,
        rules: rules,
        amountToKobo: amountToKobo,
        formatDate: formatDate,
        formatWallet: formatWallet,
        api: api,
        ApiError: ApiError,
        toast: toast,
        confirm: confirmDialog,
        setBusy: setBusy,
        formatNaira: formatNaira,
        escapeHtml: escapeHtml,
        newIdempotencyKey: newIdempotencyKey,
        url: function (path) { return APP_URL + '/' + String(path || '').replace(/^\/+/, ''); }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initPublicNav();
        initSidebar();
        initThemeToggle();
        initPasswordToggles();
        initDismissibleAlerts();
        initCopyButtons();
        initConfirmForms();
    });
})();
