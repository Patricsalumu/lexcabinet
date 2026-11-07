@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Factures et honoraires" subtitle="Numérotation continue à l'émission, PDF à l'en-tête du cabinet, USD ou CDF">
        @can('create', App\Models\Invoice::class)
            <x-slot:actions>
                <a href="{{ route('invoices.create') }}" wire:navigate class="lx-btn-primary">Nouvelle facture</a>
            </x-slot:actions>
        @endcan
    </x-lex.page-header>
    <x-lex.flash />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <nav class="flex flex-wrap gap-1 border-b border-slate-200" aria-label="Filtres">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')"
                        @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-indigo-800 font-semibold text-slate-900' => $filter === $key, 'border-transparent text-slate-600 hover:text-slate-900' => $filter !== $key])
                        @if ($filter === $key) aria-current="true" @endif>{{ $label }}</button>
            @endforeach
        </nav>
        <label for="inv-search" class="sr-only">Rechercher</label>
        <input id="inv-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Numéro, client, dossier" class="lx-input mt-0 max-w-xs">
    </div>

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto">
            <table class="lx-table">
                <thead>
                    <tr><th>Numéro</th><th>Client · dossier</th><th>Échéance</th><th class="text-right">Total</th><th class="text-right">Reste dû</th><th>Statut</th></tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr wire:key="inv-{{ $invoice->id }}" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap">
                                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-mono hover:underline">{{ $invoice->number ?? 'Brouillon' }}</a>
                                <span class="block text-xs text-slate-500">{{ ($invoice->issue_date ?? $invoice->created_at)->format('d/m/Y') }}</span>
                            </td>
                            <td>
                                <b>{{ $invoice->client->name }}</b>
                                <span class="block font-mono text-xs text-slate-500">{{ $invoice->legalCase?->reference ?? 'sans dossier (consultation)' }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-right font-mono">{{ Money::format((string) $invoice->total, $invoice->currency) }}</td>
                            <td class="whitespace-nowrap text-right font-mono">{{ $invoice->status->isOpen() ? Money::format($invoice->balanceDue(), $invoice->currency) : '—' }}</td>
                            <td><span @class(['lx-badge', $invoice->status->badgeClass(), '!bg-red-50 !text-red-800' => $invoice->isOverdue()])>{{ $invoice->statusLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-slate-500">Aucune facture dans cette liste.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    </x-lex.panel>
</div>
