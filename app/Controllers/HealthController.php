<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SetupCheck;

/** GET /api/health */
final class HealthController
{
    public function show(Request $request): void
    {
        $checks = (new SetupCheck())->run();
        $failedErrors = array_filter($checks, fn (array $c) => !$c['ok'] && $c['level'] === 'error');
        $ok = $failedErrors === [];

        $data = ['status' => $ok ? 'ok' : 'degraded', 'time_utc' => gmdate('c')];
        if (is_local()) {
            $data['checks'] = $checks;
        }

        if (!$ok) {
            Response::json([
                'success' => false,
                'message' => 'Some checks failed.',
                'error'   => ['code' => 'DEGRADED'],
                'data'    => $data,
            ], 503);
        }
        Response::success($data, 'FinTrust API is running.');
    }
}
