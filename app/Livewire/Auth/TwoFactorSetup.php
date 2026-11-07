<?php

namespace App\Livewire\Auth;

use App\Services\SettingsService;
use App\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Activation de la double authentification (obligatoire pour les administrateurs).
 * Pas de QR code (aucun paquet autorisé pour le générer) : la clé se saisit
 * manuellement dans l'application d'authentification.
 */
#[Layout('layouts.app')]
#[Title('Double authentification')]
class TwoFactorSetup extends Component
{
    private const SESSION_SECRET = 'two_factor.pending_secret';

    public string $code = '';

    #[Locked]
    public bool $enabled = false;

    public function mount(TwoFactorService $twoFactor): void
    {
        $this->enabled = auth()->user()->hasTwoFactorEnabled();

        if (! $this->enabled && ! session()->has(self::SESSION_SECRET)) {
            session()->put(self::SESSION_SECRET, $twoFactor->generateSecret());
        }
    }

    public function confirm(TwoFactorService $twoFactor): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $secret = session(self::SESSION_SECRET);
        if ($secret === null || ! $twoFactor->confirm(auth()->user(), $secret, $this->code)) {
            $this->addError('code', trans('auth.two_factor_invalid'));

            return;
        }

        session()->forget(self::SESSION_SECRET);
        session()->flash('status', 'Double authentification activée.');

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Désactivation par l'utilisateur, sur présentation d'un code valide.
     * Refusée si la double authentification est imposée (ADMIN_TWO_FACTOR=true pour un administrateur).
     */
    public function disable(TwoFactorService $twoFactor): void
    {
        $user = auth()->user();
        abort_if(! $user->hasTwoFactorEnabled() || $user->requiresTwoFactor(), 403);

        $this->validate(['code' => ['required', 'string']]);
        if (! $twoFactor->verify($user->two_factor_secret, $this->code)) {
            $this->addError('code', trans('auth.two_factor_invalid'));

            return;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
        activity('auth')->causedBy($user)->performedOn($user)->log('Double authentification désactivée');

        session()->flash('status', 'Double authentification désactivée.');
        $this->redirectRoute('two-factor.setup', navigate: true);
    }

    public function render(TwoFactorService $twoFactor, SettingsService $settings): View
    {
        $secret = session(self::SESSION_SECRET);

        return view('livewire.auth.two-factor-setup', [
            'secret' => $secret ? trim(chunk_split($secret, 4, ' ')) : null,
            'otpauth' => $secret ? $twoFactor->otpauthUrl(auth()->user(), $secret, 'LexCabinet - '.$settings->get('firm.name')) : null,
            'required' => auth()->user()->requiresTwoFactor(),
        ]);
    }
}
