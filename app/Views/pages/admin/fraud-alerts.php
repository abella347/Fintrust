<div class="alert alert--info" role="note"><?= icon('info') ?>
    <p>Alerts come from a <strong>demonstration rule-based monitor</strong> (large, rapid or failed transfers, limit breaches, PIN and login lockouts, credential stuffing). It is not a production fraud-prevention engine.</p></div>

<section class="card">
    <form class="filters filters--3" id="filters">
        <div class="form-group">
            <label for="f-status">Status</label>
            <select class="input" id="f-status" name="status">
                <option value="">All</option><option value="open">Open</option><option value="investigating">Investigating</option>
                <option value="resolved">Resolved</option><option value="dismissed">Dismissed</option>
            </select>
        </div>
        <div class="form-group">
            <label for="f-severity">Severity</label>
            <select class="input" id="f-severity" name="severity">
                <option value="">All</option><option value="critical">Critical</option><option value="high">High</option>
                <option value="medium">Medium</option><option value="low">Low</option>
            </select>
        </div>
        <div class="form-group">
            <label for="f-rule">Rule</label>
            <select class="input" id="f-rule" name="rule">
                <option value="">All rules</option>
                <?php foreach (['LARGE_TRANSFER', 'RAPID_TRANSFERS', 'FAILED_TRANSFERS', 'LIMIT_EXCEEDED', 'PIN_FAILURES', 'LOGIN_FAILURES', 'CREDENTIAL_STUFFING', 'TRANSFER_OVER_BALANCE'] as $rule): ?>
                    <option value="<?= e($rule) ?>"><?= e($rule) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</section>

<section class="mt-5" aria-labelledby="alerts-title" aria-live="polite">
    <h2 class="sr-only" id="alerts-title">Alerts</h2>
    <p class="text-muted text-sm" data-summary></p>
    <ul class="alert-cards" data-list></ul>
    <nav class="pagination" aria-label="Pagination" data-pager hidden></nav>
</section>
