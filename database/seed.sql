-- Sample data for local development. Import after schema.sql, inside the fintrust database.
-- Demo logins (all funds are simulated):
--   admin@fintrust.test   Admin@FinTrust2026   (administrator, no wallet)
--   ada@fintrust.test     Demo@Pass2026        PIN 4826   wallet 3021456789
--   ben@fintrust.test     Demo@Pass2026        PIN 4826   wallet 3098765432
--   chidi@fintrust.test   Demo@Pass2026        PIN 4826   wallet 3055500011 (frozen)
--   support@fintrust.test Support@FinTrust2026 (support analyst)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------- Roles ----------
INSERT INTO roles (id, name, description) VALUES
 (1, 'user',  'Customer: own wallet, transfers, history'),
 (2, 'admin', 'Administrator: user management, monitoring, fraud review'),
 (3, 'support', 'Support analyst: read-only monitoring and fraud review');

-- ---------- Users ----------
INSERT INTO users (id, public_id, first_name, last_name, email, phone, password_hash, status, email_verified_at, last_login_at, password_changed_at, created_at) VALUES
 (1, 'ec45d02b-48f8-42d9-9e3a-f128cb1206bf', 'Amara', 'Okafor',  'admin@fintrust.test', '+2348000000001',
     '$2y$10$8MQmUpet.6EKm1hbUylM/ut444yxNPAKM70d3bDHQAUsNw2RB/Lqe', 'active', '2026-10-01 08:00:00', NULL, '2026-10-01 08:00:00', '2026-10-01 08:00:00'),
 (2, '0cf90e47-cec5-4eff-8551-c3926cc5454e', 'Ada',   'Nwosu',   'ada@fintrust.test',   '+2348031234567',
     '$2y$10$Zue1QCBSDLxicNb6naiaXeFYeBUjXyPxqVGmkYvnZs.dwGqqrdcRq', 'active', '2026-10-02 09:10:00', NULL, '2026-10-02 09:00:00', '2026-10-02 09:00:00'),
 (3, '7c971939-c2a9-4f45-8a89-5dbfc7ba4dd0', 'Ben',   'Adeyemi', 'ben@fintrust.test',   '+2348057654321',
     '$2y$10$Zue1QCBSDLxicNb6naiaXeFYeBUjXyPxqVGmkYvnZs.dwGqqrdcRq', 'active', '2026-10-03 11:05:00', NULL, '2026-10-03 11:00:00', '2026-10-03 11:00:00'),
 (4, '23333b8f-a929-4ff2-b5f8-0bec93d2b289', 'Chidi', 'Eze',     'chidi@fintrust.test', '+2348099990000',
     '$2y$10$Zue1QCBSDLxicNb6naiaXeFYeBUjXyPxqVGmkYvnZs.dwGqqrdcRq', 'active', '2026-10-04 14:05:00', NULL, '2026-10-04 14:00:00', '2026-10-04 14:00:00'),
 (5, '64794748-7fba-4c60-ae69-b5a849f9ae52', 'Kemi', 'Bello', 'support@fintrust.test', '+2348000000005',
     '$2y$10$JuSkFtqg3ZvN.4B.qA9blOZ7KlKdyjpimT7.0p/gt2Z3fNCpJk.Wu', 'active', '2026-10-01 08:30:00', NULL, '2026-10-01 08:30:00', '2026-10-01 08:30:00');

INSERT INTO user_roles (user_id, role_id) VALUES (1, 2), (2, 1), (3, 1), (4, 1), (5, 3);

INSERT INTO transaction_pins (user_id, pin_hash) VALUES
 (2, '$2y$10$xNVAzE8dpJo8DGJx71D/j.vEUwrUzyPs4Ucd8qOvaUz5qR0hQ1No2'),
 (3, '$2y$10$xNVAzE8dpJo8DGJx71D/j.vEUwrUzyPs4Ucd8qOvaUz5qR0hQ1No2'),
 (4, '$2y$10$xNVAzE8dpJo8DGJx71D/j.vEUwrUzyPs4Ucd8qOvaUz5qR0hQ1No2');

