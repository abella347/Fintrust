<?php
/**
 * FinTrust logo: shield mark + wordmark.
 * @var string $href   (optional) link target, defaults to home
 * @var bool   $light  (optional) light text for dark backgrounds
 */
$href  ??= url('');
$light ??= false;
?>
<a href="<?= e($href) ?>" class="logo<?= $light ? ' logo--light' : '' ?>" aria-label="FinTrust home">
    <svg class="logo__mark" width="32" height="32" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M16 2.5 4.5 7v8.2c0 7.2 4.9 12.4 11.5 14.3 6.6-1.9 11.5-7.1 11.5-14.3V7L16 2.5Z" fill="#0B1F3A" stroke="#00B894" stroke-width="1.6"/>
        <path d="M11 10.5h10M11 10.5v11M11 15.8h7" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round"/>
        <circle cx="21.3" cy="20.8" r="2.4" fill="#00B894"/>
    </svg>
    <span class="logo__text">Fin<span>Trust</span></span>
</a>
