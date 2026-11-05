<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Session;
use App\Core\View;

Session::start();

if (!is_local()) {
    View::notFound();
}

View::render('pages/style-guide', [
    'title'       => 'Component library',
    'active'      => 'dashboard',
    'currentUser' => ['first_name' => 'Ada', 'last_name' => 'Nwosu', 'email' => 'ada@fintrust.test'],
    'unreadCount' => 2,
    // Sample rows - escaped like real data. Note the malicious description is shown as text.
    'sampleRows'  => [
        ['ref' => 'FT20261010TRF3A4B5C6D7', 'type' => 'transfer', 'dir' => 'out', 'party' => 'Ben Adeyemi', 'amount' => 1500050, 'status' => 'successful', 'date' => '2026-10-10 13:45:00', 'desc' => 'Lunch and transport'],
        ['ref' => 'FT20261002DEP0A1B2C3D4', 'type' => 'deposit',  'dir' => 'in',  'party' => 'Wallet funding', 'amount' => 25000000, 'status' => 'successful', 'date' => '2026-10-02 09:30:00', 'desc' => 'Simulated deposit'],
        ['ref' => 'FT20261011TRF8E9F0A1B2', 'type' => 'transfer', 'dir' => 'out', 'party' => 'Ada Nwosu', 'amount' => 50000000, 'status' => 'failed', 'date' => '2026-10-11 18:02:00', 'desc' => '<script>alert("xss")</script>'],
    ],
], 'app');
