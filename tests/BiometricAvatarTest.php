<?php

declare(strict_types=1);

use App\Core\HttpException;
use App\Helpers\Cbor;
use App\Models\BiometricRepository;
use App\Services\AvatarService;
use App\Services\BiometricService;
use App\Services\TransferService;
use App\Services\WebAuthn;

/** Marks a PHP string as a CBOR *byte* string for cbor_enc(). */
final class TestCborBytes
{
    public function __construct(public string $v) {}
}

function cbor_head(int $major, int $n): string
{
    if ($n < 24) return chr($major << 5 | $n);
    if ($n < 256) return chr($major << 5 | 24) . chr($n);
    if ($n < 65536) return chr($major << 5 | 25) . pack('n', $n);
    return chr($major << 5 | 26) . pack('N', $n);
}

function cbor_enc(mixed $v): string
{
    if ($v instanceof TestCborBytes) return cbor_head(2, strlen($v->v)) . $v->v;
    if (is_int($v)) return $v >= 0 ? cbor_head(0, $v) : cbor_head(1, -1 - $v);
    if (is_string($v)) return cbor_head(3, strlen($v)) . $v;
    if (is_array($v)) {
        $out = cbor_head(5, count($v));
        foreach ($v as $k => $item) $out .= cbor_enc($k) . cbor_enc($item);
        return $out;
    }
    throw new RuntimeException('cbor_enc: unsupported');
}

function fake_device(string $alg = 'ES256'): array
{
    if ($alg === 'ES256') {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $d = openssl_pkey_get_details($key)['ec'];
        $cose = [1 => 2, 3 => -7, -1 => 1, -2 => new TestCborBytes(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), -3 => new TestCborBytes(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))];
    } else {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $d = openssl_pkey_get_details($key)['rsa'];
        $cose = [1 => 3, 3 => -257, -1 => new TestCborBytes($d['n']), -2 => new TestCborBytes($d['e'])];
    }
    return ['key' => $key, 'cose' => $cose, 'id' => random_bytes(32)];
}

function auth_data(int $flags, int $counter, ?array $device = null, ?string $rpId = null): string
{
    $ad = hash('sha256', $rpId ?? WebAuthn::rpId(), true) . chr($flags) . pack('N', $counter);
    if ($device !== null) {
        $ad .= str_repeat("\0", 16) . pack('n', strlen($device['id'])) . $device['id'] . cbor_enc($device['cose']);
    }
    return $ad;
}

function client_data(string $type, string $challenge, ?string $origin = null): string
{
    return json_encode(['type' => $type, 'challenge' => WebAuthn::b64url($challenge), 'origin' => $origin ?? WebAuthn::origin(), 'crossOrigin' => false]);
}

/** What a phone sends back after Face ID: a signature over authData + hash(clientData). */
function sign_approval(array $device, string $challenge, int $counter, array $o = []): array
{
    $ad = auth_data($o['flags'] ?? 0x05, $counter, null, $o['rpId'] ?? null);
    $cd = client_data($o['type'] ?? 'webauthn.get', $challenge, $o['origin'] ?? null);
    openssl_sign($ad . hash('sha256', $cd, true), $signature, $device['key'], OPENSSL_ALGO_SHA256);
    if (!empty($o['tamper'])) $signature[10] = chr(ord($signature[10]) ^ 0xFF);
    return ['id' => WebAuthn::b64url($device['id']), 'clientDataJSON' => WebAuthn::b64url($cd),
            'authenticatorData' => WebAuthn::b64url($ad), 'signature' => WebAuthn::b64url($signature)];
}

function device_row(array $device): array
{
    [$pem, $alg] = WebAuthn::coseToPem(Cbor::decode(cbor_enc($device['cose'])));
    return ['public_key_pem' => $pem, 'algorithm' => $alg, 'sign_count' => 0];
}

