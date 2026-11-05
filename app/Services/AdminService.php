<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Helpers\Validate;
use App\Models\UserRepository;

/** Administrator actions. */
final class AdminService
{
    /* ---------------- accounts ---------------- */

    public function setUserStatus(int $adminId, string $publicId, string $newStatus, mixed $reason): array
    {
        $reason = $this->reason($reason);
        $target = $this->customerOrFail($publicId);

        if ((int) $target['id'] === $adminId) {
            throw new HttpException(422, 'You can’t change the status of your own account.', [], 'SELF_ACTION');
        }
        if ($target['status'] === $newStatus) {
            throw new HttpException(409, 'This account is already ' . $newStatus . '.', [], 'NO_CHANGE');
        }

        Database::transaction(function () use ($adminId, $target, $newStatus, $reason) {
            $db = Database::connection();
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$target['id']]);
            if ($newStatus === 'active') {
                // Reactivating also clears any login lockout.
                $db->prepare("UPDATE users SET status = 'active', failed_login_count = 0, locked_until = NULL WHERE id = ?")->execute([$target['id']]);
            } else {
                // A suspended user's sessions stop working on their next request (Auth::user checks status).
                $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $target['id']]);
            }
            AuditLog::record($adminId, 'admin', $newStatus === 'active' ? 'user.reactivated' : 'user.suspended', 'user', (int) $target['id'],
                ['status' => $target['status']], ['status' => $newStatus, 'reason' => $reason]);
            Notifier::notify((int) $target['id'], 'security',
                $newStatus === 'active' ? 'Account reactivated' : 'Account suspended',
                $newStatus === 'active' ? 'Your account has been reactivated. You can log in again.'
                    : 'Your account has been suspended pending a review. Contact support@fintrust.test.');
        });

        Mailer::send($target['email'], $newStatus === 'active' ? 'Your FinTrust account is active again' : 'Your FinTrust account has been suspended',
            "Hi {$target['first_name']},\n\n" . ($newStatus === 'active'
                ? 'Your account has been reactivated and you can log in again.'
                : "Your account has been suspended while we review recent activity. Contact support@fintrust.test if you have questions.") .
            "\n\n— FinTrust", (int) $target['id']);

        \App\Helpers\Cache::forget('admin-statistics');
        return ['public_id' => $publicId, 'status' => $newStatus];
    }

    /* ---------------- wallets ---------------- */

    public function setWalletStatus(int $adminId, string $walletNumber, string $newStatus, mixed $reason): array
    {
        $reason = $this->reason($reason);
        if (preg_match('/^\d{10}$/', $walletNumber) !== 1) {
            throw new HttpException(404, 'Wallet not found.', [], 'NOT_FOUND');
        }

        return Database::transaction(function () use ($adminId, $walletNumber, $newStatus, $reason) {
            $db = Database::connection();
            // Locking the wallet row means the freeze waits for any transfer in progress
            // on this wallet to finish, and no new transfer can start until we commit.
            $stmt = $db->prepare("SELECT * FROM wallets WHERE wallet_number = ? AND type = 'user' FOR UPDATE");
            $stmt->execute([$walletNumber]);
            $wallet = $stmt->fetch();
            if (!$wallet) {
                throw new HttpException(404, 'Wallet not found.', [], 'NOT_FOUND');
            }
            if ($wallet['status'] === $newStatus) {
                throw new HttpException(409, 'This wallet is already ' . $newStatus . '.', [], 'NO_CHANGE');
            }
            $db->prepare('UPDATE wallets SET status = ? WHERE id = ?')->execute([$newStatus, $wallet['id']]);
            AuditLog::record($adminId, 'admin', $newStatus === 'frozen' ? 'wallet.freeze' : 'wallet.unfreeze', 'wallet', (int) $wallet['id'],
                ['status' => $wallet['status']], ['status' => $newStatus, 'reason' => $reason]);
            Notifier::notify((int) $wallet['user_id'], 'security',
                $newStatus === 'frozen' ? 'Wallet frozen' : 'Wallet unfrozen',
                $newStatus === 'frozen'
                    ? 'Your wallet has been frozen pending a security review. Transfers and deposits are paused. Contact support@fintrust.test.'
                    : 'Your wallet has been unfrozen. You can send and receive money again.');
            \App\Helpers\Cache::forget('admin-statistics');
            return ['wallet_number' => $walletNumber, 'status' => $newStatus];
        });
    }

    /* ---------------- fraud alerts ---------------- */

    public function reviewAlert(int $reviewerId, int $alertId, mixed $status, mixed $note): array
    {
        $v = new Validate();
        $status = $v->oneOf($status, 'status', ['investigating', 'resolved', 'dismissed']);
        $note = $v->text($note, 'note', 500, 'Note');
        if ($status === null) {
            $v->add('status', 'Choose investigating, resolved or dismissed.');
        }
        if (in_array($status, ['resolved', 'dismissed'], true) && mb_strlen($note) < 5) {
            $v->add('note', 'Explain the outcome (at least 5 characters).');
        }
        $v->throwIfErrors();

        return Database::transaction(function () use ($reviewerId, $alertId, $status, $note) {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT * FROM fraud_alerts WHERE id = ? FOR UPDATE');
            $stmt->execute([$alertId]);
            $alert = $stmt->fetch();
            if (!$alert) {
                throw new HttpException(404, 'Alert not found.', [], 'NOT_FOUND');
            }
            if (in_array($alert['status'], ['resolved', 'dismissed'], true)) {
                throw new HttpException(409, 'This alert is already closed.', [], 'ALERT_CLOSED');
            }
            $db->prepare('UPDATE fraud_alerts SET status = ?, resolution_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$status, $note !== '' ? $note : $alert['resolution_note'], $reviewerId, $alertId]);
            AuditLog::record($reviewerId, 'admin', 'fraud.alert_' . $status, 'fraud_alert', $alertId,
                ['status' => $alert['status']], ['status' => $status, 'note' => $note]);
            \App\Helpers\Cache::forget('admin-statistics');
            return ['id' => $alertId, 'status' => $status];
        });
    }

    /* ---------------- helpers ---------------- */

    private function reason(mixed $reason): string
    {
        $v = new Validate();
        $text = $v->text($reason, 'reason', 255, 'Reason');
        if (mb_strlen($text) < 5) {
            $v->add('reason', 'Give a reason (at least 5 characters). It is saved in the audit log.');
        }
        $v->throwIfErrors();
        return $text;
    }

    /** Admins can only act on customer accounts, never on other staff. */
    private function customerOrFail(string $publicId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE public_id = ?');
        $stmt->execute([$publicId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new HttpException(404, 'User not found.', [], 'NOT_FOUND');
        }
        $roles = (new UserRepository())->roles((int) $user['id']);
        if (Auth::rolesCan($roles, 'admin.access')) {
            throw new HttpException(403, 'Staff accounts can’t be changed from here.', [], 'FORBIDDEN');
        }
        return $user;
    }
}
