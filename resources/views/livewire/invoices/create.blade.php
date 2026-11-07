@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Nouvelle facture" subtitle="La facture part du dossier : la ligne d'honoraires est générée selon la convention." />

    <form wire:submit="create" class="max-w-3xl">
        <x-lex.panel>
            <fieldset class="mb-4">
                <legend class="sr-only">Origine de la facture</legend>
                <div class="flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="mode" value="case" class="text-indigo-800"> À partir d'un dossier</label>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="mode" value="consultation" class="text-indigo-800"> Consultation honorée sans dossier</label>
                </div>
            </fieldset>

            @if ($mode === 'case')
                <label for="i-case" class="lx-label">Dossier</label>
                <select id="i-case" wire:model.live="case_id" class="lx-input">
                    <option value="">Choisir un dossier</option>
                    @foreach ($cases as $c)
                        <option value="{{ $c->id }}">{{ $c->reference }} · {{ $c->title }} ({{ $c->client->name }})</option>
                    @endforeach
                </select>
                @error('case_id') <p class="lx-error">{{ $message }}</p> @enderror

                @if ($this->selectedCase)
                    @php $c = $this->selectedCase; @endphp
                    <dl class="lx-kv mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                        <dt>Client</dt><dd><b>{{ $c->client->name }}</b></dd>
                        <dt>Adresse</dt><dd>{{ $c->client->address ?? '—' }}</dd>
                        <dt>Téléphone</dt><dd>{{ $c->client->phone ?? '—' }}</dd>
                        <dt>Convention</dt><dd>{{ $c->fee_type->label() }} · <span class="font-mono">{{ Money::format((string) $c->fee_amount, $c->currency) }}</span>{{ $c->fee_type->amountSuffix() }}</dd>
                        <dt>Ligne générée</dt><dd>{{ $this->preview }}</dd>
                    </dl>
                @endif
            @else
                <label for="i-appt" class="lx-label">Consultation</label>
                <select id="i-appt" wire:model="appointment_id" class="lx-input">
                    <option value="">Choisir une consultation honorée</option>
                    @foreach ($consultations as $a)
                        <option value="{{ $a->id }}">{{ $a->starts_at->format('d/m/Y H\hi') }} · {{ $a->client->name }} avec {{ $a->lawyer->name }}</option>
                    @endforeach
                </select>
                @error('appointment_id') <p class="lx-error">{{ $message }}</p> @enderror
                @if ($consultations->isEmpty())
                    <p class="lx-hint">Aucune consultation honorée à facturer.</p>
                @endif
                <p class="lx-hint">Ligne de consultation au tarif par défaut des paramètres, sans dossier.</p>
            @endif
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">Créer le brouillon</button>
            <a href="{{ route('invoices.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
