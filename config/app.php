<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name'     => Env::get('APP_NAME', 'FinTrust'),
    'tagline'  => 'Secure Transactions. Trusted Finance.',
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', 'http://localhost/fintrust'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Lagos'),
    'currency' => 'NGN',

    'session' => [
        'name'         => Env::get('SESSION_NAME', 'fintrust_sid'),
        'idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 15),
        'secure'       => Env::bool('SESSION_SECURE_COOKIE', false),
    ],

    'mail' => [
        'driver' => Env::get('MAIL_DRIVER', 'log'),
        'from'   => Env::get('MAIL_FROM', 'no-reply@fintrust.test'),
    ],
];
