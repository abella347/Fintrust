<section class="card">
    <form class="filters" id="filters" role="search">
        <div class="form-group filters__search">
            <label for="t-search">Search</label>
            <input class="input" id="t-search" name="search" type="search" maxlength="40" placeholder="Reference, wallet number or name" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="t-type">Type</label>
            <select class="input" id="t-type" name="type">
                <option value="">All types</option><option value="transfer">Transfer</option><option value="deposit">Deposit</option><option value="reversal">Reversal</option>
            </select>
        </div>
        <div class="form-group">
            <label for="t-status">Status</label>
            <select class="input" id="t-status" name="status">
                <option value="">All statuses</option><option value="successful">Successful</option><option value="failed">Failed</option>
                <option value="pending">Pending</option><option value="reversed">Reversed</option>
            </select>
        </div>
        <div class="form-group">
            <label for="t-from">From</label>
            <input class="input" id="t-from" name="from" type="date" aria-describedby="t-from-error">
            <p class="field-error" id="t-from-error" role="alert"></p>
        </div>
        <div class="form-group">
            <label for="t-to">To</label>
            <input class="input" id="t-to" name="to" type="date">
        </div>
        <div class="form-group">
            <label for="t-min">Min ₦</label>
            <input class="input" id="t-min" name="min" inputmode="decimal" placeholder="0" aria-describedby="t-min-error">
            <p class="field-error" id="t-min-error" role="alert"></p>
        </div>
        <div class="form-group">
            <label for="t-max">Max ₦</label>
            <input class="input" id="t-max" name="max" inputmode="decimal" placeholder="Any" aria-describedby="t-max-error">
            <p class="field-error" id="t-max-error" role="alert"></p>
        </div>
        <div class="filters__actions">
            <label class="checkbox" for="t-flagged"><input type="checkbox" id="t-flagged" name="flagged" value="1"> Flagged only</label>
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="reset" class="btn btn-ghost">Reset</button>
        </div>
    </form>
</section>

<section class="card mt-5" aria-labelledby="txns-title">
    <h2 class="sr-only" id="txns-title">All transactions</h2>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead>
            <tr><th scope="col">Reference</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Type</th>
                <th scope="col">Status</th><th scope="col">Date</th><th scope="col" class="num">Amount</th></tr>
            </thead>
            <tbody data-rows></tbody>
        </table>
    </div>
    <nav class="pagination" aria-label="Pagination" data-pager hidden></nav>
</section>
