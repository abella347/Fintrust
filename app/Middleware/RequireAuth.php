<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

/** Protects API routes: no valid session -> 401. */
final class RequireAuth
{
    public function handle(Request $request): void
    {
        if (Auth::user() === null) {
            throw new HttpException(401, 'Please log in to continue.', [], 'UNAUTHENTICATED');
        }
    }
}