-- ---------- Wallets ----------
-- Balances (kobo) after the transactions below; they sum to 0.
INSERT INTO wallets (id, wallet_number, user_id, type, label, balance_kobo, status, created_at) VALUES
 (1, '9000000001', NULL, 'system', 'Simulated Funding Account', -40000000, 'active', '2026-10-01 08:00:00'),
 (2, '3021456789', 2,    'user',   NULL,                         23499950, 'active', '2026-10-02 09:00:00'),
 (3, '3098765432', 3,    'user',   NULL,                         11500050, 'active', '2026-10-03 11:00:00'),
 (4, '3055500011', 4,    'user',   NULL,                          5000000, 'frozen', '2026-10-04 14:00:00');

-- ---------- Transactions + ledger ----------
-- T1: Ada funds her wallet with ₦250,000.00 (simulated deposit)
INSERT INTO transactions (id, reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id, initiated_by_user_id, description, created_at, completed_at)
VALUES (1, 'FT20261002DEP0A1B2C3D4', 'deposit', 'successful', 25000000, 1, 2, 2, 'Wallet funding (simulated)', '2026-10-02 09:30:00', '2026-10-02 09:30:00');
INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo, created_at) VALUES
 (1, 1, 'debit',  25000000, -25000000, '2026-10-02 09:30:00'),
 (1, 2, 'credit', 25000000,  25000000, '2026-10-02 09:30:00');

-- T2: Ben funds ₦100,000.00
INSERT INTO transactions (id, reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id, initiated_by_user_id, description, created_at, completed_at)
VALUES (2, 'FT20261003DEP0E5F6A7B8', 'deposit', 'successful', 10000000, 1, 3, 3, 'Wallet funding (simulated)', '2026-10-03 11:20:00', '2026-10-03 11:20:00');
INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo, created_at) VALUES
 (2, 1, 'debit',  10000000, -35000000, '2026-10-03 11:20:00'),
 (2, 3, 'credit', 10000000,  10000000, '2026-10-03 11:20:00');

-- T3: Chidi funds ₦50,000.00 (his wallet is frozen later by the admin)
INSERT INTO transactions (id, reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id, initiated_by_user_id, description, created_at, completed_at)
VALUES (3, 'FT20261004DEP0C9D0E1F2', 'deposit', 'successful', 5000000, 1, 4, 4, 'Wallet funding (simulated)', '2026-10-04 14:30:00', '2026-10-04 14:30:00');
INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo, created_at) VALUES
 (3, 1, 'debit',  5000000, -40000000, '2026-10-04 14:30:00'),
 (3, 4, 'credit', 5000000,   5000000, '2026-10-04 14:30:00');

-- T4: Ada sends Ben ₦15,000.50
INSERT INTO transactions (id, reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id, initiated_by_user_id, description, created_at, completed_at)
VALUES (4, 'FT20261010TRF3A4B5C6D7', 'transfer', 'successful', 1500050, 2, 3, 2, 'Lunch and transport', '2026-10-10 13:45:00', '2026-10-10 13:45:00');
INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo, created_at) VALUES
 (4, 2, 'debit',  1500050, 23499950, '2026-10-10 13:45:00'),
 (4, 3, 'credit', 1500050, 11500050, '2026-10-10 13:45:00');

-- T5: Ben tries to send Ada ₦500,000 - failed (insufficient funds).
-- Recorded for the audit trail, with no ledger entries.
INSERT INTO transactions (id, reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id, initiated_by_user_id, description, failure_reason, created_at, completed_at)
VALUES (5, 'FT20261011TRF8E9F0A1B2', 'transfer', 'failed', 50000000, 3, 2, 3, 'Rent share', 'INSUFFICIENT_FUNDS', '2026-10-11 18:02:00', '2026-10-11 18:02:00');

-- ---------- Notifications ----------
INSERT INTO notifications (user_id, type, title, message, transaction_id, read_at, created_at) VALUES
 (2, 'welcome',           'Welcome to FinTrust',  'Your account and wallet are ready. All funds in this demo are simulated.', NULL, '2026-10-02 09:05:00', '2026-10-02 09:00:00'),
 (2, 'deposit',           'Wallet funded',        'Your wallet was credited with ₦250,000.00.', 1, '2026-10-02 09:31:00', '2026-10-02 09:30:00'),
 (2, 'transfer_sent',     'Transfer successful',  'You sent ₦15,000.50 to Ben Adeyemi (3098765432).', 4, NULL, '2026-10-10 13:45:00'),
 (3, 'welcome',           'Welcome to FinTrust',  'Your account and wallet are ready. All funds in this demo are simulated.', NULL, NULL, '2026-10-03 11:00:00'),
 (3, 'transfer_received', 'Money received',       'You received ₦15,000.50 from Ada Nwosu.', 4, NULL, '2026-10-10 13:45:00'),
 (3, 'transfer_failed',   'Transfer failed',      'Your transfer of ₦500,000.00 failed: insufficient balance.', 5, NULL, '2026-10-11 18:02:00'),
 (4, 'security',          'Wallet frozen',        'Your wallet has been frozen pending a security review. Contact support.', NULL, NULL, '2026-10-12 10:00:00');

