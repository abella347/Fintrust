<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;
use App\Services\Settings;
use App\Services\TransferService;
use App\Services\WalletService;

const ADA = 2;
const BEN = 3;

$send = fn (string $to, string $amount, string $pin = '4826', ?string $k = null) =>
    (new TransferService())->transfer(ADA, ['recipient_wallet_number' => $to, 'amount' => $amount, 'description' => 'test', 'pin' => $pin], $k ?? idem_key());

return [
    'successful transfer debits sender, credits recipient, books balance' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $ada = balance($pdo, '3021456789');
            $ben = balance($pdo, '3098765432');
            $r = $send('3098765432', '1,500.50');
            assert_same(201, $r['code']);
            assert_same($ada - 150050, balance($pdo, '3021456789'), 'sender');
            assert_same($ben + 150050, balance($pdo, '3098765432'), 'recipient');
            assert_same($ada - 150050, $r['data']['balance_kobo']);
            assert_same('out', $r['data']['transaction']['direction']);
            books_balanced($pdo);
        });
    },
    'exactly two ledger entries: one debit, one credit, equal amounts' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $ref = $send('3098765432', '250')['data']['transaction']['reference'];
            $s = $pdo->prepare('SELECT le.entry_type, le.amount_kobo FROM ledger_entries le JOIN transactions t ON t.id = le.transaction_id WHERE t.reference = ? ORDER BY le.entry_type');
            $s->execute([$ref]);
            // ENUM columns sort by their declared order ('debit','credit'), not alphabetically.
            assert_same([['entry_type' => 'debit', 'amount_kobo' => 25000], ['entry_type' => 'credit', 'amount_kobo' => 25000]], $s->fetchAll());
        });
    },
    'both parties are notified' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $send('3098765432', '100');
            $s = $pdo->query("SELECT user_id, type FROM notifications ORDER BY id DESC LIMIT 2");
            $rows = $s->fetchAll();
            assert_same(['transfer_received', 'transfer_sent'], [$rows[0]['type'], $rows[1]['type']]);
            assert_same(BEN, (int) $rows[0]['user_id']);
        });
    },
    'insufficient balance: rejected, recorded as failed, no money moves' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $ada = balance($pdo, '3021456789');
            assert_same('INSUFFICIENT_FUNDS', error_code(fn () => $send('3098765432', '400000')));
            assert_same($ada, balance($pdo, '3021456789'));
            $row = $pdo->query("SELECT status, failure_reason FROM transactions ORDER BY id DESC LIMIT 1")->fetch();
            assert_same(['status' => 'failed', 'failure_reason' => 'INSUFFICIENT_FUNDS'], $row);
            assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM ledger_entries le JOIN transactions t ON t.id = le.transaction_id WHERE t.status = 'failed'")->fetchColumn(), 'no ledger rows for failures');
            books_balanced($pdo);
        });
    },
    'cannot send to your own wallet' => function () use ($send) {
        in_rollback(fn () => assert_same('SELF_TRANSFER', error_code(fn () => $send('3021456789', '100'))));
    },
    'unknown wallet number is rejected' => function () use ($send) {
        in_rollback(fn () => assert_same('WALLET_NOT_FOUND', error_code(fn () => $send('1234567890', '100'))));
    },
    'frozen recipient wallet cannot receive' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            assert_same('WALLET_UNAVAILABLE', error_code(fn () => $send('3055500011', '100')));
            books_balanced($pdo);
        });
    },
    'amount validation: below minimum, 3 decimals, negative, text' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            assert_same('LIMIT_EXCEEDED', error_code(fn () => $send('3098765432', '99.99')));
            assert_same('VALIDATION_FAILED', error_code(fn () => $send('3098765432', '10.005')));
            assert_same('VALIDATION_FAILED', error_code(fn () => $send('3098765432', '-500')));
            assert_same('VALIDATION_FAILED', error_code(fn () => $send('3098765432', '1e5')));
        });
    },
    'wrong PIN counts down, then locks transfers (even with the right PIN)' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            assert_same('INVALID_PIN', error_code(fn () => $send('3098765432', '100', '1111')));
            assert_same('INVALID_PIN', error_code(fn () => $send('3098765432', '100', '2222')));
            assert_same('PIN_LOCKED', error_code(fn () => $send('3098765432', '100', '3333')));
            assert_same('PIN_LOCKED', error_code(fn () => $send('3098765432', '100', '4826')));
        });
    },
    'same Idempotency-Key replays the first result: money moves once' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $k = idem_key();
            $ada = balance($pdo, '3021456789');
            $first = $send('3098765432', '700', '4826', $k);
            $second = $send('3098765432', '700', '4826', $k);
            assert_same($first['data']['transaction']['reference'], $second['data']['transaction']['reference']);
            assert_same($ada - 70000, balance($pdo, '3021456789'), 'debited once');
        });
    },
    'reusing a key for a different amount is refused' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $k = idem_key();
            $send('3098765432', '700', '4826', $k);
            assert_same('IDEMPOTENCY_KEY_REUSED', error_code(fn () => $send('3098765432', '800', '4826', $k)));
        });
    },
    'a key is released after a wrong PIN, so the retry can succeed' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            $k = idem_key();
            assert_same('INVALID_PIN', error_code(fn () => $send('3098765432', '300', '1111', $k)));
            assert_same(201, $send('3098765432', '300', '4826', $k)['code']);
        });
    },
    'missing Idempotency-Key is rejected' => function () {
        in_rollback(fn () => assert_same('IDEMPOTENCY_KEY_REQUIRED', error_code(fn () =>
            (new TransferService())->transfer(ADA, ['recipient_wallet_number' => '3098765432', 'amount' => '100', 'pin' => '4826'], null))));
    },
    'daily limit counts earlier transfers today' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            reset_pin_lock($pdo, ADA);
            // Allow only ₦1,000 more than whatever was already sent today.
            $limit = (new App\Models\WalletRepository())->sentToday(2) + 100000;
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'transfer_daily_limit_kobo'")->execute([(string) $limit]);
            Settings::flush();
            try {
                $send('3098765432', '600');
                assert_same('LIMIT_EXCEEDED', error_code(fn () => $send('3098765432', '500')));
            } finally {
                Settings::flush();
            }
        });
    },
    'unverified email cannot move money' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            $pdo->exec('UPDATE users SET email_verified_at = NULL WHERE id = ' . ADA);
            assert_same('EMAIL_NOT_VERIFIED', error_code(fn () => $send('3098765432', '100')));
        });
    },
    'no PIN set: transfers are refused' => function () use ($send) {
        in_rollback(function (PDO $pdo) use ($send) {
            $pdo->exec('DELETE FROM transaction_pins WHERE user_id = ' . ADA);
            assert_same('PIN_NOT_SET', error_code(fn () => $send('3098765432', '100')));
        });
    },
    'deposit: funding wallet debited, user credited, books balance' => function () {
        in_rollback(function (PDO $pdo) {
            $ada = balance($pdo, '3021456789');
            $sys = balance($pdo, '9000000001');
            $r = (new WalletService())->deposit(ADA, ['amount' => '5000'], idem_key());
            assert_same($ada + 500000, balance($pdo, '3021456789'));
            assert_same($sys - 500000, balance($pdo, '9000000001'));
            assert_same('in', $r['data']['transaction']['direction']);
            books_balanced($pdo);
        });
    },
    'deposit limits are enforced' => function () {
        in_rollback(function () {
            assert_same('AMOUNT_OUT_OF_RANGE', error_code(fn () => (new WalletService())->deposit(ADA, ['amount' => '50'], idem_key())));
            assert_same('AMOUNT_OUT_OF_RANGE', error_code(fn () => (new WalletService())->deposit(ADA, ['amount' => '2000000'], idem_key())));
        });
    },
    'ROLLBACK: a failure after the debit leaves no trace' => function () {
        // Runs its own top-level transaction (not in_rollback) to prove Database::transaction rolls back.
        $pdo = Database::connection();
        $before = balance($pdo, '3021456789');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        try {
            Database::transaction(function () {
                $w = new App\Models\WalletRepository();
                $locked = $w->lockForUpdate([2, 3]);
                $txn = (new App\Models\TransactionRepository())->create('transfer', 'successful', 1000, 2, 3, ADA, 'rollback test');
                $w->debit(2, 1000);                       // Money has left Ada...
                throw new RuntimeException('simulated crash before the credit');
            });
        } catch (RuntimeException) {
        }
        assert_same($before, balance($pdo, '3021456789'), 'debit was rolled back');
        assert_same($count, (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), 'transaction row was rolled back');
        books_balanced($pdo);
    },
];
