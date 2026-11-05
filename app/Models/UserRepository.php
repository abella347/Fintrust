<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Str;
use PDO;

/** All SQL for the users table (and their roles). */
final class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Row lock: used while changing login counters so two failed logins can't both read "4". */
    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function phoneTaken(string $phone, ?int $exceptUserId = null): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE phone = ? AND id <> ? LIMIT 1');
        $stmt->execute([$phone, $exceptUserId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(string $first, string $last, string $email, string $phone, string $passwordHash): int
    {
        $this->db->prepare(
            'INSERT INTO users (public_id, first_name, last_name, email, phone, password_hash, password_changed_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([Str::uuid4(), $first, $last, $email, $phone, $passwordHash]);
        return (int) $this->db->lastInsertId();
    }

    public function assignRole(int $userId, string $role): void
    {
        $this->db->prepare(
            'INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?'
        )->execute([$userId, $role]);
    }

    /**
     * @return list<string>
     */
    public function roles(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function recordLoginSuccess(int $userId, ?string $newHash): void
    {
        $sql = 'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP()'
             . ($newHash !== null ? ', password_hash = ?' : '') . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($newHash !== null ? [$newHash, $userId] : [$userId]);
    }

    public function setFailedLogins(int $userId, int $count, ?int $lockMinutes): void
    {
        $this->db->prepare(
            'UPDATE users SET failed_login_count = ?, locked_until = IF(? IS NULL, locked_until, UTC_TIMESTAMP() + INTERVAL ? MINUTE) WHERE id = ?'
        )->execute([$count, $lockMinutes, $lockMinutes ?? 0, $userId]);
    }

    public function updatePassword(int $userId, string $hash): void
    {
        $this->db->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), failed_login_count = 0, locked_until = NULL WHERE id = ?'
        )->execute([$hash, $userId]);
    }

    public function updateProfile(int $userId, string $first, string $last, string $phone): void
    {
        $this->db->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?')
            ->execute([$first, $last, $phone, $userId]);
    }

    public function markEmailVerified(int $userId): void
    {
        $this->db->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')
            ->execute([$userId]);
    }

    public function recordLoginAttempt(?int $userId, string $email, string $ip, string $agent, bool $ok, ?string $reason): void
    {
        $this->db->prepare(
            'INSERT INTO login_attempts (user_id, email, ip_address, user_agent, successful, failure_reason) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, mb_substr($email, 0, 191), $ip, $agent, $ok ? 1 : 0, $reason]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentLogins(int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT successful, ip_address, user_agent, attempted_at FROM login_attempts
             WHERE user_id = ? ORDER BY attempted_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Known device? */
    public function rememberDevice(int $userId, string $deviceHash, string $agent, string $ip): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM user_devices WHERE user_id = ? AND device_hash = ?');
        $stmt->execute([$userId, $deviceHash]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $this->db->prepare('UPDATE user_devices SET last_seen_at = UTC_TIMESTAMP(), last_ip = ?, user_agent = ? WHERE id = ?')
                ->execute([$ip, $agent, $id]);
            return false;
        }
        $this->db->prepare('INSERT INTO user_devices (user_id, device_hash, user_agent, last_ip) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $deviceHash, $agent, $ip]);
        return true;
    }

    public function deviceCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM user_devices WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
