<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TransferService;
use App\Services\WalletService;

final class WalletController
{
    /** GET /api/wallet */
    public function show(Request $r): void
    {
        Response::success((new WalletService())->summary(Auth::id()));
    }

    /** GET /api/wallet/balance */
    public function balance(Request $r): void
    {
        $w = (new WalletService())->walletFor(Auth::id());
        Response::success(['balance_kobo' => (int) $w['balance_kobo']]);
    }

    /** GET /api/wallet/lookup?wallet_number=... */
    public function lookup(Request $r): void
    {
        Response::success((new TransferService())->lookup(Auth::id(), $r->query('wallet_number')));
    }

    /** POST /api/wallet/deposit  (header: Idempotency-Key) */
    public function deposit(Request $r): void
    {
        $result = (new WalletService())->deposit(Auth::id(), $r->all(), $r->header('Idempotency-Key'));
        Response::success($result['data'], $result['message'], $result['code']);
    }
}
