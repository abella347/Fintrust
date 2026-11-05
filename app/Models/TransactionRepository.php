<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Str;
use PDO;
use PDOException;

/** SQL for transactions and ledger entries. */
final class TransactionRepository
{
    private PDO $db;

    /** Columns + joins shared by list() and findVisible(). */
    private const SELECT = "
        SELECT t.id, t.reference, t.type, t.status, t.amount_kobo, t.description, t.failure_reason,
               t.sender_wallet_id, t.recipient_wallet_id, t.created_at, t.completed_at,
               sw.wallet_number AS sender_wallet_number, sw.type AS sender_wallet_type,
               rw.wallet_number AS recipient_wallet_number,
               su.first_name AS sender_first_name, su.last_name AS sender_last_name,
               ru.first_name AS recipient_first_name, ru.last_name AS recipient_last_name,
               le.balance_after_kobo AS viewer_balance_after_kobo
        FROM transactions t
        LEFT JOIN wallets sw ON sw.id = t.sender_wallet_id
        LEFT JOIN wallets rw ON rw.id = t.recipient_wallet_id
        LEFT JOIN users su ON su.id = sw.user_id
        LEFT JOIN users ru ON ru.id = rw.user_id
        LEFT JOIN ledger_entries le ON le.transaction_id = t.id AND le.wallet_id = ?";

