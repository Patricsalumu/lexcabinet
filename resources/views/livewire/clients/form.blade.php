<div>
    <x-lex.page-header :title="$client ? 'Modifier la fiche client' : 'Nouveau client'" :subtitle="$client?->name" />

    <form wire:submit="save" class="max-w-3xl">
        <x-lex.panel>
            <div class="grid gap-4 sm:grid-cols-2">
                <fieldset class="sm:col-span-2">
                    <legend class="lx-label">Type</legend>
                    <div class="mt-2 flex gap-6">
                        @foreach ($types as $t)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="radio" wire:model.live="type" value="{{ $t->value }}" class="border-slate-300 text-indigo-800">
                                {{ $t->label() }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="sm:col-span-2">
                    <label for="c-name" class="lx-label">{{ $type === 'company' ? 'Raison sociale' : 'Nom complet' }}</label>
                    <input id="c-name" type="text" wire:model.live.debounce.400ms="name" autocomplete="off" class="lx-input">
                    @error('name') <p class="lx-error">{{ $message }}</p> @enderror
                </div>

                @if ($this->conflicts !== [])
                    <div class="lx-warn sm:col-span-2" role="alert">
                        <p class="font-semibold">Contrôle des conflits d'intérêts : {{ count($this->conflicts) }} correspondance(s)</p>
                        <ul class="mt-1 list-disc pl-5">
                            @foreach ($this->conflicts as $match)
                                <li>{{ $match['label'] }}</li>
                            @endforeach
                        </ul>
                        @unless ($client)
                            <label class="mt-3 flex items-start gap-2">
                                <input type="checkbox" wire:model="conflict_ack" class="mt-0.5 rounded border-amber-400 text-indigo-800">
                                <span>J'ai vérifié ces correspondances : il n'y a pas de conflit d'intérêts.</span>
                            </label>
                            @error('conflict_ack') <p class="lx-error">{{ $message }}</p> @enderror
                        @endunless
                    </div>
                @endif

                <div>
                    <label for="c-phone" class="lx-label">Téléphone</label>
                    <input id="c-phone" type="tel" wire:model="phone" placeholder="+243 …" class="lx-input">
                    @error('phone') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-email" class="lx-label">E-mail</label>
                    <input id="c-email" type="email" wire:model="email" class="lx-input">
                    @error('email') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="c-address" class="lx-label">Adresse</label>
                    <input id="c-address" type="text" wire:model="address" class="lx-input">
                    @error('address') <p class="lx-error">{{ $message }}</p> @enderror
                </div>

                @if ($type === 'company')
                    <div>
                        <label for="c-rccm" class="lx-label">RCCM</label>
                        <input id="c-rccm" type="text" wire:model="rccm" placeholder="CD/KNG/RCCM/…" class="lx-input">
                        @error('rccm') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="c-idnat" class="lx-label">ID. Nat.</label>
                        <input id="c-idnat" type="text" wire:model="id_nat" class="lx-input">
                        @error('id_nat') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="c-rep" class="lx-label">Représentant</label>
                        <input id="c-rep" type="text" wire:model="representative" placeholder="Nom et qualité" class="lx-input">
                        @error('representative') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="c-idtype" class="lx-label">Pièce d'identité</label>
                        <select id="c-idtype" wire:model="id_document_type" class="lx-input">
                            <option value="">—</option>
                            @foreach ($idTypes as $t)
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endforeach
                        </select>
                        @error('id_document_type') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="c-idnum" class="lx-label">Numéro de la pièce</label>
                        <input id="c-idnum" type="text" wire:model="id_document_number" class="lx-input">
                        @error('id_document_number') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model="email_consent" class="mt-0.5 rounded border-slate-300 text-indigo-800">
                    <span>Le client accepte de recevoir confirmations, rappels et factures par e-mail.</span>
                </label>
                <label class="flex items-start gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model="is_prospect" class="mt-0.5 rounded border-slate-300 text-indigo-800">
                    <span>Prospect (pas encore client)</span>
                </label>
            </div>
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">{{ $client ? 'Enregistrer' : 'Créer la fiche' }}</button>
            <a href="{{ $client ? route('clients.show', $client) : route('clients.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
