<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
restore_error_handler(); // Let the runner report warnings itself

final class SkipTest extends Exception {}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Passes only if $fn throws $class (optionally with a message containing $contains). */
function assert_throws(string $class, callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("Exception message '{$e->getMessage()}' does not contain '{$contains}'");
        }
        return;
    }
    throw new RuntimeException("Expected {$class} to be thrown, nothing was thrown");
}

require __DIR__ . '/helpers.php';

$files = glob(__DIR__ . '/*Test.php') ?: [];
$passed = $failed = $skipped = 0;

foreach ($files as $file) {
    $suite = basename($file, '.php');
    $tests = require $file;
    echo "\n{$suite}\n";
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "  \033[32m✔\033[0m {$name}\n";
        } catch (SkipTest $e) {
            $skipped++;
            echo "  \033[33m-\033[0m {$name} (skipped: {$e->getMessage()})\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  \033[31m✘ {$name}\033[0m\n      {$e->getMessage()}\n";
        }
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);
exit($failed > 0 ? 1 : 0);
