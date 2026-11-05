<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Money;
use Throwable;

/** Rule-based fraud monitor (demonstration only, not a production fraud engine). */
final class FraudMonitor
{
    /* ===================== entry points ===================== */

    /** After a transfer attempt that reached a decision ('successful' | 'failed'). */
    public static function afterTransfer(int $userId, int $transactionId, string $status, int $amountKobo): void
    {
        self::guard(function () use ($userId, $transactionId, $status, $amountKobo) {
            if ($status === 'successful' && $amountKobo >= Settings::int('fraud_large_transfer_kobo')) {
                self::raise($userId, $transactionId, 'LARGE_TRANSFER', 'high',
                    'Large transfer of ' . Money::format($amountKobo), ['amount_kobo' => $amountKobo], null);
            }
            if ($status === 'failed') {
                self::checkFailedTransfers($userId, $transactionId);
            }
            self::checkRapidTransfers($userId, $transactionId);
        });
    }

    /** A transfer refused before any record was made because it broke a limit. */
    public static function limitExceeded(int $userId, int $amountKobo, string $message): void
    {
        self::guard(fn () => self::raise($userId, null, 'LIMIT_EXCEEDED', 'low',
            'Transfer attempt above a configured limit (' . Money::format($amountKobo) . ')',
            ['amount_kobo' => $amountKobo, 'detail' => $message], 60));
    }

    public static function pinLocked(int $userId, int $attempts): void
    {
        self::guard(fn () => self::raise($userId, null, 'PIN_FAILURES', 'high',
            "{$attempts} incorrect transaction PINs; transfers locked", ['attempts' => $attempts], 30));
    }

    public static function accountLocked(int $userId, int $attempts, string $ip): void
    {
        self::guard(fn () => self::raise($userId, null, 'LOGIN_FAILURES', 'medium',
            "{$attempts} failed logins; account temporarily locked", ['attempts' => $attempts, 'ip' => $ip], 30));
    }

    /** After any failed login: is one IP guessing passwords across many accounts? */
    public static function loginFailed(string $ip): void
    {
        self::guard(function () use ($ip) {
            $threshold = Settings::int('fraud_ip_login_failures');
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) AS failures, COUNT(DISTINCT email) AS accounts FROM login_attempts
                 WHERE ip_address = ? AND successful = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
            );
            $stmt->execute([$ip]);
            $r = $stmt->fetch();
            if ((int) $r['failures'] >= $threshold) {
                self::raise(null, null, 'CREDENTIAL_STUFFING', 'high',
                    "{$r['failures']} failed logins from {$ip} against {$r['accounts']} account(s) in 15 minutes",
                    ['ip' => $ip, 'failures' => (int) $r['failures'], 'accounts' => (int) $r['accounts']], 15);
            }
        });
    }

    /* ===================== rules ===================== */

    private static function checkRapidTransfers(int $userId, int $transactionId): void
    {
        $count  = Settings::int('fraud_rapid_transfer_count');
        $window = Settings::int('fraud_rapid_window_minutes');
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM transactions
             WHERE initiated_by_user_id = ? AND type = 'transfer' AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE"
        );
        $stmt->execute([$userId, $window]);
        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= $count * 2) {
            $raised = self::raise($userId, $transactionId, 'RAPID_TRANSFERS', 'critical',
                "{$attempts} transfer attempts in {$window} minutes — wallet frozen automatically",
                ['attempts' => $attempts, 'window_minutes' => $window], $window, true);
            if ($raised) {
                self::autoFreeze($userId, $raised);
            }
        } elseif ($attempts >= $count) {
            self::raise($userId, $transactionId, 'RAPID_TRANSFERS', 'medium',
                "{$attempts} transfer attempts in {$window} minutes", ['attempts' => $attempts, 'window_minutes' => $window], $window);
        }
    }

    private static function checkFailedTransfers(int $userId, int $transactionId): void
    {
        $count  = Settings::int('fraud_failed_transfer_count');
        $window = Settings::int('fraud_failed_window_minutes');
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM transactions
             WHERE initiated_by_user_id = ? AND type = 'transfer' AND status = 'failed' AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE"
        );
        $stmt->execute([$userId, $window]);
        $failed = (int) $stmt->fetchColumn();
        if ($failed >= $count) {
            self::raise($userId, $transactionId, 'FAILED_TRANSFERS', 'medium',
                "{$failed} failed transfers in {$window} minutes", ['failed' => $failed, 'window_minutes' => $window], $window);
        }
    }

    /* ===================== helpers ===================== */

    /**
     * Inserts an alert unless an equivalent one is already open.
     * @return int|null new alert id, or null if deduplicated
     */
    private static function raise(?int $userId, ?int $transactionId, string $rule, string $severity, string $reason,
                                  array $details, ?int $dedupeMinutes, bool $escalate = false): ?int
    {
        $db = Database::connection();
        if ($dedupeMinutes !== null) {
            $sql = "SELECT COUNT(*) FROM fraud_alerts
                    WHERE rule_code = ? AND status IN ('open', 'investigating')
                      AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE "
                 . ($userId !== null ? 'AND user_id = ?' : 'AND user_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(details, "$.ip")) = ?')
                 . ($escalate ? ' AND severity = ?' : '');
            $params = [$rule, $dedupeMinutes, $userId ?? ($details['ip'] ?? '')];
            if ($escalate) {
                $params[] = $severity;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() > 0) {
                return null;
            }
        }

        $db->prepare(
            'INSERT INTO fraud_alerts (user_id, transaction_id, rule_code, severity, reason, details) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $transactionId, $rule, $severity, mb_substr($reason, 0, 255), json_encode($details)]);
        $alertId = (int) $db->lastInsertId();

        AuditLog::record(null, 'system', 'fraud.alert_raised', 'fraud_alert', $alertId, null,
            ['rule' => $rule, 'severity' => $severity, 'user_id' => $userId, 'transaction_id' => $transactionId]);

        if ($userId !== null && in_array($severity, ['high', 'critical'], true)) {
            Notifier::notify($userId, 'security', 'We noticed unusual activity',
                'Our security checks flagged recent activity on your account for review. If this wasn’t you, change your password and PIN, and contact support.', $transactionId);
        }
        return $alertId;
    }

    /** Critical rule: freeze the wallet until an admin reviews it (an "account freeze control"). */
    private static function autoFreeze(int $userId, int $alertId): void
    {
        Database::transaction(function () use ($userId, $alertId) {
            $db = Database::connection();
            $stmt = $db->prepare("SELECT id, status FROM wallets WHERE user_id = ? AND type = 'user' FOR UPDATE");
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch();
            if (!$wallet || $wallet['status'] !== 'active') {
                return;
            }
            $db->prepare("UPDATE wallets SET status = 'frozen' WHERE id = ?")->execute([$wallet['id']]);
            AuditLog::record(null, 'system', 'wallet.auto_frozen', 'wallet', (int) $wallet['id'],
                ['status' => 'active'], ['status' => 'frozen', 'fraud_alert_id' => $alertId]);
            Notifier::notify($userId, 'security', 'Wallet frozen for your protection',
                'We froze your wallet after an unusual burst of transfer attempts. An administrator will review it. Contact support@fintrust.test.');
        });
    }

    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            app_log('error', 'Fraud monitor failed: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
        }
    }
}
