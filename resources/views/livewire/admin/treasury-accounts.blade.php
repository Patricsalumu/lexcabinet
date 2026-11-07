@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Caisse USD et CDF, comptes bancaires et mobile money acceptés comme moyens de paiement." />
    <x-lex.settings-tabs />
    <x-lex.flash />
    @error('toggle') <div class="lx-crit mb-4">{{ $message }}</div> @enderror

    <div class="grid gap-6 lg:grid-cols-[1fr_minmax(0,22rem)]">
        <x-lex.panel title="Comptes de trésorerie">
            <div class="-m-5 overflow-x-auto">
                <table class="lx-table">
                    <thead><tr><th>Compte</th><th>Type</th><th>Devise</th><th>Compte comptable</th><th class="text-right">Solde</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($accounts as $row)
                            @php $a = $row['account']; @endphp
                            <tr wire:key="ta-{{ $a->id }}" @class(['opacity-50' => ! $a->is_active])>
                                <td class="font-medium">{{ $a->name }}</td>
                                <td>{{ $a->type->label() }}</td>
                                <td>{{ $a->currency->value }}</td>
                                <td class="font-mono">{{ $a->ledger_code }}</td>
                                <td class="text-right font-mono">{{ Money::format($row['balance'], $a->currency) }}</td>
                                <td class="text-right">
                                    @unless ($a->isCash())
                                        <button type="button" wire:click="toggle({{ $a->id }})" class="lx-btn py-1 text-xs">{{ $a->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-lex.panel>

        <x-lex.panel title="Ajouter un compte">
            <form wire:submit="save" class="grid gap-3">
                <div><label for="t-name" class="lx-label">Nom</label><input id="t-name" type="text" wire:model="name" placeholder="Ex. : Equity BCDC, compte CDF" class="lx-input">@error('name')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <div>
                    <label for="t-type" class="lx-label">Type</label>
                    <select id="t-type" wire:model.live="type" class="lx-input"><option value="bank">Banque</option><option value="mobile_money">Mobile money</option></select>
                </div>
                <div>
                    <label for="t-cur" class="lx-label">Devise</label>
                    <select id="t-cur" wire:model="currency" class="lx-input">@foreach ($currencies as $c)<option value="{{ $c->value }}">{{ $c->value }}</option>@endforeach</select>
                </div>
                <div><label for="t-code" class="lx-label">Compte comptable</label><input id="t-code" type="text" wire:model="ledger_code" class="lx-input">@error('ledger_code')<p class="lx-error">{{ $message }}</p>@enderror</div>
                <button type="submit" class="lx-btn-primary">Ajouter</button>
            </form>
        </x-lex.panel>
    </div>
</div>
