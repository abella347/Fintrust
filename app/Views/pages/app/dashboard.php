<?php
/**
 * @var array $currentUser
 */
use App\Core\View;
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title" data-bind="greeting">Hello, <?= e($currentUser['first_name']) ?></h2>
        <p class="text-muted mb-0" data-bind="today"></p>
    </div>
    <a class="btn btn-accent" href="<?= e(url('transfer')) ?>"><?= icon('send', '', 18) ?> Send money</a>
</div>

<div class="alert alert--warning" data-bind="frozen-alert" role="alert" hidden><?= icon('lock') ?>
    <p>Your wallet is frozen pending a security review. You can still view your history, but transfers and deposits are paused. Contact support@fintrust.test.</p></div>

<div class="dash-top">
    <?= View::partial('partials/components/balance-card') ?>

    <section class="card" aria-labelledby="qa-title">
        <h2 class="h3" id="qa-title">Quick actions</h2>
        <nav class="quick-actions" aria-label="Quick actions">
            <a class="quick-action" href="<?= e(url('wallet#add-money')) ?>"><?= icon('plus') ?>Add Money</a>
            <a class="quick-action" href="<?= e(url('transfer')) ?>"><?= icon('send') ?>Send Money</a>
            <button type="button" class="quick-action" data-open-receive><?= icon('arrow-down-left') ?>Receive Money</button>
            <a class="quick-action" href="<?= e(url('transactions')) ?>"><?= icon('history') ?>History</a>
            <a class="quick-action" href="<?= e(url('settings')) ?>"><?= icon('settings') ?>Settings</a>
        </nav>
    </section>
</div>

<div class="grid-4 mt-5" data-stats>
    <?= View::partial('partials/components/stat-card', ['label' => 'Money received', 'value' => '…', 'icon' => 'arrow-down-left', 'tone' => 'success', 'hint' => 'Last 30 days', 'id' => 'stat-received']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Money sent', 'value' => '…', 'icon' => 'arrow-up-right', 'tone' => 'info', 'hint' => 'Last 30 days', 'id' => 'stat-sent']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Transactions', 'value' => '…', 'icon' => 'activity', 'tone' => 'neutral', 'hint' => 'Successful, last 30 days', 'id' => 'stat-count']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Account security', 'value' => '…', 'icon' => 'shield-check', 'tone' => 'success', 'hint' => '', 'id' => 'stat-security']) ?>
</div>

<div class="dash-columns mt-5">
    <div class="col">
        <section class="card o-1" aria-labelledby="chart-title">
            <div class="card__header">
                <div>
                    <h2 id="chart-title">Money in vs money out</h2>
                    <p class="text-muted text-sm mb-0">Successful transactions, last 6 months</p>
                </div>
                <button type="button" class="btn btn-sm btn-ghost" data-chart-table-toggle aria-pressed="false">Show as table</button>
            </div>
            <div class="chart-legend" aria-hidden="true">
                <span><i class="chart-key chart-key--in"></i> Money in</span>
                <span><i class="chart-key chart-key--out"></i> Money out</span>
            </div>
            <div class="chart" data-chart><span class="skeleton chart__skeleton" aria-hidden="true"></span></div>
            <div class="table-wrap mt-4" data-chart-table hidden></div>
        </section>

        <section class="card o-3" aria-labelledby="recent-title">
            <div class="card__header">
                <h2 id="recent-title">Recent transactions</h2>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('transactions')) ?>">View all <?= icon('chevron-right', '', 16) ?></a>
            </div>
            <div data-recent></div>
        </section>
    </div>
    <div class="col">
        <section class="card o-2" aria-labelledby="notif-title">
            <div class="card__header">
                <h2 id="notif-title">Notifications</h2>
                <button type="button" class="btn btn-sm btn-ghost" data-mark-all-read hidden>Mark all read</button>
            </div>
            <div data-notifications></div>
            <a class="card-link" href="<?= e(url('notifications')) ?>">View all notifications <?= icon('chevron-right', '', 16) ?></a>
        </section>

        <section class="card o-4" aria-labelledby="sec-title">
            <h2 id="sec-title">Security status</h2>
            <ul class="sec-list" data-security>
                <li><span class="skeleton skeleton--text"></span></li>
                <li><span class="skeleton skeleton--text"></span></li>
                <li><span class="skeleton skeleton--text"></span></li>
            </ul>
            <a class="card-link" href="<?= e(url('settings')) ?>">Manage security settings <?= icon('chevron-right', '', 16) ?></a>
        </section>
    </div>
</div>

<?= View::partial('partials/components/receive-dialog', ['currentUser' => $currentUser]) ?>
