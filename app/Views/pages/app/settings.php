<?php
/**
 * @var array $currentUser
 */
$isCustomer = App\Core\Auth::can('wallet.use');
$initials = strtoupper(mb_substr($currentUser['first_name'], 0, 1) . mb_substr($currentUser['last_name'], 0, 1));
$photo = avatar_url($currentUser['avatar_path'] ?? null);
?>
<nav class="tabs" aria-label="Settings sections">
    <a href="#profile">Profile</a>
    <a href="#password">Password</a>
    <?php if ($isCustomer): ?>
    <a href="#pin">Transaction PIN</a>
    <a href="#biometrics">Face ID</a>
    <?php endif; ?>
    <a href="#activity">Login activity</a>
</nav>

<div class="settings-grid">
    <div class="col">
        <section class="card o-1" id="profile" aria-labelledby="profile-title" tabindex="-1">
            <h2 id="profile-title">Personal information</h2>

            <div class="avatar-editor" data-avatar-editor>
                <span class="avatar avatar--xl" data-avatar-preview data-initials="<?= e($initials) ?>"><?php if ($photo): ?><img src="<?= e($photo) ?>" alt="Your profile picture" width="88" height="88"><?php else: ?><?= e($initials) ?><?php endif; ?></span>
                <div class="avatar-editor__body">
                    <strong>Profile picture</strong>
                    <p class="text-muted text-sm mb-2" id="avatar-help">JPEG, PNG or WebP. It’s cropped to a square and only you can see it.</p>
                    <label for="avatar-file" class="sr-only">Choose a profile picture</label>
                    <input type="file" id="avatar-file" class="sr-only" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar-help" tabindex="-1">
                    <div class="cluster">
                        <button type="button" class="btn btn-sm btn-outline" data-avatar-pick><?= icon('upload', '', 16) ?> <span data-avatar-pick-label><?= $photo ? 'Change photo' : 'Upload photo' ?></span></button>
                        <button type="button" class="btn btn-sm btn-accent" data-avatar-save hidden>Save photo</button>
                        <button type="button" class="btn btn-sm btn-ghost" data-avatar-cancel hidden>Cancel</button>
                        <button type="button" class="btn btn-sm btn-ghost" data-avatar-remove<?= $photo ? '' : ' hidden' ?>>Remove</button>
                    </div>
                    <p class="field-error" data-avatar-error role="alert"></p>
                </div>
            </div>

            <p class="text-muted text-sm">Your email can’t be changed here. Contact support if it’s wrong.</p>
            <form id="profile-form" novalidate>
                <div class="form-alert" data-form-alert></div>
                <div class="form-row">
                    <?= field(['name' => 'first_name', 'label' => 'First name', 'autocomplete' => 'given-name', 'required' => true, 'maxlength' => 60, 'value' => $currentUser['first_name']]) ?>
                    <?= field(['name' => 'last_name', 'label' => 'Last name', 'autocomplete' => 'family-name', 'required' => true, 'maxlength' => 60, 'value' => $currentUser['last_name']]) ?>
                </div>
                <div class="form-group">
                    <label for="p-email">Email address</label>
                    <input class="input" id="p-email" type="email" value="<?= e($currentUser['email']) ?>" readonly aria-describedby="p-email-status">
                    <p class="help-text" id="p-email-status" data-bind="email-status"></p>
                    <button type="button" class="btn btn-sm btn-outline" data-resend-verification hidden>Resend verification email</button>
                </div>
                <?= field(['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'autocomplete' => 'tel', 'required' => true, 'maxlength' => 14, 'id' => 'p-phone']) ?>
                <?= field(['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'toggle' => true,
                           'id' => 'p-current', 'help' => 'Required to confirm it’s really you.']) ?>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </form>
        </section>
        <?php if ($isCustomer): ?>
        <section class="card o-3" id="pin" aria-labelledby="pin-title" tabindex="-1">
            <h2 id="pin-title">Transaction PIN</h2>
            <p class="text-muted text-sm" data-bind="pin-status">Your 4-digit PIN authorises every transfer.</p>
            <form id="pin-form" novalidate>
                <div class="form-alert" data-form-alert></div>
                <?= field(['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'toggle' => true, 'id' => 'pin-current']) ?>
                <div class="form-row">
                    <?= field(['name' => 'pin', 'label' => 'New PIN', 'type' => 'password', 'inputmode' => 'numeric', 'maxlength' => 4, 'autocomplete' => 'off', 'required' => true, 'class' => 'input-pin', 'id' => 'pin-new']) ?>
                    <?= field(['name' => 'pin_confirmation', 'label' => 'Confirm PIN', 'type' => 'password', 'inputmode' => 'numeric', 'maxlength' => 4, 'autocomplete' => 'off', 'required' => true, 'class' => 'input-pin', 'id' => 'pin-confirm']) ?>
                </div>
                <p class="help-text mb-4">Avoid easy PINs like 1111, 1234 or your birth year.</p>
                <button type="submit" class="btn btn-primary" data-bind="pin-button">Save PIN</button>
            </form>
        </section>
        <?php endif; ?>
    </div>
    <div class="col">
        <section class="card o-2" id="password" aria-labelledby="password-title" tabindex="-1">
            <h2 id="password-title">Change password</h2>
            <form id="password-form" novalidate>
                <div class="form-alert" data-form-alert></div>
                <?= field(['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'toggle' => true, 'id' => 'pw-current']) ?>
                <?= field(['name' => 'password', 'label' => 'New password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'id' => 'pw-new', 'maxlength' => 128]) ?>
                <div class="password-meter" aria-hidden="true"><span data-strength-bar></span></div>
                <ul class="pw-rules" data-password-rules aria-label="Password requirements">
                    <li data-rule="length">At least 10 characters</li>
                    <li data-rule="upper">An uppercase letter</li>
                    <li data-rule="lower">A lowercase letter</li>
                    <li data-rule="digit">A number</li>
                    <li data-rule="symbol">A symbol (e.g. ! @ # $)</li>
                </ul>
                <?= field(['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'toggle' => true, 'id' => 'pw-confirm', 'maxlength' => 128]) ?>
                <button type="submit" class="btn btn-primary">Update password</button>
            </form>
        </section>
        <?php if ($isCustomer): ?>
        <section class="card o-4" id="biometrics" aria-labelledby="bio-title" tabindex="-1">
            <h2 id="bio-title">Face ID / fingerprint</h2>
            <p class="text-muted text-sm">Approve transfers with your face, fingerprint or device screen lock instead of typing your PIN.
                Your biometrics never leave your device; FinTrust only stores a public key that checks the device’s signature.</p>
            <div class="alert alert--info" data-bio-unsupported hidden>
                <?= icon('info', '', 18) ?>
                <p>This browser or device can’t use Face ID / fingerprint here. Use a phone or laptop with Face ID, Touch ID,
                   a fingerprint reader or Windows Hello, and open FinTrust at the address in APP_URL. Your PIN always works.</p>
            </div>
            <ul class="device-list" data-bio-devices aria-live="polite"></ul>
            <form id="bio-form" novalidate>
                <div class="form-alert" data-form-alert></div>
                <?= field(['name' => 'current_password', 'label' => 'Current password', 'type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'toggle' => true,
                           'id' => 'bio-current', 'help' => 'Needed to add or remove a device.']) ?>
                <?= field(['name' => 'label', 'label' => 'Device name (optional)', 'maxlength' => 40, 'id' => 'bio-label', 'placeholder' => 'e.g. My iPhone']) ?>
                <button type="submit" class="btn btn-primary" data-bio-add><?= icon('fingerprint', '', 18) ?> Set up on this device</button>
            </form>
        </section>
        <?php endif; ?>
    </div>
</div>

<section class="card mt-4 settings-activity" id="activity" aria-labelledby="activity-title" tabindex="-1">
    <h2 id="activity-title">Recent login activity</h2>
    <p class="text-muted text-sm">If you don’t recognise a login, change your password straight away.</p>
    <div data-logins></div>
    <hr>
    <div class="cluster cluster--between">
        <div>
            <strong>Sign out</strong>
            <p class="text-muted text-sm mb-0">End your session on this device.</p>
        </div>
        <form method="post" action="<?= e(url('logout')) ?>">
            <?= App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-danger" data-confirm="Log out of FinTrust?"><?= icon('log-out', '', 16) ?> Log out</button>
        </form>
    </div>
</section>
