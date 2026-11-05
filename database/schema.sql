-- FinTrust schema (MySQL 8.0+ / MariaDB 10.4+). Import it inside an existing `fintrust` database.
-- Drops and recreates the FinTrust tables, so existing data is lost.

-- Error #1046 (no database selected): select the fintrust database in phpMyAdmin first.
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Start clean: remove FinTrust's own objects only (triggers go with their tables).
SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_unbalanced_transactions;
DROP VIEW IF EXISTS v_successful_without_ledger;
DROP VIEW IF EXISTS v_wallet_reconciliation;
DROP VIEW IF EXISTS v_system_balance;
DROP TABLE IF EXISTS email_outbox;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS fraud_alerts;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS transaction_idempotency_keys;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS wallets;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS email_verification_tokens;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS webauthn_credentials;
DROP TABLE IF EXISTS user_devices;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS transaction_pins;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;
SET NAMES utf8mb4;

SET time_zone = '+00:00';

-- ---------- Identity and access ----------

-- Every person who can log in: customers and administrators alike.
-- What they are allowed to do lives in roles/user_roles, not here.
CREATE TABLE users (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Public ID used in URLs instead of the numeric id
    public_id          CHAR(36)        NOT NULL,
    first_name         VARCHAR(60)     NOT NULL,
    last_name          VARCHAR(60)     NOT NULL,
    email              VARCHAR(191)    NOT NULL,
    phone              VARCHAR(20)     NOT NULL,
    -- password_hash() output (bcrypt/argon2). Never the password itself.
    password_hash      VARCHAR(255)    NOT NULL,
    status             ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
    email_verified_at  DATETIME        NULL,
    -- Brute-force protection: after N failures, locked_until is set.
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until       DATETIME        NULL,
    last_login_at      DATETIME        NULL,
    password_changed_at DATETIME       NULL,
    -- Profile picture: random file name inside storage/avatars/ (never web-served directly).
    avatar_path        VARCHAR(80)     NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    UNIQUE KEY uq_users_email (email),          -- prevents duplicate registrations, even under a race
    UNIQUE KEY uq_users_phone (phone),
    KEY idx_users_status (status),
    KEY idx_users_created (created_at)
) ENGINE=InnoDB;

-- Role-based access control (RBAC).
CREATE TABLE roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(30)      NOT NULL,   -- 'user', 'admin'
    description VARCHAR(255)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

