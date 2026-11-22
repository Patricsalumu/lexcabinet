<div>
    <x-lex.page-header title="Cabinets" subtitle="Plateforme : cabinets et comptes administrateurs. Les données des cabinets (clients, dossiers, factures…) restent confidentielles et ne sont jamais affichées ici.">
        <x-slot:actions>
            <a href="{{ route('platform.firms.create') }}" wire:navigate class="lx-btn-primary">Nouveau cabinet</a>
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />

    <div class="mb-4 max-w-sm">
        <label for="f-search" class="sr-only">Rechercher un cabinet</label>
        <input id="f-search" type="search" wire:model.live.debounce.400ms="search" class="lx-input mt-0" placeholder="Rechercher un cabinet…">
    </div>

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Cabinet</th>
                        <th class="px-4 py-2">Administrateur(s)</th>
                        <th class="px-4 py-2">Utilisateurs</th>
                        <th class="px-4 py-2">Créé le</th>
                        <th class="px-4 py-2">Accès</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($firms as $firm)
                        <tr wire:key="firm-{{ $firm->id }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('platform.firms.show', $firm) }}" wire:navigate class="font-medium text-indigo-800 underline">{{ $firm->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @foreach ($admins[$firm->id] ?? [] as $admin)
                                    <span class="block">{{ $admin->name }} · {{ $admin->email }}</span>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">{{ $firm->users_count }}</td>
                            <td class="px-4 py-3">{{ $firm->created_at->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="lx-badge {{ $firm->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ $firm->is_active ? 'Actif' : 'Suspendu' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucun cabinet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-8">{{ $firms->links() }}</div>
    </x-lex.panel>
</div>
