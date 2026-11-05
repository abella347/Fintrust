# FinTrust API contract

Every endpoint the frontend calls. Routes are declared in
`routes/api.php`; each points at a controller in `app/Controllers/`, which calls a service
in `app/Services/`.

## Conventions

- **Base path:** `/api`. JSON in, JSON out (`Content-Type: application/json`).
- **Auth:** PHP session cookie. Every state-changing request (POST/PUT/DELETE) must send
  the `X-CSRF-Token` header (read from `<meta name="csrf-token">`) and a JSON body
  (`Content-Type: application/json`). `FinTrust.api()` does both automatically.
  Missing/wrong token → 403 `CSRF_TOKEN_MISMATCH`; non-JSON body → 415 `UNSUPPORTED_MEDIA_TYPE`.
- **Access:** customer endpoints (dashboard, wallet, transactions, PIN) need a signed-in
  account with the `user` role → otherwise 401 `UNAUTHENTICATED` / 403 `FORBIDDEN`.
- **Money movement** (deposit, transfer) also needs a **verified email** (403 `EMAIL_NOT_VERIFIED`);
  transfers need a **PIN** (403 `PIN_NOT_SET`).
- **Money:** responses use integer **kobo** (`*_kobo` fields). Requests send amounts as a
  **string** of naira (`"1500.50"`), which the server converts with `Money::toKobo()`.
  No floats anywhere.
- **Times:** ISO-8601 UTC strings, e.g. `"2026-10-10T13:45:00Z"`. The browser formats them.
- **Money-moving requests** (`deposit`, `transfer`) require an `Idempotency-Key` header
  (one random UUID per user attempt, reused on retry). The server fingerprints the request
  body **without the PIN**, so retrying after a wrong PIN is still the same attempt. Only a
  completed money movement uses the key up; a replay then returns the original response.
  Rejections that moved nothing (validation, wrong PIN, insufficient funds) leave the key
  free. Same key + different body → 422 `IDEMPOTENCY_KEY_REUSED`.

### Envelope

```json
{ "success": true,  "message": "Transfer successful.", "data": { } }
{ "success": false, "message": "Insufficient balance.",
  "error": { "code": "INSUFFICIENT_FUNDS", "fields": { "amount": "Insufficient balance." } } }
```

`fields` maps form field names to messages; the frontend shows each under its input.

### Shared object: `Transaction`

```json
{
  "reference": "FT20261010TRF3A4B5C6D7",
  "type": "transfer",                 // transfer | deposit | reversal
  "direction": "out",                 // in | out, from the CURRENT user's point of view
  "status": "successful",             // pending | successful | failed | reversed
  "amount_kobo": 1500050,
  "description": "Lunch and transport",
  "counterparty": { "name": "Ben Adeyemi", "wallet_number": "3098765432" },
  "failure_reason": null,             // e.g. "INSUFFICIENT_FUNDS" when failed
  "balance_after_kobo": 23499950,     // the user's balance after it (null if failed)
  "created_at": "2026-10-10T13:45:00Z",
  "completed_at": "2026-10-10T13:45:00Z"
}
```

For deposits `counterparty` is `{ "name": "Wallet funding (simulated)", "wallet_number": null }`.

## Authentication

| Method & path | Body | Success `data` | Notable errors |
|---|---|---|---|
| `POST /api/auth/register` | `first_name, last_name, email, phone, password, password_confirmation, accept_terms` | `{ "email": "...", "requires_verification": true }` (201) | 422 `VALIDATION_FAILED` (`fields`), 409 `EMAIL_TAKEN` |
| `POST /api/auth/login` | `email, password, next?` | `{ "redirect": "dashboard" }` (or `"admin/"`) | 401 `INVALID_CREDENTIALS`, 423 `ACCOUNT_LOCKED`, 403 `ACCOUNT_SUSPENDED`, 429 `TOO_MANY_REQUESTS` |
| `POST /api/auth/logout` | – | `null` | |
| `POST /api/auth/forgot-password` | `email` | `null` — **same message whether or not the email exists** | 429 |
| `POST /api/auth/reset-password` | `token, password, password_confirmation` | `null` | 422 `INVALID_TOKEN` |
| `POST /api/auth/verify-email` | `token` | `null` | 422 `INVALID_TOKEN` |
| `POST /api/auth/resend-verification` | `email` | `null` | 429 |

## Dashboard & wallet

`GET /api/dashboard` — everything the dashboard needs in one round trip:

