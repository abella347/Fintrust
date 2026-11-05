<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Reads the system_settings table (limits, lockout rules, thresholds). */
final class Settings
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $cache = null;

    private const DEFAULTS = [
        'transfer_min_kobo'          => 10_000,
        'transfer_max_single_kobo'   => 50_000_000,
        'transfer_daily_limit_kobo'  => 200_000_000,
        'deposit_max_single_kobo'    => 100_000_000,
        'wallet_max_balance_kobo'    => 1_000_000_000,
        'fraud_large_transfer_kobo'  => 20_000_000,
        'fraud_rapid_transfer_count' => 5,
        'fraud_rapid_window_minutes' => 10,
        'fraud_failed_transfer_count' => 3,
        'fraud_failed_window_minutes' => 30,
        'fraud_ip_login_failures'    => 10,
        'login_max_attempts'         => 5,
        'login_lockout_minutes'      => 15,
        'pin_max_attempts'           => 3,
        'pin_lockout_minutes'        => 30,
        'idempotency_ttl_hours'      => 24,
    ];

    public static function int(string $key): int
    {
        if (self::$cache === null) {
            $rows = Database::connection()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
            self::$cache = $rows ?: [];
        }
        if (isset(self::$cache[$key]) && is_numeric(self::$cache[$key])) {
            return (int) self::$cache[$key];
        }
        return self::DEFAULTS[$key] ?? throw new \InvalidArgumentException("Unknown setting {$key}");
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
