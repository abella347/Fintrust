<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Dates;
use App\Models\TransactionPresenter;
use App\Models\TransactionRepository;
use App\Services\PinService;
use App\Services\WalletService;
use DateTimeImmutable;

/** GET /api/dashboard - everything the dashboard shows, in one round trip. */
final class DashboardController
{
    public function show(Request $r): void
    {
        $user = Auth::user();
        $userId = (int) $user['id'];
        $wallet = (new WalletService())->walletFor($userId);
        $walletId = (int) $wallet['id'];
        $txns = new TransactionRepository();
        $db = Database::connection();

        // Stats: last 30 days.
        $since30 = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $totals = $txns->totalsSince($walletId, $since30);

        // Chart: last 6 local months, oldest first, months with no activity included as 0.
        $firstMonth = (new DateTimeImmutable('first day of this month', Dates::local()))->setTime(0, 0)->modify('-5 months');
        $monthly = $txns->monthlyTotals($walletId, $firstMonth->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $chart = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $firstMonth->modify("+{$i} months");
            $key = $m->format('Y-m');
            $chart[] = ['label' => $m->format('M'), 'month' => $key, 'in_kobo' => $monthly[$key]['in'] ?? 0, 'out_kobo' => $monthly[$key]['out'] ?? 0];
        }

        $recent = $txns->list($walletId, [], 5, 0)['items'];

        // "Last login" = the previous successful login, not the one that started this session.
        $stmt = $db->prepare('SELECT attempted_at FROM login_attempts WHERE user_id = ? AND successful = 1 ORDER BY attempted_at DESC, id DESC LIMIT 1 OFFSET 1');
        $stmt->execute([$userId]);
        $previousLogin = $stmt->fetchColumn() ?: null;

        Response::success([
            'user' => [
                'first_name'     => $user['first_name'],
                'last_name'      => $user['last_name'],
                'email'          => $user['email'],
                'email_verified' => $user['email_verified_at'] !== null,
                'has_pin'        => (new PinService())->hasPin($userId),
                'last_login_at'  => Dates::iso($previousLogin),
            ],
            'wallet' => [
                'wallet_number' => $wallet['wallet_number'],
                'balance_kobo'  => (int) $wallet['balance_kobo'],
                'status'        => $wallet['status'],
                'currency'      => $wallet['currency'],
            ],
            'stats' => [
                'received_kobo'     => $totals['received'],
                'sent_kobo'         => $totals['sent'],
                'transaction_count' => $totals['count'],
                'period'            => 'last_30_days',
            ],
            'chart' => $chart,
            'recent_transactions' => array_map(fn ($t) => TransactionPresenter::present($t, $walletId), $recent),
            'notifications' => NotificationController::summary($userId, 3),   // Latest 3; "View all" has the rest
        ]);
    }
}
