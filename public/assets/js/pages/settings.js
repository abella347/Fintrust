document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var UI = window.FTUI;
    var el = UI.el;
    var R = FT.rules;
    var $ = function (s) { return document.querySelector(s); };

    function loadProfile() {
        FT.api('/api/user/profile').then(function (p) {
            $('#p-phone').value = p.phone;
            $('[data-bind="email-status"]').textContent = p.email_verified ? '✓ Verified' : 'Not verified — open the link we emailed you. You need this to add or send money.';
            var resend = $('[data-resend-verification]');
            resend.hidden = p.email_verified;
            resend.onclick = function () {
                FT.setBusy(resend, true);
                FT.api('/api/auth/resend-verification', { method: 'POST', body: { email: p.email } })
                    .then(function (r) { FT.toast('Verification email sent', 'success'); resend.disabled = true; resend.removeAttribute('aria-busy'); })
                    .catch(function (err) { FT.toast(err.message, 'error'); FT.setBusy(resend, false); });
            };
            if ($('[data-bind="pin-status"]')) $('[data-bind="pin-status"]').textContent = p.has_pin
                ? 'Your PIN is set. It authorises every transfer. Change it here.'
                : 'You haven’t set a PIN yet — transfers stay disabled until you do.';
            if ($('[data-bind="pin-button"]')) $('[data-bind="pin-button"]').textContent = p.has_pin ? 'Change PIN' : 'Set PIN';
            renderLogins(p.recent_logins || []);
        }).catch(function (err) { UI.errorState($('[data-logins]'), err.message, loadProfile); });
    }

    function renderLogins(list) {
        var box = $('[data-logins]');
        box.textContent = '';
        if (!list.length) { box.appendChild(UI.empty({ title: 'No login history yet' })); return; }
        var ul = el('ul', { class: 'login-list' });
        list.forEach(function (l) {
            ul.appendChild(el('li', {},
                UI.svg(l.successful ? 'check' : 'failed', 18),
                el('div', {},
                    el('strong', { text: l.successful ? 'Successful login' : 'Failed attempt' }),
                    el('small', { text: l.user_agent + ' · ' + l.ip_address })),
                el('time', { datetime: l.attempted_at, text: FT.formatDate(l.attempted_at) })));
            ul.lastChild.className = l.successful ? 'is-ok' : 'is-fail';
        });
        box.appendChild(ul);
    }

    // Clear every password/PIN box after a submit attempt - never leave secrets in the DOM.
    function wipeSecrets(form) {
        form.querySelectorAll('input[type="password"]').forEach(function (i) { i.value = ''; });
    }

    /* Profile */
    var profile = $('#profile-form');
    FT.handleForm(profile, {
        validate: function (v) {
            var p = {};
            if (!R.name(v.first_name)) p.first_name = 'Letters only, please.';
            if (!R.name(v.last_name)) p.last_name = 'Letters only, please.';
            if (!R.phone(v.phone)) p.phone = 'Enter a valid Nigerian mobile number.';
            if (!v.current_password) p.current_password = 'Enter your current password to save.';
            return p;
        },
        request: function (v) { return FT.api('/api/user/profile', { method: 'PUT', body: v }); },
        onSuccess: function (p) {
            wipeSecrets(profile);
            FT.formAlert(profile, 'Profile updated.', 'success');
            var name = document.querySelector('.topbar__user strong');
            if (name) name.textContent = p.first_name + ' ' + p.last_name;
        },
        onError: function () { wipeSecrets(profile); }
    });

    /* Password */
    var pw = $('#password-form');
    var pwInput = pw.querySelector('[name="password"]');
    pwInput.addEventListener('input', function () {
        var c = R.passwordChecks(pwInput.value);
        var score = 0;
        Object.keys(c).forEach(function (k) { pw.querySelector('[data-rule="' + k + '"]').classList.toggle('is-met', c[k]); if (c[k]) score++; });
        var bar = pw.querySelector('[data-strength-bar]');
        bar.style.width = (score * 20) + '%';
        bar.style.backgroundColor = score >= 5 ? 'var(--success)' : (score >= 3 ? 'var(--warning)' : 'var(--danger)');
    });
    FT.handleForm(pw, {
        validate: function (v) {
            var p = {};
            var c = R.passwordChecks(v.password);
            if (!v.current_password) p.current_password = 'Enter your current password.';
            if (!c.length || !c.upper || !c.lower || !c.digit || !c.symbol) p.password = 'Password must meet all the requirements below.';
            else if (v.password === v.current_password) p.password = 'Choose a different password from your current one.';
            if (v.password !== v.password_confirmation) p.password_confirmation = 'Passwords do not match.';
            return p;
        },
        request: function (v) { return FT.api('/api/user/password', { method: 'PUT', body: v }); },
        onSuccess: function (d, v) {
            wipeSecrets(pw);
            pw.querySelectorAll('.is-met').forEach(function (li) { li.classList.remove('is-met'); });
            pw.querySelector('[data-strength-bar]').style.width = '0';
            FT.formAlert(pw, 'Password changed. Other devices have been signed out.', 'success');
        },
        onError: function () { wipeSecrets(pw); }
    });

    /* PIN (customers only) */
    var pinForm = $('#pin-form');
    if (pinForm) pinForm.querySelectorAll('.input-pin').forEach(function (i) {
        i.addEventListener('input', function () { i.value = i.value.replace(/\D/g, '').slice(0, 4); });
    });
    if (pinForm) FT.handleForm(pinForm, {
        validate: function (v) {
            var p = {};
            if (!v.current_password) p.current_password = 'Enter your current password.';
            if (!R.pin(v.pin)) p.pin = 'PIN must be exactly 4 digits.';
            else if (/^(\d)\1{3}$/.test(v.pin) || '0123456789'.indexOf(v.pin) >= 0 || '9876543210'.indexOf(v.pin) >= 0) p.pin = 'Too easy to guess — avoid repeated or sequential digits.';
            if (v.pin !== v.pin_confirmation) p.pin_confirmation = 'PINs do not match.';
            return p;
        },
        request: function (v) { return FT.api('/api/user/pin', { method: 'PUT', body: v }); },
        onSuccess: function () {
            wipeSecrets(pinForm);
            FT.formAlert(pinForm, 'Transaction PIN saved.', 'success');
            $('[data-bind="pin-button"]').textContent = 'Change PIN';
        },
        onError: function () { wipeSecrets(pinForm); }
    });

    var editor = $('[data-avatar-editor]');
    if (editor) {
        var picker = $('#avatar-file');
        var preview = $('[data-avatar-preview]');
        var errBox = $('[data-avatar-error]');
        var btn = {
            pick: $('[data-avatar-pick]'), save: $('[data-avatar-save]'),
            cancel: $('[data-avatar-cancel]'), remove: $('[data-avatar-remove]')
        };
        var current = preview.querySelector('img') ? preview.querySelector('img').getAttribute('src') : null;
        var pending = null;

        var paint = function (target, url, alt) {
            target.textContent = '';
            if (url) target.appendChild(el('img', { src: url, alt: alt || '', width: target === preview ? '88' : '40', height: target === preview ? '88' : '40' }));
            else target.textContent = target.getAttribute('data-initials');
        };
        var editing = function (on) {
            btn.save.hidden = !on; btn.cancel.hidden = !on; btn.pick.hidden = on;
            btn.remove.hidden = on || !current;
            $('[data-avatar-pick-label]').textContent = current ? 'Change photo' : 'Upload photo';
        };
        var fail = function (msg) { errBox.textContent = msg; };

        btn.pick.addEventListener('click', function () { picker.click(); });
        picker.addEventListener('change', function () {
            var file = picker.files && picker.files[0];
            picker.value = '';
            fail('');
            if (!file) return;
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return fail('Choose a JPEG, PNG or WebP image.');
            if (file.size > 15 * 1024 * 1024) return fail('That image is very large. Choose one under 15 MB.');
            var reader = new FileReader();
            reader.onload = function () {
                var img = new Image();
                img.onload = function () {
                    var side = Math.min(img.naturalWidth, img.naturalHeight);
                    if (side < 32) return fail('That image is too small.');
                    var canvas = document.createElement('canvas');
                    canvas.width = canvas.height = 512;
                    var ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, 512, 512);
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, (img.naturalWidth - side) / 2, (img.naturalHeight - side) / 2, side, side, 0, 0, 512, 512);
                    pending = canvas.toDataURL('image/jpeg', 0.88);
                    paint(preview, pending, 'Preview of your new profile picture');
                    editing(true);
                    btn.save.focus();
                };
                img.onerror = function () { fail('That image could not be read.'); };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
        btn.cancel.addEventListener('click', function () {
            pending = null;
            paint(preview, current, 'Your profile picture');
            editing(false);
            btn.pick.focus();
        });
        btn.save.addEventListener('click', function () {
            FT.setBusy(btn.save, true);
            FT.api('/api/user/avatar', { method: 'POST', body: { image: pending } }).then(function (d) {
                current = d.avatar_url;
                pending = null;
                paint(preview, current, 'Your profile picture');
                var top = document.querySelector('[data-avatar]');
                if (top) paint(top, current);
                editing(false);
                FT.toast('Profile picture updated', 'success');
            }).catch(function (err) { fail(err.message); })
              .finally(function () { FT.setBusy(btn.save, false); });
        });
        btn.remove.addEventListener('click', function () {
            FT.confirm({ title: 'Remove your profile picture?', message: 'Your initials will be shown instead.', confirmText: 'Remove', danger: true })
                .then(function (yes) {
                    if (!yes) return;
                    return FT.api('/api/user/avatar', { method: 'DELETE', body: {} }).then(function () {
                        current = null;
                        paint(preview, null);
                        var top = document.querySelector('[data-avatar]');
                        if (top) paint(top, null);
                        editing(false);
                        FT.toast('Profile picture removed', 'success');
                    });
                }).catch(function (err) { fail(err.message); });
        });
    }

    /* Face ID / fingerprint (customers only) */
    var bioForm = $('#bio-form');
    if (bioForm) {
        var devices = $('[data-bio-devices]');
        var renderDevices = function (list) {
            devices.textContent = '';
            if (!list.length) {
                devices.appendChild(el('li', { class: 'device-list__empty', text: 'No devices set up yet. Your PIN is used for every transfer.' }));
                return;
            }
            list.forEach(function (d) {
                var used = d.last_used_at ? 'last used ' + FT.formatDate(d.last_used_at) : 'not used yet';
                devices.appendChild(el('li', {},
                    UI.svg('smartphone', 20),
                    el('div', {}, el('strong', { text: d.label }), el('small', { text: 'Added ' + FT.formatDate(d.created_at, false) + ' · ' + used })),
                    el('button', { type: 'button', class: 'btn btn-sm btn-ghost', 'data-remove-device': String(d.id), 'aria-label': 'Remove ' + d.label, text: 'Remove' })));
            });
        };
        var loadDevices = function () {
            FT.api('/api/user/biometrics').then(function (d) { renderDevices(d.devices); })
                .catch(function (err) { UI.errorState(devices, err.message, loadDevices); });
        };

        window.FTBio.available().then(function (ok) {
            if (ok) return;
            $('[data-bio-unsupported]').hidden = false;
            $('[data-bio-add]').disabled = true;
        });

        FT.handleForm(bioForm, {
            validate: function (v) { return v.current_password ? {} : { current_password: 'Enter your current password.' }; },
            request: function (v) {
                // 1. password check + challenge  2. the device's face/finger prompt  3. store the public key
                return FT.api('/api/user/biometrics/options', { method: 'POST', body: { current_password: v.current_password } })
                    .then(function (options) { return window.FTBio.create(options); })
                    .then(function (credential) { return FT.api('/api/user/biometrics', { method: 'POST', body: { credential: credential, label: v.label } }); });
            },
            onSuccess: function (d) {
                wipeSecrets(bioForm);
                bioForm.querySelector('[name="label"]').value = '';
                FT.formAlert(bioForm, '“' + d.label + '” can now approve your transfers.', 'success');
                loadDevices();
            },
            onError: function () { wipeSecrets(bioForm); }
        });

        devices.addEventListener('click', function (e) {
            var b = e.target.closest('[data-remove-device]');
            if (!b) return;
            var pwBox = $('#bio-current');
            FT.clearErrors(bioForm);
            if (!pwBox.value) {
                FT.showErrors(bioForm, { current_password: 'Enter your current password to remove a device.' });
                pwBox.focus();
                return;
            }
            FT.setBusy(b, true);
            FT.api('/api/user/biometrics/' + encodeURIComponent(b.getAttribute('data-remove-device')), { method: 'DELETE', body: { current_password: pwBox.value } })
                .then(function () { FT.toast('Device removed', 'success'); loadDevices(); })
                .catch(function (err) {
                    FT.setBusy(b, false);
                    if (!FT.showErrors(bioForm, err.fields || {})) FT.formAlert(bioForm, err.message, 'error');
                })
                .finally(function () { wipeSecrets(bioForm); });
        });
        loadDevices();
    }

    loadProfile();
    if (location.hash) {
        var target = document.querySelector(location.hash);
        if (target && target.tagName === 'SECTION') setTimeout(function () { target.focus(); }, 200);
    }
});
