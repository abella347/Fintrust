<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Helpers\Validate;
use App\Models\UserRepository;

/** Transaction PIN: a second secret, separate from the password, that authorises each transfer. */
final class PinService
{
    public function hasPin(int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM transaction_pins WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Throws unless $pin is correct. */
    public function verify(int $userId, string $pin): void
    {
        $max  = Settings::int('pin_max_attempts');
        $lock = Settings::int('pin_lockout_minutes');

        $outcome = Database::transaction(function () use ($userId, $pin, $max, $lock) {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT * FROM transaction_pins WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row) {
                return ['result' => 'no_pin'];
            }
            if ($row['locked_until'] !== null && strtotime($row['locked_until'] . ' UTC') > time()) {
                return ['result' => 'locked', 'minutes' => (int) ceil((strtotime($row['locked_until'] . ' UTC') - time()) / 60)];
            }
            if (password_verify($pin, $row['pin_hash'])) {
                if ((int) $row['failed_attempts'] > 0) {
                    $db->prepare('UPDATE transaction_pins SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?')->execute([$userId]);
                }
                return ['result' => 'ok'];
            }

            $attempts = (int) $row['failed_attempts'] + 1;
            if ($attempts >= $max) {
                $db->prepare('UPDATE transaction_pins SET failed_attempts = 0, locked_until = UTC_TIMESTAMP() + INTERVAL ? MINUTE WHERE user_id = ?')
                    ->execute([$lock, $userId]);
                Notifier::notify($userId, 'security', 'Transfers locked',
                    "Transfers are locked for {$lock} minutes after {$max} incorrect PIN attempts. If this wasn't you, change your password and PIN.");
                AuditLog::record($userId, 'system', 'pin.locked', 'user', $userId, null, ['attempts' => $max, 'minutes' => $lock]);
                return ['result' => 'locked', 'minutes' => $lock, 'just_locked' => true];
            }
            $db->prepare('UPDATE transaction_pins SET failed_attempts = ? WHERE user_id = ?')->execute([$attempts, $userId]);
            return ['result' => 'wrong', 'left' => $max - $attempts];
        });

        switch ($outcome['result']) {
            case 'ok':
                return;
            case 'no_pin':
                throw new HttpException(403, 'Set a transaction PIN in Settings before sending money.', [], 'PIN_NOT_SET');
            case 'locked':
                if (!empty($outcome['just_locked'])) {
                    FraudMonitor::pinLocked($userId, $max);
                }
                throw new HttpException(423, "Too many incorrect PINs. Transfers are locked for {$outcome['minutes']} minute(s).", [], 'PIN_LOCKED');
            default:
                $msg = "Incorrect PIN. {$outcome['left']} attempt" . ($outcome['left'] === 1 ? '' : 's') . ' left.';
                throw new HttpException(422, $msg, ['pin' => $msg], 'INVALID_PIN');
        }
    }

    /** Create or change the PIN. */
    public function set(int $userId, array $in): void
    {
        $user = (new UserRepository())->findById($userId);
        if (!password_verify(is_string($in['current_password'] ?? null) ? $in['current_password'] : '', $user['password_hash'])) {
            throw new HttpException(401, 'Your current password is incorrect.', ['current_password' => 'Incorrect password.'], 'INVALID_PASSWORD');
        }
        $v = new Validate();
        $pin = Validate::str($in['pin'] ?? null);
        PasswordPolicy::checkPin($v, $pin);
        if (($in['pin_confirmation'] ?? null) !== $pin) {
            $v->add('pin_confirmation', 'PINs do not match.');
        }
        $v->throwIfErrors();

        $hadPin = $this->hasPin($userId);
        Database::transaction(function () use ($userId, $pin, $hadPin) {
            // Upsert: insert the first PIN, or replace an existing one (and clear any lock).
            Database::connection()->prepare(
                'INSERT INTO transaction_pins (user_id, pin_hash) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), failed_attempts = 0, locked_until = NULL'
            )->execute([$userId, password_hash($pin, PASSWORD_DEFAULT)]);
            Notifier::notify($userId, 'security', $hadPin ? 'Transaction PIN changed' : 'Transaction PIN set',
                $hadPin ? 'Your transaction PIN was changed. If this wasn’t you, contact support immediately.' : 'Your transaction PIN is set. You can now send money.');
            AuditLog::record($userId, 'user', $hadPin ? 'pin.changed' : 'pin.created', 'user', $userId);
        });
    }
}
