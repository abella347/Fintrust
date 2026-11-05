<?php

declare(strict_types=1);

namespace App\Helpers;

/** Random identifiers. */
final class Str
{
    /** RFC 4122 version-4 UUID, e.g. "3f2c6a1e-8b0d-4c3e-9a7f-2d1b5e6c7a90". */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // Version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Hex token for links (password reset, email verification). 64 chars = 256 bits. */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** 10-digit wallet number starting with 1–8. */
    public static function walletNumber(): string
    {
        return (string) random_int(1, 8) . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
    }

    /** Transaction reference, e.g. "FT20261014A1B2C3D4E5F6". */
    public static function transactionReference(string $prefix = 'FT'): string
    {
        return $prefix . gmdate('Ymd') . strtoupper(bin2hex(random_bytes(6)));
    }

    /** Short, readable device name from a User-Agent: "Chrome on Windows", "Safari on iPhone". */
    public static function deviceName(string $userAgent): string
    {
        $browsers = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'SamsungBrowser/' => 'Samsung Internet',
                     'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];
        $systems = ['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows',
                    'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'];
        $browser = $os = null;
        foreach ($browsers as $needle => $name) {
            if (str_contains($userAgent, $needle)) { $browser = $name; break; }
        }
        foreach ($systems as $needle => $name) {
            if (str_contains($userAgent, $needle)) { $os = $name; break; }
        }
        if ($browser === null && $os === null) {
            return $userAgent === '' ? 'Unknown device' : mb_substr($userAgent, 0, 40);
        }
        return ($browser ?? 'Browser') . ($os ? ' on ' . $os : '');
    }
}
