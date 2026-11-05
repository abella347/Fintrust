# Testing FinTrust

Three layers, from fastest to most realistic:

| Layer | Command | What it proves |
|---|---|---|
| Automated tests (111) | `php tests/run.php` | Business rules, ledger, security and every HTTP workflow |
| Concurrency | `php tests/run.php --slow` | No overdraft or double-spend under simultaneous requests |
| Browser audit (optional) | `python scripts/browser-audit.py --axe …` | Accessibility, responsive layout, keyboard focus, JS errors |

On XAMPP for Windows use `C:\xampp\php\php.exe tests\run.php`.

## 1. Automated tests

```bash
php tests/run.php            # ~20 s; 110 tests + 1 skipped (the slow one)
php tests/run.php --slow     # also fires 12 transfers at the same instant
```

Expected last line: `110 passed, 0 failed, 1 skipped` (or `111 passed` with `--slow`).

| Suite | Tests | How it runs |
|---|---:|---|
| `MoneyTest` | 17 | Pure PHP: kobo maths, references, wallet numbers, device names |
| `LedgerSchemaTest` | 17 | Against MySQL inside a rolled-back transaction: triggers, constraints, integrity views |
| `TransferServiceTest` | 20 | Real `TransferService` / `WalletService`, rolled back |
| `SecurityTest` | 12 | RBAC matrix and every fraud rule, rolled back |
| `AdminServiceTest` | 7 | Suspend, freeze, fraud review, audit entries, rolled back |
| `QueryOptimizationTest` | 3 | Optimised queries return exactly what the simple versions return |
| `BiometricAvatarTest` | 8 | Face ID / fingerprint crypto with real ES256 + RS256 keys, a Face ID transfer, profile pictures |
| `HttpApiTest` | 27 | Real HTTP requests through Apache → `.htaccess` → router → middleware |
| `ConcurrencyTest` | 1 | Parallel PHP processes (`--slow` only) |

**Requirements.** Database tests need the schema imported (they're skipped otherwise).
HTTP tests need Apache running at `APP_URL` from `.env` (skipped otherwise) and the log
mail driver (`MAIL_DRIVER=log`), because they read verification and reset links from
`storage/mail/` exactly like you would.

**Effect on your data.** Service tests always roll back. HTTP tests can't (each request is
its own connection), so they create accounts named `http-test-…@example.test`, send Ben
₦200 per run, and reset this machine's rate-limit counters. Re-import
`schema.sql` → `seed.sql` (with the fintrust database selected) for a clean database.

## 2. Coverage of the required test areas

| # | Area | Automated test(s) |
|---|---|---|
| 1 | Registration | HTTP: *registration creates a user, a hashed password, the user role and a ₦0 wallet*; *invalid sign-up data returns 422* |
| 2 | Duplicate accounts | HTTP: *duplicate email (any letter case) is refused with 409*; Ledger: *emails are unique regardless of letter case* |
| 3 | Login (valid and invalid) | HTTP: *wrong password and unknown email get the identical 401 answer*; *successful login issues a new session id* |
| 4 | Logout | HTTP: *protected endpoints return 401 when signed out, and logout ends access* |
| 5 | Password hashing | HTTP registration test (`$2y$`/argon hash, `password_verify`, plain text never stored) |
| 6 | Email verification | HTTP: *unverified accounts cannot move money until the email link is used* (link is single-use) |
| 7 | Password reset | HTTP: *password reset – same answer for unknown emails, single-use link, old sessions signed out* |
| 8 | Session security / timeout | HTTP: *idle session is logged out*; *changing the password signs out other sessions*; *suspended customer cannot log in*; *new session id on login* |
| 9 | Brute-force protection | HTTP: *5 wrong passwords lock the account*; *repeated logins are rate limited (429)*; *API-wide limit* |
| 10 | Deposits | Transfer: *deposit: funding wallet debited, user credited, books balance*; *deposit limits are enforced* |
| 11 | Transfers | Transfer: *successful transfer debits sender, credits recipient*; *exactly two ledger entries*; *both parties are notified* |
| 12 | Insufficient balance | Transfer + HTTP: *insufficient balance … no money moves* (recorded as **failed**) |
| 13 | Invalid recipient / self-transfer / frozen | Transfer: *cannot send to your own wallet*; *unknown wallet number*; *frozen recipient*; Admin: *freeze wallet* |
| 14 | Transaction PIN | Transfer: *wrong PIN counts down, then locks*; *no PIN set*; HTTP wrong-PIN test |
| 15 | Duplicate transactions (idempotency) | Transfer: *same Idempotency-Key replays*; *different amount refused*; HTTP: *a transfer moves money once …*; Concurrency (4 requests share one key) |
| 16 | Transaction history and privacy | HTTP: *a customer cannot read someone else's transaction (404)*; QueryOptimization (every filter + paging) |
| 17 | Admin access control (RBAC) | Security: RBAC matrix; HTTP: *customers, support and admins see different things*; *protected pages redirect* |
| 18 | SQL injection | HTTP: *SQL injection attempts are treated as plain text* (login, search, allow-listed filters); Money: *rejects text and injection attempts* |
| 19 | XSS and CSRF | HTTP: *script in a description is stored as text and returned as JSON*; *pages send the security headers (CSP …)*; *state-changing requests need the CSRF header and a JSON body* |

