@php
    $label = 'block text-xs font-medium text-slate-600';
@endphp

<div>
    <x-lex.page-header :title="$firm->name" :subtitle="'Créé le '.$firm->created_at->format('d/m/Y').' · '.$activeUsersCount.' utilisateur(s) actif(s) sur '.$usersCount">
        <x-slot:actions>
            <a href="{{ route('platform.firms') }}" wire:navigate class="lx-btn">Tous les cabinets</a>
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />
    @if (session('error'))
        <div class="lx-crit mb-4" role="alert">{{ session('error') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-lex.panel title="Administrateurs du cabinet" note="Ils créent les autres comptes du cabinet">
            <ul class="divide-y divide-slate-100">
                @forelse ($admins as $admin)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="adm-{{ $admin->id }}">
                        <span>
                            <span class="block font-medium">{{ $admin->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $admin->email }} @unless ($admin->is_active)· <span class="text-red-700">désactivé</span>@endunless</span>
                        </span>
                        <button type="button" wire:click="resendAccess({{ $admin->id }})" class="lx-btn py-1 text-xs">Renvoyer le lien d'accès</button>
                    </li>
                @empty
                    <li class="py-2 text-sm text-slate-500">Aucun administrateur.</li>
                @endforelse
            </ul>

            <form wire:submit="addAdmin" class="mt-4 grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-3">
                <div>
                    <label for="na-name" class="{{ $label }}">Nom</label>
                    <input id="na-name" type="text" wire:model="admin_name" class="lx-input">
                    @error('admin_name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="na-email" class="{{ $label }}">E-mail</label>
                    <input id="na-email" type="email" wire:model="admin_email" class="lx-input">
                    @error('admin_email')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="na-phone" class="{{ $label }}">Téléphone</label>
                    <input id="na-phone" type="text" wire:model="admin_phone" class="lx-input">
                </div>
                <div class="sm:col-span-3">
                    <button type="submit" class="lx-btn-primary">Ajouter un administrateur</button>
                </div>
            </form>
        </x-lex.panel>

        <div class="grid gap-6">
            <x-lex.panel title="Nom du cabinet">
                <form wire:submit="rename" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <label for="f-name" class="{{ $label }}">Nom affiché sur la plateforme</label>
                        <input id="f-name" type="text" wire:model="name" class="lx-input">
                        @error('name')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="lx-btn">Enregistrer</button>
                </form>
                <p class="mt-2 text-xs text-slate-500">Le nom imprimé sur les factures et les e-mails se règle par le cabinet dans ses paramètres.</p>
            </x-lex.panel>

            <x-lex.panel title="Accès du cabinet">
                @if ($firm->is_active)
                    <p class="mb-3 text-sm"><span class="lx-badge bg-emerald-100 text-emerald-800">Actif</span> Les utilisateurs du cabinet peuvent se connecter.</p>
                    <form wire:submit="suspend" class="grid gap-3">
                        <div>
                            <label for="f-reason" class="{{ $label }}">Motif de la suspension</label>
                            <input id="f-reason" type="text" wire:model="reason" class="lx-input" placeholder="Fin de la période de test, demande du cabinet…">
                            @error('reason')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <button type="submit" class="lx-btn text-red-700" wire:confirm="Suspendre l'accès de ce cabinet ? Ses utilisateurs seront déconnectés ; aucune donnée ne sera supprimée.">Suspendre l'accès</button>
                        </div>
                    </form>
                @else
                    <p class="mb-3 text-sm"><span class="lx-badge bg-red-100 text-red-800">Suspendu</span> Aucun utilisateur du cabinet ne peut se connecter. Les données sont conservées.</p>
                    <button type="button" wire:click="reactivate" class="lx-btn-primary">Rétablir l'accès</button>
                @endif
            </x-lex.panel>
        </div>
    </div>
</div>
