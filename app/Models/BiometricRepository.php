<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/** Registered Face ID / fingerprint devices (table webauthn_credentials). */
final class BiometricRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @return list<array> newest first, without the key material
     */
    public function forUser(int $userId): array
    {
        $s = $this->db->prepare('SELECT id, credential_id, label, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY id DESC');
        $s->execute([$userId]);
        return $s->fetchAll();
    }

    public function count(int $userId): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?');
        $s->execute([$userId]);
        return (int) $s->fetchColumn();
    }

    /** Only finds a credential that belongs to $userId. */
    public function find(int $userId, string $credentialId): ?array
    {
        $s = $this->db->prepare('SELECT * FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?');
        $s->execute([$userId, $credentialId]);
        return $s->fetch() ?: null;
    }

    public function insert(int $userId, array $c, string $label): int
    {
        $this->db->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id, public_key_pem, algorithm, sign_count, label) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $c['credential_id'], $c['public_key_pem'], $c['algorithm'], $c['sign_count'], $label]);
        return (int) $this->db->lastInsertId();
    }

    public function markUsed(int $id, int $signCount): void
    {
        $this->db->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$signCount, $id]);
    }

    public function delete(int $userId, int $id): bool
    {
        $s = $this->db->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?');
        $s->execute([$id, $userId]);
        return $s->rowCount() === 1;
    }
}
