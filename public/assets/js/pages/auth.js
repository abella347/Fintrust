document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var FT = window.FinTrust;
    var R = FT.rules;

    /* Live password strength (register + reset) */
    function wirePasswordRules(form) {
        var input = form.querySelector('[name="password"]');
        var list = form.querySelector('[data-password-rules]');
        var bar = form.querySelector('[data-strength-bar]');
        if (!input || !list) return;
        var colors = ['var(--danger)', 'var(--danger)', 'var(--warning)', 'var(--warning)', 'var(--success)', 'var(--success)'];
        input.addEventListener('input', function () {
            var checks = R.passwordChecks(input.value);
            var score = 0;
            Object.keys(checks).forEach(function (k) {
                var li = list.querySelector('[data-rule="' + k + '"]');
                if (li) li.classList.toggle('is-met', checks[k]);
                if (checks[k]) score++;
            });
            if (bar) {
                bar.style.width = (score / 5 * 100) + '%';   // CSSOM style changes are allowed by our CSP
                bar.style.backgroundColor = colors[score];
            }
        });
    }

    function passwordProblems(v, prefix) {
        var p = {};
        var c = R.passwordChecks(v.password || '');
        if (!c.length || !c.upper || !c.lower || !c.digit || !c.symbol) {
            p[prefix + 'password'] = 'Password must meet all the requirements below.';
        }
        if (v.password !== v.password_confirmation) p.password_confirmation = 'Passwords do not match.';
        return p;
    }

    /* Register */
    var register = document.querySelector('[data-auth-form="register"]');
    if (register) {
        wirePasswordRules(register);
        FT.handleForm(register, {
            validate: function (v) {
                var p = {};
                if (!R.name(v.first_name)) p.first_name = 'Enter your first name (letters only).';
                if (!R.name(v.last_name)) p.last_name = 'Enter your last name (letters only).';
                if (!R.email(v.email)) p.email = 'Enter a valid email address.';
                if (!R.phone(v.phone)) p.phone = 'Enter a valid Nigerian mobile number.';
                Object.assign(p, passwordProblems(v, ''));
                if (v.password && v.email && v.password.toLowerCase().indexOf(v.email.split('@')[0].toLowerCase()) >= 0 && v.email.split('@')[0].length >= 3) {
                    p.password = 'Your password must not contain your email name.';
                }
                if (!v.accept_terms) p.accept_terms = 'Please accept the terms to continue.';
                return p;
            },
            request: function (v) { return FT.api('/api/auth/register', { method: 'POST', body: v }); },
            onSuccess: function (data) {
                window.location.href = FT.url('verify-email?email=' + encodeURIComponent(data.email));
            }
        });
    }

    /* Login */
    var login = document.querySelector('[data-auth-form="login"]');
    if (login) {
        FT.handleForm(login, {
            validate: function (v) {
                var p = {};
                if (!R.email(v.email)) p.email = 'Enter the email you registered with.';
                if (!v.password) p.password = 'Enter your password.';
                return p;
            },
            request: function (v) { return FT.api('/api/auth/login', { method: 'POST', body: v, redirectOn401: false }); },
            onSuccess: function (data) {
                FT.formAlert(login, 'Signed in. Redirecting…', 'success');
                window.location.href = FT.url(data.redirect || 'dashboard');
            },
            onError: function (err) {
                // Never say WHICH of email/password was wrong (prevents account discovery).
                if (err.code === 'INVALID_CREDENTIALS') {
                    login.querySelector('[name="password"]').value = '';
                }
            }
        });
    }

    /* Forgot password */
    var forgot = document.querySelector('[data-auth-form="forgot"]');
    if (forgot) {
        FT.handleForm(forgot, {
            validate: function (v) { return R.email(v.email) ? {} : { email: 'Enter a valid email address.' }; },
            request: function (v) { return FT.api('/api/auth/forgot-password', { method: 'POST', body: v }); },
            onSuccess: function () {
                var sent = document.querySelector('[data-sent-state]');
                sent.querySelector('[data-sent-message]').textContent =
                    'If an account exists for that address, a reset link is on its way. It expires in 30 minutes.';
                forgot.hidden = true;
                sent.hidden = false;
                sent.querySelector('h2').setAttribute('tabindex', '-1');
                sent.querySelector('h2').focus();
            }
        });
    }

    /* Reset password */
    var reset = document.querySelector('[data-auth-form="reset"]');
    if (reset) {
        wirePasswordRules(reset);
        FT.handleForm(reset, {
            validate: function (v) { return passwordProblems(v, ''); },
            request: function (v) { return FT.api('/api/auth/reset-password', { method: 'POST', body: v }); },
            onSuccess: function () {
                reset.querySelectorAll('input, button').forEach(function (el) { el.disabled = true; });
                FT.formAlert(reset, 'Password updated. Redirecting you to log in…', 'success');
                setTimeout(function () { window.location.href = FT.url('login'); }, 1800);
            }
        });
    }

    /* Verify email / resend */
    var verifyBtn = document.querySelector('[data-verify-token]');
    if (verifyBtn) {
        var box = verifyBtn.closest('[data-verify-box]');
        verifyBtn.addEventListener('click', function () {
            FT.setBusy(verifyBtn, true);
            FT.api('/api/auth/verify-email', { method: 'POST', body: { token: verifyBtn.getAttribute('data-verify-token') } })
                .then(function () {
                    FT.formAlert(box, 'Email verified! You can now log in.', 'success');
                    verifyBtn.hidden = true;
                })
                .catch(function (err) { FT.formAlert(box, err.message, 'error'); })
                .finally(function () { FT.setBusy(verifyBtn, false); });
        });
    }

    var resendBtn = document.querySelector('[data-resend-email]');
    if (resendBtn) {
        var resendBox = resendBtn.parentElement;
        resendBtn.addEventListener('click', function () {
            FT.setBusy(resendBtn, true);
            FT.api('/api/auth/resend-verification', { method: 'POST', body: { email: resendBtn.getAttribute('data-resend-email') } })
                .then(function () {
                    FT.formAlert(resendBox, 'If your account still needs verifying, a new link has been sent.', 'success');
                    // Small cool-down so the button can't be spammed.
                    resendBtn.disabled = true;
                    setTimeout(function () { resendBtn.disabled = false; }, 60000);
                })
                .catch(function (err) { FT.formAlert(resendBox, err.message, 'error'); resendBtn.disabled = false; })
                .finally(function () { resendBtn.removeAttribute('aria-busy'); });
        });
    }
});
