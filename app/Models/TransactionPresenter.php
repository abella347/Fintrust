<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class TransactionPresenter
{
    public static function present(array $t, int $viewerWalletId): array
    {
        $outgoing = (int) $t['sender_wallet_id'] === $viewerWalletId;

        if ($t['type'] === 'deposit' || $t['sender_wallet_type'] === 'system') {
            $counterparty = ['name' => 'Wallet funding (simulated)', 'wallet_number' => null];
        } elseif ($outgoing) {
            $counterparty = ['name' => trim($t['recipient_first_name'] . ' ' . $t['recipient_last_name']), 'wallet_number' => $t['recipient_wallet_number']];
        } else {
            $counterparty = ['name' => trim($t['sender_first_name'] . ' ' . $t['sender_last_name']), 'wallet_number' => $t['sender_wallet_number']];
        }

        return [
            'reference'          => $t['reference'],
            'type'               => $t['type'],
            'direction'          => $outgoing ? 'out' : 'in',
            'status'             => $t['status'],
            'amount_kobo'        => (int) $t['amount_kobo'],
            'description'        => $t['description'],
            'counterparty'       => $counterparty,
            'failure_reason'     => $t['failure_reason'],
            'balance_after_kobo' => $t['viewer_balance_after_kobo'] !== null ? (int) $t['viewer_balance_after_kobo'] : null,
            'created_at'         => Dates::iso($t['created_at']),
            'completed_at'       => Dates::iso($t['completed_at']),
        ];
    }
}
