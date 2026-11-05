<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$staff = Auth::requirePermissionPage('reports.view');

View::render('pages/admin/overview', [
    'title'       => 'Overview',
    'area'        => 'admin',
    'active'      => 'admin-dashboard',
    'currentUser' => $staff,
    'bodyClass'   => 'own-unread',
    'pageScripts' => ['shared-ui', 'admin-common', 'admin-overview'],
], 'app');
