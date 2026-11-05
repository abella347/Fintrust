-- Least-privilege database account for the app. Run as root after schema.sql.
-- No DROP/ALTER rights, and no UPDATE/DELETE on the ledger or audit log.

-- Check the tables exist first. If this statement fails with
-- "#1146 Table 'fintrust.users' doesn't exist", import schema.sql (and seed.sql) first.
DO (SELECT COUNT(*) FROM fintrust.users);

-- Change this password (all four lines below), and use the same value for DB_PASSWORD in .env
CREATE USER IF NOT EXISTS 'fintrust_app'@'localhost' IDENTIFIED BY 'admin1234';
CREATE USER IF NOT EXISTS 'fintrust_app'@'127.0.0.1' IDENTIFIED BY 'admin1234';
-- ALTER makes a re-run update the password if the account already exists.
ALTER USER 'fintrust_app'@'localhost' IDENTIFIED BY 'admin1234';
ALTER USER 'fintrust_app'@'127.0.0.1' IDENTIFIED BY 'admin1234';

-- Start from nothing (safe to re-run).
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'fintrust_app'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'fintrust_app'@'127.0.0.1';

-- Ordinary tables: read and write.
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.users                         TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.user_roles                    TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.transaction_pins              TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.user_devices                  TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.webauthn_credentials          TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.password_reset_tokens         TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.email_verification_tokens     TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.rate_limits                   TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.transaction_idempotency_keys  TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.notifications                 TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE         ON fintrust.fraud_alerts                  TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE         ON fintrust.system_settings               TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT                 ON fintrust.email_outbox                  TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT                         ON fintrust.roles                         TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';

-- Money tables: no DELETE at all. Wallets and transactions are never removed.
GRANT SELECT, INSERT, UPDATE         ON fintrust.wallets                       TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE         ON fintrust.transactions                  TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT                 ON fintrust.login_attempts                TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';

-- Ledger and audit log are append-only (also enforced by triggers in schema.sql).
GRANT SELECT, INSERT                 ON fintrust.ledger_entries                TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT, INSERT                 ON fintrust.audit_logs                    TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';

-- Integrity views: read only.
GRANT SELECT ON fintrust.v_unbalanced_transactions   TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT ON fintrust.v_successful_without_ledger TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT ON fintrust.v_wallet_reconciliation     TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
GRANT SELECT ON fintrust.v_system_balance            TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';

FLUSH PRIVILEGES;
