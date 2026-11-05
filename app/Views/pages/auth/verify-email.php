<?php
/**
 * @var string $token
 */
/**
 * @var string $email
 */
?>
<?php if ($token !== ''): ?>
    <div class="sent-state" data-verify-box>
        <span class="empty-state__icon"><?= icon('shield-check', '', 28) ?></span>
        <h1>Verify your email</h1>
        <p class="text-muted">Confirm this email address belongs to you.</p>
        <div class="form-alert" data-form-alert></div>
        <button type="button" class="btn btn-primary btn-lg btn-block" data-verify-token="<?= e($token) ?>">Verify my email</button>
    </div>
<?php else: ?>
    <div class="sent-state">
        <span class="empty-state__icon"><?= icon('mail', '', 28) ?></span>
        <h1>Check your inbox</h1>
        <p class="text-muted">
            We sent a verification link<?= $email !== '' ? ' to <strong>' . e($email) . '</strong>' : '' ?>.
            Open it to activate your account. The link expires in 24 hours.
        </p>
        <?php if (is_local()): ?>
            <p class="text-sm text-muted">Local development: open the newest file in <code>storage/mail/</code> to find the link.</p>
        <?php endif; ?>
        <div class="form-alert" data-form-alert></div>
        <?php if ($email !== ''): ?>
            <button type="button" class="btn btn-outline btn-block" data-resend-email="<?= e($email) ?>">Resend email</button>
        <?php endif; ?>
    </div>
<?php endif; ?>
<p class="text-center mt-5"><a href="<?= e(url('login')) ?>">Go to log in</a></p>
