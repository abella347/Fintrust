<div class="transfer">
    <ol class="stepper" aria-label="Transfer progress">
        <li class="is-current" data-step-indicator="1"><span>1</span> Details</li>
        <li data-step-indicator="2"><span>2</span> Review</li>
        <li data-step-indicator="3"><span>3</span> Authorise</li>
        <li data-step-indicator="4"><span>4</span> Done</li>
    </ol>

    <section class="card" aria-live="polite">
        <form id="transfer-details" data-step="1" novalidate>
            <h2>Send money</h2>
            <p class="text-muted">To another FinTrust wallet. Available balance: <strong data-bind="available">…</strong></p>
            <div class="form-alert" data-form-alert></div>

            <?= field(['name' => 'recipient_wallet_number', 'label' => 'Recipient wallet number', 'inputmode' => 'numeric', 'maxlength' => 12,
                       'autocomplete' => 'off', 'required' => true, 'placeholder' => '10-digit wallet number', 'class' => 'mono',
                       'help' => 'We’ll confirm the account name before you send.']) ?>
            <div class="recipient" data-recipient hidden></div>
            <?php if (is_local()): ?>
            <p class="demo-wallets"><span>Demo wallets:</span>
                <button type="button" class="chip" data-fill-wallet="3098765432">Ben · 309 876 5432</button>
                <button type="button" class="chip" data-fill-wallet="3021456789">Ada · 302 145 6789</button>
                <button type="button" class="chip" data-fill-wallet="3055500011" title="This wallet is frozen: use it to see a refused transfer">Chidi (frozen) · 305 550 0011</button>
            </p>
            <?php endif; ?>

            <?= field(['name' => 'amount', 'label' => 'Amount', 'prefix' => '₦', 'inputmode' => 'decimal', 'class' => 'input-money',
                       'placeholder' => '0.00', 'autocomplete' => 'off', 'required' => true, 'maxlength' => 14, 'help' => '…']) ?>

            <?= field(['name' => 'description', 'label' => 'Description (optional)', 'maxlength' => 140, 'placeholder' => 'What’s it for?', 'autocomplete' => 'off',
                       'help' => 'Up to 140 characters. The recipient will see this.']) ?>

            <button type="submit" class="btn btn-primary btn-lg btn-block">Continue</button>
        </form>

        <div data-step="2" hidden>
            <h2 tabindex="-1">Review transfer</h2>
            <p class="text-muted">Check the details carefully. Completed transfers can’t be reversed by you.</p>
            <div class="review">
                <p class="review__amount" data-bind="review-amount"></p>
                <dl class="kv kv--review" data-bind="review-list"></dl>
            </div>
            <div class="step-actions">
                <button type="button" class="btn btn-ghost" data-back="1">Edit details</button>
                <button type="button" class="btn btn-primary btn-lg" data-goto="3">Confirm and continue</button>
            </div>
        </div>

        <form id="transfer-pin" data-step="3" hidden novalidate>
            <h2 tabindex="-1">Authorise this transfer</h2>
            <p class="text-muted">Approve sending <strong data-bind="pin-amount"></strong> to <strong data-bind="pin-name"></strong></p>
            <div class="form-alert" data-form-alert></div>

            <fieldset class="auth-method" data-auth-method hidden>
                <legend>How do you want to approve it?</legend>
                <div class="auth-method__options">
                    <label class="auth-option">
                        <input type="radio" name="method" value="biometric">
                        <span class="auth-option__body"><?= icon('scan-face', '', 22) ?><strong>Face ID / fingerprint</strong><small>Use this device</small></span>
                    </label>
                    <label class="auth-option">
                        <input type="radio" name="method" value="pin" checked>
                        <span class="auth-option__body"><?= icon('lock', '', 22) ?><strong>Transaction PIN</strong><small>Your 4 digits</small></span>
                    </label>
                </div>
            </fieldset>

            <div data-pin-block>
                <?= field(['name' => 'pin', 'label' => '4-digit PIN', 'type' => 'password', 'inputmode' => 'numeric', 'maxlength' => 4,
                           'autocomplete' => 'off', 'required' => true, 'class' => 'input-pin', 'pattern' => '[0-9]{4}']) ?>
                <p class="secure-note secure-note--left"><?= icon('lock', '', 14) ?> Your PIN is checked on the server and never stored in your browser.</p>
            </div>
            <p class="secure-note secure-note--left" data-bio-block hidden><?= icon('scan-face', '', 14) ?>
                Your device will ask for your face, fingerprint or screen lock. FinTrust never sees your biometrics — only a signature for this exact transfer.</p>

            <div class="step-actions">
                <button type="button" class="btn btn-ghost" data-back="2">Back</button>
                <button type="submit" class="btn btn-accent btn-lg" data-bind="send-button">Send money</button>
            </div>
        </form>

        <div data-step="4" hidden>
            <div class="result">
                <span class="result__icon"><?= icon('check-circle', '', 40) ?></span>
                <h2 tabindex="-1">Transfer successful</h2>
                <p class="result__amount" data-bind="result-amount"></p>
                <p class="text-muted" data-bind="result-to"></p>
            </div>
            <dl class="kv kv--review" data-bind="result-list"></dl>
            <div class="step-actions step-actions--wrap">
                <button type="button" class="btn btn-outline" data-result-receipt><?= icon('download', '', 16) ?> Download receipt</button>
                <button type="button" class="btn btn-outline" data-result-details><?= icon('file-text', '', 16) ?> View details</button>
                <button type="button" class="btn btn-primary" data-new-transfer>Send another</button>
            </div>
            <p class="text-center mt-4"><a href="<?= e(url('dashboard')) ?>">Back to dashboard</a></p>
        </div>
    </section>
</div>
