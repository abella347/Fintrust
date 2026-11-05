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

/** Wallet-to-wallet transfers. */
final class TransferService
{
    private WalletRepository $wallets;
    private TransactionRepository $txns;
    private IdempotencyService $idempotency;

    public function __construct()
    {
        $this->wallets = new WalletRepository();
        $this->txns = new TransactionRepository();
        $this->idempotency = new IdempotencyService();
    }

    /**
     * @return array{code:int, message:string, data:array}
     */
    public function transfer(int $userId, array $in, mixed $idempotencyKey): array
    {
        // Validate input
        $v = new Validate();
        $number = $v->walletNumber($in['recipient_wallet_number'] ?? null);
        $amount = $v->amount($in['amount'] ?? null);
        $description = $v->text($in['description'] ?? null, 'description', 140, 'Description');
        // Authorised either by the PIN or by Face ID / fingerprint (WebAuthn approval).
        $biometric = is_array($in['webauthn'] ?? null) ? $in['webauthn'] : null;
        $pin = $biometric === null ? $v->pin($in['pin'] ?? null) : '';
        $v->throwIfErrors();

        $sender = $this->wallets->forUser($userId)
            ?? throw new HttpException(404, 'You don’t have a wallet.', [], 'WALLET_NOT_FOUND');

        // Idempotency: is this a retry of a payment we already made?
        $fingerprint = ['to' => $number, 'amount' => $amount, 'description' => $description];
        $replay = $this->idempotency->claim($userId, $idempotencyKey, 'transfer', $fingerprint);
        if ($replay !== null) {
            return $replay; // Same response as the first time; no money moves
        }

        try {
            $result = $this->execute($userId, $sender, $number, $amount, $description, $pin, (string) $idempotencyKey, $biometric);
        } catch (Throwable $e) {
            // Nothing was committed for this attempt, so the key can be retried.
            $this->idempotency->release($userId, (string) $idempotencyKey);
            if ($e instanceof HttpException && $e->errorCode() === 'LIMIT_EXCEEDED') {
                FraudMonitor::limitExceeded($userId, $amount, $e->getMessage());
            }
            throw $e;
        }

        // Fraud rules run after the money transaction has committed, so a slow or
        // failing check can never hold locks or undo a customer's transfer.
        FraudMonitor::afterTransfer($userId, $result['transaction_id'], $result['failed'] === null ? 'successful' : 'failed', $amount);

        if ($result['failed'] !== null) {
            // A failed transaction was recorded for the audit trail, but no money moved.
            $this->idempotency->release($userId, (string) $idempotencyKey);
            throw $result['failed'];
        }
        return $result['response'];
    }

