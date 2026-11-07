<div>
    <h1 class="mb-2 font-display text-xl font-semibold text-slate-900">Vérification en deux étapes</h1>
    <p class="mb-4 text-sm text-slate-600">Saisissez le code à 6 chiffres affiché par votre application d'authentification.</p>

    <form wire:submit="verify" class="flex flex-col gap-4">
        <div>
            <label for="code" class="block text-sm font-medium text-slate-700">Code de vérification</label>
            <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus
                   wire:model="code"
                   class="mt-1 block w-full rounded-md border-slate-300 font-mono text-lg tracking-widest shadow-sm focus:border-indigo-700 focus:ring-indigo-700">
            @error('code') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center justify-between">
            <a href="{{ route('login') }}" class="text-sm text-slate-600 underline hover:text-slate-900">Retour à la connexion</a>
            <button type="submit" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900">Valider</button>
        </div>
    </form>
</div>
