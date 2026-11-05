<?php

declare(strict_types=1);

use App\Helpers\Icon;

/** Absolute path inside the project, e.g. base_path('config/app.php'). */
function base_path(string $path = ''): string
{
    return FINTRUST_ROOT . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** Read a config value with dot notation: config('app.session.name'). */
function config(string $key, mixed $default = null): mixed
{
    static $cache = [];

    $parts = explode('.', $key);
    $file  = array_shift($parts);

    if (!array_key_exists($file, $cache)) {
        $path = base_path("config/{$file}.php");
        $cache[$file] = is_file($path) ? require $path : [];
    }

    $value = $cache[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Escape text for safe output inside HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Full URL for a page: url('dashboard') -> http://localhost/fintrust/dashboard */
function url(string $path = ''): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

/** The base URL this request actually arrived at, e.g. http://localhost/practice-projects/fintrust. */
function request_base_url(): ?string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '' || PHP_SAPI === 'cli') {
        return null;
    }
    $https  = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos    = strpos($script, '/public/');
    $path   = $pos === false ? '' : substr($script, 0, $pos);
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($path, '/');
}

function app_url_mismatch(): ?string
{
    if (!is_local() && is_file(base_path('.env'))) {
        return null; // Production with a real .env: never reveal configuration
    }
    $actual = request_base_url();
    if ($actual === null) {
        return null;
    }
    return strtolower(rtrim($actual, '/')) === strtolower((string) config('app.url')) ? null : $actual;
}

/** Address of the signed-in user's own profile picture (null when they have none). */
function avatar_url(?string $avatarPath): ?string
{
    return $avatarPath ? url('api/user/avatar?v=' . substr($avatarPath, 0, 12)) : null;
}

/** URL for a static file, with a cache-busting version based on file time. */
function asset(string $path): string
{
    $file    = base_path('public/assets/' . ltrim($path, '/'));
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/** Inline SVG icon (see App\Helpers\Icon). */
function icon(string $name, string $class = '', int $size = 20): string
{
    return Icon::svg($name, $class, $size);
}

/** Write a line to storage/logs/app-YYYY-MM-DD.log. */
function app_log(string $level, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] %s: %s%s\n",
        gmdate('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
    );
    @file_put_contents(base_path('storage/logs/app-' . gmdate('Y-m-d') . '.log'), $line, FILE_APPEND | LOCK_EX);
}

function is_local(): bool
{
    return config('app.env') === 'local';
}

/** Render an accessible form field (see app/Views/partials/components/field.php). */
function field(array $options): string
{
    return App\Core\View::partial('partials/components/field', $options);
}
