<?php

declare(strict_types=1);

use App\Services\AdminService;

const BEN_PUBLIC = '7c971939-c2a9-4f45-8a89-5dbfc7ba4dd0';
const ADMIN_PUBLIC = 'ec45d02b-48f8-42d9-9e3a-f128cb1206bf';

function last_audit(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1')->fetch();
}

return [
    'suspending needs a written reason' => function () {
        in_rollback(fn () => assert_same('VALIDATION_FAILED', error_code(fn () => (new AdminService())->setUserStatus(1, BEN_PUBLIC, 'suspended', 'no'))));
    },
    'suspend: status changes, audit has old/new + reason, customer is notified' => function () {
        in_rollback(function (PDO $pdo) {
            (new AdminService())->setUserStatus(1, BEN_PUBLIC, 'suspended', 'Chargeback investigation');
            assert_same('suspended', $pdo->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
            $a = last_audit($pdo);
            assert_same(['user.suspended', 'admin', 1], [$a['action'], $a['actor_type'], (int) $a['actor_user_id']]);
            assert_same(['status' => 'suspended', 'reason' => 'Chargeback investigation'], json_decode($a['new_values'], true));
            assert_same('Account suspended', $pdo->query('SELECT title FROM notifications WHERE user_id = 3 ORDER BY id DESC LIMIT 1')->fetchColumn());
        });
    },
    'reactivate also clears a login lockout' => function () {
        in_rollback(function (PDO $pdo) {
            $pdo->exec("UPDATE users SET status = 'suspended', failed_login_count = 3, locked_until = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE id = 3");
            (new AdminService())->setUserStatus(1, BEN_PUBLIC, 'active', 'Review complete');
            assert_same(['status' => 'active', 'failed_login_count' => 0, 'locked_until' => null],
                $pdo->query('SELECT status, failed_login_count, locked_until FROM users WHERE id = 3')->fetch());
        });
    },
    'staff accounts (including your own) cannot be suspended' => function () {
        in_rollback(function () {
            assert_same('FORBIDDEN', error_code(fn () => (new AdminService())->setUserStatus(1, ADMIN_PUBLIC, 'suspended', 'Testing self')));
            assert_same('FORBIDDEN', error_code(fn () => (new AdminService())->setUserStatus(1, '64794748-7fba-4c60-ae69-b5a849f9ae52', 'suspended', 'Testing staff')));
        });
    },
    'suspending twice is reported, not repeated' => function () {
        in_rollback(function () {
            (new AdminService())->setUserStatus(1, BEN_PUBLIC, 'suspended', 'First time');
            assert_same('NO_CHANGE', error_code(fn () => (new AdminService())->setUserStatus(1, BEN_PUBLIC, 'suspended', 'Second time')));
        });
    },
    'freeze wallet: transfers to and from it stop; unfreeze restores them' => function () {
        in_rollback(function (PDO $pdo) {
            $unfreezes = fn () => (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'wallet.unfreeze' AND actor_user_id = 1")->fetchColumn();
            $before = $unfreezes();
            reset_pin_lock($pdo, 2);
            (new AdminService())->setWalletStatus(1, '3098765432', 'frozen', 'Investigating alert');
            $send = fn () => (new App\Services\TransferService())->transfer(2,
                ['recipient_wallet_number' => '3098765432', 'amount' => '100', 'pin' => '4826'], idem_key());
            assert_same('WALLET_UNAVAILABLE', error_code($send));
            (new AdminService())->setWalletStatus(1, '3098765432', 'active', 'Cleared');
            assert_same(201, $send()['code']);
            assert_same($before + 1, $unfreezes(), 'one audit entry for the unfreeze');
        });
    },
    'fraud review: closing needs a note; closed alerts are final' => function () {
        in_rollback(function (PDO $pdo) {
            $svc = new AdminService();
            assert_same('VALIDATION_FAILED', error_code(fn () => $svc->reviewAlert(5, 1, 'resolved', '')));
            $svc->reviewAlert(5, 1, 'resolved', 'Verified with the customer');
            $row = $pdo->query('SELECT status, reviewed_by, resolution_note FROM fraud_alerts WHERE id = 1')->fetch();
            assert_same(['status' => 'resolved', 'reviewed_by' => 5, 'resolution_note' => 'Verified with the customer'], $row);
            assert_same('ALERT_CLOSED', error_code(fn () => $svc->reviewAlert(5, 1, 'investigating', '')));
        });
    },
];
