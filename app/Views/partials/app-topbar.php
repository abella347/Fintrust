<?php
/**
 * Top bar for signed-in pages.
 * @var string $title
 * @var array{first_name:string,last_name:string,email:string}|null $currentUser
 * @var int $unreadCount (optional)
 */
$user     = $currentUser ?? ['first_name' => 'Guest', 'last_name' => '', 'email' => ''];
$initials = strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));
$unread   = (int) ($unreadCount ?? 0);
?>
<header class="topbar">
    <button type="button" class="icon-btn topbar__menu" data-sidebar-open aria-controls="app-sidebar" aria-expanded="false" aria-label="Open menu">
        <?= icon('menu', '', 22) ?>
    </button>

    <h1 class="topbar__title"><?= e($title) ?></h1>

    <div class="topbar__actions">
        <?= App\Core\View::partial('partials/theme-toggle') ?>
        <?php if (($area ?? 'user') !== 'admin'): ?>
        <a href="<?= e(url('notifications')) ?>" class="icon-btn topbar__bell"
           aria-label="Notifications<?= $unread ? ", {$unread} unread" : '' ?>">
            <?= icon('bell') ?>
            <span class="topbar__dot" data-unread-count<?= $unread > 0 ? '' : ' hidden' ?>><?= $unread > 9 ? '9+' : ($unread ?: '') ?></span>
        </a>
        <?php endif; ?>
        <a href="<?= e(url(($area ?? 'user') === 'admin' ? 'admin/' : 'settings')) ?>" class="topbar__user">
            <span class="avatar" aria-hidden="true" data-avatar data-initials="<?= e($initials) ?>"><?php if ($photo = avatar_url($user['avatar_path'] ?? null)): ?><img src="<?= e($photo) ?>" alt="" width="40" height="40" decoding="async"><?php else: ?><?= e($initials) ?><?php endif; ?></span>
            <span class="topbar__user-text">
                <strong><?= e(trim($user['first_name'] . ' ' . $user['last_name'])) ?></strong>
                <small><?= e($user['email']) ?></small>
            </span>
        </a>
    </div>
</header>
