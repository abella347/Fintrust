<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Services\FraudMonitor;
use App\Services\Settings;
use App\Services\TransferService;

/** Newest alerts for a rule, newest first. */
function alerts(PDO $pdo, string $rule, ?int $userId = null): array
{
    $sql = 'SELECT * FROM fraud_alerts WHERE rule_code = ?' . ($userId !== null ? ' AND user_id = ?' : '') . ' ORDER BY id DESC';
    $s = $pdo->prepare($sql);
    $s->execute($userId !== null ? [$rule, $userId] : [$rule]);
    return $s->fetchAll();
}

function set_setting(PDO $pdo, string $key, int $value): void
{
    $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([(string) $value, $key]);
    Settings::flush();
}

$ada = fn (string $amount, string $pin = '4826', string $to = '3098765432') =>
    (new TransferService())->transfer(2, ['recipient_wallet_number' => $to, 'amount' => $amount, 'pin' => $pin], bin2hex(random_bytes(16)));

return [
    /* ---------------- RBAC ---------------- */
    'RBAC: customers can use wallets but not the admin area' => function () {
        assert_true(Auth::rolesCan(['user'], 'wallet.use'));
        assert_true(!Auth::rolesCan(['user'], 'admin.access'));
        assert_true(!Auth::rolesCan(['user'], 'users.manage'));
    },
    'RBAC: support can review fraud but cannot freeze wallets or suspend users' => function () {
        assert_true(Auth::rolesCan(['support'], 'fraud.review'));
        assert_true(Auth::rolesCan(['support'], 'transactions.view'));
        assert_true(!Auth::rolesCan(['support'], 'wallets.manage'));
        assert_true(!Auth::rolesCan(['support'], 'users.manage'));
        assert_true(!Auth::rolesCan(['support'], 'audit.view'));
        assert_true(!Auth::rolesCan(['support'], 'wallet.use'), 'staff have no wallet');
    },
    'RBAC: admins hold every staff permission; unknown roles hold none' => function () {
        foreach (['admin.access', 'users.manage', 'wallets.manage', 'fraud.review', 'audit.view'] as $p) {
            assert_true(Auth::rolesCan(['admin'], $p), $p);
        }
        assert_true(!Auth::rolesCan(['hacker'], 'admin.access'));
        assert_true(!Auth::rolesCan([], 'wallet.use'));
    },

    /* ---------------- fraud rules ---------------- */
    'fraud: large transfer raises a HIGH alert linked to the transaction' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            set_setting($pdo, 'fraud_large_transfer_kobo', 100000); // ₦1,000 for the test
            try {
                $ref = $ada('1500')['data']['transaction']['reference'];
                $a = alerts($pdo, 'LARGE_TRANSFER', 2)[0] ?? null;
                assert_true($a !== null, 'alert raised');
                assert_same('high', $a['severity']);
                $s = $pdo->prepare('SELECT reference FROM transactions WHERE id = ?');
                $s->execute([$a['transaction_id']]);
                assert_same($ref, $s->fetchColumn());
            } finally {
                Settings::flush();
            }
        });
    },
    'fraud: small transfers raise nothing' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            $before = (int) $pdo->query('SELECT COUNT(*) FROM fraud_alerts')->fetchColumn();
            $ada('150');
            assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM fraud_alerts')->fetchColumn());
        });
    },
    'fraud: rapid transfers -> medium alert, raised once (deduplicated)' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            set_setting($pdo, 'fraud_rapid_transfer_count', 3);
            try {
                for ($i = 0; $i < 4; $i++) {
                    $ada('100');
                }
                $rapid = alerts($pdo, 'RAPID_TRANSFERS', 2);
                assert_same(1, count($rapid), 'one alert, not one per transfer');
                assert_same('medium', $rapid[0]['severity']);
            } finally {
                Settings::flush();
            }
        });
    },
    'fraud: double the rapid threshold -> CRITICAL and the wallet is auto-frozen' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            set_setting($pdo, 'fraud_rapid_transfer_count', 2);
            try {
                for ($i = 0; $i < 4; $i++) {
                    $ada('100');
                }
                assert_same('critical', alerts($pdo, 'RAPID_TRANSFERS', 2)[0]['severity']);
                assert_same('frozen', $pdo->query("SELECT status FROM wallets WHERE wallet_number = '3021456789'")->fetchColumn());
                assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'wallet.auto_frozen'")->fetchColumn());
                // ...and the frozen wallet really can't send any more.
                $code = '';
                try { $ada('100'); } catch (App\Core\HttpException $e) { $code = $e->errorCode(); }
                assert_same('WALLET_FROZEN', $code);
            } finally {
                Settings::flush();
            }
        });
    },
    'fraud: repeated failed transfers -> FAILED_TRANSFERS alert' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            for ($i = 0; $i < 3; $i++) {
                try { $ada('400000'); } catch (App\Core\HttpException) { /* insufficient funds, recorded as failed */ }
            }
            assert_same(1, count(alerts($pdo, 'FAILED_TRANSFERS', 2)));
        });
    },
    'fraud: limit breach -> LOW alert' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            try { $ada('600000'); } catch (App\Core\HttpException) { }
            $a = alerts($pdo, 'LIMIT_EXCEEDED', 2);
            assert_same(1, count($a));
            assert_same('low', $a[0]['severity']);
        });
    },
    'fraud: PIN lockout -> HIGH alert and a security notification' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            foreach (['1111', '2222', '3333'] as $wrong) {
                try { $ada('100', $wrong); } catch (App\Core\HttpException) { }
            }
            assert_same('high', alerts($pdo, 'PIN_FAILURES', 2)[0]['severity'] ?? null);
            assert_same('We noticed unusual activity', $pdo->query("SELECT title FROM notifications WHERE user_id = 2 ORDER BY id DESC LIMIT 1")->fetchColumn());
        });
    },
    'fraud: many failed logins from one IP -> CREDENTIAL_STUFFING (no user attached)' => function () {
        in_rollback(function (PDO $pdo) {
            $ip = '203.0.113.' . random_int(1, 254);
            $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, user_agent, successful) VALUES (?, ?, 'test', 0)");
            for ($i = 0; $i < 10; $i++) {
                $stmt->execute(["victim{$i}@example.com", $ip]);
            }
            FraudMonitor::loginFailed($ip);
            FraudMonitor::loginFailed($ip); // Second call deduplicated
            $s = $pdo->prepare("SELECT COUNT(*) FROM fraud_alerts WHERE rule_code = 'CREDENTIAL_STUFFING' AND user_id IS NULL AND reason LIKE ?");
            $s->execute(['%' . $ip . '%']);
            assert_same(1, (int) $s->fetchColumn());
        });
    },
    'fraud: every alert is written to the audit log' => function () use ($ada) {
        in_rollback(function (PDO $pdo) use ($ada) {
            reset_pin_lock($pdo, 2);
            try { $ada('600000'); } catch (App\Core\HttpException) { }
            $id = (int) $pdo->query("SELECT id FROM fraud_alerts ORDER BY id DESC LIMIT 1")->fetchColumn();
            $s = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'fraud.alert_raised' AND entity_id = ?");
            $s->execute([$id]);
            assert_same(1, (int) $s->fetchColumn());
        });
    },
];
