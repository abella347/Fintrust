# FinTrust

**Secure Transactions. Trusted Finance.**

FinTrust is a portfolio fintech web application: digital wallets, wallet-to-wallet
transfers, a double-entry ledger, rule-based fraud monitoring and an admin dashboard with
role-based access. It is built with plain PHP 8.2+, MySQL/MariaDB (PDO), HTML, CSS and
JavaScript, with no frameworks, and runs on XAMPP.

> **Demo only.** All balances and transactions are simulated. FinTrust is not a licensed
> financial institution, does not hold or move real money, and makes no claim of
> certification or regulatory approval. Fraud detection is a demonstration rule-based system.

## Contents

- [Features](#features)
- [How it fits together](#how-it-fits-together)
- [Setup with XAMPP](#setup-with-xampp)
- [Demo accounts](#demo-accounts)
- [Running the tests](#running-the-tests)
- [Upgrading from an earlier phase](#upgrading-from-an-earlier-phase)
- [Maintenance](#maintenance)
- [Project structure](#project-structure)
- [Key design decisions](#key-design-decisions)
- [Documentation](#documentation)

## Features

**Customers**
- Register → verify email → log in; forgot/reset password; idle and absolute session timeouts
- Profile picture (cropped in the browser, re-encoded on the server)
- Wallet with a 10-digit number; simulated deposits ("Add money")
- Transfers approved with a 4-digit **transaction PIN** or **Face ID / fingerprint**
  (WebAuthn, Windows Hello included), with recipient name check, daily and per-transfer
  limits, and **idempotency keys** (a double-click can't pay twice)
- Transaction history with search, filters, paging, details and a printable receipt
- Dashboard: balance, 30-day totals, 6-month chart, notifications, security status
- Light and dark themes; works from 320 px phones to wide screens

**Staff** (admin and support roles)
- Overview: live **ledger health** (the books must balance), volumes, fraud and login figures
- Users: search, view, suspend/reactivate accounts, freeze/unfreeze wallets (reason required)
- Transactions with both ledger entries; fraud alert review; security monitor; audit log

**Behind the scenes**
- Double-entry ledger, `SELECT … FOR UPDATE` row locking, deadlock retry, append-only
  history enforced by triggers and database grants
- CSRF, strict CSP, prepared statements, rate limiting, account and PIN lockouts,
  hashed tokens, new-device alerts, audit logging of every sensitive action
- 111 automated tests, including real HTTP attacks against the running site and a
  concurrency test

## How it fits together

```text
Browser ── HTML pages (public/*.php, server-rendered, permission-checked)
   │       + one small JS file per page
   │
   └── fetch() JSON + X-CSRF-Token ──► public/api/index.php
                                         │  JSON-only, CSRF, API rate limit
                                         ▼
                                       Router (routes/api.php)
                                         │  middleware: RequireAuth / RequireCustomer /
                                         │              RequirePermission:<perm>
                                         ▼
                                       Controllers  (thin: read request, send JSON)
                                         ▼
                                       Services     (rules: TransferService, Ledger, FraudMonitor …)
                                         ▼
                                       Repositories (all SQL, prepared statements) ──► MySQL
```

How a transfer stays correct (DB transaction → lock both wallets in id order → check →
move money → write two ledger lines → commit) is explained step by step in
**[docs/transfers-and-ledger.md](docs/transfers-and-ledger.md)**.

## Setup with XAMPP

**Requirements:** XAMPP with PHP 8.2 or newer (MariaDB 10.4+ is included).

1. **Copy the project** to `C:\xampp\htdocs\fintrust` (macOS: `/Applications/XAMPP/htdocs/fintrust`).
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Enable PHP extensions.** In `C:\xampp\php\php.ini` make sure these lines have no
   leading `;`: `extension=pdo_mysql`, `extension=mbstring`, `extension=openssl`.
   Restart Apache after editing.
4. **Create the database.** First open `database/create_user.sql` in a text editor and
   replace all four `change-me-to-a-long-random-password` with your own password
   (Find & Replace). Then, **in phpMyAdmin** (<http://localhost/phpmyadmin>):
   1. **Databases** tab → name `fintrust`, collation `utf8mb4_unicode_ci` → **Create**.
   2. Click **fintrust** in the left sidebar, so the next imports go into it.
   3. **Import** → choose `database/schema.sql` → **Import**. Wait for the green
      "Import has been successfully finished" message.
   4. Still in fintrust, **Import** `database/seed.sql` the same way (demo accounts).
   5. Then **Import** `database/create_user.sql` (the app's database account).

   Or from the command line (in the project folder):
   ```bash
   C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE IF NOT EXISTS fintrust CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql -u root fintrust < database\schema.sql
   C:\xampp\mysql\bin\mysql -u root fintrust < database\seed.sql
   C:\xampp\mysql\bin\mysql -u root < database\create_user.sql
   ```
   The order matters, and every file is safe to re-run. If an import says
   `#1046 No database selected`, click **fintrust** in the sidebar first. If
   `create_user.sql` says `#1146 Table 'fintrust.users' doesn't exist`, import
   `schema.sql` and `seed.sql` first.
5. **Configure.** Copy `.env.example` to `.env` and set `DB_PASSWORD` to the password
   from step 4. `APP_URL` must be the address you open in the browser, with no trailing
   slash: `http://localhost/fintrust` for `htdocs\fintrust`, or for a subfolder such as
   `htdocs\practice-projects\fintrust` use `http://localhost/practice-projects/fintrust`.
6. **Check.** Open <http://localhost/fintrust/setup-check>. Every check should be green
   (one amber note about triggers is expected; the page explains it).
7. **Use it.** Open <http://localhost/fintrust/> and log in with a demo account below.
   Emails are simulated: they appear as text files in `storage/mail/`.

### Optional: a virtual host (recommended)

Point Apache straight at `public/` so nothing else can ever be reached. Add to
`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName fintrust.test
    DocumentRoot "C:/xampp/htdocs/fintrust/public"
    <Directory "C:/xampp/htdocs/fintrust/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 fintrust.test` to `C:\Windows\System32\drivers\etc\hosts`, restart Apache,
and set `APP_URL=http://fintrust.test` in `.env`.

## Demo accounts

| Email | Password | PIN | Wallet | Notes |
|---|---|---|---|---|
| admin@fintrust.test | Admin@FinTrust2026 | — | — | Administrator (full staff access) |
| support@fintrust.test | Support@FinTrust2026 | — | — | Support analyst (view + fraud review) |
| ada@fintrust.test | Demo@Pass2026 | 4826 | 3021456789 | ₦234,999.50 |
| ben@fintrust.test | Demo@Pass2026 | 4826 | 3098765432 | ₦115,000.50 |
| chidi@fintrust.test | Demo@Pass2026 | 4826 | 3055500011 | Wallet **frozen** |

To start fresh at any time, select the fintrust database and re-import `schema.sql`,
then `seed.sql` (and `create_user.sql` if you changed the password).

## Running the tests

```bash
php tests/run.php            # 110 tests + 1 skipped   (XAMPP: C:\xampp\php\php.exe tests\run.php)
php tests/run.php --slow     # adds 12 simultaneous transfers (concurrency)
```

The HTTP tests need Apache running and create `http-test-…@example.test` accounts.
**[docs/testing.md](docs/testing.md)** maps every required test area to its tests, explains
the optional accessibility/responsive browser audit (`scripts/browser-audit.py`), and has a
manual checklist for the browser.

Check the books at any time in phpMyAdmin → SQL:

```sql
SELECT * FROM v_unbalanced_transactions;                          -- 0 rows
SELECT * FROM v_wallet_reconciliation WHERE difference_kobo <> 0; -- 0 rows
SELECT * FROM v_system_balance;                                   -- 0
```

## Upgrading an existing database

Either re-import the database (step 4, which resets the data) or keep your data and
apply the migrations:

```bash
mysql -u root fintrust < database/migrations/2026_10_14_performance_indexes.sql         # databases created before 14 Oct 2026
mysql -u root fintrust < database/migrations/2026_11_05_avatars_and_biometrics.sql   # profile pictures + Face ID
```

(phpMyAdmin: click the fintrust database, then Import each file. Both are safe to run once each;
the second is also safe to run again.)

## Maintenance

```bash
php scripts/maintenance.php      # schedule daily (Windows Task Scheduler or cron)
php scripts/benchmark.php        # optional: time the real queries on 150,000 transactions
```

Maintenance prunes expired rate-limit windows, idempotency keys, tokens, old read
notifications and old simulated mail. It never deletes transactions, ledger entries,
audit logs or fraud alerts. Benchmark results and the query optimisations are in
**[docs/performance.md](docs/performance.md)**.

## Project structure

```text
fintrust/
├── public/                  ← the ONLY folder the web server should expose
│   ├── index.php, privacy.php, terms.php, 404.php
│   ├── login.php, register.php, forgot-password.php, reset-password.php, verify-email.php
│   ├── dashboard.php, wallet.php, transfer.php, transactions.php, settings.php, notifications.php
│   ├── logout.php           POST + CSRF only
│   ├── admin/               overview, users, transactions, fraud-alerts, security, audit-logs
│   ├── setup-check.php, style-guide.php   (local only)
│   ├── api/index.php        API front controller: every /api/* request lands here
│   ├── .htaccess            routing, compression, browser caching
│   └── assets/              css/app.css (design system), js/app.js, js/theme.js, js/pages/*.js
├── app/
│   ├── bootstrap.php        autoloader, .env, error handling, security headers (CSP …)
│   ├── Core/                Env, Database, Session, Csrf, Auth, Request, Response, Router, View
│   ├── Controllers/         Auth, Dashboard, Wallet, Transaction, Notification, User, Admin, Health
│   ├── Middleware/          RequireAuth, RequireCustomer, RequirePermission
│   ├── Services/            AuthService, TokenService, TransferService, WalletService, Ledger,
│   │                        PinService, IdempotencyService, FraudMonitor, AdminService,
│   │                        RateLimiter, Mailer, Notifier, AuditLog, Settings …
│   ├── Models/              repositories (all SQL lives here) + TransactionPresenter
│   ├── Helpers/             Money (kobo maths), Validate, Dates, Str, Format, Icon, Cache
│   └── Views/               layouts/, partials/, pages/
├── config/                  app.php, database.php, permissions.php (RBAC)
├── routes/api.php           route table with middleware
├── database/
│   ├── schema.sql           tables, indexes, triggers, integrity views
│   ├── seed.sql             demo users, wallets, balanced transactions
│   ├── create_user.sql      least-privilege MySQL account for the app
│   └── migrations/          changes for databases created by an earlier phase
├── scripts/                 concurrency-test, race-condition-demo, benchmark, maintenance,
│                            browser-audit.py (optional)
├── tests/                   run.php, helpers.php, HttpClient.php, *Test.php
├── docs/                    transfers-and-ledger, api, security, testing, performance
├── storage/                 logs/, mail/ (simulated emails), cache/
└── .env.example
```

## Key design decisions

- **Money is integer kobo** (`BIGINT`), never `FLOAT`; amounts are parsed as text
  (`App\Helpers\Money`), so `0.29` is exactly 29 kobo.
- **The ledger is the source of truth.** Every successful transfer writes one debit and
  one credit; wallet balances are a cache updated in the same DB transaction, and
  integrity views prove the two agree.
- **Locks in a fixed order** (`ORDER BY id … FOR UPDATE`) prevent both double-spends and
  deadlocks; a deadlock that still happens is retried.
- **Append-only history**, enforced twice: triggers *and* MySQL grants (the app account
  cannot update or delete ledger or audit rows).
- **Failures are recorded, not lost.** A rejected transfer is stored as `failed` and shown
  in history, with no ledger lines.
- **Server-side checks are the security**; the UI only mirrors them. Every API route
  re-checks the session and permission.
- **Strict CSP** (`script-src 'self'`): no inline scripts or styles; charts are hand-built SVG.
- **Nothing sensitive in the browser.** Only the theme and the hide-balance preference
  are stored in `localStorage`.

## Documentation

| Document | Contents |
|---|---|
| [docs/transfers-and-ledger.md](docs/transfers-and-ledger.md) | DB transactions, row locking, double-entry ledger and idempotency, step by step |
| [docs/api.md](docs/api.md) | Every endpoint with request and response examples |
| [docs/security.md](docs/security.md) | Each security control, where it lives, how it's tested; production checklist |
| [docs/testing.md](docs/testing.md) | Running the tests, coverage map, browser audit, manual checklist |
| [docs/performance.md](docs/performance.md) | Benchmark results, indexes, query rewrites, caching |
