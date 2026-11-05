<?php
/**
 * Friendly placeholder when a list has no rows yet.
 * @var string      $title
 * @var string      $message
 * @var string|null $actionLabel
 * @var string|null $actionHref
 * @var string      $icon
 * @var int|null    $level   heading level (default 3; 1 when it is the whole page, e.g. 404)
 */
$h = 'h' . max(1, min(6, (int) ($level ?? 3)));
?>
<div class="empty-state">
    <span class="empty-state__icon"><?= icon($icon ?? 'history', '', 28) ?></span>
    <<?= $h ?> class="empty-state__title"><?= e($title) ?></<?= $h ?>>
    <p><?= e($message) ?></p>
    <?php if (!empty($actionLabel) && !empty($actionHref)): ?>
        <a class="btn btn-primary" href="<?= e($actionHref) ?>"><?= e($actionLabel) ?></a>
    <?php endif; ?>
</div>
