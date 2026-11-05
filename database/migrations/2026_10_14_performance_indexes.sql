-- Index and view updates for databases created before 14 Oct 2026 (schema.sql already includes them).
-- Run inside the fintrust database. Details: docs/performance.md

ALTER TABLE transactions
    DROP INDEX idx_txn_sender_time,
    DROP INDEX idx_txn_recipient_time,
    DROP INDEX idx_txn_status_time,
    ADD INDEX idx_txn_sender_time (sender_wallet_id, created_at, id),
    ADD INDEX idx_txn_recipient_time (recipient_wallet_id, created_at, id),
    ADD INDEX idx_txn_created (created_at, id),
    ADD INDEX idx_txn_status_time (status, created_at, amount_kobo);

ALTER TABLE login_attempts
    ADD INDEX idx_login_failed_time (successful, attempted_at);

ALTER TABLE ledger_entries
    DROP INDEX idx_ledger_txn,
    DROP INDEX idx_ledger_wallet,
    ADD INDEX idx_ledger_txn (transaction_id, entry_type, amount_kobo),
    ADD INDEX idx_ledger_wallet (wallet_id, entry_type, amount_kobo);

-- Faster integrity views (same results, cheaper plans).
CREATE OR REPLACE VIEW v_unbalanced_transactions AS
SELECT t.id, t.reference, x.total_debits, x.total_credits
FROM (
    SELECT transaction_id,
           SUM(CASE WHEN entry_type = 'debit'  THEN amount_kobo ELSE 0 END) AS total_debits,
           SUM(CASE WHEN entry_type = 'credit' THEN amount_kobo ELSE 0 END) AS total_credits
    FROM ledger_entries GROUP BY transaction_id
    HAVING total_debits <> total_credits
) x
JOIN transactions t ON t.id = x.transaction_id;

CREATE OR REPLACE VIEW v_wallet_reconciliation AS
SELECT w.id AS wallet_id, w.wallet_number, w.type, w.balance_kobo AS cached_balance_kobo,
       COALESCE(l.ledger_balance_kobo, 0) AS ledger_balance_kobo,
       w.balance_kobo - COALESCE(l.ledger_balance_kobo, 0) AS difference_kobo
FROM wallets w
LEFT JOIN (
    SELECT wallet_id,
           SUM(CASE WHEN entry_type = 'credit' THEN CAST(amount_kobo AS SIGNED) ELSE -CAST(amount_kobo AS SIGNED) END) AS ledger_balance_kobo
    FROM ledger_entries GROUP BY wallet_id
) l ON l.wallet_id = w.id;

ANALYZE TABLE transactions, ledger_entries, login_attempts;