```json
{
  "user":   { "first_name": "Ada", "last_name": "Nwosu", "email": "ada@fintrust.test",
              "email_verified": true, "has_pin": true, "last_login_at": "2026-10-14T08:02:00Z" },
  "wallet": { "wallet_number": "3021456789", "balance_kobo": 23499950, "status": "active", "currency": "NGN" },
  "stats":  { "received_kobo": 25000000, "sent_kobo": 1500050, "transaction_count": 2, "period": "last_30_days" },
  "chart":  [ { "label": "May", "month": "2026-05", "in_kobo": 0, "out_kobo": 0 }, "... 6 months, oldest first" ],
  "recent_transactions": [ "Transaction", "... up to 5" ],
  "notifications": { "unread_count": 1, "items": [ "Notification", "... up to 5" ] }
}
```

| Method & path | Body / query | Success `data` | Notable errors |
|---|---|---|---|
| `GET /api/wallet` | – | `{ wallet_number, balance_kobo, status, currency, created_at, email_verified, has_pin, has_biometric, limits: { transfer_min_kobo, transfer_max_single_kobo, transfer_daily_limit_kobo, sent_today_kobo, deposit_max_single_kobo } }` | |
| `GET /api/wallet/balance` | – | `{ "balance_kobo": 23499950 }` | |
| `GET /api/wallet/lookup` | `?wallet_number=3098765432` | `{ "wallet_number": "3098765432", "account_name": "Ben A." }` (name partly masked) | 404 `WALLET_NOT_FOUND`, 422 `SELF_TRANSFER`, 422 `WALLET_UNAVAILABLE` |
| `POST /api/wallet/deposit` | `amount` + header `Idempotency-Key` | `{ "transaction": Transaction, "balance_kobo": 33499950 }` (201) | 422 `AMOUNT_OUT_OF_RANGE`, 423 `WALLET_FROZEN` |

## Transactions

| Method & path | Body / query | Success `data` | Notable errors |
|---|---|---|---|
| `POST /api/transactions/transfer/options` | `recipient_wallet_number, amount, description?` + `Idempotency-Key` (the same key the transfer will use) | WebAuthn request options with a one-time `challenge` bound to this exact transfer (expires in 3 min) | 422 `NO_BIOMETRICS` |
| `POST /api/transactions/transfer` | `recipient_wallet_number, amount, description?` and **either** `pin` **or** `webauthn: { id, clientDataJSON, authenticatorData, signature }` (Face ID / fingerprint approval) + `Idempotency-Key` | `{ "transaction": Transaction, "balance_kobo": 21999950 }` (201) | 422 `INSUFFICIENT_FUNDS`, `INVALID_PIN` (with `attempts_remaining`), `LIMIT_EXCEEDED`, `SELF_TRANSFER`; 404 `WALLET_NOT_FOUND`; 423 `PIN_LOCKED`, `WALLET_FROZEN`; 409 `IDEMPOTENCY_IN_PROGRESS`; 403 `BIOMETRIC_FAILED` / `BIOMETRIC_EXPIRED` |
| `GET /api/transactions` | `?page=1&per_page=10&search=&type=&status=&direction=&from=YYYY-MM-DD&to=YYYY-MM-DD` | `{ "items": [Transaction], "pagination": { "page": 1, "per_page": 10, "total": 23, "total_pages": 3 } }` | 422 bad filter |
| `GET /api/transactions/{reference}` | – | `Transaction` | 404 (also when it isn't the user's — never reveal it exists) |

## Notifications

| Method & path | Body / query | Success `data` |
|---|---|---|
| `GET /api/notifications` | `?page=1` | `{ "items": [Notification], "unread_count": 2, "pagination": {...} }` |
| `POST /api/notifications/read` | `{ "ids": [12, 13] }` or `{ "all": true }` | `{ "unread_count": 0 }` |

`Notification`: `{ "id": 12, "type": "transfer_received", "title": "Money received", "message": "...", "read": false, "transaction_reference": "FT...", "created_at": "..." }`

## User profile & security