Also covered: Face ID / fingerprint approval (`BiometricAvatarTest`: genuine approvals verify;
other challenge, phishing origin, other site, no user verification, tampered signature, someone
else's key and a repeated counter are all refused; an approval can't be replayed or reused for a
different amount), profile pictures (re-encoded; PHP/SVG disguised as images refused; private
download), ledger integrity and append-only history (`LedgerSchemaTest`), rollback on
failure (*ROLLBACK: a failure after the debit leaves no trace*), every fraud rule
(`SecurityTest`), files that must never be served (*configuration and source files are not
served*), and concurrency (`--slow`).

## 3. Concurrency

```bash
php scripts/concurrency-test.php     # or: php tests/run.php --slow
php scripts/race-condition-demo.php  # watch a double-spend happen WITHOUT row locks
```

12 transfers of ₦20,000 from a ₦50,000 wallet at the same instant (8 with their own
keys, 4 sharing one key). Correct result: exactly 2 succeed, the shared key moves money at
most once, and the ledger balances. See [transfers-and-ledger.md](transfers-and-ledger.md).

## 4. Browser audit (optional)

Checks every page — public, customer and admin — in **light and dark** theme:

- **axe-core** accessibility rules (WCAG 2.x: contrast, labels, headings, ARIA, …)
- no horizontal scrolling at **320, 375, 768, 1024 and 1366 px**
- the first 25 **Tab** stops all show a visible focus style
- no JavaScript errors

```bash
pip install playwright && python -m playwright install chromium
npm install axe-core@4.10.2                     # any folder
python scripts/browser-audit.py --axe node_modules/axe-core/axe.min.js
```

Latest result: **44 page/theme combinations, 0 findings.** What the first run found and
how it was fixed:

| Finding | Fix |
|---|---|
| Login/register side panel was `aria-hidden` but contained a link (and hid the "funds are simulated" note from screen readers) | Panel is now a labelled `<aside>`; its slogan is a paragraph, so the form's `<h1>` comes first |
| Dark mode: white text on the light-red danger button and unread badge (2.76:1) | New `--danger-solid` colour gives 4.8:1 in both themes |
| 404 page had no `<h1>` | Empty-state component takes a heading level |
| Admin overview skipped from `<h1>` to `<h3>` | Hidden "Key figures" `<h2>` |
| Charts: every bar was its own Tab stop (28 on the admin overview); ₦0 bars had no visible focus | One Tab stop + arrow keys (roving tabindex); the focused column is highlighted |
| Input focus ring was a 20 %-opacity glow, faint in dark mode | Solid 2 px ring in the theme's focus colour |
| 320 px: stat-card amounts cut off ("₦250,00…") | One column under 400 px; amounts scale with the card and never truncate |
| 720–1279 px: 6-column tables wider than the content area | Rows become cards up to 1279 px (two per row from 720 px) |
| Admin filters truncated "All statuses" | Filter grid uses 150 px minimum columns |

