<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\TransactionRepository;
use App\Models\WalletRepository;

/** Double-entry posting: the only place that changes wallet balances. */
final class Ledger
{
    /**
     * @param array<string, mixed> $from locked wallet row that pays
     * @param array<string, mixed> $to   locked wallet row that receives
     * @return array{from_after:int, to_after:int}
     */
    public static function post(int $transactionId, array $from, array $to, int $amountKobo): array
    {
        if ($amountKobo <= 0) {
            throw new \InvalidArgumentException('Ledger amount must be positive.');
        }
        if (!Database::connection()->inTransaction()) {
            throw new \LogicException('Ledger::post() must run inside a database transaction.');
        }

        $wallets = new WalletRepository();
        $txns = new TransactionRepository();

        // Because the rows are locked, these balances are current and nobody can change them before we commit.
        $fromAfter = (int) $from['balance_kobo'] - $amountKobo;
        $toAfter   = (int) $to['balance_kobo'] + $amountKobo;

        $wallets->debit((int) $from['id'], $amountKobo);
        $wallets->credit((int) $to['id'], $amountKobo);

        $txns->addLedgerEntry($transactionId, (int) $from['id'], 'debit', $amountKobo, $fromAfter);
        $txns->addLedgerEntry($transactionId, (int) $to['id'], 'credit', $amountKobo, $toAfter);

        return ['from_after' => $fromAfter, 'to_after' => $toAfter];
    }
}
