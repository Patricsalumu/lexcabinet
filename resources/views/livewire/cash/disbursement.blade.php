@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Décaisser" :subtitle="'Au-delà de '.Money::format($threshold, 'USD').' (ou l\'équivalent en CDF), la sortie attend la validation de l\'administrateur.'" />
    @foreach (['session', 'treasury_account_id'] as $field)
        @error($field) <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror
    @endforeach

    <form wire:submit="save" class="max-w-3xl">
        <x-lex.panel>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="d-cat" class="lx-label">Catégorie</label>
                    <select id="d-cat" wire:model.live="category" class="lx-input">
                        @foreach ($categories as $c)<option value="{{ $c->value }}">{{ $c->label() }} ({{ $c->ledgerCode() }})</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="d-benef" class="lx-label">Bénéficiaire</label>
                    <input id="d-benef" type="text" wire:model="beneficiary" class="lx-input">
                    @error('beneficiary') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                @if ($category === 'client_disbursement')
                    <div class="sm:col-span-2">
                        <label for="d-client" class="lx-label">Client pour qui le débours est avancé</label>
                        <select id="d-client" wire:model="client_id" class="lx-input">
                            <option value="">Choisir</option>
                            @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                        </select>
                        @error('client_id') <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label for="d-label" class="lx-label">Motif</label>
                    <input id="d-label" type="text" wire:model="label" placeholder="Ex. : taxi pour l'audience RC 4471" class="lx-input">
                    @error('label') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-cur" class="lx-label">Devise</label>
                    <select id="d-cur" wire:model.live="currency" class="lx-input"><option value="USD">USD</option><option value="CDF">CDF</option></select>
                </div>
                <div>
                    <label for="d-amount" class="lx-label">Montant</label>
                    <input id="d-amount" type="text" inputmode="decimal" wire:model="amount" class="lx-input">
                    @error('amount') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-mode" class="lx-label">Mode</label>
                    <select id="d-mode" wire:model.live="mode" class="lx-input">
                        @foreach ($modes as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="d-account" class="lx-label">Compte</label>
                    <select id="d-account" wire:model="treasury_account_id" class="lx-input">
                        @forelse ($this->accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@empty<option value="">Aucun compte en {{ $currency }} pour ce mode</option>@endforelse
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="d-voucher" class="lx-label">N° de pièce justificative</label>
                    <input id="d-voucher" type="text" wire:model="voucher" placeholder="Facture fournisseur, bon…" class="lx-input">
                    @error('voucher') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">Enregistrer la sortie</button>
            <a href="{{ route('cash.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
