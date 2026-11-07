<div>
    <x-lex.page-header :title="$appointment ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous'"
                       subtitle="Le client reçoit une confirmation avec le fichier .ics, puis un rappel la veille (s'il a accepté les e-mails)." />

    <form wire:submit="save" class="max-w-3xl">
        <x-lex.panel>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <div class="flex items-end justify-between gap-2">
                        <label for="a-client" class="lx-label">Client</label>
                        @unless ($appointment)
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" wire:model.live="newProspect" class="rounded border-slate-300 text-indigo-800"> Nouveau prospect
                            </label>
                        @endunless
                    </div>
                    @if ($newProspect && ! $appointment)
                        <div class="mt-1 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                            <div class="sm:col-span-3">
                                <label for="p-name" class="lx-label">Nom du prospect</label>
                                <input id="p-name" type="text" wire:model.live.debounce.400ms="prospect_name" class="lx-input">
                                @error('prospect_name') <p class="lx-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="p-email" class="lx-label">E-mail</label>
                                <input id="p-email" type="email" wire:model="prospect_email" class="lx-input">
                                @error('prospect_email') <p class="lx-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="p-phone" class="lx-label">Téléphone</label>
                                <input id="p-phone" type="tel" wire:model="prospect_phone" class="lx-input">
                            </div>
                            <label class="flex items-end gap-2 pb-2 text-sm">
                                <input type="checkbox" wire:model="prospect_consent" class="rounded border-slate-300 text-indigo-800"> Accepte les e-mails
                            </label>
                            @if ($this->prospectConflicts !== [])
                                <div class="lx-warn sm:col-span-3" role="alert">
                                    <p class="font-semibold">Contrôle des conflits d'intérêts :</p>
                                    <ul class="list-disc pl-5">@foreach ($this->prospectConflicts as $m)<li>{{ $m['label'] }}</li>@endforeach</ul>
                                    <label class="mt-2 flex items-start gap-2">
                                        <input type="checkbox" wire:model="conflict_ack" class="mt-0.5 rounded border-amber-400 text-indigo-800">
                                        <span>J'ai vérifié ces correspondances : il n'y a pas de conflit d'intérêts.</span>
                                    </label>
                                    @error('conflict_ack') <p class="lx-error">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>
                    @else
                        <select id="a-client" wire:model.live="client_id" class="lx-input" @disabled($appointment)>
                            <option value="">Choisir un client</option>
                            @foreach ($clients as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}{{ $c->is_prospect ? ' (prospect)' : '' }}{{ $c->email_consent && $c->email ? '' : ' — sans e-mail' }}</option>
                            @endforeach
                        </select>
                        @error('client_id') <p class="lx-error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div>
                    <label for="a-lawyer" class="lx-label">Avocat</label>
                    <select id="a-lawyer" wire:model="lawyer_id" class="lx-input">
                        <option value="">Choisir</option>
                        @foreach ($lawyers as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                    @error('lawyer_id') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="a-type" class="lx-label">Type</label>
                    <select id="a-type" wire:model="type" class="lx-input">
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="a-date" class="lx-label">Date</label>
                    <input id="a-date" type="date" wire:model="date" class="lx-input">
                    @error('date') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="a-time" class="lx-label">Heure</label>
                        <input id="a-time" type="time" wire:model="time" class="lx-input">
                    </div>
                    <div>
                        <label for="a-dur" class="lx-label">Durée</label>
                        <select id="a-dur" wire:model="duration" class="lx-input">
                            @foreach ($durations as $min => $label)
                                <option value="{{ $min }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('time') <div class="lx-crit sm:col-span-2" role="alert">{{ $message }}</div> @enderror

                <div>
                    <label for="a-loc" class="lx-label">Lieu</label>
                    <select id="a-loc" wire:model="location_type" class="lx-input">
                        @foreach ($locations as $l)
                            <option value="{{ $l->value }}">{{ $l->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="a-locd" class="lx-label">Précision du lieu</label>
                    <input id="a-locd" type="text" wire:model="location_detail" placeholder="Ex. : Tribunal de commerce, chambre 2 ; lien de visioconférence" class="lx-input">
                    @error('location_detail') <p class="lx-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="a-case" class="lx-label">Dossier (facultatif)</label>
                    <select id="a-case" wire:model="case_id" class="lx-input" @disabled($newProspect || $client_id === '')>
                        <option value="">Aucun dossier</option>
                        @foreach ($this->clientCases as $c)
                            <option value="{{ $c->id }}">{{ $c->reference }} · {{ $c->title }}</option>
                        @endforeach
                    </select>
                    @error('case_id') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="a-subject" class="lx-label">Objet</label>
                    <input id="a-subject" type="text" wire:model="subject" placeholder="Ex. : point sur la procédure" class="lx-input">
                    @error('subject') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="a-notes" class="lx-label">Notes internes (jamais envoyées au client)</label>
                    <textarea id="a-notes" wire:model="internal_notes" rows="3" class="lx-input"></textarea>
                    @error('internal_notes') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">{{ $appointment ? 'Enregistrer' : 'Créer le rendez-vous' }}</button>
            <a href="{{ route('agenda.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
