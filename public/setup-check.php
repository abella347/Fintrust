<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Core\View;
use App\Services\SetupCheck;

Session::start();

// Diagnostics reveal internals, so they don't exist outside local development.
if (!is_local()) {
    View::notFound();
}

View::render('pages/setup-check', [
    'title'  => 'Setup check',
    'checks' => (new SetupCheck())->run(),
], 'public');
