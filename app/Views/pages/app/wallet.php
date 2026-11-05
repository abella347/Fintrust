<?php
use App\Core\View;
?>
<div class="alert alert--warning" data-bind="frozen-alert" role="alert" hidden><?= icon('lock') ?>
    <p>Your wallet is frozen pending a security review. Deposits and transfers are paused.</p></div>

<div class="dash-top">
    <?= View::partial('partials/components/balance-card') ?>

    <section class="card" aria-labelledby="details-title">
        <h2 class="h3" id="details-title">Wallet details</h2>
        <dl class="kv" data-wallet-details>
            <dt>Wallet number</dt><dd><span class="skeleton skeleton--text"></span></dd>
            <dt>Status</dt><dd><span class="skeleton skeleton--text"></span></dd>
            <dt>Currency</dt><dd><span class="skeleton skeleton--text"></span></dd>
            <dt>Opened</dt><dd><span class="skeleton skeleton--text"></span></dd>
        </dl>
        <button type="button" class="btn btn-outline btn-sm mt-4" data-open-receive><?= icon('arrow-down-left', '', 16) ?> Receive money</button>
    </section>
</div>

<div class="dash-grid mt-5">
    <section class="card" id="add-money" aria-labelledby="add-title" tabindex="-1">
        <div class="card__header">
            <h2 id="add-title">Add money</h2>
            <span class="badge badge--info">Simulated</span>
        </div>
        <p class="text-muted text-sm">Top up instantly with simulated funds. No real money or card is involved.</p>

        <form id="deposit-form" novalidate>
            <div class="form-alert" data-form-alert></div>
            <?= field(['name' => 'amount', 'label' => 'Amount', 'prefix' => '₦', 'inputmode' => 'decimal', 'class' => 'input-money',
                       'placeholder' => '0.00', 'autocomplete' => 'off', 'required' => true, 'maxlength' => 14, 'help' => 'Between ₦100.00 and ₦1,000,000.00 per deposit.']) ?>
            <div class="chips" role="group" aria-label="Quick amounts">
                <?php foreach (['5000', '10000', '50000', '100000'] as $quick): ?>
                    <button type="button" class="chip" data-quick-amount="<?= e($quick) ?>">₦<?= e(number_format((int) $quick)) ?></button>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-accent btn-lg btn-block mt-4"><?= icon('plus', '', 18) ?> Add money</button>
        </form>
    </section>

    <section class="card" aria-labelledby="limits-title">
        <h2 class="h3" id="limits-title">Your limits</h2>
        <div data-limits><span class="skeleton skeleton--row"></span><span class="skeleton skeleton--row"></span></div>
    </section>
</div>

<section class="card mt-5" aria-labelledby="activity-title">
    <div class="card__header">
        <h2 id="activity-title">Recent wallet activity</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('transactions')) ?>">Full history <?= icon('chevron-right', '', 16) ?></a>
    </div>
    <div data-activity></div>
</section>

<?= View::partial('partials/components/receive-dialog', ['currentUser' => $currentUser]) ?>
