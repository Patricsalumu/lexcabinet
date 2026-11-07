@php
    $input = 'mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-700 focus:ring-indigo-700';
    $label = 'block text-sm font-medium text-slate-700';
    $btn = 'rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50';
@endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Un utilisateur peut cumuler plusieurs rôles ; ses droits s'additionnent.">
        <x-slot:actions>
            <button type="button" wire:click="create" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900">
                Nouvel utilisateur
            </button>
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.settings-tabs />
    <x-lex.flash />

    @error('is_active') <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</div> @enderror

    @if ($showForm)
        <x-lex.panel :title="$editingId ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur'" class="mb-6">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="u-name" class="{{ $label }}">Nom</label>
                    <input id="u-name" type="text" wire:model="name" class="{{ $input }}">
                    @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-title" class="{{ $label }}">Fonction</label>
                    <input id="u-title" type="text" wire:model="title" class="{{ $input }}">
                    @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-email" class="{{ $label }}">E-mail</label>
                    <input id="u-email" type="email" wire:model="email" class="{{ $input }}">
                    @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-phone" class="{{ $label }}">Téléphone</label>
                    <input id="u-phone" type="text" wire:model="phone" class="{{ $input }}">
                    @error('phone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="u-password" class="{{ $label }}">{{ $editingId ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe initial' }}</label>
                    <input id="u-password" type="password" wire:model="password" autocomplete="new-password" class="{{ $input }}">
                    @error('password') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="{{ $label }}">Rôles</legend>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach ($roleOptions as $value => $text)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" value="{{ $value }}" wire:model="roles" class="rounded border-slate-300 text-indigo-800">
                                {{ $text }}
                            </label>
                        @endforeach
                    </div>
                    @error('roles') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </fieldset>
                <div class="flex gap-2 sm:col-span-2">
                    <button type="submit" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900">Enregistrer</button>
                    <button type="button" wire:click="cancel" class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Annuler</button>
                </div>
            </form>
        </x-lex.panel>
    @endif

    <x-lex.panel title="Utilisateurs et rôles" note="Aucune suppression : un compte se désactive.">
        <div class="-m-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Nom</th>
                        <th class="px-4 py-2">Fonction</th>
                        <th class="px-4 py-2">Rôles</th>
                        <th class="px-4 py-2">État</th>
                        <th class="px-4 py-2">Dernière connexion</th>
                        <th class="px-4 py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-2">
                                <span class="font-medium text-slate-900">{{ $user->name }}</span>
                                <span class="block text-xs text-slate-500">{{ $user->email }}</span>
                            </td>
                            <td class="px-4 py-2">{{ $user->title ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @foreach ($user->roleLabels() as $role)
                                    <span class="mr-1 inline-block rounded bg-indigo-50 px-2 py-0.5 text-xs text-indigo-900">{{ $role }}</span>
                                @endforeach
                            </td>
                            <td class="px-4 py-2">
                                @if (! $user->is_active)
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">Désactivé</span>
                                @elseif ($user->isLocked())
                                    <span class="rounded bg-red-50 px-2 py-0.5 text-xs text-red-800">Verrouillé</span>
                                @else
                                    <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800">Actif</span>
                                @endif
                                @if ($user->hasTwoFactorEnabled())
                                    <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">2FA</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $user->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <button type="button" wire:click="edit({{ $user->id }})" class="{{ $btn }}">Modifier</button>
                                @if ($user->isLocked())
                                    <button type="button" wire:click="unlock({{ $user->id }})" class="{{ $btn }}">Déverrouiller</button>
                                @endif
                                @if ($user->hasTwoFactorEnabled() && ! $user->is(auth()->user()))
                                    <button type="button" wire:click="resetTwoFactor({{ $user->id }})" wire:confirm="Réinitialiser la double authentification de {{ $user->name }} ?" class="{{ $btn }}">Réinitialiser 2FA</button>
                                @endif
                                @unless ($user->is(auth()->user()))
                                    <button type="button" wire:click="toggleActive({{ $user->id }})"
                                            wire:confirm="{{ $user->is_active ? 'Désactiver' : 'Réactiver' }} le compte de {{ $user->name }} ?"
                                            class="{{ $btn }}">{{ $user->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-lex.panel>
</div>
