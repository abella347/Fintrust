<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$user = Auth::requirePageUser(); // Redirects to /login when not signed in

View::render('pages/app/notifications', [
    'title'       => 'Notifications',
    'active'      => '',
    'currentUser' => $user,
    'pageScripts' => ['shared-ui', 'notifications'],
    'bodyClass'   => 'own-unread', // This page loads the unread count itself
], 'app');