    private const VISIBLE = "(t.sender_wallet_id = ? OR (t.recipient_wallet_id = ? AND t.status IN ('successful', 'reversed')))";

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * Inserts a transaction row with a fresh unique reference.
     * @return array{id:int, reference:string}
     */
    public function create(string $type, string $status, int $amountKobo, ?int $senderWalletId, ?int $recipientWalletId,
                           ?int $initiatorId, ?string $description, ?string $failureReason = null, string $prefix = 'FT'): array
    {
        for ($try = 0; $try < 5; $try++) {
            $reference = Str::transactionReference($prefix);
            try {
                $this->db->prepare(
                    'INSERT INTO transactions (reference, type, status, amount_kobo, sender_wallet_id, recipient_wallet_id,
                                               initiated_by_user_id, description, failure_reason, ip_address, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
                )->execute([$reference, $type, $status, $amountKobo, $senderWalletId, $recipientWalletId,
                            $initiatorId, $description !== '' ? $description : null, $failureReason, $_SERVER['REMOTE_ADDR'] ?? null]);
                return ['id' => (int) $this->db->lastInsertId(), 'reference' => $reference];
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e; // Only a reference clash (practically impossible) is retried
                }
            }
        }
        throw new \RuntimeException('Could not generate a unique transaction reference.');
    }

    public function addLedgerEntry(int $transactionId, int $walletId, string $entryType, int $amountKobo, int $balanceAfter): void
    {
        $this->db->prepare(
            'INSERT INTO ledger_entries (transaction_id, wallet_id, entry_type, amount_kobo, balance_after_kobo) VALUES (?, ?, ?, ?, ?)'
        )->execute([$transactionId, $walletId, $entryType, $amountKobo, $balanceAfter]);
    }

    public function findVisible(string $reference, int $walletId): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE t.reference = ? AND ' . self::VISIBLE . ' LIMIT 1');
        $stmt->execute([$walletId, $reference, $walletId, $walletId]);
        return $stmt->fetch() ?: null;
    }

    public function findVisibleById(int $id, int $walletId): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE t.id = ? AND ' . self::VISIBLE . ' LIMIT 1');
        $stmt->execute([$walletId, $id, $walletId, $walletId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Filtered, paginated history for one wallet.
     * @param array{search?:?string,type?:?string,status?:?string,direction?:?string,from?:?string,to?:?string} $f
     * @return array{items: list<array>, total: int}
     */
    public function list(int $walletId, array $f, int $limit, int $offset): array
    {
        $branches = $this->historyBranches($walletId, $f);

        // Total = sum of the two (disjoint) halves; each COUNT reads only its index.
        $total = 0;
        foreach ($branches as [$where, $params]) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM transactions t WHERE {$where}");
            $stmt->execute($params);
            $total += (int) $stmt->fetchColumn();
        }
        if ($total === 0 || $offset >= $total) {
            return ['items' => [], 'total' => $total];
        }

        // Newest (offset + limit) ids from each half, then the requested page of the combined list.
        $parts = [];
        $params = [];
        foreach ($branches as [$where, $p]) {
            $parts[] = "(SELECT t.id, t.created_at FROM transactions t WHERE {$where} ORDER BY t.created_at DESC, t.id DESC LIMIT ?)";
            array_push($params, ...$p);
            $params[] = $offset + $limit;
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM (' . implode(' UNION ALL ', $parts) . ') page ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
        );
        $this->bind($stmt, array_merge($params, [$limit, $offset]));
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['items' => [], 'total' => $total];
        }

        // Only now join wallets/users/ledger, for just these few rows.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(self::SELECT . " WHERE t.id IN ({$in}) ORDER BY t.created_at DESC, t.id DESC");
        $stmt->execute(array_merge([$walletId], $ids));
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * @return list<array{0:string, 1:list<mixed>}>
     */
    private function historyBranches(int $walletId, array $f): array
    {
        $direction = $f['direction'] ?? null;
        $halves = [];
        if ($direction !== 'in') {
            $halves[] = ['t.sender_wallet_id = ?', 'recipient_wallet_id'];
        }
        if ($direction !== 'out') {
            $halves[] = ["t.recipient_wallet_id = ? AND t.status IN ('successful', 'reversed')", 'sender_wallet_id'];
        }

        $out = [];
        foreach ($halves as [$base, $counterpartyColumn]) {
            $where = [$base];
            $params = [$walletId];
            if (!empty($f['type']))   { $where[] = 't.type = ?';        $params[] = $f['type']; }
            if (!empty($f['status'])) { $where[] = 't.status = ?';      $params[] = $f['status']; }
            if (!empty($f['from']))   { $where[] = 't.created_at >= ?'; $params[] = Dates::localDateStartUtc($f['from']); }
            if (!empty($f['to']))     { $where[] = 't.created_at < ?';  $params[] = Dates::localDateEndUtc($f['to']); }
            if (!empty($f['search'])) {
                // Escape LIKE wildcards so searching "50%" means the text "50%".
                $term = addcslashes($f['search'], '%_\\');
                if (preg_match('/^FT[0-9A-Z]*$/i', $f['search']) === 1) {
                    // Looks like a reference: a prefix search can use the unique index.
                    $where[] = 't.reference LIKE ?';
                    $params[] = strtoupper($term) . '%';
                } else {
                    // A name: find the (few) wallets whose owner matches, then filter by wallet id.
                    $where[] = "t.{$counterpartyColumn} IN (SELECT w.id FROM wallets w JOIN users u ON u.id = w.user_id
                                WHERE CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)";
                    $params[] = '%' . $term . '%';
                }
            }
            $out[] = [implode(' AND ', $where), $params];
        }
        return $out;
    }

    /** Money in / out / count of successful transactions since $sinceUtc. */
    public function totalsSince(int $walletId, string $sinceUtc): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN dir = 'in' THEN amount_kobo END), 0) AS received,
                    COALESCE(SUM(CASE WHEN dir = 'out' THEN amount_kobo END), 0) AS sent,
                    COUNT(*) AS cnt
             FROM (" . self::SIDES_SQL . ") moves"
        );
        $stmt->execute([$walletId, $sinceUtc, $walletId, $sinceUtc]);
        $r = $stmt->fetch();
        return ['received' => (int) $r['received'], 'sent' => (int) $r['sent'], 'count' => (int) $r['cnt']];
    }

    /**
     * Money in/out per local month since $sinceUtc.
     * @return array<string, array{in:int, out:int}> keyed 'YYYY-MM'
     */
    public function monthlyTotals(int $walletId, string $sinceUtc): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE_FORMAT(created_at + INTERVAL 1 HOUR, '%Y-%m') AS ym,
                    COALESCE(SUM(CASE WHEN dir = 'in' THEN amount_kobo END), 0) AS in_kobo,
                    COALESCE(SUM(CASE WHEN dir = 'out' THEN amount_kobo END), 0) AS out_kobo
             FROM (" . self::SIDES_SQL . ") moves
             GROUP BY ym"
        );
        $stmt->execute([$walletId, $sinceUtc, $walletId, $sinceUtc]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['ym']] = ['in' => (int) $r['in_kobo'], 'out' => (int) $r['out_kobo']];
        }
        return $out;
    }

    /** Successful money leaving (out) and arriving (in) for one wallet since a time. */
    private const SIDES_SQL = "
        SELECT 'out' AS dir, amount_kobo, created_at FROM transactions
         WHERE sender_wallet_id = ? AND created_at >= ? AND status = 'successful'
        UNION ALL
        SELECT 'in' AS dir, amount_kobo, created_at FROM transactions
         WHERE recipient_wallet_id = ? AND created_at >= ? AND status = 'successful'";

    private function bind(\PDOStatement $stmt, array $params): void
    {
        foreach (array_values($params) as $i => $p) {
            $stmt->bindValue($i + 1, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
    }
}
