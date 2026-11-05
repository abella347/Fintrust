<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Helpers\Cbor;
use Throwable;

/** Face ID / fingerprint / Windows Hello through WebAuthn (the browser standard behind "passkeys"). */
final class WebAuthn
{
    public const ES256 = -7;
    public const RS256 = -257;
    private const FLAG_UP = 0x01;   // User present
    private const FLAG_UV = 0x04;   // User verified (face / finger / device PIN)
    private const FLAG_AT = 0x40;   // Attested credential data included

    /* ---------------- helpers ---------------- */

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64url(mixed $text): string
    {
        if (!is_string($text) || $text === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $text) !== 1) {
            throw self::fail('Malformed data from the browser.');
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false) {
            throw self::fail('Malformed data from the browser.');
        }
        return $bytes;
    }

    /** The site's host, e.g. "localhost" (taken from APP_URL, never from request headers). */
    public static function rpId(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** E.g. "http://localhost" or "https://fintrust.example". */
    public static function origin(): string
    {
        $u = parse_url((string) config('app.url'));
        return $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    public static function newChallenge(): string
    {
        return random_bytes(32);
    }

    /* ---------------- registration ---------------- */

    /** Options for navigator.credentials.create(), JSON-ready (binary values base64url). */
    public static function registrationOptions(array $user, string $challenge, array $existingIds): array
    {
        return [
            'challenge' => self::b64url($challenge),
            'rp' => ['name' => (string) config('app.name'), 'id' => self::rpId()],
            'user' => [
                'id' => self::b64url($user['public_id']),     // Opaque id, not personal data
                'name' => $user['email'],
                'displayName' => trim($user['first_name'] . ' ' . $user['last_name']),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ES256], ['type' => 'public-key', 'alg' => self::RS256]],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',      // Built-in Face ID / fingerprint / Windows Hello
                'userVerification' => 'required',
                'residentKey' => 'discouraged',
            ],
            'attestation' => 'none',
            'timeout' => 60000,
            'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $existingIds),
        ];
    }

    /**
     * Verifies the browser's answer to registrationOptions().
     * @param array $credential {id, clientDataJSON, attestationObject} (base64url strings)
     * @return array{credential_id:string, public_key_pem:string, algorithm:int, sign_count:int}
     */
    public static function verifyRegistration(array $credential, string $expectedChallenge): array
    {
        $clientData = self::checkClientData($credential['clientDataJSON'] ?? null, 'webauthn.create', $expectedChallenge);
        unset($clientData);

        try {
            $attestation = Cbor::decode(self::unb64url($credential['attestationObject'] ?? null));
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable) {
            throw self::fail('The device sent an unreadable response.');
        }
        if (!is_array($attestation) || !is_string($attestation['authData'] ?? null)) {
            throw self::fail('The device sent an unreadable response.');
        }
        $auth = self::parseAuthData($attestation['authData']);
        if (!($auth['flags'] & self::FLAG_AT) || $auth['credentialId'] === null || $auth['publicKey'] === null) {
            throw self::fail('The device did not create a key.');
        }
        // The id the browser reports must be the one inside the signed data.
        if (!hash_equals(self::b64url($auth['credentialId']), self::b64url(self::unb64url($credential['id'] ?? null)))) {
            throw self::fail('Credential id mismatch.');
        }
        [$pem, $alg] = self::coseToPem($auth['publicKey']);

        return [
            'credential_id' => self::b64url($auth['credentialId']),
            'public_key_pem' => $pem,
            'algorithm' => $alg,
            'sign_count' => $auth['signCount'],
        ];
    }

    /* ---------------- approving something (a transfer) ---------------- */

    public static function assertionOptions(string $challenge, array $credentialIds): array
    {
        return [
            'challenge' => self::b64url($challenge),
            'rpId' => self::rpId(),
            'allowCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id, 'transports' => ['internal', 'hybrid']], $credentialIds),
            'userVerification' => 'required',
            'timeout' => 60000,
        ];
    }

    /**
     * Verifies a signed approval from a stored credential.
     * @param array $credential DB row: public_key_pem, algorithm, sign_count
     * @param array $assertion  {clientDataJSON, authenticatorData, signature} (base64url)
     * @return int the new signature counter to store
     */
    public static function verifyAssertion(array $credential, array $assertion, string $expectedChallenge): int
    {
        $clientDataRaw = self::unb64url($assertion['clientDataJSON'] ?? null);
        self::checkClientData($assertion['clientDataJSON'] ?? null, 'webauthn.get', $expectedChallenge);
        $authData = self::unb64url($assertion['authenticatorData'] ?? null);
        $signature = self::unb64url($assertion['signature'] ?? null);
        $auth = self::parseAuthData($authData);

        $signed = $authData . hash('sha256', $clientDataRaw, true);
        $ok = openssl_verify($signed, $signature, (string) $credential['public_key_pem'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw self::fail('Face ID / fingerprint check failed. Try again or use your PIN.');
        }

        // Counter: if the device keeps one, it must always go up (a copied key would repeat it).
        $stored = (int) $credential['sign_count'];
        if (($stored > 0 || $auth['signCount'] > 0) && $auth['signCount'] <= $stored) {
            throw self::fail('This device\'s security counter went backwards. Remove it in Settings and add it again.');
        }
        return $auth['signCount'];
    }

    /* ---------------- internals ---------------- */

    private static function checkClientData(mixed $b64, string $type, string $expectedChallenge): array
    {
        $data = json_decode(self::unb64url($b64), true);
        if (!is_array($data)) {
            throw self::fail('The device sent an unreadable response.');
        }
        if (($data['type'] ?? '') !== $type) {
            throw self::fail('Unexpected response type.');
        }
        if (!is_string($data['challenge'] ?? null) || !hash_equals(self::b64url($expectedChallenge), rtrim($data['challenge'], '='))) {
            throw self::fail('This approval has expired or was meant for something else. Please try again.');
        }
        if (($data['origin'] ?? '') !== self::origin()) {
            throw self::fail('This approval came from a different website (' . self::origin() . ' expected). Open the site at the address set in APP_URL.');
        }
        if (($data['crossOrigin'] ?? false) === true) {
            throw self::fail('Approvals from embedded pages are not accepted.');
        }
        return $data;
    }

    /**
     * @return array{flags:int, signCount:int, credentialId:?string, publicKey:?array}
     */
    private static function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) {
            throw self::fail('The device sent an unreadable response.');
        }
        if (!hash_equals(hash('sha256', self::rpId(), true), substr($authData, 0, 32))) {
            throw self::fail('This approval was made for a different website.');
        }
        $flags = ord($authData[32]);
        if (!($flags & self::FLAG_UP) || !($flags & self::FLAG_UV)) {
            throw self::fail('Your face, fingerprint or device PIN was not confirmed.');
        }
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        $credentialId = $publicKey = null;
        if ($flags & self::FLAG_AT) {
            if (strlen($authData) < 55) {
                throw self::fail('The device sent an unreadable response.');
            }
            $idLength = unpack('n', substr($authData, 53, 2))[1];
            $credentialId = substr($authData, 55, $idLength);
            $offset = 55 + $idLength;
            try {
                $publicKey = Cbor::decode($authData, $offset);
            } catch (Throwable) {
                throw self::fail('The device sent an unreadable key.');
            }
        }
        return ['flags' => $flags, 'signCount' => $signCount, 'credentialId' => $credentialId, 'publicKey' => $publicKey];
    }

    /** COSE public key (from the device) -> PEM that openssl_verify understands. */
    public static function coseToPem(mixed $cose): array
    {
        if (!is_array($cose)) {
            throw self::fail('Unsupported key.');
        }
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ES256 && ($cose[-1] ?? null) === 1
            && is_string($cose[-2] ?? null) && strlen($cose[-2]) === 32 && is_string($cose[-3] ?? null) && strlen($cose[-3]) === 32) {
            // SubjectPublicKeyInfo for an uncompressed P-256 point.
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $cose[-2] . $cose[-3];
            return [self::pem($der), self::ES256];
        }
        if ($kty === 3 && $alg === self::RS256 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            $rsaKey = self::derSequence(self::derInteger($cose[-1]) . self::derInteger($cose[-2]));
            $algId = self::derSequence(hex2bin('06092a864886f70d010101') . "\x05\x00");     // rsaEncryption, NULL
            $der = self::derSequence($algId . "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey);
            return [self::pem($der), self::RS256];
        }
        throw self::fail('This device uses a key type FinTrust does not support yet.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $bytes = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $bigEndian): string
    {
        $bigEndian = ltrim($bigEndian, "\x00");
        if ($bigEndian === '' || (ord($bigEndian[0]) & 0x80)) {
            $bigEndian = "\x00" . $bigEndian;            // Keep it positive
        }
        return "\x02" . self::derLength(strlen($bigEndian)) . $bigEndian;
    }

    private static function fail(string $message): HttpException
    {
        return new HttpException(403, $message, [], 'BIOMETRIC_FAILED');
    }
}
