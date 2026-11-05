<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Fixed-window rate limiter stored in MySQL (table rate_limits). */
final class RateLimiter
{
    /** Records one hit; throws 429 Too Many Requests once over $max. */
    public static function hit(string $bucket, int $max, int $windowSeconds, string $message = 'Too many attempts. Please wait a moment and try again.'): void
    {
        $count = self::increment($bucket, $windowSeconds);
        if ($count > $max) {
            $retry = self::secondsLeft($bucket, $windowSeconds);
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                header('Retry-After: ' . $retry);
            }
            throw new HttpException(429, $message, [], 'TOO_MANY_REQUESTS');
        }
    }

    /** True if the bucket is already over $max (without adding a hit). */
    public static function tooMany(string $bucket, int $max, int $windowSeconds): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT hits FROM rate_limits WHERE bucket_key = ? AND window_start > UTC_TIMESTAMP() - INTERVAL ? SECOND'
        );
        $stmt->execute([$bucket, $windowSeconds]);
        return (int) $stmt->fetchColumn() >= $max;
    }

    private static function increment(string $bucket, int $windowSeconds): int
    {
        $pdo = Database::connection();

        // Occasional self-cleaning (about 1 request in 200) so the table stays small
        // even if scripts/maintenance.php is never scheduled.
        if (random_int(1, 200) === 1) {
            $pdo->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        }
        // If the stored window has expired, restart it at 1; otherwise add 1.
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, hits, window_start) VALUES (?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start <= UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
                window_start = IF(window_start <= UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_start)'
        )->execute([$bucket, $windowSeconds, $windowSeconds]);

        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket_key = ?');
        $stmt->execute([$bucket]);
        return (int) $stmt->fetchColumn();
    }

    private static function secondsLeft(string $bucket, int $windowSeconds): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT GREATEST(1, ? - TIMESTAMPDIFF(SECOND, window_start, UTC_TIMESTAMP())) FROM rate_limits WHERE bucket_key = ?'
        );
        $stmt->execute([$windowSeconds, $bucket]);
        return (int) $stmt->fetchColumn();
    }
}
