<?php
/**
 * Document <head> and opening <body>.
 * @var string $title
 * @var string $bodyClass (optional)
 * @var string $description (optional)
 */
use App\Core\Csrf;

$pageTitle = $title === config('app.name') ? $title . ' – ' . config('app.tagline') : $title . ' | ' . config('app.name');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($description ?? 'FinTrust is a security-focused digital wallet demo. All funds and transactions are simulated.') ?>">
    <meta name="theme-color" content="#0B1F3A">
    <meta name="app-url" content="<?= e(config('app.url')) ?>">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <script src="<?= e(asset('js/motion.js')) ?>" defer></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/pages/' . $script . '.js')) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if (($openedAt = app_url_mismatch()) !== null): ?>
<div class="setup-warning" role="alert">
    <p><strong>Setup problem: this page can't load its styles.</strong></p>
    <?php if (!is_file(base_path('.env'))): ?>
    <p>No <code>.env</code> file was found in the project folder, so the default address
       <code><?= e(config('app.url')) ?></code> is being used. Copy <code>.env.example</code> to a file named
       exactly <code>.env</code> (on Windows, make sure it isn't saved as <code>.env.txt</code>).</p>
    <?php endif; ?>
    <p>You opened the site at <code><?= e($openedAt) ?></code>, but <code>APP_URL</code> is
       <code><?= e(config('app.url')) ?></code>. They must match. Set this line in <code>.env</code>, save, and reload:</p>
    <pre>APP_URL=<?= e($openedAt) ?></pre>
</div>
<?php endif; ?>
<a class="skip-link" href="#main">Skip to main content</a>
