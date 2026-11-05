<?php

declare(strict_types=1);

define('FINTRUST_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = FINTRUST_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require FINTRUST_ROOT . '/app/Helpers/functions.php';

App\Core\Env::load(FINTRUST_ROOT . '/.env');

// Store and calculate every timestamp in UTC; convert to local time only for display.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', base_path('storage/logs/php-errors.log'));

// Turn PHP warnings ("undefined index" etc.) into exceptions so bugs fail loudly.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self'; " .
        "style-src 'self' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data:; " .
        "connect-src 'self'; " .
        "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
    header('X-Content-Type-Options: nosniff');          // Don't guess file types
    header('X-Frame-Options: DENY');                    // Can't be embedded in an iframe (clickjacking)
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header_remove('X-Powered-By');                      // Don't advertise the PHP version
    if (config('app.session.secure')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
