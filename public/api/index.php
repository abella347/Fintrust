<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Services\RateLimiter;

Session::start();

$router  = new Router();
$request = new Request();

require base_path('routes/api.php');

try {
    // Checks for every state-changing request (POST/PUT/PATCH/DELETE)
    if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
        // 1. Only JSON bodies. A plain HTML form on another site can't send
        // Content-Type: application/json without the browser asking us first.
        if (!str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            throw new HttpException(415, 'Send the request body as JSON.', [], 'UNSUPPORTED_MEDIA_TYPE');
        }
        // 2. CSRF token from the X-CSRF-Token header must match the session's token.
        // 403 (not Laravel-style 419): Apache turns unknown status codes into 500.
        if (!Csrf::verify($request->header('X-CSRF-Token'))) {
            throw new HttpException(403, 'Your session has expired. Refresh the page and try again.', [], 'CSRF_TOKEN_MISMATCH');
        }
    }

    // Coarse per-IP rate limit for the whole API (stops scripted abuse)
    $isWrite = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    RateLimiter::hit(
        'api:' . ($isWrite ? 'write' : 'read') . ':ip:' . $request->ip(),
        $isWrite ? 60 : 300,   // Per minute
        60,
        'You’re making requests too quickly. Please slow down and try again in a minute.'
    );

    $router->dispatch($request);
} catch (HttpException $e) {
    Response::error($e->getMessage(), $e->status(), $e->errorCode(), $e->errors());
} catch (Throwable $e) {
    // Unexpected failure: log every detail privately, tell the client nothing internal.
    app_log('error', get_class($e) . ': ' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
        'path' => $request->path(),
    ]);
    $message = config('app.debug') ? $e->getMessage() : 'Something went wrong. Please try again.';
    Response::error($message, 500, 'SERVER_ERROR');
}
