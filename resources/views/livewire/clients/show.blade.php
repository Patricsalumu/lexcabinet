@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header :title="$client->name" :subtitle="$client->type->label().($client->is_prospect ? ' · prospect' : '')">
        <x-slot:actions>
            @can('create', App\Models\LegalCase::class)
                <a href="{{ route('cases.create', ['client' => $client->id]) }}" wire:navigate class="lx-btn">Ouvrir un dossier</a>
            @endcan
            @can('update', $client)
                <a href="{{ route('clients.edit', $client) }}" wire:navigate class="lx-btn-primary">Modifier</a>
            @endcan
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,26rem)_1fr]">
        <x-lex.panel title="Fiche">
            <dl class="lx-kv">
                <dt>Téléphone</dt><dd>{{ $client->phone ?? '—' }}</dd>
                <dt>E-mail</dt>
                <dd>
                    {{ $client->email ?? '—' }}
                    @unless ($client->canReceiveEmails())
                        <span class="lx-badge ml-1 bg-amber-50 text-amber-900">notifications désactivées</span>
                    @endunless
                </dd>
                <dt>Adresse</dt><dd>{{ $client->address ?? '—' }}</dd>
                @if ($client->type === App\Enums\ClientType::Company)
                    <dt>RCCM</dt><dd class="font-mono">{{ $client->rccm ?? '—' }}</dd>
                    <dt>ID. Nat.</dt><dd class="font-mono">{{ $client->id_nat ?? '—' }}</dd>
                    <dt>Représentant</dt><dd>{{ $client->representative ?? '—' }}</dd>
                @else
                    <dt>Pièce d'identité</dt>
                    <dd>{{ $client->id_document_type?->label() ?? '—' }} <span class="font-mono">{{ $client->id_document_number }}</span></dd>
                @endif
                <dt>Consentement e-mail</dt><dd>{{ $client->email_consent ? 'Oui' : 'Non' }}</dd>
                <dt>Créée le</dt><dd>{{ $client->created_at->format('d/m/Y') }}</dd>
            </dl>
        </x-lex.panel>

        <div class="flex flex-col gap-6">
            <x-lex.panel title="Dossiers" :note="$cases->count().' dossier(s)'">
                @forelse ($cases as $case)
                    <div class="flex flex-wrap items-baseline gap-2 border-b border-slate-100 py-2 last:border-0">
                        <span class="lx-badge {{ $case->status->badgeClass() }}">{{ $case->status->label() }}</span>
                        <a href="{{ route('cases.show', $case) }}" wire:navigate class="font-mono text-xs text-slate-600 hover:underline">{{ $case->reference }}</a>
                        <a href="{{ route('cases.show', $case) }}" wire:navigate class="font-medium hover:underline">{{ $case->title }}</a>
                        <span class="text-xs text-slate-500">· {{ $case->leadLawyer->name }} · {{ $case->fee_type->label() }} {{ Money::format((string) $case->fee_amount, $case->currency) }}{{ $case->fee_type->amountSuffix() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Aucun dossier.</p>
                @endforelse
            </x-lex.panel>

            <x-lex.panel title="Rendez-vous, factures et paiements">
                <p class="text-sm text-slate-500">L'historique des rendez-vous (phase 3), des factures (phase 4) et des paiements avec le solde dû (phase 5) s'affichera ici.</p>
            </x-lex.panel>
        </div>
    </div>
</div>
