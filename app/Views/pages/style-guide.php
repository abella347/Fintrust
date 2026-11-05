<?php
/**
 * @var array $sampleRows
 */
use App\Core\View;
use App\Helpers\Format;
use App\Helpers\Icon;
use App\Helpers\Money;
?>
<div class="alert alert--info" role="status"><?= icon('info') ?>
    <p>Developer page (local only). Resize the window and use the moon/sun button to check every component in both themes.</p></div>

<div class="grid-2">
    <section class="balance-card" aria-labelledby="sg-balance">
        <div class="cluster cluster--between">
            <p class="balance-card__label" id="sg-balance"><?= icon('wallet', '', 16) ?> Available balance</p>
            <button type="button" class="icon-btn" id="demo-mask" aria-label="Hide balance" aria-pressed="false"><?= icon('eye') ?></button>
        </div>
        <p class="balance-card__amount" id="demo-balance"><?= e(Money::format(23499950)) ?></p>
        <div class="balance-card__meta">
            <span>Wallet no. <strong><?= e(Format::walletNumber('3021456789')) ?></strong></span>
            <button type="button" class="btn btn-sm btn-accent" data-copy="3021456789"><?= icon('copy', '', 14) ?> Copy</button>
        </div>
    </section>

    <section class="card" aria-labelledby="sg-quick">
        <h2 class="h3" id="sg-quick">Quick actions</h2>
        <nav class="quick-actions" aria-label="Quick actions">
            <a class="quick-action" href="#"><?= icon('plus') ?>Add money</a>
            <a class="quick-action" href="#"><?= icon('send') ?>Send</a>
            <a class="quick-action" href="#"><?= icon('arrow-down-left') ?>Receive</a>
            <a class="quick-action" href="#"><?= icon('history') ?>History</a>
            <a class="quick-action" href="#"><?= icon('settings') ?>Settings</a>
        </nav>
    </section>
</div>

<div class="grid-4 mt-5">
    <?= View::partial('partials/components/stat-card', ['label' => 'Money in',  'value' => Money::format(25000000), 'icon' => 'arrow-down-left', 'tone' => 'success', 'hint' => 'This month']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Money out', 'value' => Money::format(1500050),  'icon' => 'arrow-up-right',  'tone' => 'danger',  'hint' => 'This month']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Transactions', 'value' => '3', 'icon' => 'activity', 'tone' => 'info']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Security', 'value' => 'PIN set', 'icon' => 'shield-check', 'tone' => 'success', 'hint' => 'Email verified']) ?>
</div>

