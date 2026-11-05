# How FinTrust moves money safely

| Concept | Code |
|---|---|
| Transfer steps, in order | `app/Services/TransferService.php` |
| Row locks (`FOR UPDATE`, fixed order) | `WalletRepository::lockForUpdate()` |
| Transaction + deadlock retry | `Database::transaction()` |
| Double-entry posting | `app/Services/Ledger.php` |
| Idempotency keys | `app/Services/IdempotencyService.php` |
| PIN attempts and lockout | `app/Services/PinService.php` |
| Tests | `tests/TransferServiceTest.php`, `scripts/concurrency-test.php` |

This note explains the three ideas the transfer system stands on:
**database transactions**, **row locking**, and the **double-entry ledger** — plus
**idempotency keys**, which protect against duplicate requests. Every claim here is
backed by something you can run: `tests/run.php` and `scripts/race-condition-demo.php`.

---

## 1. Database transactions: all or nothing

A transfer is several SQL statements: debit the sender, credit the recipient, write
the transaction row, write two ledger rows. If PHP crashes after the debit but before
the credit, money disappears. A **database transaction** makes the whole group atomic:

```sql
START TRANSACTION;
  UPDATE wallets SET balance_kobo = balance_kobo - 500000 WHERE id = 2;  -- debit
  UPDATE wallets SET balance_kobo = balance_kobo + 500000 WHERE id = 3;  -- credit
  INSERT INTO transactions ...;
  INSERT INTO ledger_entries ...;   -- debit row
  INSERT INTO ledger_entries ...;   -- credit row
COMMIT;      -- all five become visible at once
-- or ROLLBACK; -- none of them ever happened
```

These are the **ACID** guarantees InnoDB gives us:

| Letter | Meaning | What it prevents in FinTrust |
|---|---|---|
| **A**tomic | All statements succeed or none do | Debit without credit |
| **C**onsistent | Constraints hold before and after | Negative balance (`chk_wallet_balance`) |
| **I**solated | Concurrent transfers don't see each other's half-done work | Double spending (with locks, below) |
| **D**urable | Once COMMIT returns, it survives a crash | "Successful" transfer vanishing after a power cut |

In code, `App\Core\Database::transaction()` wraps this: it commits if your function
returns, rolls back if it throws **anything**, and retries automatically if MySQL
reports a deadlock (error 1213) — safe, because a deadlocked transaction was already
fully rolled back by the server.

## 2. Row locking: stopping the double spend

Transactions alone are **not enough**. Run `php scripts/race-condition-demo.php`:

```
=== UNSAFE: read, then write (no lock) ===
  Transfers reported successful: 10   rejected: 0
  Ada's wallet balance:  ₦30,000.00   (ledger says -₦150,000.00)

=== SAFE: SELECT ... FOR UPDATE inside a transaction ===
  Transfers reported successful: 2    rejected: 8
  Ada's wallet balance:  ₦10,000.00   (ledger says ₦10,000.00)
```

Ten requests each tried to send ₦20,000 from a ₦50,000 wallet. In the unsafe version
every request *read* ₦50,000 before anyone wrote, so every balance check passed — ₦200,000
"left" a ₦50,000 wallet. This is a **race condition** (specifically a *lost update*).

The fix is a **pessimistic row lock**:

```sql
SELECT id, balance_kobo FROM wallets
WHERE id IN (:sender, :recipient)
ORDER BY id            -- always lock in the same order (see deadlocks below)
FOR UPDATE;            -- exclusive lock until COMMIT/ROLLBACK
```

`FOR UPDATE` tells InnoDB: "I'm going to change these rows; anyone else who wants to
lock or change them must wait until I commit." Request #2 blocks on that line until
request #1 commits, then reads the **new** balance (₦30,000), and so on. Checks and
writes now happen one-at-a-time per wallet, while transfers between *other* wallets
still run in parallel — that's why it's a *row* lock, not a table lock.

**Two rules that make locking correct:**

1. **Lock before you check.** The balance you compare against must be read *with*
   `FOR UPDATE` inside the same transaction. A balance read earlier (or outside the
   transaction) is stale the moment you get it.
