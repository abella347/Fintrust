<?php

declare(strict_types=1);

use App\Core\Database;

require_once __DIR__ . '/HttpClient.php';

function http_base(): string
{
    static $checked = null;
    $base = rtrim((string) config('app.url'), '/');
    if ($checked === null) {
        $c = new HttpClient($base);
        $c->request('GET', 'api/health');
        $checked = $c->status === 200;
        if ($checked) {
            // A fresh rate-limit window for this machine, so earlier runs don't cause 429s.
            Database::connection()->exec("DELETE FROM rate_limits WHERE bucket_key LIKE '%:ip:127.0.0.1' OR bucket_key LIKE '%:ip:::1' OR bucket_key LIKE 'login:account:%'");
        }
    }
    if (!$checked) {
        throw new SkipTest("site not reachable at {$base} (start Apache)");
    }
    return $base;
}

function http_client(): HttpClient
{
    $c = new HttpClient(http_base());
    $c->page('login');   // Gets a session cookie + CSRF token, like opening the page
    return $c;
}

/** Signs in a client; returns it. */
function http_login(string $email, string $password): HttpClient
{
    $c = http_client();
    $r = $c->api('POST', 'api/auth/login', ['email' => $email, 'password' => $password]);
    assert_same(200, $c->status, "login {$email}: " . ($r['message'] ?? $c->body));
    // Open the page the API sends us to (dashboard or admin/), like the browser does,
    // so we hold the CSRF token of the new, signed-in session.
    $c->page((string) ($r['data']['redirect'] ?? 'dashboard'));
    assert_same(200, $c->status, 'landing page after login');
    return $c;
}

function unique_phone(): string
{
    return '0803' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
}

/** The newest token-bearing link in storage/mail for $email (the log mail driver writes there). */
function mailed_token(string $email, string $path): string
{
    $files = glob(base_path('storage/mail/*.txt')) ?: [];
    rsort($files);
    foreach ($files as $f) {
        $text = (string) file_get_contents($f);
        if (str_contains($text, "To: {$email}") && preg_match('#' . preg_quote($path, '#') . '\?token=([A-Za-z0-9_-]+)#', $text, $m)) {
            return $m[1];
        }
    }
    throw new RuntimeException("No {$path} email found for {$email}");
}

/** Registers a new customer over HTTP. */
function http_customer(array $opt = []): array
{
    $email = 'http-test-' . bin2hex(random_bytes(4)) . '@example.test';
    $password = 'Test@Pass' . random_int(1000, 9999) . 'x';
    Database::connection()->exec("DELETE FROM rate_limits WHERE bucket_key LIKE 'register:ip:%' OR bucket_key LIKE 'login:ip:%' OR bucket_key LIKE 'api:%'");
    $c = http_client();
    $c->page('register');
    $r = $c->api('POST', 'api/auth/register', [
        'first_name' => 'Http', 'last_name' => 'Tester', 'email' => $email, 'phone' => unique_phone(),
        'password' => $password, 'password_confirmation' => $password, 'accept_terms' => true,
    ]);
    assert_same(201, $c->status, 'register: ' . json_encode($r));

    if ($opt['verify'] ?? true) {
        $v = http_client();
        $v->api('POST', 'api/auth/verify-email', ['token' => mailed_token($email, 'verify-email')]);
        assert_same(200, $v->status, 'verify email');
    }
    $c = http_login($email, $password);
    if (isset($opt['pin'])) {
        $c->api('PUT', 'api/user/pin', ['current_password' => $password, 'pin' => $opt['pin'], 'pin_confirmation' => $opt['pin']]);
        assert_same(200, $c->status, 'set PIN');
    }
    if (isset($opt['deposit'])) {
        $c->api('POST', 'api/wallet/deposit', ['amount' => $opt['deposit']], ['Idempotency-Key' => idem_key()]);
        assert_same(201, $c->status, 'deposit');
    }
    $wallet = $c->api('GET', 'api/wallet')['data'];
    return ['client' => $c, 'email' => $email, 'password' => $password, 'wallet' => $wallet];
}