function biometric_error(callable $fn): string
{
    try { $fn(); } catch (HttpException $e) { return $e->getMessage(); }
    throw new RuntimeException('Expected the approval to be refused');
}

function png_data_url(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 0, 160, 130));
    ob_start(); imagepng($img); $png = (string) ob_get_clean();
    return 'data:image/png;base64,' . base64_encode($png);
}

return [
    'CBOR: decodes the types WebAuthn uses' => function () {
        $data = cbor_enc(['fmt' => 'none', 'n' => -257, 'big' => 70000, 'b' => new TestCborBytes("\x00\xff")]);
        assert_same(['fmt' => 'none', 'n' => -257, 'big' => 70000, 'b' => "\x00\xff"], Cbor::decode($data));
        assert_throws(RuntimeException::class, fn () => Cbor::decode("\x5a\x00\x00\x00\x09ab"), 'past the end');
    },
    'Face ID: a genuine ES256 approval verifies and the counter advances' => function () {
        $device = fake_device('ES256');
        $challenge = random_bytes(32);
        assert_same(7, WebAuthn::verifyAssertion(device_row($device), sign_approval($device, $challenge, 7), $challenge));
    },
    'Face ID: Windows Hello style RS256 keys convert and verify too' => function () {
        $device = fake_device('RS256');
        $row = device_row($device);
        assert_same(WebAuthn::RS256, $row['algorithm']);
        $challenge = random_bytes(32);
        assert_same(1, WebAuthn::verifyAssertion($row, sign_approval($device, $challenge, 1), $challenge));
    },
    'Face ID: forged or misused approvals are refused' => function () {
        $device = fake_device();
        $row = device_row($device);
        $c = random_bytes(32);
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, random_bytes(32), 1), $c)), 'expired or was meant for something else'), 'other challenge');
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 1, ['origin' => 'https://evil.example']), $c)), 'different website'), 'phishing origin');
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 1, ['rpId' => 'evil.example']), $c)), 'different website'), 'other rpId');
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 1, ['flags' => 0x01]), $c)), 'not confirmed'), 'no user verification');
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 1, ['tamper' => true]), $c)), 'check failed'), 'bad signature');
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 1, ['type' => 'webauthn.create']), $c)), 'Unexpected'), 'wrong type');
        $other = fake_device();
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($other, $c, 1), $c)), 'check failed'), 'someone else\'s key');
        $row['sign_count'] = 9;
        assert_true(str_contains(biometric_error(fn () => WebAuthn::verifyAssertion($row, sign_approval($device, $c, 9), $c)), 'counter'), 'replayed counter');
    },
    'Face ID: registration reads the device public key from the attestation' => function () {
        $device = fake_device();
        $challenge = random_bytes(32);
        $attestation = cbor_enc(['fmt' => 'none', 'attStmt' => [], 'authData' => new TestCborBytes(auth_data(0x45, 0, $device))]);
        $result = WebAuthn::verifyRegistration([
            'id' => WebAuthn::b64url($device['id']),
            'clientDataJSON' => WebAuthn::b64url(client_data('webauthn.create', $challenge)),
            'attestationObject' => WebAuthn::b64url($attestation),
        ], $challenge);
        assert_same(WebAuthn::b64url($device['id']), $result['credential_id']);
        assert_same(WebAuthn::ES256, $result['algorithm']);
        $c2 = random_bytes(32);
        assert_same(3, WebAuthn::verifyAssertion($result + ['sign_count' => 0], sign_approval($device, $c2, 3), $c2), 'stored key verifies later approvals');
    },
    'Face ID transfer: moves money once; the approval cannot be replayed or reused for another transfer' => function () {
        in_rollback(function (PDO $pdo) {
            $_SESSION = [];
            $device = fake_device();
            (new BiometricRepository())->insert(2, ['credential_id' => WebAuthn::b64url($device['id'])] + device_row($device), 'Test phone');
            $bio = new BiometricService();
            $transfer = ['recipient_wallet_number' => '3098765432', 'amount' => '150', 'description' => 'Face ID test'];
            $ben = balance($pdo, '3098765432');

            $key = idem_key();
            $options = $bio->transferOptions(2, $transfer, $key);
            $approval = sign_approval($device, WebAuthn::unb64url($options['challenge']), 1);
            $result = (new TransferService())->transfer(2, $transfer + ['webauthn' => $approval], $key);
            assert_same(201, $result['code']);
            assert_same($ben + 15000, balance($pdo, '3098765432'), 'Ben credited');
            $audit = $pdo->query("SELECT new_values FROM audit_logs WHERE action = 'transfer.completed' ORDER BY id DESC LIMIT 1")->fetchColumn();
            assert_true(str_contains((string) $audit, '"authorised_by":"biometric"'), 'audit records Face ID');

            // Replaying the same signed approval for a new payment: the challenge is gone.
            assert_same('BIOMETRIC_EXPIRED', error_code(fn () => (new TransferService())->transfer(2, $transfer + ['webauthn' => $approval], idem_key())));

            // An approval made for ₦150 can't be used to send ₦5,000.
            $key2 = idem_key();
            $options2 = $bio->transferOptions(2, $transfer, $key2);
            $approval2 = sign_approval($device, WebAuthn::unb64url($options2['challenge']), 2);
            assert_same('BIOMETRIC_FAILED', error_code(fn () => (new TransferService())->transfer(2, ['amount' => '5000'] + $transfer + ['webauthn' => $approval2], $key2)));
            assert_same($ben + 15000, balance($pdo, '3098765432'), 'no extra money moved');
            books_balanced($pdo);
        });
    },
    'Face ID: options need a registered device; registration needs the password and a PIN' => function () {
        in_rollback(function () {
            $_SESSION = [];
            assert_same('NO_BIOMETRICS', error_code(fn () => (new BiometricService())->transferOptions(3, ['recipient_wallet_number' => '3021456789', 'amount' => '150'], idem_key())));
            assert_same('INVALID_PASSWORD', error_code(fn () => (new BiometricService())->registrationOptions(3, 'wrong-password')));
            $options = (new BiometricService())->registrationOptions(3, 'Demo@Pass2026');
            assert_same('platform', $options['authenticatorSelection']['authenticatorAttachment']);
            assert_same('required', $options['authenticatorSelection']['userVerification']);
        });
    },
    'profile picture: re-encoded to a 512px JPEG; fake images, SVG and huge files are refused' => function () {
        in_rollback(function () {
            if (!function_exists('imagecreatetruecolor')) throw new SkipTest('GD not installed');
            $svc = new AvatarService();
            $name = $svc->save(2, png_data_url(900, 600));
            $file = AvatarService::dir() . '/' . $name;
            try {
                assert_same(1, preg_match('/^[a-f0-9]{32}\.jpg$/', $name), 'random .jpg name');
                $info = getimagesize($file);
                assert_same([512, 512, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]], 'square 512px JPEG');
                assert_same($name, $svc->current(2));

                $bad = fn (string $url) => error_code(fn () => $svc->save(2, $url));
                assert_same('VALIDATION_FAILED', $bad('data:image/png;base64,' . base64_encode('<?php echo "hi"; ?>')), 'PHP disguised as PNG');
                assert_same('VALIDATION_FAILED', $bad('data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>')), 'SVG');
                assert_same('VALIDATION_FAILED', $bad('data:image/png;base64,' . base64_encode(str_repeat('A', 2 * 1024 * 1024 + 10))), 'over 2 MB');
                assert_same('VALIDATION_FAILED', $bad('https://example.com/x.png'), 'remote URL');

                $svc->remove(2);
                assert_true(!is_file($file), 'file deleted on removal');
                assert_same(null, $svc->current(2));
            } finally {
                if (is_file($file)) unlink($file);
            }
        });
    },
];
