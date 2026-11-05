-- Adds profile pictures and WebAuthn credentials to databases created before 5 Nov 2026.
-- Run inside the fintrust database; safe to run more than once.

ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(80) NULL AFTER password_changed_at;

CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    credential_id   VARCHAR(255)    NOT NULL,
    public_key_pem  TEXT            NOT NULL,
    algorithm       SMALLINT        NOT NULL,
    sign_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    label           VARCHAR(60)     NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webauthn_credential (credential_id),
    KEY idx_webauthn_user (user_id),
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Let the app's own database account use the new table.
GRANT SELECT, INSERT, UPDATE, DELETE ON fintrust.webauthn_credentials TO 'fintrust_app'@'localhost', 'fintrust_app'@'127.0.0.1';
FLUSH PRIVILEGES;
