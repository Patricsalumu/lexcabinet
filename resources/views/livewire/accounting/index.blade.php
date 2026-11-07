@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Comptabilité" subtitle="Écritures générées depuis les factures et la caisse · plan inspiré du SYSCOHADA · montants en USD, CDF convertis au taux de chaque opération">
        <x-slot:actions>
            <a href="{{ route('accounting.chart') }}" wire:navigate class="lx-btn">Plan comptable</a>
            @if ($canManage)
                <a href="{{ route('accounting.manual') }}" wire:navigate class="lx-btn-primary">Écriture manuelle</a>
            @endif
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />
    @error('period') <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror
    @error('entry') <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror

    <nav class="mb-4 flex flex-wrap gap-1 border-b border-slate-200" aria-label="États comptables">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    @class(['-mb-px border-b-2 px-3 py-2 text-sm', 'border-indigo-800 font-semibold text-slate-900' => $tab === $key, 'border-transparent text-slate-600 hover:text-slate-900' => $tab !== $key])
                    @if ($tab === $key) aria-current="true" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab !== 'periods')
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <button type="button" wire:click="month(-1)" class="lx-btn" aria-label="Mois précédent">‹ Mois précédent</button>
            <div><label for="a-from" class="lx-label">Du</label><input id="a-from" type="date" wire:model.live="from" class="lx-input w-auto"></div>
            <div><label for="a-to" class="lx-label">Au</label><input id="a-to" type="date" wire:model.live="to" class="lx-input w-auto"></div>
            <button type="button" wire:click="month(1)" class="lx-btn" aria-label="Mois suivant">Mois suivant ›</button>
            @if ($tab === 'journal')
                <div>
                    <label for="a-jnl" class="lx-label">Journal</label>
                    <select id="a-jnl" wire:model.live="journal" class="lx-input w-auto">
                        <option value="">Tous</option>
                        @foreach ($journals as $j)<option value="{{ $j->value }}">{{ $j->value }} · {{ $j->label() }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if ($tab === 'ledger')
                <div>
                    <label for="a-acc" class="lx-label">Compte</label>
                    <select id="a-acc" wire:model.live="account" class="lx-input w-auto">
                        <option value="">Tous les comptes mouvementés</option>
                        @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->label }}</option>@endforeach
                    </select>
                </div>
            @endif
            <span class="grow"></span>
            <a href="{{ route('accounting.export', ['report' => $tab, 'format' => 'xlsx'] + array_filter($exportQuery)) }}" class="lx-btn">Excel</a>
            <a href="{{ route('accounting.export', ['report' => $tab, 'format' => 'pdf'] + array_filter($exportQuery)) }}" class="lx-btn">PDF</a>
        </div>
    @endif

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto [&_p]:px-5 [&_p]:py-3 [&_p]:text-sm">
            @switch($tab)
                @case('journal')
                    @include('accounting.partials.journal')
                    @if ($canManage)
                        <div class="border-t border-slate-200 p-5">
                            @if ($reverseId)
                                <form wire:submit="reverseEntry" class="flex flex-wrap items-end gap-2">
                                    <div class="grow"><label for="rv" class="lx-label">Motif de la contre-passation</label><input id="rv" type="text" wire:model="reverseReason" class="lx-input"></div>
                                    <button type="submit" class="lx-btn-primary">Contre-passer</button>
                                    <button type="button" wire:click="$set('reverseId', null)" class="lx-btn">Annuler</button>
                                </form>
                                @error('reverseReason') <p class="lx-error">{{ $message }}</p> @enderror
                            @else
                                <p class="!p-0 text-xs text-slate-500">Écritures manuelles de la période :
                                    @forelse ($entries->filter(fn ($e) => $e->is_manual && ! $e->reverses_id) as $e)
                                        <button type="button" wire:click="askReverse({{ $e->id }})" class="font-mono underline">contre-passer {{ $e->number }}</button>
                                    @empty aucune. @endforelse
                                    Les écritures automatiques se corrigent par un avoir ou une contre-passation de caisse.
                                </p>
                            @endif
                        </div>
                    @endif
                    @break
                @case('ledger')
                    @include('accounting.partials.ledger')
                    @break
                @case('balance')
                    @include('accounting.partials.balance')
                    @break
                @case('result')
                    @include('accounting.partials.result')
                    @break
                @case('periods')
                    <table class="lx-table">
                        <thead><tr><th>Période</th><th class="text-right">Débits $</th><th class="text-right">Crédits $</th><th>Statut</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($periods as $p)
                                <tr wire:key="p-{{ $p['year'] }}-{{ $p['month'] }}">
                                    <td>{{ $p['label'] }}</td>
                                    <td class="text-right font-mono">{{ Money::format($p['debit'], 'USD') }}</td>
                                    <td class="text-right font-mono">{{ Money::format($p['credit'], 'USD') }}</td>
                                    <td>
                                        @if ($p['period']?->isClosed())
                                            <span class="lx-badge bg-slate-100 text-slate-700">Clôturée</span>
                                            <span class="text-xs text-slate-500">le {{ $p['period']->closed_at->format('d/m/Y') }} par {{ $p['period']->closedBy?->name }}</span>
                                        @else
                                            <span class="lx-badge bg-emerald-50 text-emerald-800">Ouverte</span>
                                        @endif
                                        @if (bccomp($p['debit'], $p['credit'], 2) !== 0)<span class="lx-badge bg-red-50 text-red-800">déséquilibrée</span>@endif
                                    </td>
                                    <td class="text-right">
                                        @if ($canClose && $p['elapsed'] && ! $p['period']?->isClosed())
                                            <button type="button" wire:click="closePeriod({{ $p['year'] }}, {{ $p['month'] }})"
                                                    wire:confirm="Clôturer {{ $p['label'] }} ? Plus aucune écriture ne pourra y être passée." class="lx-btn py-1 text-xs">Clôturer</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @break
            @endswitch
        </div>
    </x-lex.panel>
</div>