<section class="card mt-5" aria-labelledby="sg-table">
    <div class="card__header">
        <h2 id="sg-table">Transaction table</h2>
        <span class="text-muted text-sm">Becomes stacked cards below 720px</span>
    </div>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
            <tr><th scope="col">Reference</th><th scope="col">Details</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col" class="num">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sampleRows as $row): ?>
                <tr>
                    <td data-label="Reference" class="mono"><?= e($row['ref']) ?></td>
                    <td data-label="Details"><strong><?= e($row['party']) ?></strong><br><small class="text-muted"><?= e($row['desc']) ?></small></td>
                    <td data-label="Date" class="nowrap"><?= e(Format::dateTime($row['date'])) ?></td>
                    <td data-label="Status"><?= View::partial('partials/components/status-badge', ['status' => $row['status']]) ?></td>
                    <td data-label="Amount" class="num <?= $row['dir'] === 'in' ? 'amount-in' : 'amount-out' ?>">
                        <?= $row['dir'] === 'in' ? '+' : '−' ?><?= e(Money::format($row['amount'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="help-text mt-2">The last row's description is <code>&lt;script&gt;…</code> — it shows as text because every value passes through <code>e()</code>.</p>
</section>

<div class="grid-2 mt-5">
    <section class="card" aria-labelledby="sg-buttons">
        <h2 class="h3" id="sg-buttons">Buttons</h2>
        <div class="cluster">
            <button class="btn btn-primary" type="button">Primary</button>
            <button class="btn btn-accent" type="button">Accent</button>
            <button class="btn btn-outline" type="button">Outline</button>
            <button class="btn btn-ghost" type="button">Ghost</button>
            <button class="btn btn-danger" type="button">Danger</button>
            <button class="btn btn-primary" type="button" disabled>Disabled</button>
            <button class="btn btn-accent" type="button" id="demo-busy">Click: loading</button>
        </div>
        <h3 class="mt-5">Feedback</h3>
        <div class="cluster">
            <button class="btn btn-sm btn-outline" type="button" data-demo-toast="success">Success toast</button>
            <button class="btn btn-sm btn-outline" type="button" data-demo-toast="error">Error toast</button>
            <button class="btn btn-sm btn-outline" type="button" data-demo-toast="warning">Warning toast</button>
            <button class="btn btn-sm btn-outline" type="button" data-demo-toast="info">Info toast</button>
            <button class="btn btn-sm btn-primary" type="button" id="demo-confirm">Confirm dialog</button>
        </div>
        <h3 class="mt-5">Badges</h3>
        <div class="cluster">
            <?php foreach (['successful', 'pending', 'failed', 'reversed', 'frozen', 'active'] as $s): ?>
                <?= View::partial('partials/components/status-badge', ['status' => $s]) ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card" aria-labelledby="sg-form">
        <h2 class="h3" id="sg-form">Form controls</h2>
        <form novalidate>
            <div class="form-group">
                <label for="sg-wallet">Recipient wallet number</label>
                <input class="input mono" id="sg-wallet" inputmode="numeric" maxlength="10" placeholder="10-digit wallet number" autocomplete="off">
                <p class="help-text">We'll show the account name before you confirm.</p>
            </div>
            <div class="form-group">
                <label for="sg-amount">Amount</label>
                <div class="input-group input-group--prefix">
                    <span class="input-prefix" aria-hidden="true">₦</span>
                    <input class="input input-money" id="sg-amount" inputmode="decimal" placeholder="0.00" aria-invalid="true" aria-describedby="sg-amount-err">
                </div>
                <p class="field-error" id="sg-amount-err"><?= icon('alert-triangle', '', 14) ?> Amount must be at least ₦100.00</p>
            </div>
            <div class="form-group">
                <label for="sg-password">Password</label>
                <div class="input-group">
                    <input class="input" id="sg-password" type="password" value="Demo@Pass2026" autocomplete="off">
                    <button type="button" class="input-group__btn" data-toggle-password="sg-password" aria-label="Show password">
                        <span data-eye="on"><?= icon('eye') ?></span><span data-eye="off" hidden><?= icon('eye-off') ?></span>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="sg-pin">Transaction PIN</label>
                <input class="input input-pin" id="sg-pin" type="password" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="••••">
            </div>
            <label class="checkbox"><input type="checkbox"> Save as beneficiary</label>
        </form>
    </section>
</div>

<div class="grid-2 mt-5">
    <section class="card" aria-labelledby="sg-alerts">
        <h2 class="h3" id="sg-alerts">Alerts</h2>
        <?= View::partial('partials/flash', ['flashes' => [
            'success' => 'Transfer successful. Reference FT20261010TRF3A4B5C6D7.',
            'error'   => 'Insufficient balance for this transfer.',
            'warning' => 'Your wallet is frozen. Contact support.',
            'info'    => 'All funds in FinTrust are simulated.',
        ]]) ?>
    </section>

    <section class="card" aria-labelledby="sg-loading">
        <h2 class="h3" id="sg-loading">Loading &amp; empty states</h2>
        <div class="cluster mb-4"><span class="spinner" role="status" aria-label="Loading"></span><span class="text-muted">Spinner</span></div>
        <div aria-hidden="true">
            <span class="skeleton skeleton--title"></span>
            <span class="skeleton skeleton--text"></span>
            <span class="skeleton skeleton--text"></span>
            <span class="skeleton skeleton--row"></span>
        </div>
        <?= View::partial('partials/components/empty-state', [
            'title' => 'No transactions yet', 'message' => 'Fund your wallet to make your first simulated transfer.',
            'actionLabel' => 'Add money', 'actionHref' => '#', 'icon' => 'history',
        ]) ?>
    </section>
</div>

<section class="card mt-5" aria-labelledby="sg-money">
    <h2 class="h3" id="sg-money">Money formatting (integer kobo → naira)</h2>
    <div class="form-row">
        <div class="form-group">
            <label for="demo-kobo">Kobo (integer)</label>
            <input class="input mono" id="demo-kobo" type="number" value="150050" step="1">
        </div>
        <div class="form-group">
            <span class="label">FinTrust.formatNaira()</span>
            <p class="stat-card__value mono" id="demo-kobo-out" aria-live="polite"></p>
        </div>
    </div>
    <dl class="kv">
        <dt>Money::toKobo('1,500.5')</dt><dd class="mono"><?= e((string) Money::toKobo('1,500.5')) ?></dd>
        <dt>Money::format(150050)</dt><dd class="mono"><?= e(Money::format(150050)) ?></dd>
        <dt>0.1 + 0.2 in floats</dt><dd class="mono"><?= e(var_export(0.1 + 0.2, true)) ?> &larr; why we never use floats</dd>
    </dl>
</section>

<section class="card mt-5" aria-labelledby="sg-colors">
    <h2 class="h3" id="sg-colors">Brand colours</h2>
    <div class="grid-4">
        <?php foreach (['#0B1F3A' => 'Navy (primary)', '#00B894' => 'Emerald (secondary)', '#F8FAFC' => 'Off-white (background)', '#E8F4FF' => 'Light blue (accent)'] as $hex => $name): ?>
            <figure class="mb-0">
                <svg class="swatch w-100" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="40" fill="<?= e($hex) ?>"/></svg>
                <figcaption class="text-sm"><strong><?= e($name) ?></strong><br><span class="mono text-muted"><?= e($hex) ?></span></figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <h2 class="h3 mt-5">Icons (<?= count(Icon::names()) ?>)</h2>
    <div class="icon-grid">
        <?php foreach (Icon::names() as $name): ?>
            <figure><?= icon($name, '', 22) ?><figcaption><?= e($name) ?></figcaption></figure>
        <?php endforeach; ?>
    </div>
</section>
<script src="<?= e(asset('js/pages/dev-tools.js')) ?>" defer></script>
