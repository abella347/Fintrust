<?php
/** Wallet balance card. */
?>
<section class="balance-card" aria-labelledby="balance-label">
    <div class="cluster cluster--between">
        <p class="balance-card__label" id="balance-label"><?= icon('wallet', '', 16) ?> Available balance
            <span class="badge badge--success balance-card__status" data-bind="wallet-status" hidden></span></p>
        <button type="button" class="icon-btn" data-balance-toggle aria-label="Hide balance" aria-pressed="false">
            <span data-eye="on"><?= icon('eye') ?></span><span data-eye="off" hidden><?= icon('eye-off') ?></span>
        </button>
    </div>
    <p class="balance-card__amount" data-bind="balance" aria-live="polite"><span class="skeleton skeleton--on-dark skeleton--amount"></span></p>
    <div class="balance-card__meta">
        <span>Wallet no. <strong data-bind="wallet-number">— — —</strong></span>
        <button type="button" class="btn btn-sm btn-accent" data-copy="" data-bind="wallet-copy" disabled><?= icon('copy', '', 14) ?> Copy</button>
    </div>
</section>
