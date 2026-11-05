<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/** Environment and database sanity checks used by /setup-check and /api/health. */
final class SetupCheck
{
    public const REQUIRED_TABLES = [
        'users', 'roles', 'user_roles', 'transaction_pins', 'login_attempts', 'user_devices',
        'password_reset_tokens', 'email_verification_tokens', 'rate_limits', 'wallets',
        'transactions', 'ledger_entries', 'transaction_idempotency_keys', 'notifications',
        'fraud_alerts', 'audit_logs', 'system_settings', 'email_outbox',
    ];

    /**
     * @return list<array{name:string, ok:bool, detail:string, level:string}>
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->check('PHP 8.2 or newer', PHP_VERSION_ID >= 80200, 'Running PHP ' . PHP_VERSION);

        foreach (['pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
            $checks[] = $this->check("PHP extension: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'Loaded' : 'Enable it in php.ini');
        }

        $checks[] = $this->check('.env file present', is_file(base_path('.env')), 'Copy .env.example to .env', 'warning');
        $openedAt = app_url_mismatch();
        $checks[] = $this->check('APP_URL matches this address', $openedAt === null,
            $openedAt === null ? 'APP_URL = ' . config('app.url')
                : 'You opened ' . $openedAt . ' but APP_URL is ' . config('app.url') . '. Set APP_URL=' . $openedAt . ' in .env');
        $checks[] = $this->check('storage/ is writable', is_writable(base_path('storage/logs')), 'Logs and simulated emails are written here');
        $avatars = base_path('storage/avatars');
        $checks[] = $this->check('storage/avatars is writable', is_dir($avatars) ? is_writable($avatars) : is_writable(base_path('storage')),
            'Profile pictures are saved here', 'warning');

        try {
            $pdo = Database::connection();
            $checks[] = $this->check('Database connection', true, 'Connected to ' . config('database.database'));
        } catch (Throwable $e) {
            $checks[] = $this->check('Database connection', false, self::explainConnectionError($e));
            return $checks;
        }

        $checks[] = $this->check(
            'Not connecting as root',
            config('database.username') !== 'root',
            'Use the least-privilege fintrust_app account (database/create_user.sql)',
            'warning'
        );

        $existing = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $missing = array_diff(self::REQUIRED_TABLES, $existing);
        $checks[] = $this->check(
            'All ' . count(self::REQUIRED_TABLES) . ' tables exist',
            $missing === [],
            $missing === [] ? 'Schema imported' : 'Missing: ' . implode(', ', $missing) . ' — import database/schema.sql'
        );
        if ($missing !== []) {
            return $checks;
        }

        $triggers = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()"
        )->fetchColumn();
        if ($triggers === 0 && config('database.username') !== 'root') {
            // information_schema only lists triggers to accounts holding the TRIGGER
            // privilege. The least-privilege app account deliberately doesn't.
            $checks[] = $this->check(
                'Immutability triggers installed',
                false,
                'Not visible to the app account (no TRIGGER privilege, by design). As root run: SHOW TRIGGERS FROM fintrust; — expect 5',
                'warning'
            );
        } else {
            $checks[] = $this->check('Immutability triggers installed', $triggers >= 5, "{$triggers} of 5 triggers found");
        }

        $unbalanced = (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn();
        $checks[] = $this->check('Ledger balanced (debits = credits)', $unbalanced === 0, "{$unbalanced} unbalanced transaction(s)");

        $orphans = (int) $pdo->query('SELECT COUNT(*) FROM v_successful_without_ledger')->fetchColumn();
        $checks[] = $this->check('Every successful transaction has ledger entries', $orphans === 0, "{$orphans} without entries");

        $drift = (int) $pdo->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn();
        $checks[] = $this->check('Wallet balances match the ledger', $drift === 0, "{$drift} wallet(s) out of balance");

        $total = (int) $pdo->query('SELECT total_of_all_wallets_kobo FROM v_system_balance')->fetchColumn();
        $checks[] = $this->check('System-wide total is zero', $total === 0, "Sum of all wallets: {$total} kobo");

        return $checks;
    }

    public static function explainConnectionError(Throwable $e): string
    {
        $pdo  = $e instanceof \PDOException ? $e : $e->getPrevious();
        $code = $pdo instanceof \PDOException ? (int) ($pdo->errorInfo[1] ?? $pdo->getCode()) : 0;
        $msg  = $pdo instanceof \PDOException ? $pdo->getMessage() : $e->getMessage();
        if ($code === 0 && preg_match('/\[(\d{4})\]/', $msg, $m)) {
            $code = (int) $m[1];
        }
        $user = (string) config('database.username');
        $db   = (string) config('database.database');
        return match ($code) {
            1045 => "MySQL rejected the user '{$user}' with the password in .env (error 1045). DB_PASSWORD in .env must be exactly "
                  . "the password in database/create_user.sql: fix whichever is wrong, then import create_user.sql again in phpMyAdmin.",
            1044, 1142 => "The user '{$user}' has no permission on a database called '{$db}' (error {$code}). Check DB_DATABASE in .env is spelled "
                  . 'exactly like the database in phpMyAdmin (fintrust); if it is, import database/create_user.sql again.',
            1049 => "The database '{$db}' doesn't exist (error 1049). Create it in phpMyAdmin (Databases tab), click it, then import "
                  . 'schema.sql, seed.sql and create_user.sql.',
            2002, 2003, 2006 => "Can't reach MySQL (error {$code}). Start MySQL in the XAMPP Control Panel, and check DB_HOST/DB_PORT in .env "
                  . '(XAMPP default: 127.0.0.1 and 3306).',
            default => 'Database connection failed: ' . $msg,
        };
    }

    private function check(string $name, bool $ok, string $detail, string $level = 'error'): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'level' => $level];
    }
}
