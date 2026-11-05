<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Helpers\Dates;
use PDO;

final class AdminRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /* ======================== statistics ======================== */

    public function statistics(): array
    {
        $one = fn (string $sql, array $p = []) => $this->scalar($sql, $p);
        $since30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $since24h = gmdate('Y-m-d H:i:s', time() - 86400);

        $customers = "FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.name = 'user'";

        $severity = $this->db->query(
            "SELECT severity, COUNT(*) n FROM fraud_alerts WHERE status IN ('open','investigating') GROUP BY severity"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'users' => [
                'total'      => (int) $one("SELECT COUNT(*) {$customers}"),
                'active'     => (int) $one("SELECT COUNT(*) {$customers} WHERE u.status = 'active'"),
                'suspended'  => (int) $one("SELECT COUNT(*) {$customers} WHERE u.status = 'suspended'"),
                'unverified' => (int) $one("SELECT COUNT(*) {$customers} WHERE u.email_verified_at IS NULL"),
                'new_7_days' => (int) $one("SELECT COUNT(*) {$customers} WHERE u.created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            ],
            'wallets' => [
                'active' => (int) $one("SELECT COUNT(*) FROM wallets WHERE type = 'user' AND status = 'active'"),
                'frozen' => (int) $one("SELECT COUNT(*) FROM wallets WHERE type = 'user' AND status = 'frozen'"),
                'customer_balances_kobo' => (int) $one("SELECT COALESCE(SUM(balance_kobo), 0) FROM wallets WHERE type = 'user'"),
            ],
            'transactions' => [
                'volume_kobo'        => (int) $one("SELECT COALESCE(SUM(amount_kobo), 0) FROM transactions WHERE status = 'successful'"),
                'volume_30d_kobo'    => (int) $one("SELECT COALESCE(SUM(amount_kobo), 0) FROM transactions WHERE status = 'successful' AND created_at >= ?", [$since30]),
                'successful'         => (int) $one("SELECT COUNT(*) FROM transactions WHERE status = 'successful'"),
                'failed'             => (int) $one("SELECT COUNT(*) FROM transactions WHERE status = 'failed'"),
                'transfers'          => (int) $one("SELECT COUNT(*) FROM transactions WHERE type = 'transfer'"),
                'deposits'           => (int) $one("SELECT COUNT(*) FROM transactions WHERE type = 'deposit'"),
            ],
            'fraud' => [
                'open'     => array_sum(array_map('intval', $severity)),
                'critical' => (int) ($severity['critical'] ?? 0),
                'high'     => (int) ($severity['high'] ?? 0),
                'medium'   => (int) ($severity['medium'] ?? 0),
                'low'      => (int) ($severity['low'] ?? 0),
            ],
            'security' => [
                'failed_logins_24h' => (int) $one('SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND attempted_at >= ?', [$since24h]),
                'locked_accounts'   => (int) $one('SELECT COUNT(*) FROM users WHERE locked_until > UTC_TIMESTAMP()'),
                'pin_locked'        => (int) $one('SELECT COUNT(*) FROM transaction_pins WHERE locked_until > UTC_TIMESTAMP()'),
            ],
            // The double-entry checks, live: all three must be zero.
            'ledger' => [
                'unbalanced_transactions' => (int) $one('SELECT COUNT(*) FROM v_unbalanced_transactions'),
                'wallets_out_of_step'     => (int) $one('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0'),
                'system_total_kobo'       => (int) $one('SELECT total_of_all_wallets_kobo FROM v_system_balance'),
            ],
            'daily' => $this->dailySeries(14),
        ];
    }

    /** Successful transfer and deposit volume per local (Lagos, UTC+1) day, oldest first. */
    private function dailySeries(int $days): array
    {
        $start = (new \DateTimeImmutable('today', Dates::local()))->modify('-' . ($days - 1) . ' days');
        $stmt = $this->db->prepare(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', '+01:00')) AS d,
                    COALESCE(SUM(CASE WHEN type = 'transfer' AND status = 'successful' THEN amount_kobo END), 0) AS transfers,
                    COALESCE(SUM(CASE WHEN type = 'deposit'  AND status = 'successful' THEN amount_kobo END), 0) AS deposits,
                    SUM(status = 'failed') AS failed
             FROM transactions WHERE created_at >= ? GROUP BY d"
        );
        $stmt->execute([$start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['d']] = $r;
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->modify("+{$i} days");
            $key = $d->format('Y-m-d');
            $out[] = [
                'date'            => $key,
                'label'           => $d->format('j M'),
                'transfers_kobo'  => (int) ($rows[$key]['transfers'] ?? 0),
                'deposits_kobo'   => (int) ($rows[$key]['deposits'] ?? 0),
                'failed'          => (int) ($rows[$key]['failed'] ?? 0),
            ];
        }
        return $out;
    }

    /* ======================== users ======================== */

    public function users(?string $search, ?string $status, ?string $role, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($search) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $where[] = "(u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.phone LIKE ? OR w.wallet_number LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }
        if ($status) { $where[] = 'u.status = ?'; $params[] = $status; }
        if ($role)   { $where[] = 'EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.name = ?)'; $params[] = $role; }
        $w = implode(' AND ', $where);

        $from = "FROM users u LEFT JOIN wallets w ON w.user_id = u.id AND w.type = 'user' WHERE {$w}";
        $total = (int) $this->scalar("SELECT COUNT(*) {$from}", $params);

        $stmt = $this->db->prepare(
            "SELECT u.id, u.public_id, u.first_name, u.last_name, u.email, u.phone, u.status, u.email_verified_at,
                    u.locked_until, u.last_login_at, u.created_at,
                    w.wallet_number, w.balance_kobo, w.status AS wallet_status,
                    (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM fraud_alerts fa WHERE fa.user_id = u.id AND fa.status IN ('open','investigating')) AS open_alerts
             {$from} ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?"
        );
        $this->bindAll($stmt, $params, $limit, $offset);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function userByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.*, w.id AS wallet_id, w.wallet_number, w.balance_kobo, w.status AS wallet_status, w.created_at AS wallet_created_at,
                    (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                    (SELECT locked_until FROM transaction_pins p WHERE p.user_id = u.id) AS pin_locked_until,
                    (SELECT COUNT(*) FROM transaction_pins p WHERE p.user_id = u.id) AS has_pin
             FROM users u LEFT JOIN wallets w ON w.user_id = u.id AND w.type = 'user'
             WHERE u.public_id = ?"
        );
        $stmt->execute([$publicId]);
        return $stmt->fetch() ?: null;
    }

    /* ======================== transactions ======================== */

    private const TXN_SELECT = "
        SELECT t.*, sw.wallet_number AS sender_wallet_number, sw.type AS sender_wallet_type,
               rw.wallet_number AS recipient_wallet_number,
               CONCAT_WS(' ', su.first_name, su.last_name) AS sender_name,
               CONCAT_WS(' ', ru.first_name, ru.last_name) AS recipient_name,
               su.public_id AS sender_public_id, ru.public_id AS recipient_public_id,
               (SELECT COUNT(*) FROM fraud_alerts fa WHERE fa.transaction_id = t.id) AS alert_count
        FROM transactions t
        LEFT JOIN wallets sw ON sw.id = t.sender_wallet_id
        LEFT JOIN wallets rw ON rw.id = t.recipient_wallet_id
        LEFT JOIN users su ON su.id = sw.user_id
        LEFT JOIN users ru ON ru.id = rw.user_id";

    /** System-wide transaction search for staff. */
    public function transactions(array $f, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        $search = $f['search'] ?? '';
        if ($search !== '') {
            if (preg_match('/^FT[0-9A-Z]*$/i', $search) === 1) {
                $where[] = 't.reference LIKE ?';                       // Unique index, prefix range
                $params[] = strtoupper(addcslashes($search, '%_\\')) . '%';
            } elseif (preg_match('/^\d{10}$/', $search) === 1) {
                $id = (int) $this->scalar('SELECT id FROM wallets WHERE wallet_number = ?', [$search]);
                $where[] = '(t.sender_wallet_id = ? OR t.recipient_wallet_id = ?)';   // Two index lookups
                array_push($params, $id, $id);
            } else {
                $sub = "SELECT w.id FROM wallets w JOIN users u ON u.id = w.user_id WHERE CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?";
                $where[] = "(t.sender_wallet_id IN ({$sub}) OR t.recipient_wallet_id IN ({$sub}))";
                $like = '%' . addcslashes($search, '%_\\') . '%';
                array_push($params, $like, $like);
            }
        }
        foreach (['type', 'status'] as $col) {
            if (!empty($f[$col])) { $where[] = "t.{$col} = ?"; $params[] = $f[$col]; }
        }
        if (!empty($f['from'])) { $where[] = 't.created_at >= ?'; $params[] = Dates::localDateStartUtc($f['from']); }
        if (!empty($f['to']))   { $where[] = 't.created_at < ?';  $params[] = Dates::localDateEndUtc($f['to']); }
        if (!empty($f['min']))  { $where[] = 't.amount_kobo >= ?'; $params[] = $f['min']; }
        if (!empty($f['max']))  { $where[] = 't.amount_kobo <= ?'; $params[] = $f['max']; }
        if (!empty($f['flagged'])) { $where[] = 'EXISTS (SELECT 1 FROM fraud_alerts fa WHERE fa.transaction_id = t.id)'; }
        $w = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM transactions t WHERE {$w}", $params);
        if ($total === 0 || $offset >= $total) {
            return ['items' => [], 'total' => $total];
        }
        $stmt = $this->db->prepare("SELECT t.id FROM transactions t WHERE {$w} ORDER BY t.created_at DESC, t.id DESC LIMIT ? OFFSET ?");
        $this->bindAll($stmt, $params, $limit, $offset);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(self::TXN_SELECT . " WHERE t.id IN ({$in}) ORDER BY t.created_at DESC, t.id DESC");
        $stmt->execute($ids);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function transaction(string $reference): ?array
    {
        $stmt = $this->db->prepare(self::TXN_SELECT . ' WHERE t.reference = ?');
        $stmt->execute([$reference]);
        $t = $stmt->fetch();
        if (!$t) {
            return null;
        }
        $le = $this->db->prepare(
            'SELECT le.entry_type, le.amount_kobo, le.balance_after_kobo, w.wallet_number, w.type AS wallet_type
             FROM ledger_entries le JOIN wallets w ON w.id = le.wallet_id WHERE le.transaction_id = ? ORDER BY le.id'
        );
        $le->execute([$t['id']]);
        $t['ledger'] = $le->fetchAll();
        return $t;
    }

    /* ======================== fraud alerts ======================== */

    public function fraudAlerts(array $f, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        foreach (['status', 'severity', 'rule_code'] as $col) {
            if (!empty($f[$col])) { $where[] = "fa.{$col} = ?"; $params[] = $f[$col]; }
        }
        if (!empty($f['user'])) { $where[] = 'u.public_id = ?'; $params[] = $f['user']; }
        $w = implode(' AND ', $where);
        $total = (int) $this->scalar("SELECT COUNT(*) FROM fraud_alerts fa LEFT JOIN users u ON u.id = fa.user_id WHERE {$w}", $params);
        $stmt = $this->db->prepare(
            "SELECT fa.*, u.public_id AS user_public_id, CONCAT_WS(' ', u.first_name, u.last_name) AS user_name, u.email AS user_email,
                    t.reference AS transaction_reference, CONCAT_WS(' ', rv.first_name, rv.last_name) AS reviewer_name
             FROM fraud_alerts fa
             LEFT JOIN users u ON u.id = fa.user_id
             LEFT JOIN transactions t ON t.id = fa.transaction_id
             LEFT JOIN users rv ON rv.id = fa.reviewed_by
             WHERE {$w}
             ORDER BY FIELD(fa.status, 'open', 'investigating', 'resolved', 'dismissed'),
                      FIELD(fa.severity, 'critical', 'high', 'medium', 'low'), fa.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $this->bindAll($stmt, $params, $limit, $offset);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /* ======================== audit log ======================== */

    public function auditLogs(array $f, int $limit, int $offset): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($f['action']))     { $where[] = 'a.action LIKE ?'; $params[] = addcslashes($f['action'], '%_\\') . '%'; }
        if (!empty($f['actor_type'])) { $where[] = 'a.actor_type = ?'; $params[] = $f['actor_type']; }
        if (!empty($f['actor']))      { $where[] = 'u.email LIKE ?'; $params[] = '%' . addcslashes($f['actor'], '%_\\') . '%'; }
        $w = implode(' AND ', $where);
        $total = (int) $this->scalar("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE {$w}", $params);
        $stmt = $this->db->prepare(
            "SELECT a.*, u.email AS actor_email, CONCAT_WS(' ', u.first_name, u.last_name) AS actor_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE {$w} ORDER BY a.id DESC LIMIT ? OFFSET ?"
        );
        $this->bindAll($stmt, $params, $limit, $offset);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /* ======================== security events ======================== */

    public function security(): array
    {
        $q = fn (string $sql) => $this->db->query($sql)->fetchAll();
        return [
            'locked_accounts' => $q("SELECT public_id, CONCAT_WS(' ', first_name, last_name) AS name, email, locked_until
                                     FROM users WHERE locked_until > UTC_TIMESTAMP() ORDER BY locked_until DESC"),
            'pin_locked' => $q("SELECT u.public_id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, u.email, p.locked_until
                                FROM transaction_pins p JOIN users u ON u.id = p.user_id
                                WHERE p.locked_until > UTC_TIMESTAMP() ORDER BY p.locked_until DESC"),
            'top_ips' => $q("SELECT ip_address, COUNT(*) AS failures, COUNT(DISTINCT email) AS accounts, MAX(attempted_at) AS last_attempt
                             FROM login_attempts WHERE successful = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL 1 DAY
                             GROUP BY ip_address ORDER BY failures DESC LIMIT 10"),
            'recent_failures' => $q("SELECT la.email, la.ip_address, la.user_agent, la.failure_reason, la.attempted_at, u.public_id
                                     FROM login_attempts la LEFT JOIN users u ON u.id = la.user_id
                                     WHERE la.successful = 0 ORDER BY la.id DESC LIMIT 25"),
            'suspended' => $q("SELECT public_id, CONCAT_WS(' ', first_name, last_name) AS name, email, updated_at
                               FROM users WHERE status = 'suspended' ORDER BY updated_at DESC"),
            'frozen_wallets' => $q("SELECT w.wallet_number, w.updated_at, u.public_id, CONCAT_WS(' ', u.first_name, u.last_name) AS name
                                    FROM wallets w JOIN users u ON u.id = w.user_id WHERE w.status = 'frozen' ORDER BY w.updated_at DESC"),
        ];
    }

    /* ======================== helpers ======================== */

    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function bindAll(\PDOStatement $stmt, array $params, int $limit, int $offset): void
    {
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();
    }
}
