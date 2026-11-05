(function () {
    'use strict';
    var FT = window.FinTrust;

    function toBuffer(b64url) {
        var b64 = b64url.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        var bin = atob(b64), bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return bytes.buffer;
    }

    function toB64url(buffer) {
        var bytes = new Uint8Array(buffer), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function friendlyError(err) {
        if (err instanceof FT.ApiError) return err;
        var name = err && err.name;
        var message = name === 'NotAllowedError' ? 'Face ID / fingerprint was cancelled or timed out. Try again, or use your PIN.'
            : name === 'InvalidStateError' ? 'This device is already set up for your account.'
            : name === 'SecurityError' ? 'Face ID / fingerprint only works on the site\'s own address (the one in APP_URL), over HTTPS or on localhost.'
            : name === 'NotSupportedError' ? 'This device or browser doesn\'t support Face ID / fingerprint approval.'
            : 'Face ID / fingerprint didn\'t work on this device. You can use your PIN instead.';
        return new FT.ApiError(message, 0, 'BIOMETRIC_CLIENT');
    }

    function available() {
        if (!window.PublicKeyCredential || !window.isSecureContext || !navigator.credentials) return Promise.resolve(false);
        if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') return Promise.resolve(false);
        return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().catch(function () { return false; });
    }

    function create(o) {
        var publicKey = Object.assign({}, o, {
            challenge: toBuffer(o.challenge),
            user: Object.assign({}, o.user, { id: toBuffer(o.user.id) }),
            excludeCredentials: (o.excludeCredentials || []).map(function (c) { return { type: c.type, id: toBuffer(c.id) }; })
        });
        return navigator.credentials.create({ publicKey: publicKey }).then(function (cred) {
            return {
                id: cred.id,
                clientDataJSON: toB64url(cred.response.clientDataJSON),
                attestationObject: toB64url(cred.response.attestationObject)
            };
        }).catch(function (e) { throw friendlyError(e); });
    }

    function get(o) {
        var publicKey = Object.assign({}, o, {
            challenge: toBuffer(o.challenge),
            allowCredentials: (o.allowCredentials || []).map(function (c) { return { type: c.type, id: toBuffer(c.id), transports: c.transports }; })
        });
        return navigator.credentials.get({ publicKey: publicKey }).then(function (a) {
            return {
                id: a.id,
                clientDataJSON: toB64url(a.response.clientDataJSON),
                authenticatorData: toB64url(a.response.authenticatorData),
                signature: toB64url(a.response.signature)
            };
        }).catch(function (e) { throw friendlyError(e); });
    }

    window.FTBio = { available: available, create: create, get: get };
})();
