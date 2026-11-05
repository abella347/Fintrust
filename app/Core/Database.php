<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

/** One shared PDO connection per request, plus a safe transaction helper. */
final class Database
{
    private static ?PDO $pdo = null;

    /** MySQL/MariaDB error codes that mean "nothing was saved, just try again". */
    private const RETRYABLE_ERRORS = [
        1213,
        1205, // ER_LOCK_WAIT_TIMEOUT: waited too long for a row lock.
    ];

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $config = require base_path('config/database.php');

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            } catch (PDOException $e) {
                // Log the real reason, but never show credentials or hostnames to users.
                app_log('error', 'Database connection failed: ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed.', 0, $e);
            }

            foreach ($config['session_sql'] as $sql) {
                $pdo->exec($sql);
            }

            self::$pdo = $pdo;
        }

        return self::$pdo;
    }

    /**
     * Run $work inside a database transaction.
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public static function transaction(callable $work, int $maxAttempts = 3, ?string $isolation = null): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            // Nested call: just join the outer transaction.
            return $work($pdo);
        }

        $allowed = ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];
        if ($isolation !== null && !in_array($isolation, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported isolation level.');
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                if ($isolation !== null) {
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolation);
                }
                $pdo->beginTransaction();
                $result = $work($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $code = ($e instanceof PDOException) ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, self::RETRYABLE_ERRORS, true) && $attempt < $maxAttempts) {
                    usleep(random_int(20_000, 120_000) * $attempt); // Back off 20–120 ms × attempt
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Used by tests to start from a clean connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
