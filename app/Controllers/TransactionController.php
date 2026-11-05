<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validate;
use App\Models\TransactionPresenter;
use App\Models\TransactionRepository;
use App\Services\TransferService;
use App\Services\WalletService;

final class TransactionController
{
    /** POST /api/transactions/transfer  (header: Idempotency-Key) */
    public function transfer(Request $r): void
    {
        $result = (new TransferService())->transfer(Auth::id(), $r->all(), $r->header('Idempotency-Key'));
        Response::success($result['data'], $result['message'], $result['code']);
    }

    /** GET /api/transactions?page=&per_page=&search=&type=&status=&direction=&from=&to= */
    public function index(Request $r): void
    {
        $wallet = (new WalletService())->walletFor(Auth::id());
        $walletId = (int) $wallet['id'];

        // Every filter is validated against an allow-list before it gets near SQL.
        $v = new Validate();
        $filters = [
            'search'    => mb_substr(Validate::str($r->query('search')), 0, 40),
            'type'      => $v->oneOf($r->query('type'), 'type', ['transfer', 'deposit', 'reversal', 'adjustment']),
            'status'    => $v->oneOf($r->query('status'), 'status', ['pending', 'successful', 'failed', 'reversed']),
            'direction' => $v->oneOf($r->query('direction'), 'direction', ['in', 'out']),
            'from'      => $v->date($r->query('from'), 'from'),
            'to'        => $v->date($r->query('to'), 'to'),
        ];
        if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) {
            $v->add('from', '“From” must be on or before “To”.');
        }
        $v->throwIfErrors('Check your filters.');

        $perPage = max(1, min(50, (int) ($r->query('per_page') ?? 10)));
        $page = max(1, (int) ($r->query('page') ?? 1));

        $repo = new TransactionRepository();
        $result = $repo->list($walletId, $filters, $perPage, ($page - 1) * $perPage);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $totalPages) { // Asked past the end: show the last page instead
            $page = $totalPages;
            $result = $repo->list($walletId, $filters, $perPage, ($page - 1) * $perPage);
        }

        Response::success([
            'items' => array_map(fn ($t) => TransactionPresenter::present($t, $walletId), $result['items']),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $result['total'], 'total_pages' => $totalPages],
        ]);
    }

    /** GET /api/transactions/{reference} */
    public function show(Request $r): void
    {
        $wallet = (new WalletService())->walletFor(Auth::id());
        $reference = strtoupper((string) $r->param('reference'));
        $row = preg_match('/^[A-Z0-9]{6,32}$/', $reference) === 1
            ? (new TransactionRepository())->findVisible($reference, (int) $wallet['id'])
            : null;
        if ($row === null) {
            // Same answer for "doesn't exist" and "not yours": never confirm someone else's reference.
            throw new HttpException(404, 'Transaction not found.', [], 'NOT_FOUND');
        }
        Response::success(TransactionPresenter::present($row, (int) $wallet['id']));
    }
}
