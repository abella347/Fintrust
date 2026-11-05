<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Validate;

/** Password strength rules (the same ones the register form shows live). */
final class PasswordPolicy
{
    private const COMMON = [
        'password123!', 'p@ssw0rd123', 'qwerty12345!', 'welcome123!', 'fintrust123!', 'admin@123456', 'letmein1234!',
    ];

    /** Adds an error to $v for $field when the password is too weak. */
    public static function check(Validate $v, string $password, string $field = 'password', string $email = ''): void
    {
        $len = mb_strlen($password);
        if ($len < 10 || $len > 128) {
            $v->add($field, 'Password must be between 10 and 128 characters.');
            return;
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $v->add($field, 'Use upper and lower case letters, a number and a symbol.');
            return;
        }
        $local = strtolower(strstr($email, '@', true) ?: '');
        if (strlen($local) >= 3 && str_contains(strtolower($password), $local)) {
            $v->add($field, 'Your password must not contain your email name.');
            return;
        }
        if (in_array(strtolower($password), self::COMMON, true)) {
            $v->add($field, 'This password is too common. Choose something less predictable.');
        }
    }

    /** A 4-digit PIN that isn't trivially guessable. */
    public static function checkPin(Validate $v, string $pin, string $field = 'pin'): void
    {
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            $v->add($field, 'PIN must be exactly 4 digits.');
            return;
        }
        $repeated   = preg_match('/^(\d)\1{3}$/', $pin) === 1;            // 1111
        $sequential = str_contains('0123456789', $pin) || str_contains('9876543210', $pin); // 1234, 4321
        $year       = preg_match('/^(19|20)\d{2}$/', $pin) === 1;          // 1990, 2024
        if ($repeated || $sequential || $year) {
            $v->add($field, 'Too easy to guess — avoid repeated or sequential digits and years.');
        }
    }
}
