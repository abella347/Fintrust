<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Simulated email delivery for local development. */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, ?int $userId = null): void
    {
        Database::connection()->prepare(
            'INSERT INTO email_outbox (user_id, to_email, subject, body_text) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $to, $subject, $body]);

        if (config('app.mail.driver') === 'log') {
            $file = sprintf('%s/%s-%s.txt', base_path('storage/mail'), gmdate('Ymd-His'), bin2hex(random_bytes(3)));
            $content = "From: " . config('app.mail.from') . "\nTo: {$to}\nSubject: {$subject}\nDate: " . gmdate('r') . "\n\n{$body}\n";
            @file_put_contents($file, $content, LOCK_EX);
        }
    }
}
