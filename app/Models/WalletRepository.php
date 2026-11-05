<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Str;
use PDO;
use PDOException;

final class WalletRepository
{
    /** Wallet number of the system wallet that simulated deposits come from (see seed.sql). */
    public const FUNDING_WALLET_NUMBER = '9000000001';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function forUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE user_id = ? AND type = 'user'");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** Wallet + owner's name, for recipient lookups. */
    public function findByNumberWithOwner(string $number): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT w.*, u.first_name, u.last_name, u.status AS user_status
             FROM wallets w JOIN users u ON u.id = w.user_id
             WHERE w.wallet_number = ? AND w.type = 'user'"
        );
        $stmt->execute([$number]);
        return $stmt->fetch() ?: null;
    }

    public function fundingWallet(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE wallet_number = ? AND type = 'system'");
        $stmt->execute([self::FUNDING_WALLET_NUMBER]);
        return $stmt->fetch() ?: throw new \RuntimeException('System funding wallet is missing. Re-run database/seed.sql.');
    }

    /**
     * Locks the given wallet rows (lowest id first) until the transaction commits or rolls back.
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by wallet id
     */
    public function lockForUpdate(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM wallets WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }
        return $rows;
    }

    /** Relative updates ("balance = balance - x"), never "balance = <value from PHP>". */
    public function debit(int $walletId, int $kobo): void
    {
        // The extra "and balance_kobo >= ?" (for customer wallets) is a last line of defence:
        // even if a bug skipped the PHP balance check, this UPDATE would change 0 rows.
        $stmt = $this->db->prepare(
            "UPDATE wallets SET balance_kobo = balance_kobo - ?
             WHERE id = ? AND (type = 'system' OR balance_kobo >= ?)"
        );
        $stmt->execute([$kobo, $walletId, $kobo]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Debit of wallet {$walletId} affected no rows.");
        }
    }

    public function credit(int $walletId, int $kobo): void
    {
        $stmt = $this->db->prepare('UPDATE wallets SET balance_kobo = balance_kobo + ? WHERE id = ?');
        $stmt->execute([$kobo, $walletId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Credit of wallet {$walletId} affected no rows.");
        }
    }

    /** Creates the customer's wallet with a unique random number (retries on the rare collision). */
    public function createForUser(int $userId): array
    {
        for ($try = 0; $try < 5; $try++) {
            $number = Str::walletNumber();
            try {
                $this->db->prepare("INSERT INTO wallets (wallet_number, user_id, type) VALUES (?, ?, 'user')")
                    ->execute([$number, $userId]);
                return ['id' => (int) $this->db->lastInsertId(), 'wallet_number' => $number];
            } catch (PDOException $e) {
                // 1062 = duplicate key. Only a wallet-number clash is worth retrying.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || !str_contains($e->getMessage(), 'uq_wallet_number')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Could not generate a unique wallet number.');
    }

    /** Total sent today (Lagos calendar day) in successful transfers. */
    public function sentToday(int $walletId): int
    {
        [$from, $to] = \App\Helpers\Dates::localDayBoundsUtc(new \DateTimeImmutable('now'));
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount_kobo), 0) FROM transactions
             WHERE sender_wallet_id = ? AND type = 'transfer' AND status = 'successful'
               AND created_at >= ? AND created_at < ?"
        );
        $stmt->execute([$walletId, $from, $to]);
        return (int) $stmt->fetchColumn();
    }
}
