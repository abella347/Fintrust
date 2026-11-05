<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Core\View;

Session::start();

$token = (string) ($_GET['token'] ?? '');

View::render('pages/auth/verify-email', [
    'title'       => 'Verify your email',
    'pageScripts' => ['auth'],
    'token'       => preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : '',
    'email'       => filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '',
], 'auth');
