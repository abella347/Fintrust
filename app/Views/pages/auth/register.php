<h1>Create your account</h1>
<p class="text-muted">Get a FinTrust wallet in under a minute. All funds are simulated.</p>

<form id="register-form" data-auth-form="register" novalidate>
    <div class="form-alert" data-form-alert></div>

    <div class="form-row">
        <?= field(['name' => 'first_name', 'label' => 'First name', 'autocomplete' => 'given-name', 'required' => true, 'maxlength' => 60]) ?>
        <?= field(['name' => 'last_name',  'label' => 'Last name',  'autocomplete' => 'family-name', 'required' => true, 'maxlength' => 60]) ?>
    </div>
    <?= field(['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'autocomplete' => 'email', 'required' => true, 'maxlength' => 191, 'placeholder' => 'you@example.com']) ?>
    <?= field(['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'autocomplete' => 'tel', 'inputmode' => 'tel', 'required' => true, 'maxlength' => 14,
               'placeholder' => '0803 123 4567', 'help' => 'Nigerian mobile number, e.g. 08031234567 or +2348031234567.']) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'maxlength' => 128]) ?>

    <div class="password-meter" aria-hidden="true"><span data-strength-bar></span></div>
    <ul class="pw-rules" data-password-rules aria-label="Password requirements">
        <li data-rule="length">At least 10 characters</li>
        <li data-rule="upper">An uppercase letter</li>
        <li data-rule="lower">A lowercase letter</li>
        <li data-rule="digit">A number</li>
        <li data-rule="symbol">A symbol (e.g. ! @ # $)</li>
    </ul>

    <?= field(['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'maxlength' => 128]) ?>

    <div class="form-group">
        <label class="checkbox" for="f-accept_terms">
            <input type="checkbox" id="f-accept_terms" name="accept_terms" value="1" aria-describedby="f-accept_terms-error">
            <span>I agree to the <a href="<?= e(url('terms')) ?>" target="_blank" rel="noopener">Terms of service</a> and
                <a href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener">Privacy policy</a>, and understand all funds are simulated.</span>
        </label>
        <p class="field-error" id="f-accept_terms-error" role="alert"></p>
    </div>

    <div class="auth-actions">
        <button type="submit" class="btn btn-accent btn-lg btn-shine">Create account</button>
        <a class="btn btn-outline btn-lg" href="<?= e(url('login')) ?>">Log in</a>
    </div>
</form>
