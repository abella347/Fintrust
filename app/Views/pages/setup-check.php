<?php
/**
 * @var list<array{name:string, ok:bool, detail:string, level:string}> $checks
 */
$failedErrors = array_filter($checks, fn ($c) => !$c['ok'] && $c['level'] === 'error');
$warnings     = array_filter($checks, fn ($c) => !$c['ok'] && $c['level'] === 'warning');
?>
<section class="section">
    <div class="container container--narrow">
        <span class="eyebrow"><?= icon('database', '', 14) ?> Developer tools · local only</span>
        <h1>Setup check</h1>
        <p class="text-muted">Confirms that PHP, MySQL, the schema and the ledger are wired up correctly.</p>

        <?php if ($failedErrors): ?>
            <div class="alert alert--error" role="alert"><?= icon('x-circle') ?>
                <p><?= count($failedErrors) ?> check(s) failed. Fix them, then reload this page.</p></div>
        <?php elseif ($warnings): ?>
            <div class="alert alert--warning" role="status"><?= icon('alert-triangle') ?>
                <p>Everything required works. <?= count($warnings) ?> recommendation(s) below.</p></div>
        <?php else: ?>
            <div class="alert alert--success" role="status"><?= icon('check-circle') ?>
                <p>All checks passed. The books balance.</p></div>
        <?php endif; ?>

        <ul class="check-list">
            <?php foreach ($checks as $check):
                $state = $check['ok'] ? 'is-ok' : ($check['level'] === 'warning' ? 'is-warn' : 'is-fail');
                $iconName = $check['ok'] ? 'check-circle' : ($check['level'] === 'warning' ? 'alert-triangle' : 'x-circle');
            ?>
                <li>
                    <span class="<?= $state ?>"><?= icon($iconName) ?></span>
                    <div>
                        <strong><?= e($check['name']) ?></strong>
                        <small><?= e($check['detail']) ?></small>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="card mt-5">
            <div class="card__header">
                <h2>API routing</h2>
                <button type="button" class="btn btn-primary btn-sm" id="ping-api"><?= icon('zap', '', 16) ?> Call GET /api/health</button>
            </div>
            <p class="text-muted text-sm mb-2">Proves Apache rewrites /api/* to the API front controller and returns the JSON envelope.</p>
            <pre class="code-output" id="ping-result">Not called yet.</pre>
        </div>
    </div>
</section>
<script src="<?= e(asset('js/pages/dev-tools.js')) ?>" defer></script>
