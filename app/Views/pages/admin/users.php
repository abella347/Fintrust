<section class="card">
    <form class="filters filters--3" id="filters" role="search">
        <div class="form-group filters__search">
            <label for="u-search">Search</label>
            <input class="input" id="u-search" name="search" type="search" maxlength="60" placeholder="Name, email, phone or wallet number" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="u-status">Account status</label>
            <select class="input" id="u-status" name="status">
                <option value="">Any status</option><option value="active">Active</option><option value="suspended">Suspended</option>
            </select>
        </div>
        <div class="form-group">
            <label for="u-role">Role</label>
            <select class="input" id="u-role" name="role">
                <option value="">Any role</option><option value="user">Customer</option><option value="support">Support</option><option value="admin">Admin</option>
            </select>
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn btn-primary">Search</button>
            <button type="reset" class="btn btn-ghost">Reset</button>
        </div>
    </form>
</section>

<section class="card mt-5" aria-labelledby="users-title">
    <h2 class="sr-only" id="users-title">Users</h2>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
            <tr><th scope="col">User</th><th scope="col">Wallet</th><th scope="col" class="num">Balance</th><th scope="col">Status</th>
                <th scope="col">Alerts</th><th scope="col">Joined</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody data-rows></tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Pagination" data-pager hidden></nav>
</section>
