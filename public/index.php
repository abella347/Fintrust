<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Core\View;

Session::start();

View::render('pages/home', ['title' => config('app.name')], 'public');