axe also checks that every icon-only button and form field has an accessible name, and the
audit runs with `prefers-reduced-motion`, under which the CSS turns animations off. A full
keyboard-only and screen-reader walkthrough is still worth doing by hand (section 5).

## 5. Manual checklist (browser)

**New customer journey**

1. `/register` → create an account → "Check your inbox".
2. Open the newest file in `storage/mail/` → open the verification link → **Verify my email**.
3. Log in. **Send money** explains you need a PIN first.
4. Settings → Transaction PIN → try `1234` (rejected as too easy), then a good PIN.
5. Wallet → Add money ₦50,000 → Send money to Ben `3098765432` → wrong PIN once, then the right one.
6. Log in as `ben@fintrust.test` in another browser: the transfer is in his history and notifications.

| Try this | Expect |
|---|---|
| Wrong password 5× for one account | Account locked for 15 minutes (even the right password is refused) |
| Forgot password with any email | Same message whether or not the account exists |
| Use a reset link twice | Second time: "invalid or has expired" |
| Change password while logged in on two browsers | The other browser is signed out on its next click |
| Leave a page idle for 15 minutes | Next click asks you to log in again |
| Wrong PIN 3× | Transfers locked for 30 minutes, notification + audit entry |
| Send more than your balance | Rejected; a **failed** transaction appears in history |
| Double-click **Confirm transfer** | Money moves once (idempotency key) |
| Search `' OR 1=1 --` in history | Zero results |
| Complete a transfer with the keyboard only (Tab, Enter, Esc) | Every step reachable, focus always visible, dialogs close with Esc |
| Open `/fintrust/.env` or `/fintrust/app/bootstrap.php` | 403 Forbidden |

**Fraud monitoring (demo rules), as Ada**

| Do this | Alert |
|---|---|
| Send ₦200,000 or more | `LARGE_TRANSFER` (high) |
| 5 small transfers within 10 minutes | `RAPID_TRANSFERS` (medium); 10 → critical, wallet auto-frozen |
| 3 failed transfers | `FAILED_TRANSFERS` (medium) |
| Try to send more than ₦500,000 | `LIMIT_EXCEEDED` (low) |
| Wrong PIN 3 times | `PIN_FAILURES` (high) |
| 5 wrong passwords | `LOGIN_FAILURES` (medium) |

**Admin (admin@fintrust.test) and support (support@fintrust.test)**

| Page | Try |
|---|---|
| Overview | Ledger health reads *0 / 0 / ₦0.00*; chart: Tab to it, then arrow keys; **Show as table** |
| Users | Search `ben` → View → **Freeze wallet** (reason required) → Ben's transfers stop |
| Transactions | Filters, **Flagged only**, search by reference / wallet number / name; open one to see both ledger entries |
| Fraud alerts | Investigate → Resolve / Dismiss (closing needs a note) |
| Security / Audit log | Locked accounts, failed logins by IP; every admin action with before → after |
| As support | No freeze/suspend buttons, no Audit log; the API returns `403` too |

**Responsive:** resize from 320 px to a wide screen (or use the browser's device toolbar)
on the dashboard, transfer, transactions and admin pages, in both themes (moon button).

## 6. Database checks (phpMyAdmin → SQL)

```sql
SELECT * FROM v_unbalanced_transactions;                          -- 0 rows
SELECT * FROM v_wallet_reconciliation WHERE difference_kobo <> 0; -- 0 rows
SELECT * FROM v_system_balance;                                   -- 0
UPDATE ledger_entries SET amount_kobo = 1 WHERE id = 1;           -- error: append-only
SELECT action, actor_type, created_at FROM audit_logs ORDER BY id DESC LIMIT 20;
```
