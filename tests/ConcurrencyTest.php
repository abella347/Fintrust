<?php

declare(strict_types=1);

return [
    'concurrent transfers: no overdraft, no double-spend, ledger balanced (--slow)' => function () {
        if (!in_array('--slow', $GLOBALS['argv'] ?? [], true)) {
            throw new SkipTest('add --slow to run');
        }
        $script = base_path('scripts/concurrency-test.php');
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit);
        assert_same(0, $exit, "concurrency script failed:\n      " . implode("\n      ", array_slice($output, -12)));
    },
];
