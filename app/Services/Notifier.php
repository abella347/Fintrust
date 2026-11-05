<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** In-app notifications (the bell and the dashboard card). */
final class Notifier
{
    public static function notify(int $userId, string $type, string $title, string $message, ?int $transactionId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, transaction_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, mb_substr($message, 0, 500), $transactionId]);
    }
}
