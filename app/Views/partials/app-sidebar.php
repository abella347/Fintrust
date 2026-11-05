<?php
/**
 * Sidebar for signed-in areas.
 * @var string $area    'user' or 'admin'
 * @var string $active  key of the current page, e.g. 'dashboard'
 */
$area   ??= 'user';
$active ??= '';

$items = $area === 'admin'
    ? [
        // Key => [label, icon, path, permission needed to see it]
        'admin-dashboard' => ['Overview',      'dashboard',    'admin/',             'reports.view'],
        'admin-users'     => ['Users',         'users',        'admin/users',        'users.view'],
        'admin-txns'      => ['Transactions',  'activity',     'admin/transactions', 'transactions.view'],
        'admin-fraud'     => ['Fraud alerts',  'shield-alert', 'admin/fraud-alerts', 'fraud.view'],
        'admin-security'  => ['Security',      'lock',         'admin/security',     'security.view'],
        'admin-audit'     => ['Audit log',     'file-text',    'admin/audit-logs',   'audit.view'],
    ]
    : [
        'dashboard'    => ['Dashboard',     'dashboard', 'dashboard',    null],
        'wallet'       => ['Wallet',        'wallet',    'wallet',       null],
        'transfer'     => ['Send money',    'send',      'transfer',     null],
        'transactions' => ['Transactions',  'history',   'transactions', null],
        'settings'     => ['Settings',      'settings',  'settings',     null],
    ];
// Hide links the user isn't allowed to open (the pages check again server-side).
$items = array_filter($items, fn ($item) => $item[3] === null || App\Core\Auth::can($item[3]));
$roleLabel = App\Core\Auth::hasRole('admin') ? 'Administrator' : 'Support analyst';
?>
<aside class="sidebar" id="app-sidebar" aria-label="<?= $area === 'admin' ? 'Admin' : 'Account' ?> navigation">
    <div class="sidebar__header">
        <?= App\Core\View::partial('partials/logo', ['light' => true, 'href' => url($area === 'admin' ? 'admin/' : 'dashboard')]) ?>
        <button type="button" class="icon-btn sidebar__close" data-sidebar-close aria-label="Close menu"><?= icon('x') ?></button>
    </div>

    <?php if ($area === 'admin'): ?>
        <p class="sidebar__badge"><?= icon('lock', '', 14) ?> <?= e($roleLabel) ?></p>
    <?php endif; ?>

    <nav class="sidebar__nav">
        <ul>
            <?php foreach ($items as $key => [$label, $iconName, $path]): ?>
                <li>
                    <a href="<?= e(url($path)) ?>" class="sidebar__link<?= $key === $active ? ' is-active' : '' ?>"
                       <?= $key === $active ? 'aria-current="page"' : '' ?>>
                        <?= icon($iconName) ?><span><?= e($label) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar__footer">
        <p class="sidebar__sim"><?= icon('info', '', 14) ?> <?= $area === 'admin' ? 'Demo data · simulated funds' : 'Simulated funds only' ?></p>
        <form method="post" action="<?= e(url('logout')) ?>" data-logout-form>
            <?= App\Core\Csrf::field() ?>
            <button type="submit" class="sidebar__link sidebar__logout"><?= icon('log-out') ?><span>Log out</span></button>
        </form>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close hidden></div>
