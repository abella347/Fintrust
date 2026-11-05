<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\TokenService;

/** Controllers are thin: read the request, call a service, shape the response. */
final class AuthController
{
    public function register(Request $r): void
    {
        $result = (new AuthService())->register($r->all(), $r->ip());
        Response::success(
            ['email' => $result['email'], 'requires_verification' => true],
            'Account created. Check your email to verify it.',
            201
        );
    }

    public function login(Request $r): void
    {
        $result = (new AuthService())->login($r->all(), $r->ip(), $r->userAgent(), $this->deviceToken());

        // Staff accounts (admin, support) have no wallet: send them to the admin area.
        $isStaffOnly = Auth::rolesCan($result['roles'], 'admin.access') && !in_array('user', $result['roles'], true);
        $redirect = $isStaffOnly ? Auth::safeNext($r->string('next'), 'admin/') : Auth::safeNext($r->string('next'));
        if ($isStaffOnly && !str_starts_with($redirect, 'admin')) {
            $redirect = 'admin/';
        }

        Response::success(['redirect' => $redirect], 'Welcome back, ' . $result['user']['first_name'] . '.');
    }

    public function logout(Request $r): void
    {
        (new AuthService())->logout();
        Response::success(null, 'You have been logged out.');
    }

    public function forgotPassword(Request $r): void
    {
        (new TokenService())->requestPasswordReset($r->input('email'), $r->ip());
        Response::success(null, 'If an account exists for that email, we have sent a reset link.');
    }

    public function resetPassword(Request $r): void
    {
        (new TokenService())->resetPassword($r->all());
        Response::success(null, 'Password updated. You can now log in.');
    }

    public function verifyEmail(Request $r): void
    {
        (new TokenService())->verifyEmail($r->input('token'));
        Response::success(null, 'Email verified.');
    }

    public function resendVerification(Request $r): void
    {
        (new TokenService())->resendVerification($r->input('email'), $r->ip());
        Response::success(null, 'If your account still needs verifying, a new link has been sent.');
    }

    /** A long-lived random cookie that identifies this browser, so we can say "new login from another device". */
    private function deviceToken(): string
    {
        $token = $_COOKIE['ft_device'] ?? '';
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            setcookie('ft_device', $token, [
                'expires'  => time() + 365 * 86400,
                'path'     => '/',
                'secure'   => (bool) config('app.session.secure'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return $token;
    }
}
