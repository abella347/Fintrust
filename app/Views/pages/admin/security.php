<div class="grid-2">
    <section class="card" aria-labelledby="locked-title">
        <h2 id="locked-title">Locked accounts</h2>
        <p class="text-muted text-sm">Temporarily locked after repeated wrong passwords. Locks lift on their own.</p>
        <ul class="mini-list" data-list="locked_accounts"></ul>
    </section>
    <section class="card" aria-labelledby="pin-title">
        <h2 id="pin-title">PIN-locked customers</h2>
        <p class="text-muted text-sm">Transfers paused after repeated wrong transaction PINs.</p>
        <ul class="mini-list" data-list="pin_locked"></ul>
    </section>
    <section class="card" aria-labelledby="susp-title">
        <h2 id="susp-title">Suspended accounts</h2>
        <ul class="mini-list" data-list="suspended"></ul>
    </section>
    <section class="card" aria-labelledby="frozen-title">
        <h2 id="frozen-title">Frozen wallets</h2>
        <ul class="mini-list" data-list="frozen_wallets"></ul>
    </section>
</div>

<section class="card mt-5" aria-labelledby="ips-title">
    <h2 id="ips-title">Failed logins by IP address (last 24 hours)</h2>
    <p class="text-muted text-sm">Many failures against many accounts from one address usually means automated password guessing.</p>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th scope="col">IP address</th><th scope="col" class="num">Failures</th><th scope="col" class="num">Accounts tried</th><th scope="col">Last attempt</th></tr></thead>
            <tbody data-rows="top_ips"></tbody>
        </table>
    </div>
</section>

<section class="card mt-5" aria-labelledby="fails-title">
    <h2 id="fails-title">Recent failed logins</h2>
    <div class="table-wrap">
        <table class="table table--stack">
            <thead><tr><th scope="col">Email tried</th><th scope="col">Reason</th><th scope="col">IP address</th><th scope="col">Browser</th><th scope="col">When</th></tr></thead>
            <tbody data-rows="recent_failures"></tbody>
        </table>
    </div>
</section>