| Method & path | Body | Success `data` | Notable errors |
|---|---|---|---|
| `GET /api/user/profile` | – | `{ first_name, last_name, email, phone, email_verified, has_pin, has_biometric, avatar_url, created_at, recent_logins: [ { successful, ip_address, user_agent, attempted_at } ] }` | |
| `PUT /api/user/profile` | `first_name, last_name, phone, current_password` | updated profile | 401 `INVALID_PASSWORD`, 422 |
| `PUT /api/user/password` | `current_password, password, password_confirmation` | `null` | 401 `INVALID_PASSWORD`, 422 |
| `PUT /api/user/pin` | `current_password, pin, pin_confirmation` | `{ "has_pin": true }` | 401 `INVALID_PASSWORD`, 422 `WEAK_PIN` |
| `POST /api/user/avatar` | `image`: a `data:image/jpeg|png|webp;base64,...` URL (≤ 2 MB) | `{ "avatar_url": "…/api/user/avatar?v=…" }` — re-encoded server-side to a 512×512 JPEG | 422 `VALIDATION_FAILED` (`fields.avatar`) |
| `GET /api/user/avatar` | – | the signed-in user's own picture (`image/jpeg`, `nosniff`) | 404 when none |
| `DELETE /api/user/avatar` | `{}` | `{ "avatar_url": null }` | |
| `GET /api/user/biometrics` | – | `{ "devices": [ { id, label, created_at, last_used_at } ] }` | |
| `POST /api/user/biometrics/options` | `current_password` | WebAuthn creation options (platform authenticator, user verification required) | 401 `INVALID_PASSWORD`, 422 `PIN_REQUIRED`, `TOO_MANY_DEVICES` |
| `POST /api/user/biometrics` | `credential: { id, clientDataJSON, attestationObject }, label?` | `{ id, label }` (201) | 403 `BIOMETRIC_FAILED` / `BIOMETRIC_EXPIRED`, 409 `ALREADY_REGISTERED` |
| `DELETE /api/user/biometrics/{id}` | `current_password` | `null` | 401 `INVALID_PASSWORD`, 404 |

Sensitive changes (profile, password, PIN, adding or removing Face ID devices) all require `current_password` — this is the
"reauthentication before sensitive changes" requirement.

## Admin (staff only)

Every route checks a permission from `config/permissions.php` (`RequirePermission:<perm>`):
signed out → 401, missing permission → 403. Users are addressed by `public_id` (UUID), never
by their numeric id. Every change requires a `reason` (5–255 chars) and is written to the audit log.

| Method & path | Permission | Body / query | Success `data` |
|---|---|---|---|
| `GET /api/admin/statistics` | `reports.view` | – | `{ users, wallets, transactions, fraud, security, ledger: { unbalanced_transactions, wallets_out_of_step, system_total_kobo }, daily: [ {date, label, transfers_kobo, deposits_kobo, failed} ], recent_alerts, recent_transactions }` |
| `GET /api/admin/users` | `users.view` | `?search=&status=&role=&page=` | `{ items: [User], pagination }` |
| `GET /api/admin/users/{public_id}` | `users.view` | – | `{ user, transactions, alerts, logins, can_manage: { users, wallets } }` |
| `POST /api/admin/users/{public_id}/suspend` | `users.manage` | `reason` | `{ public_id, status }` — 403 for staff accounts, 409 `NO_CHANGE` |
| `POST /api/admin/users/{public_id}/reactivate` | `users.manage` | `reason` | same; also clears a login lockout |
| `POST /api/admin/wallets/{number}/freeze` | `wallets.manage` | `reason` | `{ wallet_number, status }` |
| `POST /api/admin/wallets/{number}/unfreeze` | `wallets.manage` | `reason` | same |
| `GET /api/admin/transactions` | `transactions.view` | `?search=&type=&status=&from=&to=&min=&max=&flagged=1&page=` | `{ items, pagination }` (`min`/`max` in naira) |
| `GET /api/admin/transactions/{reference}` | `transactions.view` | – | transaction + both parties + `ledger: [ {entry_type, wallet_number, amount_kobo, balance_after_kobo} ]` |
| `GET /api/admin/fraud-alerts` | `fraud.view` | `?status=&severity=&rule=&page=` | `{ items, pagination, can_review }` |
| `PUT /api/admin/fraud-alerts/{id}` | `fraud.review` | `status` (investigating/resolved/dismissed), `note` (required to close) | `{ id, status }` — 409 `ALERT_CLOSED` |
| `GET /api/admin/audit-logs` | `audit.view` | `?action=<prefix>&actor=<email>&actor_type=&page=` | `{ items: [ {action, actor, entity_type, entity_id, old_values, new_values, ip_address, created_at} ], pagination }` |
| `GET /api/admin/security` | `security.view` | – | `{ locked_accounts, pin_locked, top_ips, recent_failures, suspended, frozen_wallets }` |
