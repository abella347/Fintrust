<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Core\View;

Session::start();

// The token arrives in the emailed link: /reset-password?token=<64 hex chars>.
// We only check its format here; the API checks it against the database hash.
$token = (string) ($_GET['token'] ?? '');
$validShape = preg_match('/^[a-f0-9]{64}$/', $token) === 1;

View::render('pages/auth/reset-password', [
    'title'       => 'Choose a new password',
    'pageScripts' => ['auth'],
    'token'       => $validShape ? $token : '',
], 'auth');
