<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification TOTP (Google Authenticator, Microsoft Authenticator…).
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** URI otpauth:// à saisir ou à transformer en QR code dans l'application d'authentification. */
    public function otpauthUrl(User $user, string $secret, string $issuer): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return preg_match('/^\d{6}$/', $code) === 1
            && $this->google2fa->verifyKey($secret, $code, 1) !== false;
    }

    /** Valide le code saisi et active la double authentification. */
    public function confirm(User $user, string $secret, string $code): bool
    {
        if (! $this->verify($secret, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();

        activity('auth')->causedBy($user)->performedOn($user)->log('Double authentification activée');

        return true;
    }

    /** Code courant (utilisé par les tests). */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
