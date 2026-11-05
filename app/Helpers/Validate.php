<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Core\HttpException;

/** Server-side input validation. */
final class Validate
{
    /**
     * @var array<string, string>
     */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field] ??= $message; // Keep the first error per field
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function throwIfErrors(string $message = 'Please correct the highlighted fields.'): void
    {
        if ($this->errors) {
            throw new HttpException(422, $message, $this->errors, 'VALIDATION_FAILED');
        }
    }

    /** Anything not a string (arrays, objects, numbers) is treated as missing. */
    public static function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** Letters (any language), spaces, apostrophes and hyphens; 1–60 chars. */
    public function name(mixed $value, string $field, string $label): string
    {
        $v = preg_replace('/\s+/u', ' ', self::str($value)) ?? '';
        if ($v === '') {
            $this->add($field, "{$label} is required.");
        } elseif (preg_match("/^\p{L}[\p{L}' -]{0,59}$/u", $v) !== 1) {
            $this->add($field, "{$label} can only contain letters, spaces, apostrophes and hyphens (max 60).");
        }
        return $v;
    }

    /** Lower-cased, validated email. */
    public function email(mixed $value, string $field = 'email'): string
    {
        $v = mb_strtolower(self::str($value));
        if ($v === '' || strlen($v) > 191 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->add($field, 'Enter a valid email address.');
        }
        return $v;
    }

    public function phone(mixed $value, string $field = 'phone'): string
    {
        $v = preg_replace('/[\s\-()]/', '', self::str($value)) ?? '';
        if (preg_match('/^(?:\+234|234|0)([789][01]\d{8})$/', $v, $m) !== 1) {
            $this->add($field, 'Enter a valid Nigerian mobile number, e.g. 08031234567.');
            return $v;
        }
        return '+234' . $m[1];
    }

    /** Amount string ("1,500.50") to integer kobo via Money::toKobo (no floats). */
    public function amount(mixed $value, string $field = 'amount'): int
    {
        if (!is_string($value) && !is_int($value)) {
            $this->add($field, 'Enter a valid amount, e.g. 5000 or 5000.50.');
            return 0;
        }
        try {
            return Money::toKobo($value);
        } catch (\InvalidArgumentException $e) {
            $this->add($field, $e->getMessage());
            return 0;
        }
    }

    public function walletNumber(mixed $value, string $field = 'recipient_wallet_number'): string
    {
        $v = preg_replace('/\D/', '', self::str($value)) ?? '';
        if (preg_match('/^\d{10}$/', $v) !== 1) {
            $this->add($field, 'Enter a 10-digit wallet number.');
        }
        return $v;
    }

    public function pin(mixed $value, string $field = 'pin'): string
    {
        $v = self::str($value);
        if (preg_match('/^\d{4}$/', $v) !== 1) {
            $this->add($field, 'Enter your 4-digit PIN.');
        }
        return $v;
    }

    /** Optional free text: trimmed, control characters removed, length-limited. */
    public function text(mixed $value, string $field, int $max, string $label = 'This field'): string
    {
        $v = preg_replace('/[\x00-\x1F\x7F]/u', '', self::str($value)) ?? '';
        if (mb_strlen($v) > $max) {
            $this->add($field, "{$label} must be {$max} characters or fewer.");
        }
        return $v;
    }

    /** Must be exactly true (checkbox) - "1" or "yes" are not accepted silently. */
    public function accepted(mixed $value, string $field, string $message): void
    {
        if ($value !== true && $value !== '1' && $value !== 1) {
            $this->add($field, $message);
        }
    }

    /** YYYY-MM-DD or empty. */
    public function date(mixed $value, string $field): ?string
    {
        $v = self::str($value);
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $this->add($field, 'Use a valid date (YYYY-MM-DD).');
            return null;
        }
        return $v;
    }

    /** Value must be one of $allowed, or empty. */
    public function oneOf(mixed $value, string $field, array $allowed): ?string
    {
        $v = self::str($value);
        if ($v === '') {
            return null;
        }
        if (!in_array($v, $allowed, true)) {
            $this->add($field, 'Invalid value.');
            return null;
        }
        return $v;
    }
}
