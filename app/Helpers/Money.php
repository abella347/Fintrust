<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/** Money is stored as an integer number of kobo (₦1 = 100 kobo). */
final class Money
{
    /** Largest amount we accept from a form: ₦999,999,999.99 */
    public const MAX_INPUT_KOBO = 99_999_999_999;

    /**
     * "1500" -> 150000, "1,500.5" -> 150050, "0.01" -> 1
     * @throws InvalidArgumentException for anything that isn't a clean positive amount with at most 2 decimal places.
     */
    public static function toKobo(string|int $input): int
    {
        $clean = str_replace([',', ' ', '₦'], '', trim((string) $input));

        // Digits, optionally a dot and 1–2 more digits. No signs, no exponents.
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $clean, $m) !== 1) {
            throw new InvalidArgumentException('Enter a valid amount, e.g. 1500 or 1500.50.');
        }

        $naira = (int) $m[1];
        $kobo  = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0; // "5" means 50 kobo

        $total = $naira * 100 + $kobo;
        if ($total <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if ($total > self::MAX_INPUT_KOBO) {
            throw new InvalidArgumentException('Amount is too large.');
        }
        return $total;
    }

    /** 150050 -> "₦1,500.50"  (negative values -> "-₦1,500.50") */
    public static function format(int $kobo, bool $withSymbol = true): string
    {
        $sign  = $kobo < 0 ? '-' : '';
        $abs   = abs($kobo);
        $naira = intdiv($abs, 100);
        $rest  = $abs % 100;

        return $sign . ($withSymbol ? '₦' : '') . number_format($naira) . '.' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }

    /** 150050 -> "1500.50" (for JSON / inputs, no symbol, no commas). */
    public static function toDecimalString(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $abs  = abs($kobo);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
