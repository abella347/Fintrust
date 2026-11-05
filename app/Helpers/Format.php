<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/** Display formatting used by views. */
final class Format
{
    /** '2026-10-10 13:45:00' (UTC) -> '10 Oct 2026, 2:45 PM' (Africa/Lagos) */
    public static function dateTime(?string $utc, string $pattern = 'j M Y, g:i A'): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone((string) config('app.timezone')))->format($pattern);
    }

    /** "3021456789" -> "302 145 6789" - easier to read aloud and compare. */
    public static function walletNumber(string $number): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '$1 $2 $3', $number) ?? $number;
    }

    /** Status -> badge CSS modifier. */
    public static function badgeClass(string $status): string
    {
        return match ($status) {
            'successful', 'active', 'resolved', 'credit'       => 'badge--success',
            'failed', 'suspended', 'closed', 'critical', 'high' => 'badge--danger',
            'pending', 'frozen', 'investigating', 'medium'      => 'badge--warning',
            'reversed', 'open', 'low', 'debit'                  => 'badge--info',
            default                                             => 'badge--neutral',
        };
    }
}
