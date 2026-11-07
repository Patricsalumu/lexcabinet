<div>
    <x-lex.page-header :title="$case ? 'Modifier le dossier' : 'Nouveau dossier'" :subtitle="$case ? $case->reference.' · '.$case->title : 'La référence LEX-AAAA-NNNN est attribuée à l\'ouverture.'" />

    <form wire:submit="save" class="max-w-4xl">
        <div class="grid gap-6">
            <x-lex.panel title="Affaire">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="d-client" class="lx-label">Client</label>
                        <select id="d-client" wire:model.live="client_id" class="lx-input">
                            <option value="">Choisir un client</option>
                            @foreach ($clients as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}{{ $c->is_prospect ? ' (prospect)' : '' }}</option>
                            @endforeach
                        </select>
                        @error('client_id') <p class="lx-error">{{ $message }}</p> @enderror
                        @if ($this->selectedClient)
                            <p class="lx-hint">{{ $this->selectedClient->address ?? 'Adresse non renseignée' }} · {{ $this->selectedClient->phone ?? 'téléphone non renseigné' }}</p>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label for="d-title" class="lx-label">Intitulé</label>
                        <input id="d-title" type="text" wire:model="title" placeholder="Ex. : Société X c/ Société Y" class="lx-input">
                        @error('title') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="d-adverse" class="lx-label">Partie adverse</label>
                        <input id="d-adverse" type="text" wire:model.live.debounce.400ms="adverse_party" autocomplete="off" class="lx-input">
                        <p class="lx-hint">Contrôlée contre la liste des clients du cabinet.</p>
                        @error('adverse_party') <p class="lx-error">{{ $message }}</p> @enderror
                        @if ($this->adverseConflicts !== [])
                            <div class="lx-crit mt-2" role="alert">
                                <b>Conflit possible :</b>
                                @foreach ($this->adverseConflicts as $m) {{ $m['label'] }}@if (! $loop->last) ; @endif @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <label for="d-matter" class="lx-label">Matière</label>
                        <select id="d-matter" wire:model="matter" class="lx-input">
                            @foreach ($matters as $m)
                                <option value="{{ $m->value }}">{{ $m->label() }}</option>
                            @endforeach
                        </select>
                        @error('matter') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-opened" class="lx-label">Date d'ouverture</label>
                        <input id="d-opened" type="date" wire:model="opened_on" max="{{ today()->toDateString() }}" @disabled($case) class="lx-input disabled:bg-slate-50">
                        @error('opened_on') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-jur" class="lx-label">Juridiction</label>
                        <input id="d-jur" type="text" wire:model="jurisdiction" class="lx-input">
                        @error('jurisdiction') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-rg" class="lx-label">N° de rôle (RG)</label>
                        <input id="d-rg" type="text" wire:model="docket_number" placeholder="RC 0000" class="lx-input">
                        @error('docket_number') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-lex.panel>

            <x-lex.panel title="Équipe">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="d-lead" class="lx-label">Avocat responsable</label>
                        <select id="d-lead" wire:model.live="lead_lawyer_id" class="lx-input">
                            <option value="">Choisir</option>
                            @foreach ($lawyers as $l)
                                <option value="{{ $l->id }}">{{ $l->name }}</option>
                            @endforeach
                        </select>
                        @error('lead_lawyer_id') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <fieldset>
                        <legend class="lx-label">Collaborateurs</legend>
                        <div class="mt-2 grid gap-1">
                            @foreach ($lawyers as $l)
                                @continue((string) $l->id === $lead_lawyer_id)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" value="{{ $l->id }}" wire:model="collaborators" class="rounded border-slate-300 text-indigo-800">
                                    {{ $l->name }}
                                </label>
                            @endforeach
                        </div>
                        @error('collaborators.*') <p class="lx-error">{{ $message }}</p> @enderror
                    </fieldset>
                </div>
            </x-lex.panel>

            <x-lex.panel title="Convention d'honoraires">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="d-fee-type" class="lx-label">Type</label>
                        <select id="d-fee-type" wire:model.live="fee_type" class="lx-input">
                            @foreach ($feeTypes as $f)
                                <option value="{{ $f->value }}">{{ $f->label() }}</option>
                            @endforeach
                        </select>
                        @error('fee_type') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-fee" class="lx-label">{{ App\Enums\FeeType::tryFrom($fee_type)?->amountLabel() ?? 'Montant' }}</label>
                        <input id="d-fee" type="text" inputmode="decimal" wire:model="fee_amount" class="lx-input">
                        @error('fee_amount') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-cur" class="lx-label">Devise</label>
                        <select id="d-cur" wire:model="currency" class="lx-input">
                            @foreach ($currencies as $c)
                                <option value="{{ $c->value }}">{{ $c->value }}</option>
                            @endforeach
                        </select>
                        @error('currency') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($fee_type === 'subscription')
                    <p class="lx-hint mt-3">Abonnement : une facture tous les 30 jours à partir de la date d'ouverture, jusqu'à la clôture du dossier.</p>
                @elseif ($fee_type === 'retainer_success')
                    <p class="lx-hint mt-3">Le calcul de l'honoraire de résultat reste à préciser avec le cabinet ; seule la provision est saisie ici.</p>
                @endif
            </x-lex.panel>
        </div>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">{{ $case ? 'Enregistrer' : 'Ouvrir le dossier' }}</button>
            <a href="{{ $case ? route('cases.show', $case) : route('cases.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
