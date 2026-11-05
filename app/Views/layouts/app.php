<?php
/**
 * Layout for signed-in users and admins: sidebar + top bar + content.
 * @var string $content
 * @var array  $flashes
 * @var string $area      'user' (default) or 'admin'
 * @var string $active    current nav item key
 */
use App\Core\View;

$vars = get_defined_vars();
$vars['bodyClass'] = trim(($bodyClass ?? '') . ' app-body');
echo View::partial('partials/head', $vars);
?>
<div class="app-shell">
    <?= View::partial('partials/app-sidebar', ['area' => $area ?? 'user', 'active' => $active ?? '']) ?>
    <div class="app-main">
        <?= View::partial('partials/app-topbar', [
            'title'       => $title,
            'currentUser' => $currentUser ?? null,
            'unreadCount' => $unreadCount ?? 0,
            'area'        => $area ?? 'user',
        ]) ?>
        <main id="main" class="app-content" tabindex="-1">
            <?php if (!empty($flashes)): ?>
                <div class="flash-stack"><?= View::partial('partials/flash', ['flashes' => $flashes]) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<div class="toast-region" aria-live="polite" data-toast-region></div>
</body>
</html>
