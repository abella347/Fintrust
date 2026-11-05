<div class="alert alert--info" role="note"><?= icon('file-text') ?>
    <p>The audit log is append-only: the database refuses to edit or delete entries, even for administrators.</p></div>

<section class="card">
    <form class="filters filters--3" id="filters" role="search">
        <div class="form-group">
            <label for="a-action">Action starts with</label>
            <input class="input" id="a-action" name="action" maxlength="60" placeholder="e.g. wallet. or auth.login" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="a-actor">Actor email</label>
            <input class="input" id="a-actor" name="actor" maxlength="100" placeholder="e.g. admin@" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="a-type">Actor type</label>
            <select class="input" id="a-type" name="actor_type">
                <option value="">Anyone</option><option value="admin">Admin</option><option value="user">Customer</option><option value="system">System</option>
            </select>
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="reset" class="btn btn-ghost">Reset</button>
        </div>
    </form>
</section>

<section class="card mt-5" aria-labelledby="audit-title">
    <h2 class="sr-only" id="audit-title">Audit entries</h2>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th scope="col">When</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Change</th><th scope="col">IP</th></tr></thead>
            <tbody data-rows></tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Pagination" data-pager hidden></nav>
</section>
