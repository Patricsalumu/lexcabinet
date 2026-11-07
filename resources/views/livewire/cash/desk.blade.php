@php
    use App\Enums\CashMovementType;
    use App\Enums\MovementStatus;
    use App\Support\Money;
@endphp

<div>
    <x-lex.page-header title="Caisse"
        :subtitle="$session
            ? 'Session ouverte le '.$session->opened_at->format('d/m/Y à H\hi').' par '.$session->openedBy->name.' · fonds comptés '.Money::format((string) $session->opening_usd, 'USD').' et '.Money::format((string) $session->opening_cdf, 'CDF')
            : 'Caisse fermée : ouvrez une session pour enregistrer des opérations en espèces'">
        @if ($canOperate)
            <x-slot:actions>
                @if ($session)
                    <a href="{{ route('cash.receipt') }}" wire:navigate class="lx-btn-primary">Encaisser</a>
                    <a href="{{ route('cash.disbursement') }}" wire:navigate class="lx-btn">Décaisser</a>
                    <button type="button" wire:click="show('exchange')" class="lx-btn">Change</button>
                    <button type="button" wire:click="show('close')" class="lx-btn">Clôturer la caisse</button>
                @else
                    <button type="button" wire:click="show('open')" class="lx-btn-primary">Ouvrir la caisse</button>
                    <a href="{{ route('cash.receipt') }}" wire:navigate class="lx-btn">Encaisser (banque, mobile money)</a>
                @endif
            </x-slot:actions>
        @endif
    </x-lex.page-header>
    <x-lex.flash />
    @if (session('receipt_id'))
        <p class="-mt-2 mb-4 text-sm"><a href="{{ route('cash.receipt-pdf', session('receipt_id')) }}" target="_blank" class="font-semibold text-indigo-900 underline">Imprimer le reçu</a></p>
    @endif
    @foreach (['session', 'amount', 'movement', 'exchange_rate', 'treasury_account_id'] as $field)
        @error($field) <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror
    @endforeach

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($accounts as $row)
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs text-slate-500">{{ $row['account']->name }}</p>
                <p class="font-mono text-xl">{{ Money::format($row['balance'], $row['account']->currency) }}</p>
                <p class="text-xs text-slate-500">{{ $row['account']->type->label() }} · compte {{ $row['account']->ledger_code }}</p>
            </div>
        @endforeach
    </div>
    <p class="-mt-3 mb-6 text-sm text-slate-600">
        Encaissements du jour :
        @forelse ($todayReceipts as $cur => $total)
            <span class="font-mono">{{ Money::format((string) $total, $cur) }}</span>@if (! $loop->last) et @endif
        @empty
            aucun
        @endforelse
    </p>

    {{-- Panneaux d'action --}}
    @if ($panel === 'open')
        <x-lex.panel title="Ouvrir la caisse" class="mb-6 max-w-xl">
            <form wire:submit="openSession" class="grid gap-3 sm:grid-cols-2">
                <div><label for="o-usd" class="lx-label">Fonds comptés USD</label><input id="o-usd" type="text" inputmode="decimal" wire:model="countUsd" class="lx-input">@error('countUsd')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div><label for="o-cdf" class="lx-label">Fonds comptés CDF</label><input id="o-cdf" type="text" inputmode="decimal" wire:model="countCdf" class="lx-input">@error('countCdf')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div class="flex gap-2 sm:col-span-2"><button type="submit" class="lx-btn-primary">Ouvrir</button><button type="button" wire:click="cancelPanel" class="lx-btn">Annuler</button></div>
            </form>
        </x-lex.panel>
    @elseif ($panel === 'close' && $closeExpected)
        <x-lex.panel title="Clôturer la caisse" class="mb-6 max-w-2xl">
            <form wire:submit="closeSession" class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="c-usd" class="lx-label">Comptage réel USD</label>
                    <input id="c-usd" type="text" inputmode="decimal" wire:model.live.debounce.400ms="countUsd" class="lx-input">
                    <p class="lx-hint">Solde théorique : {{ Money::format($closeExpected['USD'], 'USD') }}
                        @if (is_numeric($countUsd)) · écart <b>{{ Money::format(bcsub(Money::normalize((string) $countUsd), $closeExpected['USD'], 2), 'USD') }}</b>@endif</p>
                    @error('countUsd')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-cdf" class="lx-label">Comptage réel CDF</label>
                    <input id="c-cdf" type="text" inputmode="decimal" wire:model.live.debounce.400ms="countCdf" class="lx-input">
                    <p class="lx-hint">Solde théorique : {{ Money::format($closeExpected['CDF'], 'CDF') }}
                        @if (is_numeric($countCdf)) · écart <b>{{ Money::format(bcsub(Money::normalize((string) $countCdf), $closeExpected['CDF'], 2), 'CDF') }}</b>@endif</p>
                    @error('countCdf')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="c-reason" class="lx-label">Motif de l'écart (obligatoire s'il y a un écart)</label>
                    <textarea id="c-reason" wire:model="reason" rows="2" class="lx-input"></textarea>
                    @error('reason')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div class="flex gap-2 sm:col-span-2"><button type="submit" class="lx-btn-primary">Clôturer</button><button type="button" wire:click="cancelPanel" class="lx-btn">Annuler</button></div>
            </form>
        </x-lex.panel>
    @elseif ($panel === 'exchange')
        <x-lex.panel title="Opération de change" class="mb-6 max-w-2xl">
            <form wire:submit="exchange" class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="fx-from" class="lx-label">Sens</label>
                    <select id="fx-from" wire:model.live="fxFrom" class="lx-input"><option value="USD">USD vers CDF</option><option value="CDF">CDF vers USD</option></select>
                </div>
                <div><label for="fx-amount" class="lx-label">Montant cédé ({{ $fxFrom }})</label><input id="fx-amount" type="text" inputmode="decimal" wire:model.live.debounce.400ms="fxAmount" class="lx-input">@error('fxAmount')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div><label for="fx-rate" class="lx-label">Taux (FC pour 1 $)</label><input id="fx-rate" type="text" inputmode="decimal" wire:model.live.debounce.400ms="fxRate" class="lx-input">@error('fxRate')<p class="lx-error">{{ $message }}</p>@enderror</div>
                @if (is_numeric($fxAmount) && is_numeric($fxRate) && (float) $fxRate > 0)
                    @php $to = $fxFrom === 'USD' ? App\Enums\Currency::CDF : App\Enums\Currency::USD; @endphp
                    <p class="text-sm sm:col-span-3">Montant reçu : <b class="font-mono">{{ Money::format(app(App\Services\CashService::class)->convert(Money::normalize((string) $fxAmount), App\Enums\Currency::from($fxFrom), $to, Money::normalize((string) $fxRate, 4)), $to) }}</b></p>
                @endif
                <div class="flex gap-2 sm:col-span-3"><button type="submit" class="lx-btn-primary">Enregistrer le change</button><button type="button" wire:click="cancelPanel" class="lx-btn">Annuler</button></div>
            </form>
        </x-lex.panel>
    @elseif (in_array($panel, ['reverse', 'reject'], true) && $target)
        <x-lex.panel :title="$panel === 'reverse' ? 'Contre-passer une opération' : 'Refuser un décaissement'" class="mb-6 max-w-2xl">
            <p class="mb-3 text-sm">{{ $target->type->label() }} du {{ $target->occurred_at->format('d/m/Y H\hi') }} · {{ $target->label }} · <span class="font-mono">{{ Money::format((string) $target->amount, $target->currency) }}</span></p>
            <form wire:submit="{{ $panel }}" class="grid gap-3">
                <div><label for="r-reason" class="lx-label">Motif</label><textarea id="r-reason" wire:model="reason" rows="2" class="lx-input"></textarea>@error('reason')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div class="flex gap-2"><button type="submit" class="lx-btn-primary">{{ $panel === 'reverse' ? 'Contre-passer' : 'Refuser' }}</button><button type="button" wire:click="cancelPanel" class="lx-btn">Annuler</button></div>
            </form>
        </x-lex.panel>
    @endif

    @if ($pending->isNotEmpty())
        <x-lex.panel title="Décaissements à valider" :note="'au-delà de '.Money::format((string) app(App\Services\SettingsService::class)->get('cash.disbursement_threshold_usd'), 'USD')" class="mb-6">
            @foreach ($pending as $p)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 py-2 last:border-0" wire:key="pending-{{ $p->id }}">
                    <div>
                        <p class="font-semibold"><span class="font-mono">{{ Money::format((string) $p->amount, $p->currency) }}</span> · {{ $p->label }}</p>
                        <p class="text-xs text-slate-500">{{ $p->category->label() }} · {{ $p->beneficiary }} · {{ $p->account->name }} · demandé par {{ $p->creator?->name }} le {{ $p->occurred_at->format('d/m/Y à H\hi') }}</p>
                    </div>
                    @if ($canValidate)
                        <div class="flex gap-2">
                            <button type="button" wire:click="show('reject', {{ $p->id }})" class="lx-btn">Refuser</button>
                            <button type="button" wire:click="approve({{ $p->id }})" class="lx-btn-primary">Valider</button>
                        </div>
                    @else
                        <span class="lx-badge bg-amber-50 text-amber-900">En attente de l'administrateur</span>
                    @endif
                </div>
            @endforeach
        </x-lex.panel>
    @endif

    <x-lex.panel title="Opérations" note="Aucune suppression : une erreur se corrige par une contre-passation.">
        <div class="-m-5 overflow-x-auto">
            <table class="lx-table">
                <thead><tr><th>Date</th><th>Opération</th><th>Libellé</th><th>Compte · mode</th><th class="text-right">Montant</th><th class="text-right">Taux</th><th>Pièce</th><th></th></tr></thead>
                <tbody>
                    @foreach ($movements as $m)
                        <tr wire:key="mv-{{ $m->id }}" @class(['opacity-60' => $m->status === MovementStatus::Rejected || $m->reversal])>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $m->occurred_at->format('d/m/Y') }}<br>{{ $m->occurred_at->format('H\hi') }}</td>
                            <td>
                                {{ $m->type->label() }}
                                @if ($m->status !== MovementStatus::Validated)<span class="lx-badge {{ $m->status === MovementStatus::Pending ? 'bg-amber-50 text-amber-900' : 'bg-slate-100 text-slate-600' }}">{{ $m->status->label() }}</span>@endif
                                @if ($m->reversal)<span class="lx-badge bg-slate-100 text-slate-600">contre-passé</span>@endif
                            </td>
                            <td>
                                {{ $m->label }}
                                @if ($m->client)<span class="block text-xs text-slate-500">{{ $m->client->name }}</span>@endif
                                @if ($m->rejection_reason)<span class="block text-xs text-slate-500">Refus : {{ $m->rejection_reason }}</span>@endif
                            </td>
                            <td class="text-xs">{{ $m->account->name }}@if ($m->counterAccount) → {{ $m->counterAccount->name }}@endif<br><span class="text-slate-500">{{ $m->mode?->label() }} {{ $m->reference }}</span></td>
                            <td class="whitespace-nowrap text-right font-mono">
                                {{ $m->direction === 'in' ? '+' : '−' }}{{ Money::format((string) $m->amount, $m->currency) }}
                                @if ($m->counter_amount)<br>+{{ Money::format((string) $m->counter_amount, $m->counter_currency) }}@endif
                            </td>
                            <td class="text-right font-mono text-xs">{{ Money::formatRate((string) $m->exchange_rate) }}</td>
                            <td class="whitespace-nowrap font-mono text-xs">
                                @if ($m->receipt_number)
                                    <a href="{{ route('cash.receipt-pdf', $m) }}" target="_blank" class="text-indigo-900 underline">{{ $m->receipt_number }}</a>
                                @else
                                    {{ $m->voucher ?? '—' }}
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($canOperate && $m->status === MovementStatus::Validated && ! $m->reversal && in_array($m->type, [CashMovementType::Receipt, CashMovementType::Disbursement, CashMovementType::Exchange], true))
                                    <button type="button" wire:click="show('reverse', {{ $m->id }})" class="text-xs text-red-700 hover:underline">Contre-passer</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $movements->links() }}</div>
    </x-lex.panel>

    @if ($sessions->isNotEmpty())
        <x-lex.panel title="Sessions clôturées" class="mt-6">
            <div class="-m-5 overflow-x-auto">
                <table class="lx-table">
                    <thead><tr><th>Clôture</th><th>Par</th><th class="text-right">Compté USD</th><th class="text-right">Écart USD</th><th class="text-right">Compté CDF</th><th class="text-right">Écart CDF</th><th>Motif</th></tr></thead>
                    <tbody>
                        @foreach ($sessions as $s)
                            <tr>
                                <td class="font-mono text-xs">{{ $s->closed_at->format('d/m/Y H\hi') }}</td>
                                <td>{{ $s->closedBy?->name }}</td>
                                <td class="text-right font-mono">{{ Money::format((string) $s->counted_usd, 'USD') }}</td>
                                <td class="text-right font-mono">{{ Money::format((string) $s->difference_usd, 'USD') }}</td>
                                <td class="text-right font-mono">{{ Money::format((string) $s->counted_cdf, 'CDF') }}</td>
                                <td class="text-right font-mono">{{ Money::format((string) $s->difference_cdf, 'CDF') }}</td>
                                <td>{{ $s->difference_reason ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-lex.panel>
    @endif
</div>
