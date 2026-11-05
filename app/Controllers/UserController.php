<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Dates;
use App\Helpers\Str;
use App\Helpers\Validate;
use App\Models\UserRepository;
use App\Services\AuditLog;
use App\Services\AvatarService;
use App\Services\BiometricService;
use App\Services\Mailer;
use App\Services\Notifier;
use App\Services\PasswordPolicy;
use App\Services\PinService;
use App\Services\RateLimiter;

/** Profile and security settings. */
final class UserController
{
    /** GET /api/user/profile */
    public function profile(Request $r): void
    {
        Response::success($this->profileData(Auth::id()));
    }

    /** PUT /api/user/profile */
    public function updateProfile(Request $r): void
    {
        $userId = Auth::id();
        $users = new UserRepository();
        $user = $this->reauthenticate($userId, $r->input('current_password'));

        $v = new Validate();
        $first = $v->name($r->input('first_name'), 'first_name', 'First name');
        $last  = $v->name($r->input('last_name'), 'last_name', 'Last name');
        $phone = $v->phone($r->input('phone'));
        $v->throwIfErrors();
        if ($users->phoneTaken($phone, $userId)) {
            throw new HttpException(409, 'This phone number belongs to another account.', ['phone' => 'This phone number belongs to another account.'], 'PHONE_TAKEN');
        }

        Database::transaction(function () use ($users, $user, $userId, $first, $last, $phone) {
            $users->updateProfile($userId, $first, $last, $phone);
            AuditLog::record($userId, 'user', 'profile.updated', 'user', $userId,
                ['first_name' => $user['first_name'], 'last_name' => $user['last_name'], 'phone' => $user['phone']],
                ['first_name' => $first, 'last_name' => $last, 'phone' => $phone]);
        });
        Response::success($this->profileData($userId), 'Profile updated.');
    }

    /** PUT /api/user/password */
    public function updatePassword(Request $r): void
    {
        $userId = Auth::id();
        $user = $this->reauthenticate($userId, $r->input('current_password'));
        $password = is_string($r->input('password')) ? $r->input('password') : '';

        $v = new Validate();
        PasswordPolicy::check($v, $password, 'password', $user['email']);
        if (!$v->hasErrors() && password_verify($password, $user['password_hash'])) {
            $v->add('password', 'Choose a different password from your current one.');
        }
        if ($r->input('password_confirmation') !== $password) {
            $v->add('password_confirmation', 'Passwords do not match.');
        }
        $v->throwIfErrors();

        Database::transaction(function () use ($userId, $password) {
            (new UserRepository())->updatePassword($userId, password_hash($password, PASSWORD_DEFAULT));
            Notifier::notify($userId, 'security', 'Password changed', 'Your password was changed. Other sessions have been signed out.');
            AuditLog::record($userId, 'user', 'auth.password_changed', 'user', $userId);
        });
        // password_changed_at is now newer than every other session's login time,
        // so Auth::user() ends those sessions on their next request. Keep this one.
        Auth::touchAuthTime();
        Mailer::send($user['email'], 'Your FinTrust password was changed',
            "Hi {$user['first_name']},\n\nYour password was just changed from your account settings. If this wasn't you, contact security@fintrust.test immediately.", $userId);
        Response::success(null, 'Password changed. Other sessions have been signed out.');
    }

    /** PUT /api/user/pin */
    public function updatePin(Request $r): void
    {
        RateLimiter::hit('pin-change:user:' . Auth::id(), 5, 900);
        (new PinService())->set(Auth::id(), $r->all());
        Response::success(['has_pin' => true], 'Transaction PIN saved.');
    }

    /** POST /api/user/avatar  {image: "data:image/jpeg;base64,..."} */
    public function uploadAvatar(Request $r): void
    {
        RateLimiter::hit('avatar:user:' . Auth::id(), 20, 3600, 'Too many picture changes. Try again later.');
        $name = (new AvatarService())->save(Auth::id(), $r->input('image'));
        Response::success(['avatar_url' => avatar_url($name)], 'Profile picture updated.');
    }

    /** DELETE /api/user/avatar */
    public function removeAvatar(Request $r): void
    {
        (new AvatarService())->remove(Auth::id());
        Response::success(['avatar_url' => null], 'Profile picture removed.');
    }

    /** GET /api/user/avatar  (the image itself, own picture only) */
    public function avatar(Request $r): void
    {
        (new AvatarService())->stream(Auth::id());
    }

    /** Checks the current password, with its own rate limit so it can't be brute-forced from here. */
    private function reauthenticate(int $userId, mixed $password): array
    {
        RateLimiter::hit('reauth:user:' . $userId, 10, 900, 'Too many password attempts. Wait 15 minutes and try again.');
        $user = (new UserRepository())->findById($userId);
        if (!is_string($password) || !password_verify($password, $user['password_hash'])) {
            throw new HttpException(401, 'Your current password is incorrect.', ['current_password' => 'Incorrect password.'], 'INVALID_PASSWORD');
        }
        return $user;
    }

    private function profileData(int $userId): array
    {
        $users = new UserRepository();
        $u = $users->findById($userId);
        return [
            'first_name'     => $u['first_name'],
            'last_name'      => $u['last_name'],
            'email'          => $u['email'],
            'phone'          => $u['phone'],
            'email_verified' => $u['email_verified_at'] !== null,
            'has_pin'        => (new PinService())->hasPin($userId),
            'has_biometric'  => (new BiometricService())->hasDevices($userId),
            'avatar_url'     => avatar_url($u['avatar_path'] ?? null),
            'created_at'     => Dates::iso($u['created_at']),
            'recent_logins'  => array_map(fn ($l) => [
                'successful'   => (bool) $l['successful'],
                'ip_address'   => $l['ip_address'],
                'user_agent'   => Str::deviceName((string) $l['user_agent']),
                'attempted_at' => Dates::iso($l['attempted_at']),
            ], $users->recentLogins($userId, 10)),
        ];
    }
}
