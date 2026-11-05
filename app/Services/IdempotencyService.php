<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDOException;

/** Makes "send money" safe to retry. */
final class IdempotencyService
{
    /** A 'processing' claim older than this is from a crashed request and may be taken over. */
    private const STALE_SECONDS = 60;

    /**
     * @param array<string, mixed> $fingerprint request fields that define "the same payment" (never the PIN)
     * @return array{code:int, message:string, data:mixed}|null replayed response, or null if this request now owns the key
     */
    public function claim(int $userId, mixed $key, string $endpoint, array $fingerprint): ?array
    {
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) !== 1) {
            throw new HttpException(400, 'Missing or invalid Idempotency-Key header.', [], 'IDEMPOTENCY_KEY_REQUIRED');
        }
        ksort($fingerprint);
        $hash = hash('sha256', $endpoint . '|' . json_encode($fingerprint));
        $db = Database::connection();

        for ($try = 0; $try < 2; $try++) {
            try {
                $db->prepare(
                    'INSERT INTO transaction_idempotency_keys (user_id, idempotency_key, endpoint, request_hash, expires_at)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR)'
                )->execute([$userId, $key, $endpoint, $hash, Settings::int('idempotency_ttl_hours')]);
                return null; // Claimed: this request does the work
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }

            // The key already exists. Look at what happened to it.
            $stmt = $db->prepare(
                'SELECT *, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age, expires_at < UTC_TIMESTAMP() AS expired
                 FROM transaction_idempotency_keys WHERE user_id = ? AND idempotency_key = ?'
            );
            $stmt->execute([$userId, $key]);
            $row = $stmt->fetch();
            if (!$row) {
                continue; // Released in between: try to claim again
            }
            if ((int) $row['expired'] === 1) {
                $this->release($userId, $key);
                continue;
            }
            if ($row['request_hash'] !== $hash || $row['endpoint'] !== $endpoint) {
                throw new HttpException(422, 'This request key was already used for a different payment. Start a new payment.', [], 'IDEMPOTENCY_KEY_REUSED');
            }
            if ($row['status'] === 'completed') {
                $body = json_decode((string) $row['response_body'], true);
                return ['code' => (int) $row['response_code'], 'message' => $body['message'] ?? 'OK', 'data' => $body['data'] ?? null];
            }
            if ((int) $row['age'] < self::STALE_SECONDS) {
                throw new HttpException(409, 'This payment is already being processed. Please wait a moment.', [], 'IDEMPOTENCY_IN_PROGRESS');
            }
            // A crashed request left a stale claim; its money transaction never committed
            // (otherwise the key would be 'completed'), so it is safe to take over.
            $take = $db->prepare("UPDATE transaction_idempotency_keys SET created_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'processing'");
            $take->execute([$row['id']]);
            if ($take->rowCount() === 1) {
                return null;
            }
        }
        throw new HttpException(409, 'This payment is already being processed. Please wait a moment.', [], 'IDEMPOTENCY_IN_PROGRESS');
    }

    /** Call inside the money transaction, right before it commits. */
    public function complete(int $userId, string $key, int $transactionId, int $code, string $message, mixed $data): void
    {
        Database::connection()->prepare(
            "UPDATE transaction_idempotency_keys
             SET status = 'completed', transaction_id = ?, response_code = ?, response_body = ?
             WHERE user_id = ? AND idempotency_key = ?"
        )->execute([$transactionId, $code, json_encode(['message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE), $userId, $key]);
    }

    /** Nothing moved: free the key so the same attempt can be retried. */
    public function release(int $userId, string $key): void
    {
        Database::connection()->prepare(
            "DELETE FROM transaction_idempotency_keys WHERE user_id = ? AND idempotency_key = ? AND status <> 'completed'"
        )->execute([$userId, $key]);
    }
}
