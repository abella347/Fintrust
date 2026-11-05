<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Helpers\Money;
use App\Helpers\Validate;
use App\Models\TransactionPresenter;
use App\Models\TransactionRepository;
use App\Models\UserRepository;
use App\Models\WalletRepository;
use Throwable;

/** Wallet details and simulated deposits. */
final class WalletService
{
    private WalletRepository $wallets;

    public function __construct()
    {
        $this->wallets = new WalletRepository();
    }

    public function walletFor(int $userId): array
    {
        return $this->wallets->forUser($userId)
            ?? throw new HttpException(404, 'You don’t have a wallet.', [], 'WALLET_NOT_FOUND');
    }

    /** GET /api/wallet */
    public function summary(int $userId): array
    {
        $w = $this->walletFor($userId);
        $user = (new UserRepository())->findById($userId);
        return [
            'wallet_number'  => $w['wallet_number'],
            'balance_kobo'   => (int) $w['balance_kobo'],
            'status'         => $w['status'],
            'currency'       => $w['currency'],
            'created_at'     => \App\Helpers\Dates::iso($w['created_at']),
            'email_verified' => $user['email_verified_at'] !== null,
            'has_pin'        => (new PinService())->hasPin($userId),
            'has_biometric'  => (new BiometricService())->hasDevices($userId),
            'limits' => [
                'transfer_min_kobo'         => Settings::int('transfer_min_kobo'),
                'transfer_max_single_kobo'  => Settings::int('transfer_max_single_kobo'),
                'transfer_daily_limit_kobo' => Settings::int('transfer_daily_limit_kobo'),
                'sent_today_kobo'           => $this->wallets->sentToday((int) $w['id']),
                'deposit_max_single_kobo'   => Settings::int('deposit_max_single_kobo'),
            ],
        ];
    }

    /**
     * Simulated "Add money".
     * @return array{code:int, message:string, data:array}
     */
    public function deposit(int $userId, array $in, mixed $idempotencyKey): array
    {
        $v = new Validate();
        $amount = $v->amount($in['amount'] ?? null);
        $v->throwIfErrors();

        $min = 10_000;
        $max = Settings::int('deposit_max_single_kobo');
        if ($amount < $min || $amount > $max) {
            $msg = 'Deposits must be between ' . Money::format($min) . ' and ' . Money::format($max) . '.';
            throw new HttpException(422, $msg, ['amount' => $msg], 'AMOUNT_OUT_OF_RANGE');
        }

        $wallet = $this->walletFor($userId);
        $user = (new UserRepository())->findById($userId);
        if ($user['email_verified_at'] === null) {
            throw new HttpException(403, 'Verify your email address before adding money.', [], 'EMAIL_NOT_VERIFIED');
        }

        $idem = new IdempotencyService();
        $replay = $idem->claim($userId, $idempotencyKey, 'deposit', ['amount' => $amount]);
        if ($replay !== null) {
            return $replay;
        }

        try {
            return Database::transaction(function () use ($userId, $wallet, $amount, $idem, $idempotencyKey) {
                $funding = $this->wallets->fundingWallet();
                // Same locking rule as transfers: both rows, lowest id first (the system wallet is id 1).
                $locked = $this->wallets->lockForUpdate([(int) $funding['id'], (int) $wallet['id']]);
                $from = $locked[(int) $funding['id']];
                $to = $locked[(int) $wallet['id']];

                if ($to['status'] !== 'active') {
                    throw new HttpException(423, 'Your wallet is frozen. Deposits are paused.', [], 'WALLET_FROZEN');
                }
                $maxBalance = Settings::int('wallet_max_balance_kobo');
                if ((int) $to['balance_kobo'] + $amount > $maxBalance) {
                    $msg = 'This would take your balance above the ' . Money::format($maxBalance) . ' wallet limit.';
                    throw new HttpException(422, $msg, ['amount' => $msg], 'BALANCE_LIMIT');
                }

                $txns = new TransactionRepository();
                $txn = $txns->create('deposit', 'successful', $amount, (int) $from['id'], (int) $to['id'], $userId, 'Wallet funding (simulated)');
                $after = Ledger::post($txn['id'], $from, $to, $amount);

                Notifier::notify($userId, 'deposit', 'Wallet funded', 'Your wallet was credited with ' . Money::format($amount) . '.', $txn['id']);
                AuditLog::record($userId, 'user', 'deposit.completed', 'transaction', $txn['id'], null, ['reference' => $txn['reference'], 'amount_kobo' => $amount]);

                $data = [
                    'transaction'  => TransactionPresenter::present($txns->findVisibleById($txn['id'], (int) $to['id']), (int) $to['id']),
                    'balance_kobo' => $after['to_after'],
                ];
                $message = 'Wallet funded with ' . Money::format($amount) . '.';
                $idem->complete($userId, (string) $idempotencyKey, $txn['id'], 201, $message, $data);
                return ['code' => 201, 'message' => $message, 'data' => $data];
            }, 3, 'READ COMMITTED');
        } catch (Throwable $e) {
            $idem->release($userId, (string) $idempotencyKey);
            throw $e;
        }
    }
}
