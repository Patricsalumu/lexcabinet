@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Caisse, comptes bancaires et mobile money. Les comptes « affichés aux clients » sont vos moyens de paiement : ils figurent en signature de tous les e-mails clients et sur les factures." />
    <x-lex.settings-tabs />
    <x-lex.flash />
    @error('toggle') <div class="lx-crit mb-4">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-[1fr_minmax(0,24rem)]">
        <div class="flex flex-col gap-6">
            <x-lex.panel title="Comptes de trésorerie et moyens de paiement">
                <div class="-m-5 overflow-x-auto">
                    <table class="lx-table">
                        <thead><tr><th>Compte</th><th>Coordonnées communiquées</th><th>Clients</th><th class="text-right">Solde</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($accounts as $row)
                                @php $a = $row['account']; @endphp
                                <tr wire:key="ta-{{ $a->id }}" @class(['opacity-50' => ! $a->is_active, 'bg-indigo-50/50' => $editingId === $a->id])>
                                    <td>
                                        <span class="font-medium">{{ $a->name }}</span>
                                        <span class="block text-xs text-slate-500">{{ $a->type->label() }} · {{ $a->currency->value }} · compte {{ $a->ledger_code }}</span>
                                    </td>
                                    <td class="text-xs">
                                        @if ($a->holder_name)<span class="block">Intitulé : {{ $a->holder_name }}</span>@endif
                                        @if ($a->account_number)<span class="block font-mono">N° {{ $a->account_number }}</span>@endif
                                        @if ($a->payment_details)<span class="block text-slate-500">{{ $a->payment_details }}</span>@endif
                                        @if (! $a->holder_name && ! $a->account_number && ! $a->payment_details)<span class="text-slate-400">—</span>@endif
                                    </td>
                                    <td>
                                        @if ($a->show_to_clients && $a->is_active)
                                            <span class="lx-badge bg-emerald-50 text-emerald-800">Affiché</span>
                                        @else
                                            <span class="lx-badge bg-slate-100 text-slate-600">Masqué</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right font-mono">{{ Money::format($row['balance'], $a->currency) }}</td>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" wire:click="edit({{ $a->id }})" class="lx-btn py-1 text-xs">Modifier</button>
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

            <x-lex.panel title="Signature des e-mails clients (aperçu)" note="Ajoutée automatiquement à la fin de chaque e-mail envoyé à un client">
                <pre class="whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-50 p-4 font-sans text-sm">{{ $signature }}</pre>
                <p class="lx-hint mt-2">Adresse, téléphone et e-mail viennent de l'onglet Cabinet.</p>
            </x-lex.panel>
        </div>

        <x-lex.panel :title="$editingId ? 'Modifier le compte' : 'Ajouter un compte'">
            <form wire:submit="save" class="grid gap-3">
                <div>
                    <label for="t-name" class="lx-label">Nom affiché</label>
                    <input id="t-name" type="text" wire:model="name" placeholder="Ex. : Rawbank, Equity BCDC, M-Pesa" class="lx-input">
                    @error('name')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="t-holder" class="lx-label">Intitulé du compte</label>
                    <input id="t-holder" type="text" wire:model="holder_name" placeholder="Ex. : Cabinet Mukendi & Associés" class="lx-input">
                    @error('holder_name')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="t-number" class="lx-label">{{ $type === 'mobile_money' ? 'Numéro mobile money' : 'Numéro de compte ou IBAN' }}</label>
                    <input id="t-number" type="text" wire:model="account_number" class="lx-input font-mono">
                    @error('account_number')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="t-details" class="lx-label">Précisions (facultatif)</label>
                    <input id="t-details" type="text" wire:model="payment_details" placeholder="Ex. : SWIFT RAWBCDKI ; au cabinet, du lundi au vendredi" class="lx-input">
                    @error('payment_details')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="show_to_clients" class="rounded border-slate-300 text-indigo-800">
                    Afficher aux clients (signature des e-mails, factures)
                </label>

                @unless ($editingId)
                    <div class="grid grid-cols-2 gap-3 border-t border-slate-200 pt-3">
                        <div>
                            <label for="t-type" class="lx-label">Type</label>
                            <select id="t-type" wire:model.live="type" class="lx-input"><option value="bank">Banque</option><option value="mobile_money">Mobile money</option></select>
                        </div>
                        <div>
                            <label for="t-cur" class="lx-label">Devise</label>
                            <select id="t-cur" wire:model="currency" class="lx-input">@foreach ($currencies as $c)<option value="{{ $c->value }}">{{ $c->value }}</option>@endforeach</select>
                        </div>
                        <div class="col-span-2">
                            <label for="t-code" class="lx-label">Compte comptable</label>
                            <input id="t-code" type="text" wire:model="ledger_code" class="lx-input">
                            @error('ledger_code')<p class="lx-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @else
                    <p class="lx-hint">Type, devise et compte comptable ne se modifient pas après la création (cohérence de la comptabilité).</p>
                @endunless

                <div class="flex gap-2">
                    <button type="submit" class="lx-btn-primary">{{ $editingId ? 'Enregistrer' : 'Ajouter' }}</button>
                    @if ($editingId)<button type="button" wire:click="cancel" class="lx-btn">Annuler</button>@endif
                </div>
            </form>
        </x-lex.panel>
    </div>
</div>
