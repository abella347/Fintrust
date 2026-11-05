<?php

declare(strict_types=1);

namespace App\Core;

/** Who is signed in? */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return is_int($id) ? $id : null;
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::put('user_id', $userId);
        Session::put('auth_at', time());
        Session::put('_created', time()); // Absolute session lifetime starts at login
        self::$loaded = false;
    }

    public static function logout(): void
    {
        Session::restart();
        self::$user = null;
        self::$loaded = true;
    }

    /** Keep this session alive after the user changes their own password. */
    public static function touchAuthTime(): void
    {
        Session::put('auth_at', time() + 1);
    }

    /**
     * @return array<string, mixed>|null The signed-in user's row plus 'roles', or null.
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $id = self::id();
        if ($id === null) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            "SELECT u.id, u.public_id, u.first_name, u.last_name, u.email, u.phone, u.status,
                    u.email_verified_at, u.password_changed_at, u.avatar_path, GROUP_CONCAT(r.name) AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ?
             GROUP BY u.id"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        // Password changed after this session logged in -> this session is no longer trusted.
        $authAt = (int) Session::get('auth_at', 0);
        $changed = $row && $row['password_changed_at'] ? strtotime($row['password_changed_at'] . ' UTC') : 0;

        if (!$row || $row['status'] !== 'active' || $changed > $authAt) {
            Session::restart();
            Session::flash('warning', 'Please log in again.');
            return null;
        }

        $row['roles'] = $row['roles'] ? explode(',', $row['roles']) : [];
        return self::$user = $row;
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::user()['roles'] ?? [], true);
    }

    /** RBAC check: does the signed-in user hold a role that grants $permission? */
    public static function can(string $permission): bool
    {
        return self::rolesCan(self::user()['roles'] ?? [], $permission);
    }

    /**
     * @param list<string> $roles
     */
    public static function rolesCan(array $roles, string $permission): bool
    {
        $map = config('permissions', []);
        foreach ($roles as $role) {
            if (in_array($permission, $map[$role] ?? [], true)) {
                return true;
            }
        }
        return false;
    }

    /** For customer pages: returns the user, or redirects (guests to login, admins to the admin area). */
    public static function requirePageUser(): array
    {
        $user = self::user();
        if ($user === null) {
            Session::flash('info', 'Please log in to continue.');
            self::redirect('login?next=' . rawurlencode(self::currentPath()));
        }
        if (!self::rolesCan($user['roles'], 'wallet.use')) {
            self::redirect('admin/'); // Staff accounts have no wallet
        }
        return $user;
    }

    /** For staff pages: the user must be signed in and hold $permission. */
    public static function requirePermissionPage(string $permission): array
    {
        $user = self::user();
        if ($user === null) {
            Session::flash('info', 'Please log in to continue.');
            self::redirect('login?next=' . rawurlencode(self::currentPath()));
        }
        if (!self::can($permission)) {
            Session::flash('error', 'You don’t have permission to open that page.');
            self::redirect(self::can('admin.access') ? 'admin/' : 'dashboard');
        }
        return $user;
    }

    /** For login/register pages: signed-in users go straight to their area. */
    public static function redirectIfSignedIn(): void
    {
        if (self::user() !== null) {
            self::redirect(self::homeFor(self::user()));
        }
    }

    /** Staff without a wallet go to the admin area; customers to their dashboard. */
    public static function homeFor(array $user): string
    {
        return self::rolesCan($user['roles'], 'admin.access') && !in_array('user', $user['roles'], true) ? 'admin/' : 'dashboard';
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . url($path), true, 303);
        exit;
    }

    /** Only accept "next" redirects to our own pages, e.g. "transactions". */
    public static function safeNext(?string $next, string $fallback = 'dashboard'): string
    {
        $next = trim((string) $next, '/');
        return preg_match('#^[a-z0-9][a-z0-9\-/]{0,60}$#', $next) === 1 ? $next : $fallback;
    }

    /** Current page path relative to the app, e.g. "transactions". */
    private static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = parse_url(config('app.url'), PHP_URL_PATH) ?: '';
        $path = substr($path, strlen(rtrim($base, '/')));
        return self::safeNext(preg_replace('/\.php$/', '', $path));
    }
}