function db_user(string $email): array
{
    $s = Database::connection()->prepare(
        "SELECT u.*, w.wallet_number, w.balance_kobo, w.status AS wallet_status,
                (SELECT GROUP_CONCAT(r.name) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
         FROM users u LEFT JOIN wallets w ON w.user_id = u.id WHERE u.email = ?"
    );
    $s->execute([$email]);
    return $s->fetch() ?: [];
}

return [
    // Registration
    'HTTP: registration creates a user, a hashed password, the user role and a ₦0 wallet' => function () {
        $u = http_customer(['verify' => false]);
        $row = db_user($u['email']);
        assert_true(str_starts_with($row['password_hash'], '$2y$') || str_starts_with($row['password_hash'], '$argon2'), 'password is hashed');
        assert_true(password_verify($u['password'], $row['password_hash']), 'hash verifies');
        assert_true(!str_contains($row['password_hash'], $u['password']), 'plain password not stored');
        assert_same(1, preg_match('/^\d{10}$/', (string) $row['wallet_number']), 'wallet number is 10 digits');
        assert_same(0, (int) $row['balance_kobo']);
        assert_same('user', $row['roles']);
        assert_true($row['email_verified_at'] === null, 'not verified yet');
    },
    'HTTP: duplicate email (any letter case) is refused with 409' => function () {
        $u = http_customer(['verify' => false]);
        $c = http_client();
        $r = $c->api('POST', 'api/auth/register', [
            'first_name' => 'Dup', 'last_name' => 'Licate', 'email' => strtoupper($u['email']), 'phone' => unique_phone(),
            'password' => 'Another@Pass2026', 'password_confirmation' => 'Another@Pass2026', 'accept_terms' => true,
        ]);
        assert_same(409, $c->status);
        assert_same('EMAIL_TAKEN', $r['error']['code']);
    },
    'HTTP: invalid sign-up data returns 422 with a message per field (HTML in names is rejected)' => function () {
        $c = http_client();
        $r = $c->api('POST', 'api/auth/register', [
            'first_name' => '<script>alert(1)</script>', 'last_name' => '', 'email' => 'not-an-email', 'phone' => '123',
            'password' => 'password', 'password_confirmation' => 'different', 'accept_terms' => false,
        ]);
        assert_same(422, $c->status);
        foreach (['first_name', 'last_name', 'email', 'phone', 'password', 'password_confirmation', 'accept_terms'] as $f) {
            assert_true(isset($r['error']['fields'][$f]), "error for {$f}");
        }
    },

    // Login / logout / sessions
    'HTTP: wrong password and unknown email get the identical 401 answer' => function () {
        $c = http_client();
        $a = $c->api('POST', 'api/auth/login', ['email' => 'ben@fintrust.test', 'password' => 'Wrong@Pass2026']);
        $s1 = $c->status;
        $b = $c->api('POST', 'api/auth/login', ['email' => 'nobody-' . bin2hex(random_bytes(3)) . '@example.test', 'password' => 'Wrong@Pass2026']);
        assert_same(401, $s1);
        assert_same(401, $c->status);
        assert_same($a['message'], $b['message'], 'same message');
        assert_same($a['error']['code'], $b['error']['code'], 'same code');
        Database::connection()->exec("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE email = 'ben@fintrust.test'");
    },
    'HTTP: successful login issues a new session id (no session fixation)' => function () {
        $u = http_customer();
        $c = http_client();
        $before = $c->sessionId();
        assert_true($before !== null, 'anonymous session exists');
        $c->api('POST', 'api/auth/login', ['email' => $u['email'], 'password' => $u['password']]);
        assert_same(200, $c->status);
        assert_true($c->sessionId() !== null && $c->sessionId() !== $before, 'session id changed');
        // The old id must not be signed in.
        $old = new HttpClient(http_base());
        $old->cookies[(string) config('app.session.name')] = $before;
        $old->api('GET', 'api/wallet');
        assert_same(401, $old->status, 'old session id is useless');
    },
    'HTTP: protected endpoints return 401 when signed out, and logout ends access' => function () {
        $anon = http_client();
        foreach (['api/wallet', 'api/dashboard', 'api/transactions', 'api/user/profile', 'api/admin/statistics'] as $path) {
            $anon->api('GET', $path);
            assert_same(401, $anon->status, $path);
        }
        $u = http_customer();
        $c = $u['client'];
        $c->api('POST', 'api/auth/logout');
        assert_same(200, $c->status);
        $c->api('GET', 'api/wallet');
        assert_same(401, $c->status, 'after logout');
    },
    'HTTP: protected pages redirect to login when signed out' => function () {
        $c = new HttpClient(http_base());
        $c->request('GET', 'dashboard');
        assert_true(in_array($c->status, [302, 303], true), "redirect, got {$c->status}");
        assert_true(str_contains($c->headers['location'] ?? '', 'login'), 'redirects to login');
        $c->request('GET', 'admin/');
        assert_true(in_array($c->status, [302, 303, 403], true), 'admin area not served');
        assert_true(!str_contains($c->body, 'Audit'), 'no admin content');
    },

    // Account security
    'HTTP: password reset – same answer for unknown emails, single-use link, old sessions signed out' => function () {
        $u = http_customer();
        $signedIn = $u['client'];
        $c = http_client();
        $known = $c->api('POST', 'api/auth/forgot-password', ['email' => $u['email']]);
        $unknown = $c->api('POST', 'api/auth/forgot-password', ['email' => 'nobody-' . bin2hex(random_bytes(3)) . '@example.test']);
        assert_same($known['message'], $unknown['message'], 'no account enumeration');
        assert_same(200, $c->status);

        sleep(1); // password_changed_at has 1-second precision
        $token = mailed_token($u['email'], 'reset-password');
        $new = 'Fresh@Pass' . random_int(1000, 9999) . 'y';
        $c->api('POST', 'api/auth/reset-password', ['token' => $token, 'password' => $new, 'password_confirmation' => $new]);
        assert_same(200, $c->status, 'reset');
        $c->api('POST', 'api/auth/reset-password', ['token' => $token, 'password' => $new . 'z', 'password_confirmation' => $new . 'z']);
        assert_same(422, $c->status, 'link is single-use');

        $signedIn->api('GET', 'api/wallet');
        assert_same(401, $signedIn->status, 'session from before the reset is ended');
        $t = http_client();
        $t->api('POST', 'api/auth/login', ['email' => $u['email'], 'password' => $u['password']]);
        assert_same(401, $t->status, 'old password no longer works');
        http_login($u['email'], $new);
    },
    'HTTP: 5 wrong passwords lock the account, even against the right password' => function () {
        $u = http_customer();
        $c = http_client();
        for ($i = 1; $i <= 5; $i++) {
            $c->api('POST', 'api/auth/login', ['email' => $u['email'], 'password' => 'Wrong@Pass2026']);
        }
        assert_same(423, $c->status, '5th failure locks');
        $r = $c->api('POST', 'api/auth/login', ['email' => $u['email'], 'password' => $u['password']]);
        assert_same(423, $c->status, 'correct password refused while locked');
        assert_same('ACCOUNT_LOCKED', $r['error']['code']);
    },
    'HTTP: changing the password signs out other sessions but keeps this one' => function () {
        $u = http_customer();
        $other = http_login($u['email'], $u['password']);
        sleep(1);
        $new = 'Change@Pass' . random_int(1000, 9999) . 'q';
        $c = $u['client'];
        $c->api('PUT', 'api/user/password', ['current_password' => $u['password'], 'password' => $new, 'password_confirmation' => $new]);
        assert_same(200, $c->status, 'password changed: ' . $c->body);
        $c->api('GET', 'api/wallet');
        assert_same(200, $c->status, 'this session continues');
        $other->api('GET', 'api/wallet');
        assert_same(401, $other->status, 'the other session is ended');
    },
    'HTTP: a session idle for longer than SESSION_IDLE_MINUTES is logged out' => function () {
        $u = http_customer();
        $file = rtrim((string) (ini_get('session.save_path') ?: sys_get_temp_dir()), '/') . '/sess_' . $u['client']->sessionId();
        if (!is_file($file) || !is_writable($file)) {
            throw new SkipTest('PHP session files not reachable from the CLI on this machine');
        }
        // Pretend the last request happened (idle + 1) minutes ago.
        $past = time() - ((int) config('app.session.idle_minutes') + 1) * 60;
        $data = (string) file_get_contents($file);
        file_put_contents($file, preg_replace('/_last_activity\|i:\d+;/', "_last_activity|i:{$past};", $data));
        $u['client']->api('GET', 'api/wallet');
        assert_same(401, $u['client']->status, 'idle session rejected');
    },
    'HTTP: a suspended customer cannot log in' => function () {
        $u = http_customer();
        Database::connection()->prepare("UPDATE users SET status = 'suspended' WHERE email = ?")->execute([$u['email']]);
        $u['client']->api('GET', 'api/wallet');
        assert_same(401, $u['client']->status, 'existing session ended');
        $c = http_client();
        $r = $c->api('POST', 'api/auth/login', ['email' => $u['email'], 'password' => $u['password']]);
        assert_same(403, $c->status);
        assert_same('ACCOUNT_SUSPENDED', $r['error']['code']);
    },

    // Email verification
    'HTTP: unverified accounts cannot move money until the email link is used' => function () {
        $u = http_customer(['verify' => false]);
        $c = $u['client'];
        $r = $c->api('POST', 'api/wallet/deposit', ['amount' => '100'], ['Idempotency-Key' => idem_key()]);
        assert_same(403, $c->status);
        assert_same('EMAIL_NOT_VERIFIED', $r['error']['code']);

        $token = mailed_token($u['email'], 'verify-email');
        $v = http_client();
        $v->api('POST', 'api/auth/verify-email', ['token' => $token]);
        assert_same(200, $v->status);
        $v->api('POST', 'api/auth/verify-email', ['token' => $token]);
        assert_true($v->status >= 400 && $v->status < 500, 'token is single-use');

        $c->api('POST', 'api/wallet/deposit', ['amount' => '100'], ['Idempotency-Key' => idem_key()]);
        assert_same(201, $c->status, 'deposit after verification');
    },

    // CSRF / content type
    'HTTP: state-changing requests need the CSRF header and a JSON body' => function () {
        $u = http_customer(['pin' => '5193', 'deposit' => '500']);
        $c = $u['client'];
        $body = ['recipient_wallet_number' => '3098765432', 'amount' => '100', 'pin' => '5193'];

        $r = $c->api('POST', 'api/transactions/transfer', $body, ['X-CSRF-Token' => '', 'Idempotency-Key' => idem_key()]);
        assert_same(403, $c->status, 'missing token');
        $r = $c->api('POST', 'api/transactions/transfer', $body, ['X-CSRF-Token' => str_repeat('a', 64), 'Idempotency-Key' => idem_key()]);
        assert_same(403, $c->status, 'wrong token');
        assert_same('CSRF_TOKEN_MISMATCH', $r['error']['code']);

        // A classic cross-site <form> post is form-encoded: refused before anything runs.
        $c->request('POST', 'api/transactions/transfer', http_build_query($body),
            ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => (string) $c->csrf]);
        assert_same(415, $c->status, 'form-encoded body');

        $after = $c->api('GET', 'api/wallet/balance')['data']['balance_kobo'];
        assert_same(50000, $after, 'no money moved by any rejected request');
    },

    // RBAC
    'HTTP: role-based access – customers, support and admins see different things' => function () {
        $cust = http_login('ada@fintrust.test', 'Demo@Pass2026');
        $cust->api('GET', 'api/admin/users');
        assert_same(403, $cust->status, 'customer → admin API');
        $cust->request('GET', 'admin/');
        assert_true(in_array($cust->status, [302, 303, 403], true) && !str_contains($cust->headers['location'] ?? '', 'admin'), 'customer → admin page is refused');

        $support = http_login('support@fintrust.test', 'Support@FinTrust2026');
        $support->api('GET', 'api/admin/users');
        assert_same(200, $support->status, 'support can view users');
        $r = $support->api('POST', 'api/admin/wallets/3098765432/freeze', ['reason' => 'Testing permissions only']);
        assert_same(403, $support->status, 'support cannot freeze wallets');
        $support->api('GET', 'api/wallet');
        assert_same(403, $support->status, 'staff have no wallet');

        $admin = http_login('admin@fintrust.test', 'Admin@FinTrust2026');
        $admin->api('POST', 'api/admin/wallets/3098765432/freeze', ['reason' => 'HTTP test: freeze then unfreeze']);
        assert_same(200, $admin->status, 'admin can freeze');
        $admin->api('POST', 'api/admin/wallets/3098765432/unfreeze', ['reason' => 'HTTP test: restore']);
        assert_same(200, $admin->status, 'admin can unfreeze');
        $admin->api('POST', 'api/admin/wallets/3098765432/freeze', ['reason' => '']);
        assert_same(422, $admin->status, 'a reason is required');
    },

    // Transfers / history
    'HTTP: a transfer moves money once, and the same Idempotency-Key replays the same result' => function () {
        $u = http_customer(['pin' => '5193', 'deposit' => '500']);
        $c = $u['client'];
        $pdo = Database::connection();
        $benBefore = balance($pdo, '3098765432');
        $key = idem_key();
        $body = ['recipient_wallet_number' => '3098765432', 'amount' => '100.00', 'pin' => '5193', 'description' => 'HTTP test'];
        $first = $c->api('POST', 'api/transactions/transfer', $body, ['Idempotency-Key' => $key]);
        assert_same(201, $c->status, json_encode($first));
        $second = $c->api('POST', 'api/transactions/transfer', $body, ['Idempotency-Key' => $key]);
        assert_same(201, $c->status, 'replay');
        assert_same($first['data']['transaction']['reference'], $second['data']['transaction']['reference'], 'same reference');
        assert_same(50000 - 10000, $c->api('GET', 'api/wallet/balance')['data']['balance_kobo'], 'debited once');
        assert_same($benBefore + 10000, balance($pdo, '3098765432'), 'credited once');

        // Same key, different body: refused rather than silently replayed.
        $c->api('POST', 'api/transactions/transfer', ['amount' => '200.00'] + $body, ['Idempotency-Key' => $key]);
        assert_true($c->status === 409 || $c->status === 422, 'key reused with a different body is refused');
        books_balanced($pdo);
    },
    'HTTP: insufficient balance and wrong PIN fail without moving money' => function () {
        $u = http_customer(['pin' => '5193', 'deposit' => '150']);
        $c = $u['client'];
        $r = $c->api('POST', 'api/transactions/transfer', ['recipient_wallet_number' => '3098765432', 'amount' => '200', 'pin' => '5193'], ['Idempotency-Key' => idem_key()]);
        assert_same('INSUFFICIENT_FUNDS', $r['error']['code'] ?? json_encode($r));
        $r = $c->api('POST', 'api/transactions/transfer', ['recipient_wallet_number' => '3098765432', 'amount' => '100', 'pin' => '0000'], ['Idempotency-Key' => idem_key()]);
        assert_true(in_array($r['error']['code'] ?? '', ['INVALID_PIN', 'VALIDATION_ERROR'], true), 'wrong PIN refused: ' . json_encode($r));
        assert_same(15000, $c->api('GET', 'api/wallet/balance')['data']['balance_kobo']);
        // The failed attempt is recorded in history as failed.
        $h = $c->api('GET', 'api/transactions?status=failed');
        assert_true($h['data']['pagination']['total'] >= 1, 'failed transfer visible in history');
    },
    'HTTP: a customer cannot read someone else\'s transaction (404, not 403)' => function () {
        $u = http_customer();
        $c = $u['client'];
        $c->api('GET', 'api/transactions/FT20261010TRF3A4B5C6D7');   // Ada → Ben, seeded
        assert_same(404, $c->status);
        $c->api('GET', 'api/transactions/FT00000000NOPE000000000');
        assert_same(404, $c->status, 'unknown reference gives the same answer');
        $list = $c->api('GET', 'api/transactions');
        assert_same(0, $list['data']['pagination']['total'], 'new user sees only their own (none)');
    },

    // Injection / XSS / headers
    'HTTP: SQL injection attempts are treated as plain text' => function () {
        $c = http_client();
        $c->api('POST', 'api/auth/login', ['email' => "admin@fintrust.test' OR '1'='1", 'password' => "' OR '1'='1"]);
        assert_true(in_array($c->status, [401, 422], true), 'login injection: ' . $c->status);
        $u = http_customer(['deposit' => '100']);
        foreach (["' OR 1=1 --", "%' UNION SELECT password_hash FROM users --", "FT' OR 'x'='x"] as $term) {
            $r = $u['client']->api('GET', 'api/transactions?search=' . rawurlencode($term));
            assert_same(200, $u['client']->status, "search {$term}");
            assert_same(0, $r['data']['pagination']['total'], "no rows for {$term}");
            assert_true(!str_contains($u['client']->body, '$2y$'), 'no hashes leaked');
        }
        $u['client']->api('GET', 'api/transactions?type=' . rawurlencode("deposit' OR '1'='1"));
        assert_same(422, $u['client']->status, 'filters are allow-listed');
    },
    'HTTP: script in a description is stored as text and returned as JSON data, never HTML' => function () {
        $u = http_customer(['pin' => '5193', 'deposit' => '100']);
        $c = $u['client'];
        $xss = '<img src=x onerror=alert(1)>';
        $r = $c->api('POST', 'api/transactions/transfer', ['recipient_wallet_number' => '3098765432', 'amount' => '100', 'pin' => '5193', 'description' => $xss], ['Idempotency-Key' => idem_key()]);
        assert_same(201, $c->status, json_encode($r));
        assert_same('application/json', strtok($c->headers['content-type'] ?? '', ';'), 'JSON content type');
        assert_same($xss, $r['data']['transaction']['description'], 'stored verbatim (escaped when displayed)');
        assert_same('nosniff', $c->headers['x-content-type-options'] ?? '', 'nosniff');
    },
    'HTTP: pages send the security headers (CSP, frame, referrer) and API responses are not cached' => function () {
        $c = new HttpClient(http_base());
        $c->request('GET', 'login');
        assert_same(200, $c->status);
        $csp = $c->headers['content-security-policy'] ?? '';
        assert_true(preg_match("/script-src 'self'(;|$)/", $csp) === 1, "scripts only from this site: {$csp}");
        assert_true(str_contains($csp, "frame-ancestors 'none'"), 'frame-ancestors');
        assert_same('DENY', $c->headers['x-frame-options'] ?? '');
        assert_true(isset($c->headers['referrer-policy']), 'referrer policy');
        assert_true(str_contains(strtolower(implode(' ', [$c->headers['set-cookie'] ?? ''])), 'httponly'), 'session cookie is HttpOnly');
        assert_true(str_contains(strtolower($c->headers['set-cookie'] ?? ''), 'samesite=lax'), 'session cookie is SameSite');
        $c->request('GET', 'api/health');
        assert_same('no-store', $c->headers['cache-control'] ?? '', 'API not cached');
    },
    'HTTP: configuration and source files are not served' => function () {
        $c = new HttpClient(http_base());
        foreach (['.env', '../.env', 'app/bootstrap.php', '../app/bootstrap.php', 'config/app.php', 'database/schema.sql',
                  '.htaccess', 'storage/logs/app.log', 'tests/run.php'] as $path) {
            $c->request('GET', $path);
            assert_true($c->status !== 200 || !preg_match('/DB_PASSWORD|<\?php|CREATE TABLE/', $c->body), "{$path} exposed ({$c->status})");
            assert_true(!str_contains($c->body, 'DB_PASSWORD'), "{$path} leaks secrets");
        }
    },
    'HTTP: unknown API routes give a JSON 404 and errors never show stack traces' => function () {
        $c = new HttpClient(http_base());
        $r = $c->api('GET', 'api/does-not-exist');
        assert_same(404, $c->status);
        assert_same(false, $r['success'] ?? null);
        assert_true(!str_contains($c->body, 'Stack trace') && !str_contains($c->body, '.php:'), 'no internals');
    },

    'HTTP: the API-wide limit stops a client sending more than 60 writes a minute' => function () {
        $c = http_client();
        Database::connection()->exec("DELETE FROM rate_limits WHERE bucket_key LIKE 'api:%'");
        $codes = [];
        for ($i = 0; $i < 62; $i++) {
            $c->api('POST', 'api/auth/verify-email', ['token' => 'not-a-real-token']);
            $codes[] = $c->status;
        }
        assert_same(429, end($codes), 'request 62 is refused');
        assert_true(!in_array(429, array_slice($codes, 0, 40), true), 'normal use is not affected');
        Database::connection()->exec("DELETE FROM rate_limits WHERE bucket_key LIKE 'api:%' OR bucket_key LIKE 'reset:%'");
    },

    // Profile pictures and Face ID endpoints
    'HTTP: profile picture upload, private download and removal' => function () {
        if (!function_exists('imagecreatetruecolor')) throw new SkipTest('GD not installed');
        $u = http_customer();
        $c = $u['client'];
        $img = imagecreatetruecolor(300, 200);
        ob_start(); imagepng($img); $png = (string) ob_get_clean();
        $r = $c->api('POST', 'api/user/avatar', ['image' => 'data:image/png;base64,' . base64_encode($png)]);
        assert_same(200, $c->status, json_encode($r));
        $path = (string) parse_url($r['data']['avatar_url'], PHP_URL_PATH) . '?' . parse_url($r['data']['avatar_url'], PHP_URL_QUERY);
        $path = preg_replace('#^.*?/api/#', 'api/', $path);
        $c->request('GET', $path);
        assert_same(200, $c->status);
        assert_same('image/jpeg', $c->headers['content-type'] ?? '');
        assert_same('nosniff', $c->headers['x-content-type-options'] ?? '');
        assert_same(1, preg_match('/^\xFF\xD8/', $c->body), 'JPEG bytes');

        $other = http_customer();                 // Someone else only ever gets their own picture
        $other['client']->request('GET', 'api/user/avatar');
        assert_same(404, $other['client']->status);
        (new HttpClient(http_base()))->request('GET', 'api/user/avatar');

        $c->api('POST', 'api/user/avatar', ['image' => 'data:image/png;base64,' . base64_encode('<script>alert(1)</script>')]);
        assert_same(422, $c->status, 'non-image refused');
        $c->api('DELETE', 'api/user/avatar', []);
        assert_same(200, $c->status);
        $c->request('GET', 'api/user/avatar');
        assert_same(404, $c->status, 'removed');
    },
    'HTTP: Face ID setup needs the password (wrong password does not log you out) and a device for transfers' => function () {
        $u = http_customer(['pin' => '5193']);
        $c = $u['client'];
        $r = $c->api('POST', 'api/user/biometrics/options', ['current_password' => 'Wrong@Pass2026']);
        assert_same(401, $c->status);
        assert_same('INVALID_PASSWORD', $r['error']['code'], 'not UNAUTHENTICATED, so the page stays signed in');
        $c->api('GET', 'api/wallet');
        assert_same(200, $c->status, 'still signed in');
        $r = $c->api('POST', 'api/user/biometrics/options', ['current_password' => $u['password']]);
        assert_same(200, $c->status);
        assert_same('platform', $r['data']['authenticatorSelection']['authenticatorAttachment']);
        $r = $c->api('POST', 'api/transactions/transfer/options', ['recipient_wallet_number' => '3098765432', 'amount' => '150'], ['Idempotency-Key' => idem_key()]);
        assert_same('NO_BIOMETRICS', $r['error']['code'] ?? json_encode($r));
        $r = $c->api('POST', 'api/transactions/transfer', ['recipient_wallet_number' => '3098765432', 'amount' => '150', 'webauthn' => ['id' => 'abc']], ['Idempotency-Key' => idem_key()]);
        assert_true(in_array($r['error']['code'] ?? '', ['BIOMETRIC_EXPIRED', 'EMAIL_NOT_VERIFIED', 'INSUFFICIENT_FUNDS'], true) || $c->status === 403, 'unapproved biometric transfer refused: ' . json_encode($r));
    },

    // Rate limiting (run last: it fills this IP's login window)
    'HTTP: repeated logins from one address are rate limited (429 with Retry-After)' => function () {
        $c = http_client();
        $got429 = false;
        for ($i = 0; $i < 35 && !$got429; $i++) {
            $c->api('POST', 'api/auth/login', ['email' => 'nobody-ratelimit@example.test', 'password' => 'Wrong@Pass2026']);
            $got429 = $c->status === 429;
        }
        assert_true($got429, 'expected 429 within 35 attempts');
        assert_true((int) ($c->headers['retry-after'] ?? 0) > 0, 'Retry-After header');
        // Clean up so the rest of your testing isn't blocked.
        Database::connection()->exec("DELETE FROM rate_limits WHERE bucket_key LIKE 'login:%' OR bucket_key LIKE 'api:%'");
    },
];
