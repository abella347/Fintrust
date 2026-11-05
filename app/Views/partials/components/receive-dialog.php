<?php /**
 * @var array $currentUser
 */ ?>
<dialog class="modal" id="receive-dialog" aria-labelledby="receive-title">
    <div class="modal__body text-center">
        <span class="empty-state__icon"><?= icon('arrow-down-left', '', 28) ?></span>
        <h2 id="receive-title">Receive money</h2>
        <p class="text-muted">Share your wallet number. Other FinTrust users can send you money with it.</p>
        <p class="receive-number mono" data-bind="receive-number">— — —</p>
        <p class="text-sm text-muted mb-0">Account name shown to senders: <strong><?= e(isset($currentUser) ? $currentUser['first_name'] . ' ' . mb_substr($currentUser['last_name'], 0, 1) . '.' : '') ?></strong></p>
    </div>
    <div class="modal__actions">
        <button type="button" class="btn btn-ghost" data-close-dialog>Close</button>
        <button type="button" class="btn btn-outline" data-share-wallet hidden><?= icon('send', '', 16) ?> Share</button>
        <button type="button" class="btn btn-primary" data-copy="" data-bind="receive-copy"><?= icon('copy', '', 16) ?> Copy number</button>
    </div>
</dialog>
