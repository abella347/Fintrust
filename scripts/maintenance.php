<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$pdo = Database::connection();
$jobs = [
    'expired rate-limit windows (older than 1 day)'
        => 'DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'expired idempotency keys'
        => 'DELETE FROM transaction_idempotency_keys WHERE expires_at < UTC_TIMESTAMP()',
    'used or expired password-reset tokens (older than 7 days)'
        => 'DELETE FROM password_reset_tokens WHERE (used_at IS NOT NULL OR expires_at < UTC_TIMESTAMP()) AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY',
    'used or expired email-verification tokens (older than 7 days)'
        => 'DELETE FROM email_verification_tokens WHERE (used_at IS NOT NULL OR expires_at < UTC_TIMESTAMP()) AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY',
    'read notifications older than 180 days'
        => 'DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 180 DAY',
];

foreach ($jobs as $label => $sql) {
    $n = $pdo->exec($sql);
    printf("%-62s %6d deleted\n", $label, $n);
}

// Simulated emails in storage/mail older than 30 days.
$removed = 0;
foreach (glob(base_path('storage/mail/*.txt')) ?: [] as $file) {
    if (filemtime($file) < time() - 30 * 86400 && @unlink($file)) {
        $removed++;
    }
}
printf("%-62s %6d deleted\n", 'simulated email files older than 30 days', $removed);
