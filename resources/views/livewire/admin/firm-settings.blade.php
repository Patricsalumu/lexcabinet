@php
    $input = 'mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-700 focus:ring-indigo-700';
    $label = 'block text-sm font-medium text-slate-700';
@endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Identité du cabinet, règles de facturation et de caisse, notifications" />
    <x-lex.settings-tabs />
    <x-lex.flash />

    <form wire:submit="save" class="grid gap-6 xl:grid-cols-2">
        <x-lex.panel title="Cabinet">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'name' => 'Nom du cabinet', 'bar' => 'Barreau / n° d\'inscription', 'address' => 'Adresse',
                    'rccm' => 'RCCM', 'id_nat' => 'ID. Nat.', 'nif' => 'NIF', 'phone' => 'Téléphone',
                    'email' => 'E-mail d\'envoi', 'bank_details' => 'Coordonnées bancaires (factures)',
                ] as $key => $text)
                    <div @class(['sm:col-span-2' => in_array($key, ['name', 'address', 'bank_details'])])>
                        <label for="firm-{{ $key }}" class="{{ $label }}">{{ $text }}</label>
                        <input id="firm-{{ $key }}" type="{{ $key === 'email' ? 'email' : 'text' }}" wire:model="firm.{{ $key }}" class="{{ $input }}">
                        @error('firm.'.$key) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="sm:col-span-2">
                    <label for="logo" class="{{ $label }}">Logo (PNG ou JPEG, 2 Mo maximum)</label>
                    <div class="mt-1 flex items-center gap-4">
                        @if ($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="Aperçu du logo" class="h-12 rounded border border-slate-200 object-contain">
                        @elseif ($logoPath)
                            <img src="{{ route('firm.logo') }}?v={{ md5($logoPath) }}" alt="Logo actuel" class="h-12 rounded border border-slate-200 object-contain">
                        @endif
                        <input id="logo" type="file" wire:model="logo" accept="image/png,image/jpeg" class="text-sm">
                    </div>
                    @error('logo') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-lex.panel>

        <div class="flex flex-col gap-6">
            <x-lex.panel title="Facturation et caisse">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" wire:model="rules.vat_enabled" class="rounded border-slate-300 text-indigo-800">
                        Cabinet assujetti à la TVA (appliquée après réduction, jamais sur les débours)
                    </label>
                    <div>
                        <label for="vat-rate" class="{{ $label }}">Taux de TVA (%)</label>
                        <input id="vat-rate" type="text" inputmode="decimal" wire:model="rules.vat_rate" class="{{ $input }}">
                        @error('rules.vat_rate') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="threshold" class="{{ $label }}">Seuil de validation des décaissements ($)</label>
                        <input id="threshold" type="text" inputmode="decimal" wire:model="rules.threshold" class="{{ $input }}">
                        <p class="mt-1 text-xs text-slate-500">Équivalent CDF au taux du jour.</p>
                        @error('rules.threshold') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="consult-fee" class="{{ $label }}">Tarif de consultation par défaut</label>
                        <input id="consult-fee" type="text" inputmode="decimal" wire:model="rules.consultation_fee" class="{{ $input }}">
                        @error('rules.consultation_fee') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="consult-cur" class="{{ $label }}">Devise de la consultation</label>
                        <select id="consult-cur" wire:model="rules.consultation_currency" class="{{ $input }}">
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->value }}">{{ $currency->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-start gap-2 text-sm sm:col-span-2">
                        <input type="checkbox" wire:model="rules.subscription_auto_issue" class="mt-0.5 rounded border-slate-300 text-indigo-800">
                        <span>Émettre automatiquement les factures d'abonnement
                            <span class="block text-xs text-slate-500">Question encore ouverte : par défaut, elles sont créées en brouillon.</span></span>
                    </label>
                </div>
            </x-lex.panel>

            <x-lex.panel title="Notifications et horaires">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="reminder-time" class="{{ $label }}">Rappel J-1 à</label>
                        <input id="reminder-time" type="time" wire:model="rules.reminder_time" class="{{ $input }}">
                        @error('rules.reminder_time') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="reminder-hours" class="{{ $label }}">Rappel avant le RDV (h)</label>
                        <input id="reminder-hours" type="number" min="0" max="48" wire:model="rules.reminder_hours" class="{{ $input }}">
                        @error('rules.reminder_hours') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="recap-time" class="{{ $label }}">Récapitulatif des avocats à</label>
                        <input id="recap-time" type="time" wire:model="rules.recap_time" class="{{ $input }}">
                        @error('rules.recap_time') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="opens" class="{{ $label }}">Ouverture</label>
                        <input id="opens" type="time" wire:model="rules.opens_at" class="{{ $input }}">
                        @error('rules.opens_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="closes" class="{{ $label }}">Fermeture</label>
                        <input id="closes" type="time" wire:model="rules.closes_at" class="{{ $input }}">
                        @error('rules.closes_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <fieldset class="sm:col-span-3">
                        <legend class="{{ $label }}">Jours d'ouverture</legend>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ($weekdays as $num => $day)
                                <label class="flex items-center gap-1.5 text-sm">
                                    <input type="checkbox" value="{{ $num }}" wire:model="rules.days" class="rounded border-slate-300 text-indigo-800">
                                    {{ $day }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                <p class="mt-4 text-xs text-slate-500">
                    Serveur d'envoi des e-mails : configuré dans le fichier d'environnement du serveur
                    (actuellement « {{ config('mail.default') }} »).
                </p>
            </x-lex.panel>

            <div>
                <button type="submit" class="rounded-md bg-indigo-800 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-900" wire:loading.attr="disabled">
                    Enregistrer les paramètres
                </button>
            </div>
        </div>
    </form>
</div>
