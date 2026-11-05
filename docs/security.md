# FinTrust security controls

Every security requirement from the project brief, where it is implemented, and how to
see it working. FinTrust is a portfolio project: these controls demonstrate good practice
and are not a certification or a guarantee.

## Authentication

| Control | Where | How to see it |
|---|---|---|
| Password hashing (`password_hash`, bcrypt, salted) | `AuthService::register`, `UserRepository` | `SELECT password_hash FROM users` shows `$2y$…`, never the password |
| Automatic re-hash when the algorithm improves | `AuthService::login` (`password_needs_rehash`) | — |
| Strong password rules (10+ chars, upper, lower, digit, symbol, not your email name) | `PasswordPolicy::check` | Register with `abc` |
| Session id regenerated at login (stops session fixation) | `Auth::login` → `Session::regenerate` | Session cookie value changes after login |
| Secure cookies: HttpOnly, SameSite=Lax, Secure in production | `Session::start` | Browser dev tools → Application → Cookies |
| Idle timeout (15 min) and absolute lifetime (8 h) | `Session::enforceIdleTimeout` | Set `SESSION_IDLE_MINUTES=1` and wait |
| Per-account lockout after 5 wrong passwords (15 min) | `AuthService::login` (row-locked counter) | Wrong password 5× |
| Per-IP login rate limit (30 / 15 min) | `RateLimiter::hit('login:ip:…')` | 31 rapid attempts → 429 |
| Same answer for unknown email and wrong password, same timing | `AuthService::login` (`DUMMY_HASH`) | — |
| Password reset: 256-bit single-use tokens, stored hashed, 30-min expiry | `TokenService` | Use a link twice → "invalid or expired" |
| All other sessions end after a password change | `Auth::user` compares `password_changed_at` with `auth_at` | Change password in one browser, click in another |
| New-device login notification | `AuthController::deviceToken`, `UserRepository::rememberDevice` | Log in from a second browser |
| Logout is POST + CSRF; destroys session and cookie | `public/logout.php`, `Session::restart` | — |

## Authorization (RBAC)

| Control | Where |
|---|---|
| Role → permission map (`user`, `support`, `admin`) | `config/permissions.php` |
| Checks by permission, not role name | `Auth::can`, `Auth::rolesCan` |
| API routes protected by middleware | `RequireAuth`, `RequireCustomer`, `RequirePermission:<perm>` in `routes/api.php` |
| Admin pages protected server-side | `Auth::requirePermissionPage` |
| Users see only their own data (enforced in SQL) | `TransactionRepository::VISIBLE`, `WHERE user_id = ?` on notifications |
| Someone else's transaction reference → 404, not 403 | `TransactionController::show` |

## Administrator actions

| Control | Where |
|---|---|
| Separate permissions for viewing, reviewing fraud, managing users and managing wallets | `config/permissions.php` |
| A written reason is required for every change, stored in the audit log with before/after values | `AdminService::reason`, `AuditLog::record` |
| Staff accounts (including your own) can't be suspended from the admin area | `AdminService::customerOrFail` |
| The changed row is locked (`FOR UPDATE`), so a freeze waits for an in-flight transfer and blocks new ones | `AdminService::setWalletStatus` |
| Closed fraud alerts are final; closing requires a note | `AdminService::reviewAlert` |
| Users addressed by unguessable `public_id`, not sequential ids | `/api/admin/users/{public_id}` |
| Customer is notified (and emailed for suspensions) when staff act on their account | `AdminService` |

## Requests and data

