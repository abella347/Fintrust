<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$staff = Auth::requirePermissionPage('fraud.view');

View::render('pages/admin/fraud-alerts', [
    'title'       => 'Fraud alerts',
    'area'        => 'admin',
    'active'      => 'admin-fraud',
    'currentUser' => $staff,
    'bodyClass'   => 'own-unread',
    'pageScripts' => ['shared-ui', 'admin-common', 'admin-fraud-alerts'],
], 'app');
