<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\Session::start();
App\Core\View::render('pages/privacy', ['title' => 'Privacy policy'], 'public');
