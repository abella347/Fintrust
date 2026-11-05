<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

final class RequireCustomer
{
    public function handle(Request $request): void
    {
        (new RequireAuth())->handle($request);
        if (!Auth::can('wallet.use')) {
            throw new HttpException(403, 'This action is only available to customer accounts.', [], 'FORBIDDEN');
        }
    }
}
