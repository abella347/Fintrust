<?php
use App\Core\View;
?>
<div class="overview-bar">
    <div class="health" data-health role="status">
        <span class="skeleton skeleton--text"></span>
    </div>
    <p class="text-muted text-sm mb-0" data-generated aria-live="polite"></p>
    <button type="button" class="btn btn-sm btn-outline" data-refresh>Refresh figures</button>
</div>

<h2 class="sr-only">Key figures</h2>
<div class="grid-3 mt-4">
    <?= View::partial('partials/components/stat-card', ['label' => 'Customers', 'value' => '…', 'icon' => 'users', 'tone' => 'info', 'hint' => '', 'id' => 'st-users']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Active wallets', 'value' => '…', 'icon' => 'wallet', 'tone' => 'success', 'hint' => '', 'id' => 'st-wallets']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Simulated volume', 'value' => '…', 'icon' => 'activity', 'tone' => 'neutral', 'hint' => '', 'id' => 'st-volume']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Transactions', 'value' => '…', 'icon' => 'chart', 'tone' => 'neutral', 'hint' => '', 'id' => 'st-txns']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Open fraud alerts', 'value' => '…', 'icon' => 'shield-alert', 'tone' => 'warning', 'hint' => '', 'id' => 'st-fraud']) ?>
    <?= View::partial('partials/components/stat-card', ['label' => 'Failed logins (24h)', 'value' => '…', 'icon' => 'lock', 'tone' => 'danger', 'hint' => '', 'id' => 'st-logins']) ?>
</div>

<div class="dash-grid mt-5">
    <section class="card" aria-labelledby="vol-title">
        <div class="card__header">
            <div>
                <h2 id="vol-title">Daily volume</h2>
                <p class="text-muted text-sm mb-0">Successful transactions, last 14 days</p>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" data-chart-table-toggle aria-pressed="false">Show as table</button>
        </div>
        <div class="chart-legend" aria-hidden="true">
            <span><i class="chart-key chart-key--out"></i> Transfers</span>
            <span><i class="chart-key chart-key--in"></i> Deposits</span>
        </div>
        <div class="chart" data-chart><span class="skeleton chart__skeleton" aria-hidden="true"></span></div>
        <div class="table-wrap mt-4" data-chart-table hidden></div>
    </section>

    <section class="card" aria-labelledby="alerts-title">
        <div class="card__header">
            <h2 id="alerts-title">Open fraud alerts</h2>
            <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/fraud-alerts?status=open')) ?>">Review <?= icon('chevron-right', '', 16) ?></a>
        </div>
        <ul class="alert-list" data-alerts></ul>
    </section>
</div>

<section class="card mt-5" aria-labelledby="recent-title">
    <div class="card__header">
        <h2 id="recent-title">Latest transactions</h2>
        <a class="btn btn-sm btn-ghost" href="<?= e(url('admin/transactions')) ?>">All transactions <?= icon('chevron-right', '', 16) ?></a>
    </div>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th scope="col">Reference</th><th scope="col">From → To</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col" class="num">Amount</th></tr></thead>
            <tbody data-rows></tbody>
        </table>
    </div>
</section>