-- ---------- Fraud monitoring sample ----------
INSERT INTO fraud_alerts (user_id, transaction_id, rule_code, severity, reason, details, status, created_at) VALUES
 (3, 5, 'TRANSFER_OVER_BALANCE', 'medium', 'Transfer attempt far above available balance',
  JSON_OBJECT('attempted_kobo', 50000000, 'available_kobo', 11500050), 'open', '2026-10-11 18:02:00'),
 (4, NULL, 'PIN_FAILURES', 'high', '3 incorrect transaction PIN attempts within 5 minutes',
  JSON_OBJECT('attempts', 3, 'window_minutes', 5), 'investigating', '2026-10-12 09:55:00');

-- ---------- Login history ----------
INSERT INTO login_attempts (user_id, email, ip_address, user_agent, successful, failure_reason, attempted_at) VALUES
 (2, 'ada@fintrust.test',   '127.0.0.1', 'Mozilla/5.0 (seed)', 1, NULL,           '2026-10-10 13:40:00'),
 (4, 'chidi@fintrust.test', '127.0.0.1', 'Mozilla/5.0 (seed)', 0, 'bad_password', '2026-10-12 09:50:00'),
 (4, 'chidi@fintrust.test', '127.0.0.1', 'Mozilla/5.0 (seed)', 1, NULL,           '2026-10-12 09:52:00');

-- ---------- Audit trail ----------
INSERT INTO audit_logs (actor_user_id, actor_type, action, entity_type, entity_id, old_values, new_values, ip_address, created_at) VALUES
 (NULL, 'system', 'seed.loaded', NULL, NULL, NULL, JSON_OBJECT('note', 'Sample data loaded'), '127.0.0.1', '2026-10-01 08:00:00'),
 (2,    'user',   'transfer.completed', 'transaction', 4, NULL, JSON_OBJECT('reference', 'FT20261010TRF3A4B5C6D7', 'amount_kobo', 1500050), '127.0.0.1', '2026-10-10 13:45:00'),
 (1,    'admin',  'wallet.freeze', 'wallet', 4, JSON_OBJECT('status', 'active'), JSON_OBJECT('status', 'frozen', 'reason', 'PIN_FAILURES alert under investigation'), '127.0.0.1', '2026-10-12 10:00:00');

-- ---------- System settings (limits are in kobo) ----------
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
 ('schema_version',              '1',         'Database schema version'),
 ('transfer_min_kobo',           '10000',     'Smallest transfer: ₦100.00'),
 ('transfer_max_single_kobo',    '50000000',  'Largest single transfer: ₦500,000.00'),
 ('transfer_daily_limit_kobo',   '200000000', 'Max total sent per user per day: ₦2,000,000.00'),
 ('deposit_max_single_kobo',     '100000000', 'Largest simulated deposit: ₦1,000,000.00'),
 ('wallet_max_balance_kobo',     '1000000000','Largest allowed wallet balance: ₦10,000,000.00'),
 ('fraud_large_transfer_kobo',   '20000000',  'Flag transfers at or above ₦200,000.00'),
 ('fraud_rapid_transfer_count',  '5',         'Flag when this many transfers happen...'),
 ('fraud_rapid_window_minutes',  '10',        '...within this many minutes (double the count = critical, wallet auto-frozen)'),
 ('fraud_failed_transfer_count', '3',         'Flag this many failed transfers...'),
 ('fraud_failed_window_minutes', '30',        '...within this many minutes'),
 ('fraud_ip_login_failures',     '10',        'Flag an IP with this many failed logins in 15 minutes (any accounts)'),
 ('login_max_attempts',          '5',         'Failed logins before temporary lockout'),
 ('login_lockout_minutes',       '15',        'Login lockout duration'),
 ('pin_max_attempts',            '3',         'Wrong PINs before transfers are locked'),
 ('pin_lockout_minutes',         '30',        'PIN lockout duration'),
 ('idempotency_ttl_hours',       '24',        'How long an idempotency key is remembered');

COMMIT;
