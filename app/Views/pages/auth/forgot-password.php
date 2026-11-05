<h1>Forgot your password?</h1>
<p class="text-muted">Enter your email and we’ll send you a link to choose a new one. The link works once and expires after 30 minutes.</p>

<form id="forgot-form" data-auth-form="forgot" novalidate>
    <div class="form-alert" data-form-alert></div>
    <?= field(['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'autocomplete' => 'email', 'required' => true, 'maxlength' => 191]) ?>
    <button type="submit" class="btn btn-primary btn-lg btn-block">Send reset link</button>
</form>

<div class="sent-state" data-sent-state hidden>
    <span class="empty-state__icon"><?= icon('mail', '', 28) ?></span>
    <h2>Check your inbox</h2>
    <p class="text-muted" data-sent-message></p>
    <?php if (is_local()): ?>
        <p class="text-sm text-muted">Local development: emails are written to <code>storage/mail/</code> and the <code>email_outbox</code> table instead of being sent.</p>
    <?php endif; ?>
</div>

<p class="text-center mt-5"><a href="<?= e(url('login')) ?>">Back to log in</a></p>
