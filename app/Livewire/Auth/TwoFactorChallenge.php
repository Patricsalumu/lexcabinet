<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AuthenticationService;
use App\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Seconde étape de connexion : code TOTP de l'application d'authentification.
 */
#[Layout('layouts.guest')]
#[Title('Vérification en deux étapes')]
class TwoFactorChallenge extends Component
{
    public const SESSION_USER = 'login.two_factor_user_id';

    public const SESSION_REMEMBER = 'login.two_factor_remember';

    public string $code = '';

    public function mount(): void
    {
        if (! session()->has(self::SESSION_USER)) {
            $this->redirectRoute('login');
        }
    }

    public function verify(TwoFactorService $twoFactor, AuthenticationService $auth): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $user = User::query()->find(session(self::SESSION_USER));
        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            session()->forget([self::SESSION_USER, self::SESSION_REMEMBER]);
            $this->redirectRoute('login');

            return;
        }

        $key = 'two-factor:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));

            return;
        }

        if (! $twoFactor->verify($user->two_factor_secret, $this->code)) {
            RateLimiter::hit($key);
            activity('auth')->performedOn($user)->log('Code de double authentification refusé');
            $this->addError('code', trans('auth.two_factor_invalid'));

            return;
        }

        RateLimiter::clear($key);
        $remember = (bool) session(self::SESSION_REMEMBER, false);
        session()->forget([self::SESSION_USER, self::SESSION_REMEMBER]);

        Auth::login($user, $remember);
        session()->regenerate();
        $auth->registerSuccess($user);

        $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
