@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Écriture manuelle" subtitle="Montants en USD ; l'écriture doit être équilibrée (total des débits égal au total des crédits)." />
    @error('lines') <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror
    @error('period') <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror

    <form wire:submit="save" class="max-w-5xl">
        <x-lex.panel>
            <div class="mb-4 grid gap-3 sm:grid-cols-[10rem_10rem_minmax(0,1fr)_10rem]">
                <div>
                    <label for="m-jnl" class="lx-label">Journal</label>
                    <select id="m-jnl" wire:model="journal" class="lx-input">@foreach ($journals as $j)<option value="{{ $j->value }}">{{ $j->value }} · {{ $j->label() }}</option>@endforeach</select>
                </div>
                <div><label for="m-date" class="lx-label">Date</label><input id="m-date" type="date" wire:model="date" class="lx-input">@error('date')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div><label for="m-label" class="lx-label">Libellé</label><input id="m-label" type="text" wire:model="label" class="lx-input">@error('label')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div><label for="m-ref" class="lx-label">Pièce</label><input id="m-ref" type="text" wire:model="reference" class="lx-input"></div>
            </div>

            <table class="lx-table">
                <thead><tr><th>Compte</th><th>Libellé de ligne</th><th class="text-right">Débit $</th><th class="text-right">Crédit $</th><th></th></tr></thead>
                <tbody>
                    @foreach ($lines as $i => $line)
                        <tr wire:key="ml-{{ $i }}">
                            <td>
                                <label for="ml-code-{{ $i }}" class="sr-only">Compte</label>
                                <select id="ml-code-{{ $i }}" wire:model="lines.{{ $i }}.code" class="lx-input mt-0">
                                    <option value="">Choisir</option>
                                    @foreach ($accounts as $a)<option value="{{ $a->code }}">{{ $a->code }} · {{ $a->label }}</option>@endforeach
                                </select>
                                @error("lines.$i.code")<p class="lx-error">{{ $message }}</p>@enderror
                            </td>
                            <td><label for="ml-l-{{ $i }}" class="sr-only">Libellé</label><input id="ml-l-{{ $i }}" type="text" wire:model="lines.{{ $i }}.label" class="lx-input mt-0"></td>
                            <td><label for="ml-d-{{ $i }}" class="sr-only">Débit</label><input id="ml-d-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="lines.{{ $i }}.debit" class="lx-input mt-0 text-right">@error("lines.$i.debit")<p class="lx-error">{{ $message }}</p>@enderror</td>
                            <td><label for="ml-c-{{ $i }}" class="sr-only">Crédit</label><input id="ml-c-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="lines.{{ $i }}.credit" class="lx-input mt-0 text-right">@error("lines.$i.credit")<p class="lx-error">{{ $message }}</p>@enderror</td>
                            <td class="text-right">@if (count($lines) > 2)<button type="button" wire:click="removeLine({{ $i }})" class="text-xs text-red-700 hover:underline">Retirer</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="2"><button type="button" wire:click="addLine" class="lx-btn py-1 text-xs">Ajouter une ligne</button></td>
                        <td class="text-right font-mono">{{ Money::format($this->totals['debit'], 'USD') }}</td>
                        <td class="text-right font-mono">{{ Money::format($this->totals['credit'], 'USD') }}</td>
                        <td>
                            @if (bccomp($this->totals['debit'], $this->totals['credit'], 2) === 0 && bccomp($this->totals['debit'], '0', 2) > 0)
                                <span class="lx-badge bg-emerald-50 text-emerald-800">équilibrée</span>
                            @else
                                <span class="lx-badge bg-red-50 text-red-800">déséquilibrée</span>
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary">Enregistrer l'écriture</button>
            <a href="{{ route('accounting.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
