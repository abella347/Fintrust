<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$staff = Auth::requirePermissionPage('audit.view');

View::render('pages/admin/audit-logs', [
    'title'       => 'Audit log',
    'area'        => 'admin',
    'active'      => 'admin-audit',
    'currentUser' => $staff,
    'bodyClass'   => 'own-unread',
    'pageScripts' => ['shared-ui', 'admin-common', 'admin-audit-logs'],
], 'app');
