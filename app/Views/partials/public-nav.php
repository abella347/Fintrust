<?php
/** Public site header with responsive (hamburger) navigation. */
$home  = url('');
$links = [
    'Home'         => $home . '#top',
    'About Us'     => $home . '#about',
    'Features'     => $home . '#features',
    'Security'     => $home . '#security',
    'How It Works' => $home . '#how-it-works',
    'Contact'      => $home . '#contact',
];
?>
<header class="site-header" id="top">
    <div class="container site-header__inner">
        <?= App\Core\View::partial('partials/logo') ?>

        <button type="button" class="icon-btn nav-toggle" aria-controls="site-nav" aria-expanded="false" aria-label="Open menu" data-nav-toggle>
            <span class="nav-toggle__open"><?= icon('menu', '', 24) ?></span>
            <span class="nav-toggle__close"><?= icon('x', '', 24) ?></span>
        </button>

        <nav class="site-nav" id="site-nav" aria-label="Main">
            <ul class="site-nav__links">
                <?php foreach ($links as $label => $href): ?>
                    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="site-nav__actions">
                <?= App\Core\View::partial('partials/theme-toggle') ?>
                <a class="btn btn-ghost" href="<?= e(url('login')) ?>">Login</a>
                <a class="btn btn-accent" href="<?= e(url('register')) ?>">Get Started</a>
            </div>
        </nav>
    </div>
</header>
