<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

/** RBAC for API routes. */
final class RequirePermission
{
    public function __construct(private readonly string $permission)
    {
    }

    public function handle(Request $request): void
    {
        (new RequireAuth())->handle($request);
        if (!Auth::can($this->permission)) {
            throw new HttpException(403, 'You don’t have permission to do that.', [], 'FORBIDDEN');
        }
    }
}
