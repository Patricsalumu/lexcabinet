@php
    $label = 'block text-xs font-medium text-slate-600';
@endphp

<div>
    <x-lex.page-header title="Nouveau cabinet" subtitle="Le cabinet est créé prêt à l'emploi : plan comptable SYSCOHADA, modèles d'e-mails, caisses USD et CDF. Son administrateur reçoit un lien sécurisé pour définir son mot de passe, puis crée lui-même les autres comptes." />

    <form wire:submit="save" class="grid max-w-3xl gap-6">
        <x-lex.panel title="Cabinet">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="c-name" class="{{ $label }}">Nom du cabinet</label>
                    <input id="c-name" type="text" wire:model="name" class="lx-input" required>
                    @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-email" class="{{ $label }}">E-mail du cabinet (adresse de réponse des clients)</label>
                    <input id="c-email" type="email" wire:model="email" class="lx-input">
                    @error('email')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-phone" class="{{ $label }}">Téléphone</label>
                    <input id="c-phone" type="text" wire:model="phone" class="lx-input">
                </div>
                <div class="sm:col-span-2">
                    <label for="c-address" class="{{ $label }}">Adresse</label>
                    <input id="c-address" type="text" wire:model="address" class="lx-input">
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">L'administrateur du cabinet complète ensuite l'identité (RCCM, NIF, logo…), les moyens de paiement et le taux de change dans ses paramètres.</p>
        </x-lex.panel>

        <x-lex.panel title="Premier administrateur du cabinet">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="a-name" class="{{ $label }}">Nom</label>
                    <input id="a-name" type="text" wire:model="admin_name" class="lx-input" required>
                    @error('admin_name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="a-email" class="{{ $label }}">E-mail (identifiant de connexion)</label>
                    <input id="a-email" type="email" wire:model="admin_email" class="lx-input" required>
                    @error('admin_email')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="a-phone" class="{{ $label }}">Téléphone</label>
                    <input id="a-phone" type="text" wire:model="admin_phone" class="lx-input">
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">Aucun mot de passe n'est saisi ni envoyé : l'administrateur définit le sien par le lien reçu (valable 72 heures, renvoyable).</p>
        </x-lex.panel>

        <div class="flex gap-3">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">Créer le cabinet</button>
            <a href="{{ route('platform.firms') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
