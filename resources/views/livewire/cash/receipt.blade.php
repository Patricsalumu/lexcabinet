@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Encaisser un paiement" subtitle="Sur une facture ou comme provision sans facture ; un reçu numéroté est émis." />
    @foreach (['session', 'treasury_account_id', 'invoice_id'] as $field)
        @error($field) <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror
    @endforeach

    <form wire:submit="save" class="max-w-3xl">
        <x-lex.panel>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="r-client" class="lx-label">Client</label>
                    <select id="r-client" wire:model.live="client_id" class="lx-input">
                        <option value="">Choisir un client</option>
                        @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    @error('client_id') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="r-invoice" class="lx-label">Facture réglée</label>
                    <select id="r-invoice" wire:model.live="invoice_id" class="lx-input" @disabled($client_id === '')>
                        <option value="">Aucune : provision sur honoraires</option>
                        @foreach ($this->openInvoices as $i)
                            <option value="{{ $i->id }}">{{ $i->number }} · reste dû {{ Money::format($i->balanceDue(), $i->currency) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="r-cur" class="lx-label">Devise reçue</label>
                    <select id="r-cur" wire:model.live="currency" class="lx-input"><option value="USD">USD</option><option value="CDF">CDF</option></select>
                </div>
                <div>
                    <label for="r-amount" class="lx-label">Montant reçu</label>
                    <input id="r-amount" type="text" inputmode="decimal" wire:model.live.debounce.400ms="amount" class="lx-input">
                    @error('amount') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-mode" class="lx-label">Mode</label>
                    <select id="r-mode" wire:model.live="mode" class="lx-input">
                        @foreach ($modes as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="r-account" class="lx-label">Compte</label>
                    <select id="r-account" wire:model="treasury_account_id" class="lx-input">
                        @forelse ($this->accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@empty<option value="">Aucun compte en {{ $currency }} pour ce mode</option>@endforelse
                    </select>
                </div>
                <div>
                    <label for="r-ref" class="lx-label">Référence</label>
                    <input id="r-ref" type="text" wire:model="reference" placeholder="N° de virement, de chèque ou de transaction" class="lx-input">
                    @error('reference') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="r-rate" class="lx-label">Taux appliqué (FC pour 1 $)</label>
                    <input id="r-rate" type="text" inputmode="decimal" wire:model.live.debounce.400ms="exchange_rate" class="lx-input">
                    @error('exchange_rate') <p class="lx-error">{{ $message }}</p> @enderror
                </div>
                @if ($this->imputation)
                    <div class="lx-warn sm:col-span-2">{{ $this->imputation }}</div>
                @endif
            </div>
        </x-lex.panel>

        <div class="mt-4 flex gap-2">
            <button type="submit" class="lx-btn-primary" wire:loading.attr="disabled">Encaisser et émettre le reçu</button>
            <a href="{{ route('cash.index') }}" wire:navigate class="lx-btn">Annuler</a>
        </div>
    </form>
</div>