| Control | Where |
|---|---|
| SQL injection: real prepared statements only (`EMULATE_PREPARES=false`) | `config/database.php`, every repository |
| LIKE wildcards escaped in search | `TransactionRepository::list` |
| Filter values checked against allow-lists | `TransactionController::index` |
| Server-side validation of every field | `App\Helpers\Validate`, services |
| CSRF token on every POST/PUT/DELETE (header) + JSON-only bodies → `403 CSRF_TOKEN_MISMATCH` / `415` | `public/api/index.php`, `Csrf` |
| XSS: `e()` in PHP templates, `textContent` in JavaScript | views, `shared-ui.js` |
| Content Security Policy `script-src 'self'`, no inline scripts/styles | `app/bootstrap.php` |
| `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS (production) | `app/bootstrap.php` |
| No secrets or tokens in browser storage (only theme / hide-balance prefs) | `theme.js`, `shared-ui.js` |
| Errors logged privately, generic message to the client | `public/api/index.php`, `APP_DEBUG=false` |
| Global API rate limit: 300 reads, 60 writes per minute per IP | `public/api/index.php` |
| Backend folders and `.env` not web-accessible | `.htaccess` files, VirtualHost on `public/` |

## Database

| Control | Where |
|---|---|
| Least-privilege MySQL account (no DROP/ALTER, no DELETE on money tables) | `database/create_user.sql` |
| Ledger and audit log are append-only (triggers + grants) | `database/schema.sql` |
| Completed transactions can't be altered (trigger) | `trg_txn_protect_completed` |
| Customer balances can't go negative (CHECK constraint) | `chk_wallet_balance` |
| Strict SQL mode, UTC timestamps | `config/database.php` |

## Transactions

| Control | Where |
|---|---|
| Hashed 4-digit transaction PIN; 3 wrong → 30-min lock | `PinService` |
| Weak PINs rejected (1111, 1234, years) | `PasswordPolicy::checkPin` |
| Amount validation without floats (integer kobo) | `Money::toKobo`, `Validate::amount` |
| Min / max per transfer, daily limit, wallet balance cap | `TransferService`, `system_settings` |
| Atomic transfers, row locks, deadlock retry | `Database::transaction`, `WalletRepository::lockForUpdate` |
| Double-entry ledger, integrity views | `Ledger::post`, `v_*` views |
| Idempotency keys | `IdempotencyService` |
| Frozen wallets can't send, receive or deposit | `TransferService`, `WalletService` |
| Email must be verified before money moves | `TransferService`, `WalletService` |

## Face ID / fingerprint approval (WebAuthn)

Customers can approve a transfer with Face ID, Touch ID, a fingerprint reader or Windows Hello
instead of the PIN. This is the browser standard WebAuthn (the technology behind passkeys).

| Control | Where |
|---|---|
| Biometrics never leave the device; the server stores only the device's **public key** (`webauthn_credentials`) | `WebAuthn::verifyRegistration` |
| Adding/removing a device needs the account password (rate-limited) and an existing PIN as fallback; max 5 devices; customer notified + audited | `BiometricService` |
| Each approval signs a **one-time challenge** kept in the server-side session: expires in 3 minutes, deleted on first use (no replay) | `BiometricService::transferOptions / verifyTransfer` |
| The challenge is **bound to the exact transfer**: recipient, amount, description and Idempotency-Key — an approval for ₦1,000 to Ben can't send ₦50,000 elsewhere | `BiometricService::transferHash` |
| Checks on every approval: type, challenge, **origin** (phishing sites fail), relying-party id hash, user-present + **user-verified** flags, signature (ES256 / RS256) and a signature counter that must increase | `WebAuthn::verifyAssertion` |
| Origin and rp id come from `APP_URL`, never from request headers | `WebAuthn::origin / rpId` |
| Failed approvals are audit-logged (`security.biometric_failed`); the audit of each transfer records `authorised_by: pin | biometric` | `BiometricService`, `TransferService` |
| Works only in a secure context: HTTPS, or `http://localhost` for development | browser rule |

Attestation (proof of the device's make and model) is deliberately not requested: the app only
needs to know the *same* device signs again. A bank would add attestation and device policies.

## Profile pictures

| Control | Where |
|---|---|
| Sent as a `data:` URL inside the normal JSON + CSRF API request (no separate upload channel) | `settings.js`, `UserController::uploadAvatar` |
| Only JPEG/PNG/WebP up to 2 MB, verified from the bytes (`getimagesizefromstring`), not the file name or MIME type claimed | `AvatarService::save` |
| **Re-encoded with GD** to a fresh 512×512 JPEG: strips EXIF (e.g. GPS location) and anything hidden in the file | `AvatarService::normalise` |
| Stored under a random name in `storage/avatars/` (outside the web root, `Require all denied`), served only to its owner with `nosniff` and `Content-Security-Policy: default-src 'none'` | `AvatarService::stream` |
| Rate limited (20 changes/hour), audited (`profile.avatar_updated`/`removed`) | `UserController`, `AuditLog` |

## Fraud monitoring (demonstration, rule-based)

`app/Services/FraudMonitor.php`. Thresholds are in `system_settings`.

| Rule | Trigger | Severity |
|---|---|---|
| `LARGE_TRANSFER` | One transfer ≥ ₦200,000 | high |
| `RAPID_TRANSFERS` | 5 attempts in 10 min / 10 attempts in 10 min | medium / **critical + wallet auto-frozen** |
| `FAILED_TRANSFERS` | 3 failed transfers in 30 min | medium |
| `LIMIT_EXCEEDED` | Attempt above a per-transfer or daily limit | low |
| `PIN_FAILURES` | Transfers locked after 3 wrong PINs | high |
| `LOGIN_FAILURES` | Account locked after 5 wrong passwords | medium |
| `CREDENTIAL_STUFFING` | 10 failed logins from one IP in 15 min (any accounts) | high |

Alerts are deduplicated while an equivalent one is still open, written to the audit log, and
(for high/critical) the customer gets a "We noticed unusual activity" notification. Monitoring
runs after the money transaction commits and can never block or undo a transfer.

## Audit trail

`audit_logs` records (with IP and browser): registration, login, logout, account lockout, email
verification, password reset request and completion, password and profile changes, PIN
creation/change/lockout, completed and failed transfers, deposits, fraud alerts, automatic
wallet freezes and every administrator action with before/after values.

## How these are verified

`tests/HttpApiTest.php` attacks the running site over real HTTP: CSRF (missing/wrong token,
form-encoded body), session fixation, idle timeout, sessions ended by password change,
reset or suspension, account lockout, rate limits, RBAC per role, other users'
transactions, SQL injection in login/search/filters, script in a description, security
headers and cookie flags, and requests for `.env`, source and SQL files. These tests found and
fixed one bug: CSRF failures used the non-standard status 419, which Apache
turned into `500 Internal Server Error`; they now return `403`. See
[testing.md](testing.md) for the full map.

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS only.
- VirtualHost `DocumentRoot` pointing at `public/`.
- Change every demo password; delete demo accounts (`seed.sql` is for development only).
- Replace the `log` mail driver with a real provider.
- Run `database/create_user.sql` with a long random password and never use `root` for the app.
- Schedule `php scripts/maintenance.php` daily (prunes expired rate limits, keys and tokens).
