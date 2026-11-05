<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HttpException;

function balance(PDO $pdo, string $walletNumber): int
{
    $s = $pdo->prepare('SELECT balance_kobo FROM wallets WHERE wallet_number = ?');
    $s->execute([$walletNumber]);
    return (int) $s->fetchColumn();
}

function idem_key(): string
{
    return bin2hex(random_bytes(16));
}

/** Runs $fn and returns the HttpException's error code (fails if nothing is thrown). */
function error_code(callable $fn): string
{
    try {
        $fn();
    } catch (HttpException $e) {
        return $e->errorCode();
    }
    throw new RuntimeException('Expected an HttpException, nothing was thrown');
}

function books_balanced(PDO $pdo): void
{
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn(), 'unbalanced transactions');
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn(), 'wallet drift');
    assert_same(0, (int) $pdo->query('SELECT total_of_all_wallets_kobo FROM v_system_balance')->fetchColumn(), 'system total');
}

function reset_pin_lock(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE transaction_pins SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?')->execute([$userId]);
}

/** Run $fn inside a transaction that is always rolled back. */
function in_rollback(callable $fn): void
{
    try {
        $pdo = Database::connection();
        $pdo->query('SELECT 1 FROM wallets LIMIT 1');
    } catch (Throwable) {
        throw new SkipTest('database not reachable or schema not imported');
    }
    $pdo->beginTransaction();
    try {
        $fn($pdo);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/** Expect a PDOException whose message mentions $needle. $needle may be "a|b": either text is accepted. */
function expect_db_error(PDO $pdo, string $sql, array $params, string $needle): void
{
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        foreach (explode('|', $needle) as $option) {
            if (str_contains($e->getMessage(), $option)) {
                return;
            }
        }
        throw new RuntimeException("Error '{$e->getMessage()}' does not mention '{$needle}'");
    }
    throw new RuntimeException("Expected the database to reject: {$sql}");
}