    private function execute(int $userId, array $sender, string $number, int $amount, string $description, string $pin, string $key, ?array $biometric = null): array
    {
        $user = (new UserRepository())->findById($userId);
        if ($user['email_verified_at'] === null) {
            throw new HttpException(403, 'Verify your email address before sending money.', [], 'EMAIL_NOT_VERIFIED');
        }

        // Recipient exists and isn't the sender
        if ($number === $sender['wallet_number']) {
            throw new HttpException(422, 'You can’t send money to your own wallet.', ['recipient_wallet_number' => 'This is your own wallet.'], 'SELF_TRANSFER');
        }
        $recipient = $this->wallets->findByNumberWithOwner($number)
            ?? throw new HttpException(404, 'No FinTrust wallet has this number.', ['recipient_wallet_number' => 'No FinTrust wallet has this number.'], 'WALLET_NOT_FOUND');

        if ($sender['status'] !== 'active') {
            throw new HttpException(423, 'Your wallet is ' . $sender['status'] . '. Transfers are paused.', [], 'WALLET_FROZEN');
        }

        // PIN (counts failures and locks after too many), or a Face ID /
        // fingerprint approval signed for exactly this transfer
        if ($biometric !== null) {
            (new BiometricService())->verifyTransfer($userId, $biometric, $number, $amount, $description, $key);
        } else {
            (new PinService())->verify($userId, $pin);
        }

        // Per-transfer limits
        $min = Settings::int('transfer_min_kobo');
        $max = Settings::int('transfer_max_single_kobo');
        if ($amount < $min || $amount > $max) {
            $msg = 'Transfers must be between ' . Money::format($min) . ' and ' . Money::format($max) . '.';
            throw new HttpException(422, $msg, ['amount' => $msg], 'LIMIT_EXCEEDED');
        }
        $dailyLimit = Settings::int('transfer_daily_limit_kobo');
        $maxBalance = Settings::int('wallet_max_balance_kobo');
        $recipientName = trim($recipient['first_name'] . ' ' . $recipient['last_name']);
        $senderName = trim($user['first_name'] . ' ' . $user['last_name']);

        return Database::transaction(function () use ($userId, $sender, $recipient, $amount, $description, $key, $dailyLimit, $maxBalance, $recipientName, $senderName, $biometric) {
            $senderId = (int) $sender['id'];
            $recipientId = (int) $recipient['id'];

            // Lock both rows. Another transfer touching either wallet now waits here until we COMMIT.
            $locked = $this->wallets->lockForUpdate([$senderId, $recipientId]);
            $from = $locked[$senderId];
            $to = $locked[$recipientId];

            if ($from['status'] !== 'active') {
                throw new HttpException(423, 'Your wallet is frozen. Transfers are paused.', [], 'WALLET_FROZEN');
            }

            // Daily limit, counted while our wallet is locked so parallel transfers can't both squeeze under it.
            if ($this->wallets->sentToday($senderId) + $amount > $dailyLimit) {
                $msg = 'This would exceed your daily transfer limit of ' . Money::format($dailyLimit) . '.';
                throw new HttpException(422, $msg, ['amount' => $msg], 'LIMIT_EXCEEDED');
            }

            // Business failures we record as failed transactions (no ledger entries, no money moved).
            $failure = null;
            if ($to['status'] !== 'active' || $recipient['user_status'] !== 'active' || (int) $to['balance_kobo'] + $amount > $maxBalance) {
                $failure = ['RECIPIENT_UNAVAILABLE', 423, 'This wallet can’t receive money right now.', ['recipient_wallet_number' => 'This wallet can’t receive money right now.'], 'WALLET_UNAVAILABLE'];
            } elseif ((int) $from['balance_kobo'] < $amount) {
                // The balance check happens on the locked row, so it can't be stale.
                $failure = ['INSUFFICIENT_FUNDS', 422, 'Insufficient balance. Available: ' . Money::format((int) $from['balance_kobo']) . '.', ['amount' => 'More than your available balance.'], 'INSUFFICIENT_FUNDS'];
            }

            if ($failure !== null) {
                $txn = $this->txns->create('transfer', 'failed', $amount, $senderId, $recipientId, $userId, $description, $failure[0]);
                Notifier::notify($userId, 'transfer_failed', 'Transfer failed',
                    'Your transfer of ' . Money::format($amount) . ' to ' . $recipientName . ' failed: '
                    . ($failure[0] === 'INSUFFICIENT_FUNDS' ? 'insufficient balance.' : 'the recipient cannot receive funds right now.'), $txn['id']);
                AuditLog::record($userId, 'user', 'transfer.failed', 'transaction', $txn['id'], null,
                    ['reference' => $txn['reference'], 'amount_kobo' => $amount, 'reason' => $failure[0]]);
                // Returned, not thrown: throwing here would ROLL BACK the failed-transaction record.
                return ['failed' => new HttpException($failure[1], $failure[2], $failure[3], $failure[4]), 'response' => null, 'transaction_id' => $txn['id']];
            }

            // Reference + balanced double-entry posting.
            $txn = $this->txns->create('transfer', 'successful', $amount, $senderId, $recipientId, $userId, $description);
            $after = Ledger::post($txn['id'], $from, $to, $amount);

            Notifier::notify($userId, 'transfer_sent', 'Transfer successful',
                'You sent ' . Money::format($amount) . ' to ' . $recipientName . ' (' . $to['wallet_number'] . ').', $txn['id']);
            Notifier::notify((int) $recipient['user_id'], 'transfer_received', 'Money received',
                'You received ' . Money::format($amount) . ' from ' . $senderName . '.', $txn['id']);
            AuditLog::record($userId, 'user', 'transfer.completed', 'transaction', $txn['id'], null,
                ['reference' => $txn['reference'], 'amount_kobo' => $amount, 'to_wallet' => $to['wallet_number'],
                 'authorised_by' => $biometric !== null ? 'biometric' : 'pin']);

            $data = [
                'transaction' => TransactionPresenter::present($this->txns->findVisibleById($txn['id'], $senderId), $senderId),
                'balance_kobo' => $after['from_after'],
            ];
            // Saved in the same commit as the money movement (see IdempotencyService).
            $this->idempotency->complete($userId, $key, $txn['id'], 201, 'Transfer successful.', $data);

            return ['failed' => null, 'response' => ['code' => 201, 'message' => 'Transfer successful.', 'data' => $data], 'transaction_id' => $txn['id']];
        }, 3, 'READ COMMITTED');
    }

    /** Masked owner name for "confirm recipient" (e.g. "Ben A."). */
    public function lookup(int $userId, mixed $walletNumber): array
    {
        // Rate limited: otherwise someone could script through numbers to harvest names.
        RateLimiter::hit('lookup:user:' . $userId, 30, 60, 'Too many lookups. Please wait a minute.');

        $v = new Validate();
        $number = $v->walletNumber($walletNumber);
        $v->throwIfErrors();

        $own = $this->wallets->forUser($userId);
        if ($own && $own['wallet_number'] === $number) {
            throw new HttpException(422, 'You can’t send money to your own wallet.', ['recipient_wallet_number' => 'This is your own wallet.'], 'SELF_TRANSFER');
        }
        $w = $this->wallets->findByNumberWithOwner($number);
        if (!$w) {
            throw new HttpException(404, 'No FinTrust wallet has this number.', ['recipient_wallet_number' => 'No FinTrust wallet has this number.'], 'WALLET_NOT_FOUND');
        }
        if ($w['status'] !== 'active' || $w['user_status'] !== 'active') {
            throw new HttpException(422, 'This wallet can’t receive money right now.', ['recipient_wallet_number' => 'This wallet can’t receive money right now.'], 'WALLET_UNAVAILABLE');
        }
        return ['wallet_number' => $number, 'account_name' => $w['first_name'] . ' ' . mb_substr($w['last_name'], 0, 1) . '.'];
    }
}
