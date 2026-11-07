@php
    use App\Support\Money;
    $input = 'mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-700 focus:ring-indigo-700';
    $label = 'block text-sm font-medium text-slate-700';
@endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Nombre de francs congolais (FC) pour 1 dollar américain. Le taux est mémorisé sur chaque facture et chaque mouvement de caisse." />
    <x-lex.settings-tabs />
    <x-lex.flash />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <x-lex.panel title="Saisir un taux">
            <form wire:submit="save" class="flex flex-col gap-4">
                <div>
                    <label for="rate-date" class="{{ $label }}">Date</label>
                    <input id="rate-date" type="date" wire:model="rateDate" max="{{ today()->toDateString() }}" class="{{ $input }}">
                    @error('rateDate') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="rate" class="{{ $label }}">Taux (FC pour 1 $)</label>
                    <input id="rate" type="text" inputmode="decimal" wire:model="rate" class="{{ $input }}">
                    @error('rate') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="source" class="{{ $label }}">Source</label>
                    <select id="source" wire:model="source" class="{{ $input }}">
                        @foreach ($sources as $value => $text)
                            <option value="{{ $value }}">{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-slate-500">Un seul taux par jour : une nouvelle saisie pour la même date corrige le taux existant (la correction est tracée).</p>
                <div>
                    <button type="submit" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900">Enregistrer le taux</button>
                </div>
            </form>
        </x-lex.panel>

        <x-lex.panel title="Historique">
            <div class="-m-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Date</th>
                            <th class="px-4 py-2 text-right">FC pour 1 $</th>
                            <th class="px-4 py-2">Source</th>
                            <th class="px-4 py-2">Saisi par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rates as $r)
                            <tr wire:key="rate-{{ $r->id }}">
                                <td class="px-4 py-2 font-mono">{{ $r->rate_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ Money::formatRate((string) $r->rate) }}</td>
                                <td class="px-4 py-2">{{ $r->source->label() }}</td>
                                <td class="px-4 py-2">{{ $r->createdBy?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Aucun taux enregistré.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $rates->links() }}</div>
        </x-lex.panel>
    </div>
</div>
