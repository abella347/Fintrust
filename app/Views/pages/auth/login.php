<?php
/**
 * @var string $next  validated with Auth::safeNext()
 */
/**
 * @var bool $expired
 */
?>
<h1>Welcome back</h1>
<p class="text-muted">Log in to your FinTrust account.</p>

<?php if ($expired): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert-triangle') ?><p>Your session ended. Please log in again.</p></div>
<?php endif; ?>

<form id="login-form" data-auth-form="login" novalidate>
    <div class="form-alert" data-form-alert></div>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <?= field(['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'autocomplete' => 'username', 'required' => true, 'maxlength' => 191]) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'toggle' => true, 'maxlength' => 128]) ?>

    <div class="form-meta">
        <a href="<?= e(url('forgot-password')) ?>">Forgot password?</a>
    </div>

    <div class="auth-actions">
        <button type="submit" class="btn btn-accent btn-lg btn-shine">Log in</button>
        <a class="btn btn-outline btn-lg" href="<?= e(url('register')) ?>">Create account</a>
    </div>
</form>

<p class="secure-note"><?= icon('lock', '', 14) ?> Too many failed attempts temporarily lock the account to stop password guessing.</p>

<?php if (is_local()): ?>
    <div class="dev-box">
        <p class="mb-2"><strong>Demo accounts</strong> · shown in local development only</p>
        <p class="text-sm text-muted mb-0">
            Customer: <code>ada@fintrust.test</code> or <code>ben@fintrust.test</code> / <code>Demo@Pass2026</code> (PIN <code>4826</code>)<br>
            Frozen wallet: <code>chidi@fintrust.test</code> · Admin: <code>admin@fintrust.test</code> / <code>Admin@FinTrust2026</code>
        </p>
    </div>
<?php endif; ?>