-- Many-to-many: a user can hold several roles.
CREATE TABLE user_roles (
    user_id     BIGINT UNSIGNED  NOT NULL,
    role_id     TINYINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED  NULL,       -- admin who granted it (NULL = system/seed)
    assigned_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT fk_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Transaction PIN, separate from the password. Stored as a hash, like a password.
-- One row per user (user_id is the primary key = one-to-one relationship).
CREATE TABLE transaction_pins (
    user_id         BIGINT UNSIGNED  NOT NULL,
    pin_hash        VARCHAR(255)     NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME         NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_pins_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every login attempt, successful or not (rate limiting, login history, fraud rules).
CREATE TABLE login_attempts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NULL,     -- NULL when the email doesn't exist
    email          VARCHAR(191)    NOT NULL,
    ip_address     VARCHAR(45)     NOT NULL, -- 45 chars fits IPv6
    user_agent     VARCHAR(255)    NOT NULL,
    successful     TINYINT(1)      NOT NULL,
    failure_reason VARCHAR(50)     NULL,     -- 'bad_password', 'locked', 'suspended', ...
    attempted_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_email_time (email, attempted_at),   -- "failures for this email in last 15 min"
    KEY idx_login_ip_time (ip_address, attempted_at), -- "failures from this IP in last 15 min"
    KEY idx_login_user_time (user_id, attempted_at),
    KEY idx_login_failed_time (successful, attempted_at), -- security page: recent failures
    CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Browsers/devices a user has logged in from, to detect "login from a new device".
CREATE TABLE user_devices (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    device_hash   CHAR(64)        NOT NULL,  -- SHA-256 of a device cookie value
    user_agent    VARCHAR(255)    NOT NULL,
    last_ip       VARCHAR(45)     NOT NULL,
    first_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_user_hash (user_id, device_hash),
    CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- WebAuthn credentials (Face ID / fingerprint / Windows Hello). Only the device's public key is stored.
CREATE TABLE webauthn_credentials (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    credential_id   VARCHAR(255)    NOT NULL,      -- base64url, as sent by the browser
    public_key_pem  TEXT            NOT NULL,
    algorithm       SMALLINT        NOT NULL,      -- COSE: -7 = ES256, -257 = RS256
    sign_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    label           VARCHAR(60)     NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webauthn_credential (credential_id),
    KEY idx_webauthn_user (user_id),
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Single-use, time-limited tokens, stored as SHA-256 hashes only.
CREATE TABLE password_reset_tokens (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    token_hash   CHAR(64)        NOT NULL,
    expires_at   DATETIME        NOT NULL,
    used_at      DATETIME        NULL,       -- set once used -> can't be used twice
    requested_ip VARCHAR(45)     NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id, created_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE email_verification_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64)        NOT NULL,
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verify_token (token_hash),
    KEY idx_verify_user (user_id),
    CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Generic fixed-window rate limiter (e.g. "max 5 transfers per minute").
CREATE TABLE rate_limits (
    bucket_key   VARCHAR(191)     NOT NULL,  -- e.g. 'transfer:user:42'
    hits         INT UNSIGNED     NOT NULL DEFAULT 0,
    window_start DATETIME         NOT NULL,
    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB;

-- ---------- Wallets, transactions and ledger ----------

-- 'user' wallets can't go below zero; the 'system' funding wallet goes negative as deposits are simulated.
-- balance_kobo is a cached total; the ledger is the source of truth.
CREATE TABLE wallets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wallet_number CHAR(10)        NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    type          ENUM('user','system') NOT NULL DEFAULT 'user',
    label         VARCHAR(60)     NULL,      -- name for system wallets
    currency      CHAR(3)         NOT NULL DEFAULT 'NGN',
    balance_kobo  BIGINT          NOT NULL DEFAULT 0,
    status        ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallet_number (wallet_number),
    UNIQUE KEY uq_wallet_user (user_id),     -- one wallet per user (NULLs allowed for system wallets)
    KEY idx_wallet_status (status),
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    -- Customer balances can never go negative, even if application code is wrong
    CONSTRAINT chk_wallet_balance CHECK (type = 'system' OR balance_kobo >= 0),
    CONSTRAINT chk_wallet_owner CHECK (
        (type = 'user' AND user_id IS NOT NULL) OR (type = 'system' AND user_id IS NULL)
    ),
    CONSTRAINT chk_wallet_number CHECK (wallet_number REGEXP '^[0-9]{10}$')
) ENGINE=InnoDB;

-- One row per money movement. Failed attempts are kept, with no ledger entries.
CREATE TABLE transactions (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference            VARCHAR(32)     NOT NULL,  -- e.g. FT20261014A1B2C3D4E5F6
    type                 ENUM('transfer','deposit','reversal','adjustment') NOT NULL,
    status               ENUM('pending','successful','failed','reversed') NOT NULL DEFAULT 'pending',
    amount_kobo          BIGINT UNSIGNED NOT NULL,
    currency             CHAR(3)         NOT NULL DEFAULT 'NGN',
    sender_wallet_id     BIGINT UNSIGNED NULL,      -- money leaves this wallet
    recipient_wallet_id  BIGINT UNSIGNED NULL,      -- money arrives here
    initiated_by_user_id BIGINT UNSIGNED NULL,
    description          VARCHAR(140)    NULL,
    failure_reason       VARCHAR(100)    NULL,
    reverses_transaction_id BIGINT UNSIGNED NULL,   -- set on a 'reversal' row
    ip_address           VARCHAR(45)     NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at         DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_txn_reference (reference),
    -- Wallet history, newest first (read in index order, no sort)
    KEY idx_txn_sender_time (sender_wallet_id, created_at, id),
    KEY idx_txn_recipient_time (recipient_wallet_id, created_at, id),
    -- Admin list, newest first
    KEY idx_txn_created (created_at, id),
    -- Covering index for volume totals by status
    KEY idx_txn_status_time (status, created_at, amount_kobo),
    KEY idx_txn_type_time (type, created_at),
    KEY idx_txn_initiator (initiated_by_user_id, created_at),
    CONSTRAINT fk_txn_sender FOREIGN KEY (sender_wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_txn_recipient FOREIGN KEY (recipient_wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_txn_initiator FOREIGN KEY (initiated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_txn_reverses FOREIGN KEY (reverses_transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
    CONSTRAINT chk_txn_amount CHECK (amount_kobo > 0),
    CONSTRAINT chk_txn_not_self CHECK (sender_wallet_id IS NULL OR recipient_wallet_id IS NULL
                                       OR sender_wallet_id <> recipient_wallet_id)
) ENGINE=InnoDB;

-- Double-entry ledger: for each transaction, debits = credits. Append-only (see triggers).
CREATE TABLE ledger_entries (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id     BIGINT UNSIGNED NOT NULL,
    wallet_id          BIGINT UNSIGNED NOT NULL,
    entry_type         ENUM('debit','credit') NOT NULL,
    amount_kobo        BIGINT UNSIGNED NOT NULL,
    -- Wallet balance right after this entry
    balance_after_kobo BIGINT          NOT NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Covering indexes for the integrity views
    KEY idx_ledger_txn (transaction_id, entry_type, amount_kobo),
    KEY idx_ledger_wallet (wallet_id, entry_type, amount_kobo),
    CONSTRAINT fk_ledger_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_amount CHECK (amount_kobo > 0)
) ENGINE=InnoDB;

-- A retried request with the same Idempotency-Key gets the stored response instead of moving money again.
CREATE TABLE transaction_idempotency_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(64)     NOT NULL,
    endpoint        VARCHAR(60)     NOT NULL,  -- 'transfer', 'deposit'
    request_hash    CHAR(64)        NOT NULL,  -- SHA-256 of the request body; same key + different body = rejected
    status          ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    transaction_id  BIGINT UNSIGNED NULL,
    response_code   SMALLINT UNSIGNED NULL,
    response_body   JSON            NULL,      -- replayed verbatim on retry
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idem_user_key (user_id, idempotency_key),
    KEY idx_idem_expires (expires_at),
    CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_idem_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Security, monitoring and notifications ----------

CREATE TABLE notifications (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    type           VARCHAR(40)     NOT NULL,  -- 'transfer_sent', 'transfer_received', 'security', ...
    title          VARCHAR(120)    NOT NULL,
    message        VARCHAR(500)    NOT NULL,
    transaction_id BIGINT UNSIGNED NULL,
    read_at        DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user_read (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Output of the rule-based fraud monitor (demonstration only, not a production fraud engine).
CREATE TABLE fraud_alerts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,
    transaction_id  BIGINT UNSIGNED NULL,
    rule_code       VARCHAR(50)     NOT NULL,  -- 'LARGE_TRANSFER', 'RAPID_TRANSFERS', 'PIN_FAILURES', ...
    severity        ENUM('low','medium','high','critical') NOT NULL,
    reason          VARCHAR(255)    NOT NULL,
    details         JSON            NULL,
    status          ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
    reviewed_by     BIGINT UNSIGNED NULL,
    resolution_note VARCHAR(500)    NULL,
    reviewed_at     DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fraud_status_sev (status, severity, created_at),
    KEY idx_fraud_user (user_id, created_at),
    KEY idx_fraud_rule_time (rule_code, created_at),
    CONSTRAINT fk_fraud_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_fraud_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_fraud_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Who did what, when, from where. Append-only (see triggers).
CREATE TABLE audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,       -- NULL = the system itself
    actor_type    ENUM('user','admin','system') NOT NULL,
    action        VARCHAR(80)     NOT NULL,   -- 'wallet.freeze', 'auth.login', 'transfer.completed'
    entity_type   VARCHAR(40)     NULL,       -- 'wallet', 'user', 'transaction'
    entity_id     BIGINT UNSIGNED NULL,
    old_values    JSON            NULL,
    new_values    JSON            NULL,
    ip_address    VARCHAR(45)     NULL,
    user_agent    VARCHAR(255)    NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_action_time (action, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tunable limits and settings, changeable by admins without touching code.
CREATE TABLE system_settings (
    setting_key   VARCHAR(60)     NOT NULL,
    setting_value VARCHAR(255)    NOT NULL,
    description   VARCHAR(255)    NOT NULL,
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Simulated email delivery for local development ("outbox" you can read).
CREATE TABLE email_outbox (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NULL,
    to_email   VARCHAR(191)    NOT NULL,
    subject    VARCHAR(200)    NOT NULL,
    body_text  TEXT            NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_outbox_user (user_id, created_at),
    CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Immutability triggers ----------
DELIMITER $$

CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: post a reversal instead of editing.';
END$$

CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: rows cannot be deleted.';
END$$

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only.';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only.';
END$$

-- A finished transaction's money facts are frozen. Only status may move
-- forward (successful -> reversed); amount, wallets and reference can't change.
CREATE TRIGGER trg_txn_protect_completed BEFORE UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF OLD.status IN ('successful','failed','reversed') AND (
           NEW.amount_kobo <> OLD.amount_kobo
        OR NEW.reference   <> OLD.reference
        OR NOT (NEW.sender_wallet_id    <=> OLD.sender_wallet_id)
        OR NOT (NEW.recipient_wallet_id <=> OLD.recipient_wallet_id)
        OR NEW.type <> OLD.type
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Completed transactions cannot be altered.';
    END IF;

    IF OLD.status = 'failed' AND NEW.status <> 'failed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A failed transaction cannot change status.';
    END IF;

    IF OLD.status = 'successful' AND NEW.status NOT IN ('successful','reversed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A successful transaction can only become reversed.';
    END IF;
END$$

DELIMITER ;

-- ---------- Integrity views ----------

-- Transactions whose debits and credits don't match. Must always be empty.
CREATE VIEW v_unbalanced_transactions AS
SELECT  t.id,
        t.reference,
        x.total_debits,
        x.total_credits
FROM (
    SELECT transaction_id,
           SUM(CASE WHEN entry_type = 'debit'  THEN amount_kobo ELSE 0 END) AS total_debits,
           SUM(CASE WHEN entry_type = 'credit' THEN amount_kobo ELSE 0 END) AS total_credits
    FROM ledger_entries
    GROUP BY transaction_id
    HAVING total_debits <> total_credits
) x
JOIN transactions t ON t.id = x.transaction_id;

-- Successful transactions with no ledger rows at all (money "moved" with no record). Must be empty.
CREATE VIEW v_successful_without_ledger AS
SELECT t.id, t.reference, t.type, t.amount_kobo
FROM   transactions t
LEFT JOIN ledger_entries le ON le.transaction_id = t.id
WHERE  t.status IN ('successful','reversed')
GROUP BY t.id, t.reference, t.type, t.amount_kobo
HAVING COUNT(le.id) = 0;

-- Cached wallet balance vs. the balance recomputed from the ledger.
-- `difference_kobo` must be 0 for every wallet.
CREATE VIEW v_wallet_reconciliation AS
SELECT  w.id AS wallet_id,
        w.wallet_number,
        w.type,
        w.balance_kobo AS cached_balance_kobo,
        COALESCE(l.ledger_balance_kobo, 0) AS ledger_balance_kobo,
        w.balance_kobo - COALESCE(l.ledger_balance_kobo, 0) AS difference_kobo
FROM wallets w
LEFT JOIN (
    SELECT wallet_id,
           SUM(CASE WHEN entry_type = 'credit' THEN CAST(amount_kobo AS SIGNED) ELSE -CAST(amount_kobo AS SIGNED) END) AS ledger_balance_kobo
    FROM ledger_entries
    GROUP BY wallet_id
) l ON l.wallet_id = w.id;

-- Sum of all wallet balances across the system. Must always be 0.
CREATE VIEW v_system_balance AS
SELECT COALESCE(SUM(balance_kobo), 0) AS total_of_all_wallets_kobo FROM wallets;
