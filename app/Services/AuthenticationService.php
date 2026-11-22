<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Vérification des identifiants : compte actif, verrouillage après
 * User::MAX_FAILED_LOGINS échecs consécutifs (déverrouillage par un administrateur).
 */
class AuthenticationService
{
    /**
     * Vérifie les identifiants sans ouvrir la session.
     *
     * @throws ValidationException
     */
    public function check(string $email, string $password, string $field = 'email'): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([$field => trans('auth.failed')]);
        }

        if ($user->isLocked()) {
            activity('auth')->performedOn($user)->log('Tentative de connexion sur un compte verrouillé');

            throw ValidationException::withMessages([$field => trans('auth.locked')]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user);

            throw ValidationException::withMessages([
                $field => $user->isLocked() ? trans('auth.locked') : trans('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            activity('auth')->performedOn($user)->log('Tentative de connexion sur un compte désactivé');

            throw ValidationException::withMessages([$field => trans('auth.inactive')]);
        }

        if ($user->firm_id !== null && ! $user->firm?->is_active) {
            activity('auth')->performedOn($user)->log('Tentative de connexion : cabinet désactivé');

            throw ValidationException::withMessages([$field => trans('auth.firm_inactive')]);
        }

        return $user;
    }

    /** À appeler une fois la session ouverte (après la double authentification le cas échéant). */
    public function registerSuccess(User $user): void
    {
        $user->forceFill(['failed_login_attempts' => 0, 'last_login_at' => now()])->saveQuietly();

        activity('auth')->causedBy($user)->performedOn($user)->log('Connexion');
    }

    private function registerFailure(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $fill = ['failed_login_attempts' => $attempts];

        if ($attempts >= User::MAX_FAILED_LOGINS) {
            $fill['locked_at'] = now();
        }

        $user->forceFill($fill)->saveQuietly();

        activity('auth')->performedOn($user)
            ->withProperties(['attempts' => $attempts])
            ->log($attempts >= User::MAX_FAILED_LOGINS
                ? 'Compte verrouillé après '.User::MAX_FAILED_LOGINS.' échecs de connexion'
                : 'Échec de connexion');
    }
}
