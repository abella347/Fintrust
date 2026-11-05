<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
Auth::redirectIfSignedIn();

View::render('pages/auth/register', ['title' => 'Create your account', 'pageScripts' => ['auth']], 'auth');
