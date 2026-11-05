<?php

declare(strict_types=1);

namespace App\Core;

/** JSON responses: {success, message, data} on success, {success, message, error: {code, fields}} on failure. */
final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store'); // Never cache balances or transactions
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function error(string $message, int $status, string $code, array $fields = []): never
    {
        $error = ['code' => $code];
        if ($fields) {
            $error['fields'] = $fields;
        }
        self::json(['success' => false, 'message' => $message, 'error' => $error], $status);
    }
}
