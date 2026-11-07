@php use App\Models\TimeEntry; @endphp

<div>
    <x-lex.page-header title="Dossiers" :subtitle="$ownOnly ? 'Vos dossiers et ceux où vous êtes collaborateur' : 'Affaires suivies par le cabinet'">
        @can('create', App\Models\LegalCase::class)
            <x-slot:actions>
                <a href="{{ route('cases.create') }}" wire:navigate class="lx-btn-primary">Nouveau dossier</a>
            </x-slot:actions>
        @endcan
    </x-lex.page-header>
    <x-lex.flash />

    <div class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_14rem]">
        <div>
            <label for="case-search" class="sr-only">Rechercher</label>
            <input id="case-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Référence, intitulé, client, partie adverse, n° de rôle" class="lx-input mt-0">
        </div>
        <div>
            <label for="case-status" class="sr-only">Statut</label>
            <select id="case-status" wire:model.live="status" class="lx-input mt-0">
                <option value="active">Dossiers actifs</option>
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        @unless ($ownOnly)
            <div>
                <label for="case-lawyer" class="sr-only">Avocat</label>
                <select id="case-lawyer" wire:model.live="lawyer" class="lx-input mt-0">
                    <option value="">Tous les avocats</option>
                    @foreach ($lawyers as $l)
                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
    </div>

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto">
            <table class="lx-table">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Intitulé</th>
                        <th>Matière</th>
                        <th>Juridiction · n° de rôle</th>
                        <th>Avocat</th>
                        <th>Statut</th>
                        <th class="text-right">Temps non facturé</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cases as $case)
                        <tr wire:key="case-{{ $case->id }}" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap font-mono text-xs"><a href="{{ route('cases.show', $case) }}" wire:navigate class="hover:underline">{{ $case->reference }}</a></td>
                            <td>
                                <a href="{{ route('cases.show', $case) }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ $case->title }}</a>
                                <span class="block text-xs text-slate-500">{{ $case->client->name }}</span>
                            </td>
                            <td>{{ $case->matter->label() }}</td>
                            <td>{{ $case->jurisdiction ?? '—' }} <span class="block font-mono text-xs text-slate-500">{{ $case->docket_number }}</span></td>
                            <td>{{ $case->leadLawyer->name }}</td>
                            <td><span class="lx-badge {{ $case->status->badgeClass() }}">{{ $case->status->label() }}</span></td>
                            <td class="whitespace-nowrap text-right font-mono">{{ $case->unbilled_minutes ? TimeEntry::formatMinutes((int) $case->unbilled_minutes) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-slate-500">Aucun dossier ne correspond.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $cases->links() }}</div>
    </x-lex.panel>
</div>
