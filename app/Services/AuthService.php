<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Helpers\Str;
use App\Helpers\Validate;
use App\Models\UserRepository;
use App\Models\WalletRepository;
use PDOException;

/** Registration, login and logout. */
final class AuthService
{
    /** A real bcrypt hash of a random string. */
    private const DUMMY_HASH = '$2y$10$Zue1QCBSDLxicNb6naiaXeFYeBUjXyPxqVGmkYvnZs.dwGqqrdcRq';

    private UserRepository $users;
    private WalletRepository $wallets;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->wallets = new WalletRepository();
    }

    /**
     * @return array{user_id:int, email:string}
     */
    public function register(array $in, string $ip): array
    {
        RateLimiter::hit('register:ip:' . $ip, 10, 3600, 'Too many sign-ups from this network. Try again later.');

        $v = new Validate();
        $first = $v->name($in['first_name'] ?? null, 'first_name', 'First name');
        $last  = $v->name($in['last_name'] ?? null, 'last_name', 'Last name');
        $email = $v->email($in['email'] ?? null);
        $phone = $v->phone($in['phone'] ?? null);
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        PasswordPolicy::check($v, $password, 'password', $email);
        if (($in['password_confirmation'] ?? null) !== $password) {
            $v->add('password_confirmation', 'Passwords do not match.');
        }
        $v->accepted($in['accept_terms'] ?? null, 'accept_terms', 'Please accept the terms to continue.');
        $v->throwIfErrors();

        if ($this->users->findByEmail($email)) {
            throw new HttpException(409, 'An account with this email already exists.', ['email' => 'An account with this email already exists. Try logging in.'], 'EMAIL_TAKEN');
        }
        if ($this->users->phoneTaken($phone)) {
            throw new HttpException(409, 'This phone number is already registered.', ['phone' => 'This phone number is already registered.'], 'PHONE_TAKEN');
        }

        // password_hash() picks a strong algorithm (bcrypt today), generates a random
        // salt and stores both inside the hash string. Never store or log the password.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // One transaction: user, role, wallet and welcome note all exist, or none do.
            $result = Database::transaction(function () use ($first, $last, $email, $phone, $hash) {
                $userId = $this->users->create($first, $last, $email, $phone, $hash);
                $this->users->assignRole($userId, 'user');
                $wallet = $this->wallets->createForUser($userId);
                Notifier::notify($userId, 'welcome', 'Welcome to FinTrust',
                    'Your account and wallet (' . $wallet['wallet_number'] . ') are ready. Verify your email and set a transaction PIN to start sending money. All funds are simulated.');
                AuditLog::record($userId, 'user', 'auth.registered', 'user', $userId, null, ['email' => $email, 'wallet_number' => $wallet['wallet_number']]);
                $token = (new TokenService())->issueEmailVerification($userId);
                return ['user_id' => $userId, 'token' => $token];
            });
        } catch (PDOException $e) {
            // Two sign-ups with the same email at the same instant: the UNIQUE index
            // stops the second one even though both passed the check above.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'An account with this email or phone already exists.', [], 'EMAIL_TAKEN');
            }
            throw $e;
        }

        TokenService::sendVerificationEmail($email, $first, $result['token'], $result['user_id']);
        return ['user_id' => $result['user_id'], 'email' => $email];
    }

    /**
     * @return array{user: array, roles: list<string>}
     */
    public function login(array $in, string $ip, string $agent, ?string $deviceToken): array
    {
        $email    = mb_strtolower(Validate::str($in['email'] ?? null));
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if ($email === '' || $password === '') {
            throw new HttpException(422, 'Enter your email and password.', [], 'VALIDATION_FAILED');
        }

        RateLimiter::hit('login:ip:' . $ip, 30, 900, 'Too many login attempts from this network. Wait 15 minutes and try again.');

        $maxAttempts = Settings::int('login_max_attempts');
        $lockMinutes = Settings::int('login_lockout_minutes');
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH); // Same work as a real check -> no timing hint
            $this->users->recordLoginAttempt(null, $email, $ip, $agent, false, 'unknown_email');
            FraudMonitor::loginFailed($ip);
            throw $this->invalidCredentials();
        }

        $userId = (int) $user['id'];

        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > time()) {
            $this->users->recordLoginAttempt($userId, $email, $ip, $agent, false, 'locked');
            $minutes = (int) ceil((strtotime($user['locked_until'] . ' UTC') - time()) / 60);
            throw new HttpException(423, "Too many failed attempts. This account is locked for {$minutes} more minute(s).", [], 'ACCOUNT_LOCKED');
        }

        if (!password_verify($password, $user['password_hash'])) {
            // Count the failure under a row lock so parallel guesses are all counted.
            $locked = Database::transaction(function () use ($userId, $maxAttempts, $lockMinutes) {
                $row = $this->users->lockById($userId);
                $count = (int) $row['failed_login_count'] + 1;
                $lock = $count >= $maxAttempts;
                $this->users->setFailedLogins($userId, $lock ? 0 : $count, $lock ? $lockMinutes : null);
                if ($lock) {
                    AuditLog::record($userId, 'system', 'auth.account_locked', 'user', $userId, null, ['minutes' => $lockMinutes]);
                    Notifier::notify($userId, 'security', 'Account temporarily locked',
                        "We locked your account for {$lockMinutes} minutes after {$maxAttempts} failed login attempts. If this wasn't you, reset your password.");
                }
                return $lock;
            });
            $this->users->recordLoginAttempt($userId, $email, $ip, $agent, false, 'bad_password');
            FraudMonitor::loginFailed($ip);
            if ($locked) {
                FraudMonitor::accountLocked($userId, $maxAttempts, $ip);
                throw new HttpException(423, "Too many failed attempts. This account is locked for {$lockMinutes} minutes.", [], 'ACCOUNT_LOCKED');
            }
            throw $this->invalidCredentials();
        }

        // Correct password from here on. Status is checked only now, so a stranger
        // can't learn that an account is suspended without knowing its password.
        if ($user['status'] !== 'active') {
            $this->users->recordLoginAttempt($userId, $email, $ip, $agent, false, 'suspended');
            throw new HttpException(403, 'This account is suspended. Contact support@fintrust.test.', [], 'ACCOUNT_SUSPENDED');
        }

        // If PHP's default algorithm or cost has been strengthened since this hash
        // was made, upgrade it now while we have the plain password.
        $newHash = password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT) ? password_hash($password, PASSWORD_DEFAULT) : null;
        $this->users->recordLoginSuccess($userId, $newHash);
        $this->users->recordLoginAttempt($userId, $email, $ip, $agent, true, null);

        // New-device detection: a random device cookie, stored only as a hash.
        $isNewDevice = $deviceToken !== null && $this->users->rememberDevice($userId, hash('sha256', $deviceToken), $agent, $ip);
        if ($isNewDevice && $this->users->deviceCount($userId) > 1) {
            Notifier::notify($userId, 'security', 'New login from another device',
                'Your account was accessed from a new device (' . Str::deviceName($agent) . ', IP ' . $ip . '). If this wasn’t you, change your password now.');
        }
        AuditLog::record($userId, 'user', 'auth.login', 'user', $userId, null, ['new_device' => $isNewDevice]);

        Auth::login($userId);
        return ['user' => $user, 'roles' => $this->users->roles($userId)];
    }

    public function logout(): void
    {
        if ($id = Auth::id()) {
            AuditLog::record($id, 'user', 'auth.logout', 'user', $id);
        }
        Auth::logout();
    }

    /** One message for "no such email" and "wrong password": never reveal which accounts exist. */
    private function invalidCredentials(): HttpException
    {
        return new HttpException(401, 'Incorrect email or password.', [], 'INVALID_CREDENTIALS');
    }
}
