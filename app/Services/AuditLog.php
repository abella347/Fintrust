<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Append-only record of who did what (audit_logs table). */
final class AuditLog
{
    public static function record(
        ?int $actorId,
        string $actorType,          // 'user' | 'admin' | 'system'
        string $action,             // E.g. 'transfer.completed'
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null
    ): void {
        Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, actor_type, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $actorType,
            $action,
            $entityType,
            $entityId,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    }
}