2. **Lock in a consistent order (lowest wallet id first).** If Ada → Ben locks Ada then
   Ben, while Ben → Ada locks Ben then Ada, each can hold one lock and wait forever for
   the other: a **deadlock**. MySQL detects it and kills one (error 1213). Sorting by
   id makes this far rarer; `Database::transaction()` retries the rare remainder.

**Isolation level.** MySQL defaults to `REPEATABLE READ`. FinTrust's transfer uses
`READ COMMITTED`, because every value that matters is read with `FOR UPDATE` (which
always sees the latest committed row), and `READ COMMITTED` takes fewer *gap locks*,
so unrelated inserts into `transactions`/`ledger_entries` block each other less. The
correctness comes from the explicit locks, not from the isolation level.

We also write balance changes **relatively** — `balance_kobo = balance_kobo - ?` —
never `balance_kobo = <value computed in PHP>`. Under the lock both are correct, but
the relative form can't silently overwrite someone else's change if a lock is ever
forgotten.

## 3. The double-entry ledger: the books must balance

`wallets.balance_kobo` is a convenient **cache**. The authoritative record is
`ledger_entries`, where **every successful transaction writes entries whose debits
equal its credits**:

| transaction | wallet | entry | amount | balance after |
|---|---|---|---|---|
| T4 transfer | Ada 3021456789 | debit  | 1,500,050 | 23,499,950 |
| T4 transfer | Ben 3098765432 | credit | 1,500,050 | 11,500,050 |

Money is never created or destroyed, only moved. Even deposits follow the rule: the
"Add Money" feature debits the **Simulated Funding Account** (system wallet
`9000000001`, allowed to go negative) and credits the user. Consequences you can check
at any time:

```sql
SELECT * FROM v_unbalanced_transactions;                         -- must be empty
SELECT * FROM v_wallet_reconciliation WHERE difference_kobo <> 0; -- must be empty
SELECT * FROM v_system_balance;                                  -- must be 0
```

In the race demo, the cached balances *looked* plausible (₦30,000 + ₦20,000 = ₦50,000)
but the ledger recorded ₦200,000 of movement — the ledger is what exposes the bug.
That's why it's the source of truth.

**History is append-only.** Triggers reject `UPDATE`/`DELETE` on `ledger_entries` and
`audit_logs`, and the app's MySQL account isn't even *granted* those permissions —
two independent locks. A mistake is corrected with a new `reversal` transaction that
posts the opposite entries, so the history of what happened is never lost.

## 4. Idempotency keys: the same click never pays twice

A user taps **Send**, the network stalls, the browser retries. Without protection
that's two transfers. The frontend generates one random key per attempt
(`FinTrust.newIdempotencyKey()`) and sends it as an `Idempotency-Key` header. The server:

1. `INSERT` the key into `transaction_idempotency_keys` (status `processing`).
   The `UNIQUE (user_id, idempotency_key)` index means only **one** request can win
   this insert, even if two arrive in the same millisecond.
2. Duplicate? If the stored request hash differs → reject (key reused for a different
   transfer). If still `processing` → `409 Conflict`. If `completed` → replay the stored
   response; no money moves.
3. Run the transfer; store the response alongside the key.

## 5. The transfer, step by step (as implemented)

```
validate input (amount -> kobo, wallet number format)      outside the transaction
verify PIN (password_verify, attempt counter)              outside — cheap rejections first
claim idempotency key (unique insert)
BEGIN (READ COMMITTED)
  SELECT ... FROM wallets WHERE id IN (s, r) ORDER BY id FOR UPDATE
  check: both active, not frozen, sender != recipient
  check: sender balance >= amount, daily limit, single limit
  INSERT transactions (status 'successful', unique reference)
  UPDATE wallets SET balance_kobo = balance_kobo - amt WHERE id = s
  UPDATE wallets SET balance_kobo = balance_kobo + amt WHERE id = r
  INSERT ledger_entries (debit s), (credit r)  with balance_after
  INSERT notifications, audit_logs
COMMIT                         -- any exception anywhere above => ROLLBACK
store idempotent response; run fraud rules
```

Failed attempts (insufficient funds, frozen wallet) are recorded as `failed`
transactions **without** ledger entries, giving the audit trail and fraud monitor
something to see while proving no money moved.
