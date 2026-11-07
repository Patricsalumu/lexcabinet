<?php

namespace App\Livewire\Forms;

use App\Livewire\Auth\TwoFactorChallenge;
use App\Services\AuthenticationService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string|email', as: 'adresse e-mail')]
    public string $email = '';

    #[Validate('required|string', as: 'mot de passe')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Vérifie les identifiants (compte actif, non verrouillé). Renvoie « two-factor »
     * si un code TOTP doit encore être saisi, « authenticated » sinon.
     *
     * @throws ValidationException
     */
    public function authenticate(): string
    {
        $this->ensureIsNotRateLimited();

        $auth = app(AuthenticationService::class);

        try {
            $user = $auth->check($this->email, $this->password, 'form.email');
        } catch (ValidationException $e) {
            RateLimiter::hit($this->throttleKey());

            throw $e;
        }

        RateLimiter::clear($this->throttleKey());

        // Tout utilisateur qui a activé la double authentification doit saisir son code.
        if ($user->hasTwoFactorEnabled()) {
            session()->put(TwoFactorChallenge::SESSION_USER, $user->id);
            session()->put(TwoFactorChallenge::SESSION_REMEMBER, $this->remember);

            return 'two-factor';
        }

        Auth::login($user, $this->remember);
        $auth->registerSuccess($user);

        return 'authenticated';
    }

    /**
     * Limitation anti-force brute par adresse IP (en plus du verrouillage du compte).
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 10)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
