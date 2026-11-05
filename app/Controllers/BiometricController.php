<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\BiometricService;

/** Face ID / fingerprint (WebAuthn) endpoints. */
final class BiometricController
{
    /** GET /api/user/biometrics */
    public function index(Request $r): void
    {
        Response::success(['devices' => (new BiometricService())->devices(Auth::id())]);
    }

    /** POST /api/user/biometrics/options  {current_password} -> options for navigator.credentials.create() */
    public function registrationOptions(Request $r): void
    {
        Response::success((new BiometricService())->registrationOptions(Auth::id(), $r->input('current_password')));
    }

    /** POST /api/user/biometrics  {credential:{id, clientDataJSON, attestationObject}, label} */
    public function register(Request $r): void
    {
        $device = (new BiometricService())->register(Auth::id(), $r->input('credential'), $r->input('label'), $r->userAgent());
        Response::success($device, 'Face ID / fingerprint added. You can now use it to approve transfers.', 201);
    }

    /** DELETE /api/user/biometrics/{id}  {current_password} */
    public function remove(Request $r): void
    {
        (new BiometricService())->remove(Auth::id(), (int) $r->param('id'), $r->input('current_password'));
        Response::success(null, 'Device removed.');
    }

    /** POST /api/transactions/transfer/options  (same body + Idempotency-Key as the transfer) */
    public function transferOptions(Request $r): void
    {
        Response::success((new BiometricService())->transferOptions(Auth::id(), $r->all(), $r->header('Idempotency-Key')));
    }
}
