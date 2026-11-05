<?php

declare(strict_types=1);

namespace App\Core;

/** CSRF (Cross-Site Request Forgery) protection. */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** Compare in constant time so the token can't be guessed byte-by-byte from timing. */
    public static function verify(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    /** Hidden input for classic HTML forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    /** Called after login so a token issued to an anonymous visitor isn't reused. */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
