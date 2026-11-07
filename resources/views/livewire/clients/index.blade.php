<div>
    <x-lex.page-header title="Clients" :subtitle="$total.' fiche(s) · personnes physiques et morales'">
        @can('create', App\Models\Client::class)
            <x-slot:actions>
                <a href="{{ route('clients.create') }}" wire:navigate class="lx-btn-primary">Nouveau client</a>
            </x-slot:actions>
        @endcan
    </x-lex.page-header>
    <x-lex.flash />

    <div class="mb-4">
        <label for="client-search" class="sr-only">Rechercher un client</label>
        <input id="client-search" type="search" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher un nom, un e-mail, un téléphone, un RCCM" class="lx-input max-w-md">
    </div>

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto">
            <table class="lx-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th class="text-right">Dossiers</th>
                        <th>E-mails</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($clients as $client)
                        <tr wire:key="client-{{ $client->id }}" class="hover:bg-slate-50">
                            <td>
                                <a href="{{ route('clients.show', $client) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ $client->name }}</a>
                                @if ($client->is_prospect)
                                    <span class="lx-badge ml-1 bg-amber-50 text-amber-900">Prospect</span>
                                @endif
                                @if ($client->rccm)
                                    <span class="block font-mono text-xs text-slate-500">{{ $client->rccm }}</span>
                                @endif
                            </td>
                            <td>{{ $client->type->label() }}</td>
                            <td>
                                {{ $client->phone ?? '—' }}
                                <span class="block text-xs text-slate-500">{{ $client->email ?? 'pas d\'e-mail' }}</span>
                            </td>
                            <td class="text-right font-mono">{{ $client->cases_count }}</td>
                            <td>
                                @if ($client->canReceiveEmails())
                                    <span class="lx-badge bg-emerald-50 text-emerald-800">Acceptés</span>
                                @else
                                    <span class="lx-badge bg-amber-50 text-amber-900">Désactivés</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-500">Aucun client ne correspond.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $clients->links() }}</div>
    </x-lex.panel>
</div>
