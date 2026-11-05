<?php
/**
 * Small metric card.
 * @var string      $label
 * @var string      $value      already formatted, e.g. "₦250,000.00"
 * @var string      $icon       icon name
 * @var string      $tone       'success' | 'danger' | 'info' | 'warning' | 'neutral'
 * @var string|null $hint       optional small line under the value
 * @var string|null $id         optional id on the value (for JS updates)
 */
$tone ??= 'neutral';
?>
<article class="stat-card stat-card--<?= e($tone) ?>">
    <span class="stat-card__icon"><?= icon($icon ?? 'chart') ?></span>
    <div>
        <h3 class="stat-card__label"><?= e($label) ?></h3>
        <p class="stat-card__value"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?>><?= e($value) ?></p>
        <p class="stat-card__hint"<?= empty($hint) ? ' hidden' : '' ?>><?= e($hint ?? '') ?></p>
    </div>
</article>
