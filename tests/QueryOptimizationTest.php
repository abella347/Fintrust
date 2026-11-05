<?php

declare(strict_types=1);

use App\Models\TransactionRepository;
use App\Services\TransferService;
use App\Services\WalletService;

/** The original, obviously-correct version of the history query. */
function naive_history(PDO $pdo, int $walletId, array $f): array
{
    $where = ["(t.sender_wallet_id = ? OR (t.recipient_wallet_id = ? AND t.status IN ('successful','reversed')))"];
    $p = [$walletId, $walletId];
    if (!empty($f['type']))   { $where[] = 't.type = ?';   $p[] = $f['type']; }
    if (!empty($f['status'])) { $where[] = 't.status = ?'; $p[] = $f['status']; }
    if (($f['direction'] ?? '') === 'in')  { $where[] = 't.recipient_wallet_id = ?'; $p[] = $walletId; }
    if (($f['direction'] ?? '') === 'out') { $where[] = 't.sender_wallet_id = ?';    $p[] = $walletId; }
    if (!empty($f['search'])) {
        $where[] = "(t.reference LIKE ? OR CONCAT_WS(' ', CASE WHEN t.sender_wallet_id = ? THEN ru.first_name ELSE su.first_name END,
                     CASE WHEN t.sender_wallet_id = ? THEN ru.last_name ELSE su.last_name END) LIKE ?)";
        array_push($p, strtoupper($f['search']) . '%', $walletId, $walletId, '%' . $f['search'] . '%');
    }
    $s = $pdo->prepare('SELECT t.id FROM transactions t
        LEFT JOIN wallets sw ON sw.id = t.sender_wallet_id LEFT JOIN wallets rw ON rw.id = t.recipient_wallet_id
        LEFT JOIN users su ON su.id = sw.user_id LEFT JOIN users ru ON ru.id = rw.user_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY t.created_at DESC, t.id DESC');
    $s->execute($p);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

return [
    'optimised history returns exactly the same rows, in the same order, for every filter' => function () {
        in_rollback(function (PDO $pdo) {
            reset_pin_lock($pdo, 2);
            reset_pin_lock($pdo, 3);
            // Some extra activity in both directions, including a failure.
            (new WalletService())->deposit(2, ['amount' => '1000'], idem_key());
            foreach (['120', '130'] as $amt) {
                (new TransferService())->transfer(2, ['recipient_wallet_number' => '3098765432', 'amount' => $amt, 'pin' => '4826'], idem_key());
            }
            (new TransferService())->transfer(3, ['recipient_wallet_number' => '3021456789', 'amount' => '140', 'pin' => '4826'], idem_key());
            try {
                (new TransferService())->transfer(2, ['recipient_wallet_number' => '3098765432', 'amount' => '450000', 'pin' => '4826'], idem_key());
            } catch (App\Core\HttpException) {
            }

            $repo = new TransactionRepository();
            $filters = [[], ['direction' => 'in'], ['direction' => 'out'], ['status' => 'failed'], ['status' => 'successful'],
                        ['type' => 'deposit'], ['type' => 'transfer', 'direction' => 'out'], ['search' => 'Ben'], ['search' => 'FT2026']];
            foreach ([2, 3] as $wallet) {
                foreach ($filters as $f) {
                    $expected = naive_history($pdo, $wallet, $f);
                    $label = "wallet {$wallet} " . json_encode($f);
                    $all = $repo->list($wallet, $f, 50, 0);
                    assert_same(count($expected), $all['total'], "total for {$label}");
                    assert_same($expected, array_map(fn ($r) => (int) $r['id'], $all['items']), "order for {$label}");
                    // Pagination: page 2 of size 2 must be rows 3-4 of the full list.
                    $page2 = $repo->list($wallet, $f, 2, 2);
                    assert_same(array_slice($expected, 2, 2), array_map(fn ($r) => (int) $r['id'], $page2['items']), "page 2 for {$label}");
                }
            }
        });
    },
    'optimised dashboard totals match a direct calculation' => function () {
        in_rollback(function (PDO $pdo) {
            $since = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
            $r = (new TransactionRepository())->totalsSince(2, $since);
            $s = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN recipient_wallet_id = 2 THEN amount_kobo END), 0) AS received,
                COALESCE(SUM(CASE WHEN sender_wallet_id = 2 THEN amount_kobo END), 0) AS sent, COUNT(*) AS cnt
                FROM transactions WHERE status = 'successful' AND created_at >= ? AND (sender_wallet_id = 2 OR recipient_wallet_id = 2)");
            $s->execute([$since]);
            $x = $s->fetch();
            assert_same(['received' => (int) $x['received'], 'sent' => (int) $x['sent'], 'count' => (int) $x['cnt']], $r);
            $monthly = (new TransactionRepository())->monthlyTotals(2, $since);
            assert_same((int) $x['received'], array_sum(array_column($monthly, 'in')), 'monthly in adds up');
            assert_same((int) $x['sent'], array_sum(array_column($monthly, 'out')), 'monthly out adds up');
        });
    },
    'admin search: reference, wallet number and name each find the right rows' => function () {
        in_rollback(function () {
            $repo = new App\Models\AdminRepository();
            $byRef = $repo->transactions(['search' => 'FT20261010TRF3A4B5C6D7'], 20, 0);
            assert_same(1, $byRef['total']);
            $byWallet = $repo->transactions(['search' => '3055500011'], 20, 0);
            assert_same(['FT20261004DEP0C9D0E1F2'], array_column($byWallet['items'], 'reference'));
            $byName = $repo->transactions(['search' => 'Chidi'], 20, 0);
            assert_same(1, $byName['total']);
            assert_same(0, $repo->transactions(['search' => "' OR 1=1 --"], 20, 0)['total'], 'injection attempt is just text');
        });
    },
];
