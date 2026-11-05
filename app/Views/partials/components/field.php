<?php
/**
 * Accessible form field: label + input + help text + error slot.
 * @var string      $name
 * @var string      $label
 * @var string      $type          text|email|tel|password|number|date|textarea (default text)
 * @var string|null $id
 * @var string|null $value
 * @var string|null $autocomplete
 * @var string|null $inputmode
 * @var string|null $placeholder
 * @var string|null $help
 * @var bool        $required
 * @var int|null    $maxlength
 * @var string|null $pattern
 * @var string|null $prefix        e.g. '₦'
 * @var string      $class         extra input classes
 * @var bool        $toggle        password show/hide button
 */
$id        ??= 'f-' . $name;
$type      ??= 'text';
$required  ??= false;
$class     ??= '';
$toggle    ??= false;
$prefix    ??= null;
$describedBy = trim((!empty($help) ? $id . '-help ' : '') . $id . '-error');

$attrs = sprintf(
    'id="%s" name="%s" class="input %s" aria-describedby="%s"%s%s%s%s%s%s%s',
    e($id), e($name), e($class), e($describedBy),
    $required ? ' required' : '',
    !empty($autocomplete) ? ' autocomplete="' . e($autocomplete) . '"' : '',
    !empty($inputmode) ? ' inputmode="' . e($inputmode) . '"' : '',
    !empty($placeholder) ? ' placeholder="' . e($placeholder) . '"' : '',
    !empty($maxlength) ? ' maxlength="' . (int) $maxlength . '"' : '',
    !empty($pattern) ? ' pattern="' . e($pattern) . '"' : '',
    isset($min) ? ' min="' . e($min) . '"' : ''
);
?>
<div class="form-group">
    <label for="<?= e($id) ?>"><?= e($label) ?><?php if ($required): ?> <span class="req" aria-hidden="true">*</span><?php endif; ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea <?= $attrs ?>><?= e($value ?? '') ?></textarea>
    <?php elseif ($toggle || $prefix !== null): ?>
        <div class="input-group<?= $prefix !== null ? ' input-group--prefix' : '' ?>">
            <?php if ($prefix !== null): ?><span class="input-prefix" aria-hidden="true"><?= e($prefix) ?></span><?php endif; ?>
            <input type="<?= e($type) ?>" <?= $attrs ?> value="<?= e($value ?? '') ?>">
            <?php if ($toggle): ?>
                <button type="button" class="input-group__btn" data-toggle-password="<?= e($id) ?>" aria-label="Show password">
                    <span data-eye="on"><?= icon('eye') ?></span><span data-eye="off" hidden><?= icon('eye-off') ?></span>
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <input type="<?= e($type) ?>" <?= $attrs ?> value="<?= e($value ?? '') ?>">
    <?php endif; ?>
    <?php if (!empty($help)): ?><p class="help-text" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
    <p class="field-error" id="<?= e($id) ?>-error" role="alert"></p>
</div>
