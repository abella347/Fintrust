<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$staff = Auth::requirePermissionPage('users.view');

View::render('pages/admin/users', [
    'title'       => 'Users',
    'area'        => 'admin',
    'active'      => 'admin-users',
    'currentUser' => $staff,
    'bodyClass'   => 'own-unread',
    'pageScripts' => ['shared-ui', 'admin-common', 'admin-users'],
], 'app');
