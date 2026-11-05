<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;

/** The database stores UTC; people think in Nigerian (WAT) calendar days. */
final class Dates
{
    public static function local(): DateTimeZone
    {
        return new DateTimeZone((string) config('app.timezone', 'Africa/Lagos'));
    }

    public static function localDayBoundsUtc(DateTimeImmutable $moment): array
    {
        $start = $moment->setTimezone(self::local())->setTime(0, 0);
        return [self::utc($start), self::utc($start->modify('+1 day'))];
    }

    /** 'YYYY-MM-DD' (local) -> UTC start of that local day. */
    public static function localDateStartUtc(string $ymd): string
    {
        return self::utc(new DateTimeImmutable($ymd . ' 00:00:00', self::local()));
    }

    /** 'YYYY-MM-DD' (local) -> UTC start of the following local day (exclusive end). */
    public static function localDateEndUtc(string $ymd): string
    {
        return self::utc((new DateTimeImmutable($ymd . ' 00:00:00', self::local()))->modify('+1 day'));
    }

    /** DB 'Y-m-d H:i:s' (UTC) -> ISO-8601 '2026-10-10T13:45:00Z' for the API. */
    public static function iso(?string $utc): ?string
    {
        if ($utc === null) {
            return null;
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
