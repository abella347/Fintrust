<?php

declare(strict_types=1);

namespace App\Core;

/** Tiny .env reader (no Composer package needed). */
final class Env
{
    /**
     * @var array<string, string>
     */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return; // Missing .env is allowed; defaults/real env vars are used.
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        // Some Windows editors start the file with an invisible UTF-8 "BOM"; without
        // removing it, the first setting (APP_ENV) would silently be ignored.
        if (isset($lines[0])) {
            $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]) ?? $lines[0];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip one pair of surrounding quotes: KEY="value with spaces"
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1) {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // Real server environment variables win over the .env file.
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }
}
