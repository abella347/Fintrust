<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
Auth::redirectIfSignedIn();

View::render('pages/auth/login', [
    'title'       => 'Log in',
    'pageScripts' => ['auth'],
    // Where to go after login. safeNext() only allows our own page paths (no open redirects).
    'next'        => Auth::safeNext($_GET['next'] ?? null),
    'expired'     => isset($_GET['expired']),
], 'auth');
