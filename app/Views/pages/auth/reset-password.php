<?php /**
 * @var string $token
 */ ?>
<h1>Choose a new password</h1>

<?php if ($token === ''): ?>
    <div class="alert alert--error" role="alert"><?= icon('x-circle') ?>
        <p>This reset link is invalid or incomplete. Please request a new one.</p></div>
    <a class="btn btn-primary btn-block" href="<?= e(url('forgot-password')) ?>">Request a new link</a>
<?php else: ?>
    <p class="text-muted">For your security, all other sessions will be signed out after you change it.</p>
    <form id="reset-form" data-auth-form="reset" novalidate>
        <div class="form-alert" data-form-alert></div>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <?= field(['name' => 'password', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'maxlength' => 128]) ?>
        <div class="password-meter" aria-hidden="true"><span data-strength-bar></span></div>
        <ul class="pw-rules" data-password-rules aria-label="Password requirements">
            <li data-rule="length">At least 10 characters</li>
            <li data-rule="upper">An uppercase letter</li>
            <li data-rule="lower">A lowercase letter</li>
            <li data-rule="digit">A number</li>
            <li data-rule="symbol">A symbol (e.g. ! @ # $)</li>
        </ul>
        <?= field(['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'maxlength' => 128]) ?>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Update password</button>
    </form>
<?php endif; ?>

<p class="text-center mt-5"><a href="<?= e(url('login')) ?>">Back to log in</a></p>
