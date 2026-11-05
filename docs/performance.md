# Performance

FinTrust's seed data is tiny, so every query is instant on it. To find the queries
that would get slow on a real-sized system, there is a benchmark that builds a
separate database with **2,000 customers and 150,000 balanced transactions** (one
"busy" customer has about 15,000), times the **real repository code** the app runs, and
prints MySQL's `EXPLAIN` plans.

```bash
php scripts/benchmark.php                        # XAMPP root with no password
php scripts/benchmark.php --password=secret      # root with a password
php scripts/benchmark.php --transactions=50000   # smaller/faster run
php scripts/benchmark.php --keep                 # keep the fintrust_bench DB to explore it
```

Your real `fintrust` database is never touched; the benchmark uses `fintrust_bench`
and drops it afterwards (unless `--keep`). The last result is saved to
`storage/cache/benchmark-last.json`.

## Results

Median of several runs, MariaDB 10.11, 150,000 transactions, milliseconds.
Your numbers will differ by machine; the ratios are what matter.

| Query (where it's used) | Before | After | Change |
|---|---:|---:|---|
| Customer history, page 1 (Transactions page) | 80.2 | 14.5 | 5.5× faster |
| Customer history, page 50 | 88.8 | 14.4 | 6× |
| Customer history, name search | 139 | 58 | 2.4× |
| Dashboard 6-month chart | 230.6 | 42.8 | 5.4× |
| Admin transactions, page 1 | 333 | 21 | 16× |
| Admin transactions, offset 4,980 | 371 | 22 | 17× |
| Admin search by reference | 1,662 | 0.6 | ~2,700× |
| Admin overview statistics (uncached) | 2,837 | 483 | 5.9× (and cached for 60 s) |
| Admin security page | 56 | 2.4 | 23× |

Already-fast queries (single transaction by reference 0.3 ms, daily-limit sum 0.3 ms,
30-day totals 5 ms, notifications 7 ms, open fraud alerts 2 ms) were left alone.

A test (`tests/QueryOptimizationTest.php`) compares the optimised history query with the
simple original for 9 filter combinations × 2 wallets, including page 2, so the speed-up
cannot quietly change *what* is returned.

## What changed, and why

### 1. `OR` across two columns → two index-friendly halves (`UNION ALL`)

A customer's history is "transactions I sent **or** transactions I received". With
`WHERE sender_wallet_id = ? OR recipient_wallet_id = ?` MySQL can't walk one index in
date order, so it gathers every matching row and sorts them all (`Using filesort`) —
for every page.

`TransactionRepository::list()` now asks each half separately, each using its own
index **already in date order**, and merges them:

```sql
(SELECT id, created_at FROM transactions WHERE sender_wallet_id = ?
   ORDER BY created_at DESC, id DESC LIMIT 20)
UNION ALL
(SELECT id, created_at FROM transactions WHERE recipient_wallet_id = ? AND status IN ('successful','reversed')
   ORDER BY created_at DESC, id DESC LIMIT 20)
ORDER BY created_at DESC, id DESC LIMIT 10 OFFSET 10
```

Each half only needs `offset + limit` rows, read straight off the index. The dashboard
totals and 6-month chart use the same two-halves idea (`SIDES_SQL`).

### 2. Find the page of ids first, join afterwards ("deferred join")

The list queries used to join `wallets` and `users` twice (sender and recipient) for
**every** candidate row before applying `LIMIT`. Now:

1. `COUNT(*)` and the page of ids run on `transactions` alone (index-only).
2. The joined `SELECT` runs for just those 10–20 ids (`WHERE t.id IN (…)`).

This is why admin page 250 costs the same as page 1.

### 3. Composite indexes that match the queries

| Index | Serves |
|---|---|
| `transactions (sender_wallet_id, created_at, id)` | "sent" half of history, newest first, stable order |
| `transactions (recipient_wallet_id, created_at, id)` | "received" half |
| `transactions (created_at, id)` | admin transaction list, newest first |
| `transactions (status, created_at, amount_kobo)` | admin "failed" filter, overview counts and volume (covering: no table reads) |
| `ledger_entries (transaction_id, entry_type, amount_kobo)` | ledger-balance check per transaction (covering) |
| `ledger_entries (wallet_id, entry_type, amount_kobo)` | wallet reconciliation (covering) |
| `login_attempts (successful, attempted_at)` | failed logins in the last 24 h (security page) |

Rules of thumb used: **equality columns first, then the range/sort column**, and add the
selected columns when that makes the index *covering* (MySQL answers from the index
without touching the table). `id` is the tie-breaker so paging never skips or repeats rows
created in the same second.

### 4. Searches that can use an index

| Search input | Old | New |
|---|---|---|
| A reference (`FT2026…`) | `LIKE '%…%'` on every row | `reference LIKE 'FT2026…%'` (prefix, uses the unique index) |
| A 10-digit wallet number (admin) | joined text match | look up the wallet id, then the two indexed halves |
| A name | `CONCAT(...) LIKE` on the joined result | find matching wallet ids in `users` first, then `IN (…)` |

### 5. Integrity views rewritten

`v_unbalanced_transactions` and `v_wallet_reconciliation` now add up `ledger_entries` in
a derived table **first** and join afterwards, using the covering ledger indexes. On 150k
transactions the unbalanced-transactions check went from about 2 s to 0.35 s, and wallet
reconciliation from about 0.8 s to 0.2 s.

### 6. Caching the admin overview (60 seconds)

The overview proves the books balance by reading every ledger row. That stays correct
but costs ~0.5 s on 150k transactions, so `AdminController::statistics` caches the
result for 60 s (`App\Helpers\Cache`, a small file cache in `storage/cache/` with atomic
writes). The page shows **"Figures as of …"** and a **Refresh figures** button
(`?fresh=1`). Admin actions (freeze, suspend, alert review) clear the cache immediately.
Money pages are **never** cached — balances and history always come live from MySQL.

### 7. Browser side

- `public/.htaccess` compresses text responses (`mod_deflate`: CSS 64 KB → 13 KB gzip)
  and lets browsers keep CSS/JS/SVG for a year (`Cache-Control: immutable`). That's safe
  because `asset()` adds `?v=<file modified time>`, so a changed file gets a new URL.
- No frameworks or chart libraries: one CSS file, one shared JS file, one small script per
  page, all `defer` (except the tiny `theme.js`, which must run before the first paint to
  avoid a light/dark flash). Charts are hand-built SVG.
- API responses are `Cache-Control: no-store` (financial data must never come from a cache).

### 8. Housekeeping so tables don't grow forever

```bash
php scripts/maintenance.php      # schedule daily (Windows Task Scheduler or cron)
```

Deletes expired rate-limit windows, expired idempotency keys, used/expired tokens, old
read notifications and old simulated mail files. It **never** touches transactions,
ledger entries, audit logs or fraud alerts. The rate limiter also cleans its own table
on about 1 request in 200, in case the script is never scheduled.

## Upgrading an older database

A fresh import of `database/schema.sql` already has everything. For an existing
database (keeps your data):

```bash
mysql -u root fintrust < database/migrations/2026_10_14_performance_indexes.sql
```

(In phpMyAdmin: click the fintrust database, then Import that file.)

## Reading an EXPLAIN plan (quick guide)

Run `EXPLAIN <your query>` in phpMyAdmin → SQL.

| Column | Good sign | Warning sign |
|---|---|---|
| `type` | `const`, `eq_ref`, `ref`, `range` | `ALL` (reads the whole table) |
| `key` | the index you expected | `NULL` |
| `rows` | close to what you return | far more than you return |
| `Extra` | `Using index` (covering) | `Using filesort`, `Using temporary` on big tables |
