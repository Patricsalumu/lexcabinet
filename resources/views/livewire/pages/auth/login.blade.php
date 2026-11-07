<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Connexion')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        if ($this->form->authenticate() === 'two-factor') {
            $this->redirectRoute('two-factor.challenge', navigate: true);

            return;
        }

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <h1 class="mb-4 font-display text-xl font-semibold text-slate-900">Connexion</h1>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login">
        <div>
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input wire:model="form.email" id="email" class="mt-1 block w-full" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Mot de passe" />
            <x-text-input wire:model="form.password" id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <div class="mt-4 block">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-slate-300 text-indigo-800 shadow-sm focus:ring-indigo-700" name="remember">
                <span class="ms-2 text-sm text-slate-600">Rester connecté</span>
            </label>
        </div>

        <div class="mt-4 flex items-center justify-end">
            <a class="rounded-md text-sm text-slate-600 underline hover:text-slate-900" href="{{ route('password.request') }}" wire:navigate>
                Mot de passe oublié ?
            </a>

            <x-primary-button class="ms-3">Se connecter</x-primary-button>
        </div>
    </form>

    <p class="mt-6 text-xs text-slate-500">Les comptes sont créés par l'administrateur du cabinet. Après 5 échecs, le compte est verrouillé.</p>
</div>
