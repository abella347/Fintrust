<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Session;
use App\Helpers\Dates;
use App\Helpers\Str;
use App\Helpers\Validate;
use App\Models\BiometricRepository;
use App\Models\UserRepository;
use PDOException;

/** Account side of Face ID / fingerprint approval (see WebAuthn for the crypto). */
final class BiometricService
{
    private const REG_KEY = 'webauthn_register';
    private const TX_KEY = 'webauthn_transfer';
    private const MAX_DEVICES = 5;

    private BiometricRepository $repo;

    public function __construct()
    {
        $this->repo = new BiometricRepository();
    }

    public function hasDevices(int $userId): bool
    {
        return $this->repo->count($userId) > 0;
    }

    /**
     * @return list<array{id:int,label:string,created_at:?string,last_used_at:?string}>
     */
    public function devices(int $userId): array
    {
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'label' => $r['label'],
            'created_at' => Dates::iso($r['created_at']),
            'last_used_at' => Dates::iso($r['last_used_at']),
        ], $this->repo->forUser($userId));
    }

    /* ---------------- add a device ---------------- */

    public function registrationOptions(int $userId, mixed $password): array
    {
        $user = $this->reauthenticate($userId, $password);
        if (!(new PinService())->hasPin($userId)) {
            throw new HttpException(422, 'Set your transaction PIN first, so you always have a fallback.', [], 'PIN_REQUIRED');
        }
        if ($this->repo->count($userId) >= self::MAX_DEVICES) {
            throw new HttpException(422, 'You can register up to ' . self::MAX_DEVICES . ' devices. Remove one first.', [], 'TOO_MANY_DEVICES');
        }
        $challenge = WebAuthn::newChallenge();
        Session::put(self::REG_KEY, ['challenge' => WebAuthn::b64url($challenge), 'expires' => time() + 300]);
        $existing = array_column($this->repo->forUser($userId), 'credential_id');
        return WebAuthn::registrationOptions($user, $challenge, $existing);
    }

    public function register(int $userId, mixed $credential, mixed $label, string $userAgent): array
    {
        $challenge = $this->takeChallenge(self::REG_KEY);              // Single use, even if verification fails
        if (!is_array($credential)) {
            throw new HttpException(422, 'Missing device response.', [], 'VALIDATION_FAILED');
        }
        $verified = WebAuthn::verifyRegistration($credential, WebAuthn::unb64url($challenge['challenge']));

        $label = trim(Validate::str($label));
        $label = $label === '' ? Str::deviceName($userAgent) : mb_substr(preg_replace('/\s+/u', ' ', $label) ?? '', 0, 40);
        try {
            $id = $this->repo->insert($userId, $verified, $label);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'This device is already registered.', [], 'ALREADY_REGISTERED');
            }
            throw $e;
        }
        AuditLog::record($userId, 'user', 'security.biometric_added', 'user', $userId, null, ['device' => $label]);
        Notifier::notify($userId, 'security', 'Face ID / fingerprint added',
            "\"{$label}\" can now approve your transfers. If this wasn't you, remove it in Settings and change your password.");
        return ['id' => $id, 'label' => $label];
    }

    public function remove(int $userId, int $deviceId, mixed $password): void
    {
        $this->reauthenticate($userId, $password);
        if (!$this->repo->delete($userId, $deviceId)) {
            throw new HttpException(404, 'Device not found.', [], 'NOT_FOUND');
        }
        AuditLog::record($userId, 'user', 'security.biometric_removed', 'user', $userId, null, ['device_id' => $deviceId]);
        Notifier::notify($userId, 'security', 'Face ID / fingerprint removed', 'A device can no longer approve your transfers.');
    }

    /* ---------------- approve a transfer ---------------- */

    /** Challenge for one specific transfer (validated the same way TransferService does). */
    public function transferOptions(int $userId, array $in, mixed $idempotencyKey): array
    {
        [$number, $amount, $description] = $this->transferValues($in);
        $key = $this->key($idempotencyKey);
        $devices = array_column($this->repo->forUser($userId), 'credential_id');
        if ($devices === []) {
            throw new HttpException(422, 'Add Face ID / fingerprint in Settings first, or use your PIN.', [], 'NO_BIOMETRICS');
        }
        $challenge = WebAuthn::newChallenge();
        Session::put(self::TX_KEY, [
            'challenge' => WebAuthn::b64url($challenge),
            'transfer' => self::transferHash($userId, $number, $amount, $description, $key),
            'expires' => time() + 180,
        ]);
        return WebAuthn::assertionOptions($challenge, $devices);
    }

    /** Called by TransferService instead of the PIN check. */
    public function verifyTransfer(int $userId, mixed $assertion, string $number, int $amount, string $description, string $key): void
    {
        $challenge = $this->takeChallenge(self::TX_KEY);
        try {
            if (!hash_equals($challenge['transfer'], self::transferHash($userId, $number, $amount, $description, $key))) {
                throw new HttpException(403, 'This approval was for a different transfer. Please approve again.', [], 'BIOMETRIC_FAILED');
            }
            if (!is_array($assertion) || !is_string($assertion['id'] ?? null)) {
                throw new HttpException(403, 'Missing Face ID / fingerprint approval.', [], 'BIOMETRIC_FAILED');
            }
            $device = $this->repo->find($userId, WebAuthn::b64url(WebAuthn::unb64url($assertion['id'])))
                ?? throw new HttpException(403, 'This device is not registered for your account.', [], 'BIOMETRIC_FAILED');
            $counter = WebAuthn::verifyAssertion($device, $assertion, WebAuthn::unb64url($challenge['challenge']));
            $this->repo->markUsed((int) $device['id'], $counter);
        } catch (HttpException $e) {
            AuditLog::record($userId, 'user', 'security.biometric_failed', 'user', $userId, null, ['reason' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function transferHash(int $userId, string $number, int $amount, string $description, string $key): string
    {
        return hash('sha256', json_encode([$userId, $number, $amount, $description, $key]));
    }

    /* ---------------- internals ---------------- */

    private function takeChallenge(string $slot): array
    {
        $c = Session::get($slot);
        Session::forget($slot);
        if (!is_array($c) || ($c['expires'] ?? 0) < time()) {
            throw new HttpException(403, 'The approval request expired. Please try again.', [], 'BIOMETRIC_EXPIRED');
        }
        return $c;
    }

    private function transferValues(array $in): array
    {
        $v = new Validate();
        $number = $v->walletNumber($in['recipient_wallet_number'] ?? null);
        $amount = $v->amount($in['amount'] ?? null);
        $description = $v->text($in['description'] ?? null, 'description', 140, 'Description');
        $v->throwIfErrors();
        return [$number, $amount, $description];
    }

    private function key(mixed $idempotencyKey): string
    {
        if (!is_string($idempotencyKey) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey) !== 1) {
            throw new HttpException(400, 'Missing or invalid Idempotency-Key header.', [], 'IDEMPOTENCY_KEY_REQUIRED');
        }
        return $idempotencyKey;
    }

    private function reauthenticate(int $userId, mixed $password): array
    {
        RateLimiter::hit('reauth:user:' . $userId, 10, 900, 'Too many password attempts. Wait 15 minutes and try again.');
        $user = (new UserRepository())->findById($userId);
        if (!is_string($password) || !password_verify($password, $user['password_hash'])) {
            throw new HttpException(401, 'Your current password is incorrect.', ['current_password' => 'Incorrect password.'], 'INVALID_PASSWORD');
        }
        return $user;
    }
}
