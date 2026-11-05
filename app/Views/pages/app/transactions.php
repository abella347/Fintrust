<section class="card" aria-labelledby="filters-title">
    <h2 class="sr-only" id="filters-title">Filter transactions</h2>
    <form class="filters" id="filters" role="search">
        <div class="form-group filters__search">
            <label for="q-search">Search</label>
            <div class="input-group input-group--prefix">
                <span class="input-prefix" aria-hidden="true"><?= icon('search', '', 16) ?></span>
                <input class="input" id="q-search" name="search" type="search" placeholder="Reference or name" maxlength="40" autocomplete="off">
            </div>
        </div>
        <div class="form-group">
            <label for="q-type">Type</label>
            <select class="input" id="q-type" name="type">
                <option value="">All types</option><option value="transfer">Transfer</option>
                <option value="deposit">Deposit</option><option value="reversal">Reversal</option>
            </select>
        </div>
        <div class="form-group">
            <label for="q-direction">Direction</label>
            <select class="input" id="q-direction" name="direction">
                <option value="">In &amp; out</option><option value="in">Money in</option><option value="out">Money out</option>
            </select>
        </div>
        <div class="form-group">
            <label for="q-status">Status</label>
            <select class="input" id="q-status" name="status">
                <option value="">All statuses</option><option value="successful">Successful</option>
                <option value="failed">Failed</option><option value="pending">Pending</option><option value="reversed">Reversed</option>
            </select>
        </div>
        <div class="form-group">
            <label for="q-from">From</label>
            <input class="input" id="q-from" name="from" type="date" aria-describedby="q-from-error">
            <p class="field-error" id="q-from-error" role="alert"></p>
        </div>
        <div class="form-group">
            <label for="q-to">To</label>
            <input class="input" id="q-to" name="to" type="date">
        </div>
        <div class="filters__actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <button type="reset" class="btn btn-ghost">Reset</button>
        </div>
    </form>
</section>

<section class="card mt-5" aria-labelledby="results-title">
    <div class="card__header">
        <h2 id="results-title">History</h2>
        <p class="text-muted text-sm mb-0" data-summary aria-live="polite"></p>
    </div>
    <div class="table-wrap" data-table-wrap>
        <table class="table table--stack">
            <caption class="sr-only">Your transactions, newest first</caption>
            <thead>
            <tr>
                <th scope="col">Details</th><th scope="col">Reference</th><th scope="col">Type</th>
                <th scope="col">Date</th><th scope="col">Status</th><th scope="col" class="num">Amount</th>
            </tr>
            </thead>
            <tbody data-rows></tbody>
        </table>
    </div>
    <div data-state></div>
    <nav class="pagination" aria-label="Pagination" data-pagination hidden>
        <button type="button" class="btn btn-sm btn-outline" data-page="prev">Previous</button>
        <span data-page-label></span>
        <button type="button" class="btn btn-sm btn-outline" data-page="next">Next</button>
    </nav>
</section>
