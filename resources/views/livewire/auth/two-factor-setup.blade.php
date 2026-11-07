<div>
    <x-lex.page-header title="Double authentification" />
    <x-lex.flash />

    <x-lex.panel class="max-w-2xl">
        @if ($enabled)
            <p class="text-sm text-slate-700">
                La double authentification est <b>activée</b> sur votre compte. À chaque connexion, un code
                de votre application d'authentification vous sera demandé.
            </p>
            <p class="mt-2 text-sm text-slate-500">Téléphone perdu ou changé ? Demandez à un administrateur de la réinitialiser.</p>
        @else
            @if ($required)
                <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                    En tant qu'administrateur, vous devez activer la double authentification avant d'accéder à l'application.
                </div>
            @endif

            <ol class="list-decimal space-y-3 pl-5 text-sm text-slate-700">
                <li>Installez une application d'authentification sur votre téléphone (Google Authenticator, Microsoft Authenticator…).</li>
                <li>
                    Dans l'application, choisissez « Saisir une clé de configuration » et indiquez :
                    <dl class="mt-2 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
                        <dt class="text-slate-500">Compte</dt><dd>{{ auth()->user()->email }}</dd>
                        <dt class="text-slate-500">Clé</dt><dd class="select-all break-all font-mono text-base text-slate-900">{{ $secret }}</dd>
                        <dt class="text-slate-500">Type</dt><dd>Basée sur l'heure</dd>
                    </dl>
                    <details class="mt-2 text-xs text-slate-500">
                        <summary class="cursor-pointer">Lien de configuration (otpauth)</summary>
                        <code class="select-all break-all">{{ $otpauth }}</code>
                    </details>
                </li>
                <li>
                    Saisissez le code à 6 chiffres affiché :
                    <form wire:submit="confirm" class="mt-2 flex flex-wrap items-start gap-2">
                        <div>
                            <label for="code" class="sr-only">Code de vérification</label>
                            <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                                   wire:model="code"
                                   class="block w-40 rounded-md border-slate-300 font-mono text-lg tracking-widest shadow-sm focus:border-indigo-700 focus:ring-indigo-700">
                            @error('code') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900">Activer</button>
                    </form>
                </li>
            </ol>
        @endif
    </x-lex.panel>
</div>
