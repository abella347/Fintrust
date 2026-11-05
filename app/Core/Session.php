<?php

declare(strict_types=1);

namespace App\Core;

/** Secure PHP session handling. */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cfg = config('app.session');

        // Only accept session IDs that this server created (blocks "session fixation",
        // where an attacker plants a known ID in the victim's browser).
        ini_set('session.use_strict_mode', '1');
        // Session ID only travels in a cookie, never in the URL (URLs leak into logs/history).
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) {
            // Longer, denser IDs. (PHP 8.4+ deprecates these settings; its defaults are fine.)
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) ($cfg['idle_minutes'] * 60));

        session_name($cfg['name']);
        session_set_cookie_params([
            'lifetime' => 0,               // Cookie dies when the browser closes
            'path'     => '/',
            'secure'   => $cfg['secure'],  // HTTPS only (turn on in production)
            'httponly' => true,            // JavaScript can't read it -> XSS can't steal it
            'samesite' => 'Lax',           // Not sent on cross-site POSTs -> helps stop CSRF
        ]);

        session_start();
        self::enforceIdleTimeout((int) $cfg['idle_minutes']);
    }

    /** Even an active session ends after this long; the user logs in again. */
    private const ABSOLUTE_LIFETIME_SECONDS = 8 * 3600;

    /** Log the user out automatically after N idle minutes, or after 8 hours in total, whichever comes first. */
    private static function enforceIdleTimeout(int $minutes): void
    {
        $now     = time();
        $last    = $_SESSION['_last_activity'] ?? null;
        $created = $_SESSION['_created'] ?? null;

        $idleExpired     = $last !== null && ($now - (int) $last) > $minutes * 60;
        $absoluteExpired = $created !== null && ($now - (int) $created) > self::ABSOLUTE_LIFETIME_SECONDS;

        if ($idleExpired || $absoluteExpired) {
            self::restart();
            $_SESSION['_flash']['warning'] = 'Your session expired. Please log in again.';
        }
        $_SESSION['_last_activity'] = $now;
        $_SESSION['_created'] ??= $now;
    }

    /** Call right after a successful login (and after privilege changes). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Set a one-time message shown on the next page (success, error, warning, info). */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type] = $message;
    }

    /**
     * @return array<string, string> Messages, removed once read.
     */
    public static function pullFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    public static function restart(): void
    {
        self::destroy();
        session_start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
