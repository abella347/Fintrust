<?php

declare(strict_types=1);

use App\Core\Database;

return [
    'seed data balances: no unbalanced transactions' => function () {
        in_rollback(function (PDO $pdo) {
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn());
        });
    },
    'seed data balances: cached balances equal ledger balances' => function () {
        in_rollback(function (PDO $pdo) {
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn());
        });
    },
    'all wallets together sum to exactly zero' => function () {
        in_rollback(function (PDO $pdo) {
            assert_same(0, (int) $pdo->query('SELECT total_of_all_wallets_kobo FROM v_system_balance')->fetchColumn());
        });
    },
    'ledger entries cannot be updated' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, 'UPDATE ledger_entries SET amount_kobo = 1 WHERE id = 1', [], 'append-only|denied'));
    },
    'ledger entries cannot be deleted' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, 'DELETE FROM ledger_entries WHERE id = 1', [], 'append-only|denied'));
    },
    'audit log cannot be edited' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, "UPDATE audit_logs SET action = 'x' WHERE id = 1", [], 'append-only|denied'));
    },
    'completed transaction amount cannot be changed' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, 'UPDATE transactions SET amount_kobo = 1 WHERE id = 4', [], 'cannot be altered'));
    },
    'failed transaction cannot be flipped to successful' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, "UPDATE transactions SET status = 'successful' WHERE id = 5", [], 'failed transaction'));
    },
    'customer wallet balance can never go negative' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error($pdo, 'UPDATE wallets SET balance_kobo = -1 WHERE id = 2', [], 'chk_wallet_balance'));
    },
    'system wallet MAY go negative (it is the funding source)' => function () {
        in_rollback(function (PDO $pdo) {
            $pdo->exec('UPDATE wallets SET balance_kobo = balance_kobo - 100 WHERE id = 1');
            assert_true(true);
        });
    },
    'a transaction cannot send to the same wallet' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error(
            $pdo,
            "INSERT INTO transactions (reference, type, amount_kobo, sender_wallet_id, recipient_wallet_id) VALUES (?, 'transfer', 100, 2, 2)",
            ['TESTSELF'],
            'chk_txn_not_self'
        ));
    },
    'transaction amount must be positive' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error(
            $pdo,
            "INSERT INTO transactions (reference, type, amount_kobo, sender_wallet_id, recipient_wallet_id) VALUES (?, 'transfer', 0, 2, 3)",
            ['TESTZERO'],
            'chk_txn_amount'
        ));
    },
    'transaction references are unique' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error(
            $pdo,
            "INSERT INTO transactions (reference, type, amount_kobo, sender_wallet_id, recipient_wallet_id) VALUES ('FT20261010TRF3A4B5C6D7', 'transfer', 100, 2, 3)",
            [],
            'Duplicate'
        ));
    },
    'same idempotency key cannot be stored twice for one user' => function () {
        in_rollback(function (PDO $pdo) {
            $sql = "INSERT INTO transaction_idempotency_keys (user_id, idempotency_key, endpoint, request_hash, expires_at)
                    VALUES (2, 'key-123', 'transfer', REPEAT('a', 64), UTC_TIMESTAMP() + INTERVAL 1 DAY)";
            $pdo->exec($sql);
            expect_db_error($pdo, $sql, [], 'Duplicate');
        });
    },
    'emails are unique regardless of letter case' => function () {
        in_rollback(fn (PDO $pdo) => expect_db_error(
            $pdo,
            "INSERT INTO users (public_id, first_name, last_name, email, phone, password_hash) VALUES (UUID(), 'A', 'B', 'ADA@FinTrust.test', '+2340000000099', 'x')",
            [],
            'Duplicate'
        ));
    },
    'a balanced transfer posted correctly keeps the books balanced' => function () {
        in_rollback(function (PDO $pdo) {
            $pdo->exec("INSERT INTO transactions (reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id)
                        VALUES ('TESTBAL1', 'transfer', 'successful', 10000, 2, 3)");
            $txn = (int) $pdo->lastInsertId();
            $pdo->exec('UPDATE wallets SET balance_kobo = balance_kobo - 10000 WHERE id = 2');
            $pdo->exec('UPDATE wallets SET balance_kobo = balance_kobo + 10000 WHERE id = 3');
            $pdo->exec("INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo) VALUES
                        ({$txn}, 2, 'debit', 10000, 23489950), ({$txn}, 3, 'credit', 10000, 11510050)");
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn());
            assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn());
        });
    },
    'a one-sided (debit only) posting is caught by the integrity views' => function () {
        in_rollback(function (PDO $pdo) {
            $pdo->exec("INSERT INTO transactions (reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id)
                        VALUES ('TESTBAD1', 'transfer', 'successful', 10000, 2, 3)");
            $txn = (int) $pdo->lastInsertId();
            $pdo->exec('UPDATE wallets SET balance_kobo = balance_kobo - 10000 WHERE id = 2');
            $pdo->exec("INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo)
                        VALUES ({$txn}, 2, 'debit', 10000, 23489950)");
            assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn(), 'unbalanced view');
            assert_same(-10000, (int) $pdo->query('SELECT total_of_all_wallets_kobo FROM v_system_balance')->fetchColumn(), 'money vanished');
        });
    },
];
