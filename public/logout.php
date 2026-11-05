<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\AuthService;

Session::start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::verify($_POST['_csrf'] ?? null)) {
    Session::flash('error', 'Your session form expired. Please try logging out again.');
    Auth::redirect(Auth::user() ? 'dashboard' : 'login');
}

// Records the logout in the audit log, then destroys the session and its cookie
// and starts a fresh, empty session (new id) to carry the message below.
(new AuthService())->logout();
Session::flash('success', 'You have been logged out securely.');
Auth::redirect('login');
