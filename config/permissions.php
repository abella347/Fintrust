<?php

declare(strict_types=1);

return [
    // Customers: their own wallet, transfers and history.
    'user' => [
        'wallet.use',
    ],

    // Support analysts: can look at everything and work fraud alerts,
    // but cannot suspend people or freeze money.
    'support' => [
        'admin.access',
        'users.view',
        'transactions.view',
        'fraud.view',
        'fraud.review',
        'security.view',
        'reports.view',
    ],

    // Administrators: everything support can do, plus account and wallet controls.
    'admin' => [
        'admin.access',
        'users.view',
        'users.manage',      // Suspend / reactivate accounts
        'wallets.manage',    // Freeze / unfreeze wallets
        'transactions.view',
        'fraud.view',
        'fraud.review',
        'security.view',
        'audit.view',
        'reports.view',
    ],
];
