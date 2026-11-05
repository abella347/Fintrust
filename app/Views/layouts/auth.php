<?php
/**
 * Layout for login / register / password reset.
 * @var string $content
 * @var array  $flashes
 */
use App\Core\View;

$vars = get_defined_vars();
$vars['bodyClass'] = trim(($bodyClass ?? '') . ' auth-body');
echo View::partial('partials/head', $vars);

// The pill in the photo panel's nav points to "the other" auth page.
$page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
$pill = $page === 'login'
    ? ['label' => 'Sign up', 'href' => url('register')]
    : ['label' => 'Log in', 'href' => url('login')];
?>
<div class="auth-scene">
    <div class="auth-scene__orb auth-scene__orb--a" aria-hidden="true"></div>
    <div class="auth-scene__orb auth-scene__orb--b" aria-hidden="true"></div>

    <div class="auth-panel">
        <main id="main" class="auth-form-side" tabindex="-1">
            <div class="auth-form-side__top">
                <?= View::partial('partials/logo') ?>
                <?= View::partial('partials/theme-toggle') ?>
            </div>
            <div class="auth-card">
                <?= View::partial('partials/flash', ['flashes' => $flashes ?? []]) ?>
                <?= $content ?>
            </div>
            <svg class="auth-wave" viewBox="0 0 100 800" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                <path d="M0,0 H42 C118,150 -6,290 52,420 C104,540 18,660 64,800 H0 Z"/>
            </svg>
        </main>

        <aside class="auth-visual" aria-label="About FinTrust">
            <nav class="auth-visual__nav" aria-label="Site">
                <a href="<?= e(url('')) ?>">Home</a>
                <a href="<?= e(url('#about')) ?>">About</a>
                <a href="<?= e(url('#features')) ?>">Features</a>
                <a class="auth-visual__pill" href="<?= e($pill['href']) ?>"><?= e($pill['label']) ?></a>
            </nav>

            <span class="auth-circle auth-circle--lg" aria-hidden="true"></span>
            <span class="auth-circle auth-circle--md" aria-hidden="true"></span>
            <span class="auth-circle auth-circle--sm" aria-hidden="true"></span>

            <div class="auth-glass" aria-hidden="true">
                <span class="auth-glass__icon"><?= icon('check-circle', '', 18) ?></span>
                <span><strong>Transfer successful</strong><small>₦15,000.50 · PIN verified</small></span>
            </div>

            <div class="auth-visual__copy">
                <p class="auth-visual__title">Your Money. Your Security. <span>Our Priority.</span></p>
                <ul class="auth-visual__points">
                    <li><?= icon('shield-check', '', 18) ?> PIN-protected transfers</li>
                    <li><?= icon('scale', '', 18) ?> Double-entry ledger on every naira</li>
                    <li><?= icon('activity', '', 18) ?> Suspicious activity monitoring</li>
                </ul>
                <p class="auth-visual__note">Demo project — all funds are simulated.</p>
            </div>
        </aside>
    </div>
</div>
<div class="toast-region" aria-live="polite" data-toast-region></div>
</body>
</html>
