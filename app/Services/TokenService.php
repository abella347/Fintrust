<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Helpers\Str;
use App\Helpers\Validate;
use App\Models\UserRepository;

/** Email verification and password reset links. */
final class TokenService
{
    private const VERIFY_TTL_HOURS = 24;
    private const RESET_TTL_MINUTES = 30;

    /* ---------------- email verification ---------------- */

    public function issueEmailVerification(int $userId): string
    {
        $token = Str::token();
        Database::connection()->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR)'
        )->execute([$userId, hash('sha256', $token), self::VERIFY_TTL_HOURS]);
        return $token;
    }

    public static function sendVerificationEmail(string $email, string $firstName, string $token, int $userId): void
    {
        $link = url('verify-email?token=' . $token);
        Mailer::send($email, 'Verify your FinTrust email', "Hi {$firstName},\n\nConfirm your email address by opening this link:\n{$link}\n\nThe link expires in 24 hours. If you didn't create a FinTrust account, ignore this email.\n\n— FinTrust (demo: all funds are simulated)", $userId);
    }

    public function verifyEmail(mixed $token): void
    {
        $row = $this->consume('email_verification_tokens', $token, function (array $row) {
            (new UserRepository())->markEmailVerified((int) $row['user_id']);
            AuditLog::record((int) $row['user_id'], 'user', 'auth.email_verified', 'user', (int) $row['user_id']);
        });
        if ($row === null) {
            throw new HttpException(422, 'This verification link is invalid or has expired. Request a new one.', [], 'INVALID_TOKEN');
        }
    }

    /** Always succeeds from the caller's view, so it can't reveal which emails exist. */
    public function resendVerification(mixed $email, string $ip): void
    {
        $address = mb_strtolower(Validate::str($email));
        RateLimiter::hit('verify-resend:ip:' . $ip, 5, 3600);
        RateLimiter::hit('verify-resend:email:' . hash('sha256', $address), 3, 3600, 'Please wait before requesting another email.');
        $user = (new UserRepository())->findByEmail($address);
        if ($user && $user['email_verified_at'] === null && $user['status'] === 'active') {
            $token = $this->issueEmailVerification((int) $user['id']);
            self::sendVerificationEmail($user['email'], $user['first_name'], $token, (int) $user['id']);
        }
    }

    /* ---------------- password reset ---------------- */

    public function requestPasswordReset(mixed $email, string $ip): void
    {
        $v = new Validate();
        $address = $v->email($email);
        $v->throwIfErrors();

        RateLimiter::hit('reset:ip:' . $ip, 10, 900);
        RateLimiter::hit('reset:email:' . hash('sha256', $address), 3, 900, 'Please wait a few minutes before requesting another reset email.');

        $user = (new UserRepository())->findByEmail($address);
        if (!$user || $user['status'] !== 'active') {
            return; // Same response as success: no account enumeration
        }

        $token = Str::token();
        Database::transaction(function () use ($user, $token, $ip) {
            $db = Database::connection();
            // Only the newest link works: retire any earlier unused ones.
            $db->prepare('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
                ->execute([$user['id']]);
            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?)'
            )->execute([$user['id'], hash('sha256', $token), self::RESET_TTL_MINUTES, $ip]);
            AuditLog::record((int) $user['id'], 'user', 'auth.password_reset_requested', 'user', (int) $user['id']);
        });

        $link = url('reset-password?token=' . $token);
        Mailer::send($user['email'], 'Reset your FinTrust password',
            "Hi {$user['first_name']},\n\nSomeone (hopefully you) asked to reset your FinTrust password. Open this link to choose a new one:\n{$link}\n\nIt works once and expires in 30 minutes. If you didn't ask for this, ignore this email — your password won't change.\n\n— FinTrust",
            (int) $user['id']);
    }

    public function resetPassword(array $in): void
    {
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $v = new Validate();
        // We don't know the email yet (the token tells us), so the email-name rule is checked after lookup.
        PasswordPolicy::check($v, $password);
        if (($in['password_confirmation'] ?? null) !== $password) {
            $v->add('password_confirmation', 'Passwords do not match.');
        }
        $v->throwIfErrors();

        $users = new UserRepository();
        $row = $this->consume('password_reset_tokens', $in['token'] ?? null, function (array $row) use ($users, $password) {
            $userId = (int) $row['user_id'];
            $user = $users->findById($userId);
            $v = new Validate();
            PasswordPolicy::check($v, $password, 'password', $user['email']);
            $v->throwIfErrors(); // Throwing here rolls back, so the token stays unused
            $users->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
            Notifier::notify($userId, 'security', 'Password changed', 'Your password was reset. All other sessions have been signed out.');
            AuditLog::record($userId, 'user', 'auth.password_reset', 'user', $userId);
        });
        if ($row === null) {
            throw new HttpException(422, 'This reset link is invalid or has expired. Request a new one.', [], 'INVALID_TOKEN');
        }
        $user = $users->findById((int) $row['user_id']);
        Mailer::send($user['email'], 'Your FinTrust password was changed',
            "Hi {$user['first_name']},\n\nYour password was just changed. If this wasn't you, contact security@fintrust.test immediately.", (int) $user['id']);
    }

    /* ---------------- shared ---------------- */

    private function consume(string $table, mixed $token, callable $onValid): ?array
    {
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);

        return Database::transaction(function () use ($table, $hash, $onValid) {
            // $table is one of two fixed names chosen in this class, never user input.
            $stmt = Database::connection()->prepare(
                "SELECT * FROM {$table} WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE"
            );
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            Database::connection()->prepare("UPDATE {$table} SET used_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$row['id']]);
            $onValid($row);
            return $row;
        });
    }
}
