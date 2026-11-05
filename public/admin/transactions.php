<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;

Session::start();
$staff = Auth::requirePermissionPage('transactions.view');

View::render('pages/admin/transactions', [
    'title'       => 'Transactions',
    'area'        => 'admin',
    'active'      => 'admin-txns',
    'currentUser' => $staff,
    'bodyClass'   => 'own-unread',
    'pageScripts' => ['shared-ui', 'admin-common', 'admin-transactions'],
], 'app');
