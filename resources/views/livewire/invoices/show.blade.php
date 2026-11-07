@php
    use App\Enums\InvoiceStatus;
    use App\Support\Money;
    $cur = $invoice->currency;
    $m = fn ($v) => Money::format((string) $v, $cur);
    $isDraft = $invoice->status === InvoiceStatus::Draft;
    $canEdit = auth()->user()->can('update', $invoice);
    $feesNet = bcsub((string) $invoice->fees_total, (string) $invoice->discount_amount, 2);
@endphp

<div>
    <x-lex.page-header :title="$invoice->number ?? 'Brouillon de facture'"
        :subtitle="$invoice->client->name.($invoice->legalCase ? ' · '.$invoice->legalCase->reference.' · '.$invoice->legalCase->title : ' · consultation sans dossier')">
        <x-slot:actions>
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="lx-btn">{{ $isDraft ? 'Aperçu PDF' : 'PDF' }}</a>
            @can('send', $invoice)
                <button type="button" wire:click="send" class="lx-btn">Envoyer par e-mail</button>
            @endcan
            @if ($canEdit)
                <button type="button" wire:click="deleteDraft" wire:confirm="Supprimer ce brouillon ?" class="lx-btn text-red-700">Supprimer le brouillon</button>
                <button type="button" wire:click="issue" wire:confirm="Émettre la facture ? Elle recevra son numéro définitif et ne pourra plus être modifiée." class="lx-btn-primary">Émettre</button>
            @endif
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />
    @if (session('error'))<div class="lx-crit mb-4" role="alert">{{ session('error') }}</div>@endif
    @error('invoice') <div class="lx-crit mb-4" role="alert">{{ $message }}</div> @enderror

    <div class="grid gap-6 xl:grid-cols-[1fr_minmax(0,22rem)]">
        <div class="flex flex-col gap-6">
            <x-lex.panel>
                <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs text-slate-500">Facturé à</p>
                        <p class="font-semibold">{{ $invoice->client->name }}</p>
                        <p class="text-sm text-slate-600">{{ $invoice->client->address ?? '—' }} · {{ $invoice->client->phone ?? '—' }}</p>
                    </div>
                    <div class="text-right text-sm">
                        <span @class(['lx-badge', $invoice->status->badgeClass(), '!bg-red-50 !text-red-800' => $invoice->isOverdue()])>{{ $invoice->statusLabel() }}</span>
                        @if ($invoice->issue_date)
                            <p class="mt-1 text-slate-600">Émise le {{ $invoice->issue_date->format('d/m/Y') }} · échéance {{ $invoice->due_date->format('d/m/Y') }}</p>
                        @endif
                        @if ($invoice->legalCase)
                            <p class="text-slate-600">{{ $invoice->legalCase->fee_type->label() }}</p>
                        @endif
                        @if ($invoice->period_start)
                            <p class="text-slate-600">Période du {{ $invoice->period_start->format('d/m/Y') }} au {{ $invoice->period_end->format('d/m/Y') }}</p>
                        @endif
                    </div>
                </div>

                @if ($invoice->creditNote)
                    <div class="lx-crit mb-4">
                        Annulée par l'avoir <a href="{{ route('credit-notes.pdf', $invoice->creditNote) }}" target="_blank" class="font-mono font-semibold underline">{{ $invoice->creditNote->number }}</a>
                        du {{ $invoice->creditNote->issue_date->format('d/m/Y') }} · motif : {{ $invoice->creditNote->reason }}
                    </div>
                @endif

                <div class="-mx-5 overflow-x-auto">
                    <table class="lx-table">
                        <thead><tr><th>Désignation</th><th>Type</th><th class="text-right">Qté</th><th class="text-right">Prix unit.</th><th class="text-right">Montant</th>@if ($canEdit)<th></th>@endif</tr></thead>
                        <tbody>
                            @forelse ($invoice->lines as $line)
                                <tr wire:key="line-{{ $line->id }}">
                                    <td>
                                        {{ $line->label }}
                                        @if ($line->isAuto())<span class="lx-badge ml-1 bg-indigo-50 text-indigo-900">automatique</span>@endif
                                    </td>
                                    <td class="whitespace-nowrap">{{ $line->type->label() }}{{ $line->taxable ? '' : ' (HT)' }}</td>
                                    <td class="text-right font-mono">{{ $line->quantityLabel() }}</td>
                                    <td class="whitespace-nowrap text-right font-mono">{{ $m($line->unit_price) }}</td>
                                    <td class="whitespace-nowrap text-right font-mono">{{ $m($line->amount) }}</td>
                                    @if ($canEdit)
                                        <td class="text-right">
                                            @unless ($line->isAuto())
                                                <button type="button" wire:click="removeLine({{ $line->id }})" class="text-xs text-red-700 hover:underline">Retirer</button>
                                            @endunless
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-4 text-center text-slate-500">Aucune ligne.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <dl class="ml-auto mt-4 grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
                    <dt>Honoraires</dt><dd class="text-right font-mono">{{ $m($invoice->fees_total) }}</dd>
                    @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
                        <dt>Réduction {{ rtrim(rtrim((string) $invoice->discount_percent, '0'), '.') }} % (honoraires seuls)</dt><dd class="text-right font-mono">-{{ $m($invoice->discount_amount) }}</dd>
                        <dt>Honoraires nets</dt><dd class="text-right font-mono">{{ $m($feesNet) }}</dd>
                    @endif
                    <dt>Débours et frais</dt><dd class="text-right font-mono">{{ $m($invoice->other_total) }}</dd>
                    @if ($invoice->vat_enabled)
                        <dt>TVA {{ rtrim(rtrim((string) $invoice->vat_rate, '0'), '.') }} % sur {{ $m($invoice->taxable_base) }}</dt><dd class="text-right font-mono">{{ $m($invoice->vat_amount) }}</dd>
                    @endif
                    <dt class="border-t border-slate-300 pt-1 font-semibold">Total</dt><dd class="border-t border-slate-300 pt-1 text-right font-mono font-semibold">{{ $m($invoice->total) }}</dd>
                    @if ($invoice->status->isOpen() || $invoice->status === InvoiceStatus::Paid)
                        <dt>Déjà payé</dt><dd class="text-right font-mono">{{ $m($invoice->paid_amount) }}</dd>
                        <dt class="font-semibold">Reste dû</dt><dd class="text-right font-mono font-semibold">{{ $m($invoice->balanceDue()) }}</dd>
                    @endif
                </dl>
                @if ($invoice->exchange_rate)
                    <p class="mt-3 text-xs text-slate-500">{{ $isDraft ? 'Taux du jour (mémorisé à l\'émission)' : 'Taux mémorisé' }} : 1 $ = {{ Money::formatRate((string) $invoice->exchange_rate) }} FC.</p>
                @endif
            </x-lex.panel>

            @if ($canEdit)
                <x-lex.panel title="Ligne complémentaire" note="Débours, frais de greffe, transport, frais de dossier">
                    <form wire:submit="addLine" class="grid gap-3 sm:grid-cols-[10rem_minmax(0,1fr)_5rem_8rem_auto] sm:items-end">
                        <div>
                            <label for="l-type" class="lx-label">Type</label>
                            <select id="l-type" wire:model="lineType" class="lx-input">
                                @foreach ($lineTypes as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="l-label" class="lx-label">Libellé</label>
                            <input id="l-label" type="text" wire:model="lineLabel" placeholder="Ex. : frais de signification" class="lx-input">
                        </div>
                        <div>
                            <label for="l-qty" class="lx-label">Qté</label>
                            <input id="l-qty" type="text" inputmode="decimal" wire:model="lineQuantity" class="lx-input">
                        </div>
                        <div>
                            <label for="l-price" class="lx-label">Prix unit. ({{ $cur->symbol() }})</label>
                            <input id="l-price" type="text" inputmode="decimal" wire:model="linePrice" class="lx-input">
                        </div>
                        <button type="submit" class="lx-btn">Ajouter</button>
                    </form>
                    @foreach (['lineLabel', 'lineQuantity', 'linePrice', 'lineType', 'line', 'type'] as $f)
                        @error($f) <p class="lx-error">{{ $message }}</p> @enderror
                    @endforeach
                </x-lex.panel>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            @if ($canEdit)
                <x-lex.panel title="Options du brouillon">
                    <form wire:submit="saveOptions" class="grid gap-3">
                        <div>
                            <label for="o-discount" class="lx-label">Réduction sur les honoraires (%)</label>
                            <input id="o-discount" type="text" inputmode="decimal" wire:model="discount" class="lx-input">
                            <p class="lx-hint">Ne s'applique ni aux débours ni aux frais.</p>
                            @error('discount') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="vat" class="rounded border-slate-300 text-indigo-800">
                            Appliquer la TVA (après réduction, hors débours)
                        </label>
                        <div>
                            <label for="o-terms" class="lx-label">Échéance</label>
                            <select id="o-terms" wire:model="terms" class="lx-input">
                                <option value="0">À réception</option>
                                <option value="15">15 jours</option>
                                <option value="30">30 jours</option>
                                <option value="45">45 jours</option>
                                <option value="60">60 jours</option>
                            </select>
                        </div>
                        <div>
                            <label for="o-notes" class="lx-label">Mention sur la facture</label>
                            <textarea id="o-notes" wire:model="notes" rows="2" class="lx-input"></textarea>
                        </div>
                        <button type="submit" class="lx-btn">Appliquer</button>
                        @if ($invoice->legalCase)
                            <button type="button" wire:click="refreshAuto" class="lx-btn">Actualiser la ligne d'honoraires</button>
                        @endif
                    </form>
                </x-lex.panel>
            @endif

            <x-lex.panel title="Informations">
                <dl class="lx-kv">
                    <dt>Devise</dt><dd>{{ $cur->value }}</dd>
                    @if ($invoice->legalCase)
                        <dt>Dossier</dt>
                        <dd>
                            @can('view', $invoice->legalCase)
                                <a href="{{ route('cases.show', $invoice->legalCase) }}" wire:navigate class="font-mono hover:underline">{{ $invoice->legalCase->reference }}</a>
                            @else
                                <span class="font-mono">{{ $invoice->legalCase->reference }}</span>
                            @endcan
                        </dd>
                    @endif
                    @if ($invoice->appointment)
                        <dt>Consultation</dt><dd>{{ $invoice->appointment->starts_at->format('d/m/Y H\hi') }} avec {{ $invoice->appointment->lawyer->name }}</dd>
                    @endif
                    @if ($invoice->issuedBy)<dt>Émise par</dt><dd>{{ $invoice->issuedBy->name }}</dd>@endif
                </dl>
            </x-lex.panel>

            @can('cancel', $invoice)
                <x-lex.panel title="Annuler par un avoir">
                    @if (bccomp((string) $invoice->paid_amount, '0', 2) > 0)
                        <p class="text-sm text-slate-600">Cette facture a déjà reçu des paiements : elle ne peut pas être annulée par un avoir.</p>
                    @else
                        <form wire:submit="cancel" class="grid gap-3">
                            <div>
                                <label for="c-reason" class="lx-label">Motif</label>
                                <textarea id="c-reason" wire:model="reason" rows="2" class="lx-input"></textarea>
                                @error('reason') <p class="lx-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" wire:confirm="Émettre un avoir qui annule cette facture ?" class="lx-btn text-red-700">Émettre l'avoir</button>
                        </form>
                    @endif
                </x-lex.panel>
            @endcan
        </div>
    </div>
</div>
