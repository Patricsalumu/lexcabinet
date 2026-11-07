@php
    use App\Models\TimeEntry;
    use App\Support\Money;
    $canUpdate = auth()->user()->can('update', $case);
    $canLogTime = auth()->user()->can('logTime', $case);
@endphp

<div>
    <x-lex.page-header :title="$case->title" :subtitle="$case->reference.' · ouvert le '.$case->opened_on->format('d/m/Y')">
        @if ($canUpdate)
            <x-slot:actions>
                <a href="{{ route('cases.edit', $case) }}" wire:navigate class="lx-btn-primary">Modifier</a>
            </x-slot:actions>
        @endif
    </x-lex.page-header>
    <x-lex.flash />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_1fr]">
        {{-- Colonne de gauche : fiche du dossier --}}
        <div class="flex flex-col gap-6">
            <x-lex.panel title="Dossier">
                <dl class="lx-kv">
                    <dt>Client</dt>
                    <dd>
                        @can('view', $case->client)
                            <a href="{{ route('clients.show', $case->client) }}" wire:navigate class="hover:underline">{{ $case->client->name }}</a>
                        @else
                            {{ $case->client->name }}
                        @endcan
                        <span class="block text-xs text-slate-500">{{ $case->client->address ?? '—' }} · {{ $case->client->phone ?? '—' }}</span>
                    </dd>
                    <dt>Partie adverse</dt><dd>{{ $case->adverse_party ?? '—' }}</dd>
                    <dt>Matière</dt><dd>{{ $case->matter->label() }}</dd>
                    <dt>Juridiction</dt><dd>{{ $case->jurisdiction ?? '—' }} <span class="font-mono">{{ $case->docket_number }}</span></dd>
                    <dt>Avocat</dt>
                    <dd>{{ $case->leadLawyer->name }}@if ($case->collaborators->isNotEmpty()) <span class="text-slate-500">avec {{ $case->collaborators->pluck('name')->join(', ', ' et ') }}</span>@endif</dd>
                    <dt>Honoraires</dt>
                    <dd>{{ $case->fee_type->label() }} · <span class="font-mono">{{ Money::format((string) $case->fee_amount, $case->currency) }}</span>{{ $case->fee_type->amountSuffix() }}</dd>
                    @if ($case->fee_type === App\Enums\FeeType::Subscription)
                        <dt>Prochaine facture</dt>
                        <dd>{{ $case->status->isActive() && $case->next_subscription_invoice_on ? $case->next_subscription_invoice_on->format('d/m/Y') : 'Abonnement arrêté (dossier clôturé)' }}</dd>
                    @endif
                    <dt>Statut</dt>
                    <dd>
                        @if ($canUpdate)
                            <label for="case-status" class="sr-only">Statut</label>
                            <select id="case-status" wire:model.live="status" class="lx-input mt-0 w-auto py-1">
                                @foreach ($statuses as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </select>
                        @else
                            <span class="lx-badge {{ $case->status->badgeClass() }}">{{ $case->status->label() }}</span>
                        @endif
                        @if ($case->closed_on)
                            <span class="block text-xs text-slate-500">depuis le {{ $case->closed_on->format('d/m/Y') }}</span>
                        @endif
                    </dd>
                </dl>
            </x-lex.panel>

            @canany(['viewAny', 'create'], App\Models\Invoice::class)
                <x-lex.panel title="Factures" :note="$invoices->count().' facture(s)'">
                    @forelse ($invoices as $inv)
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 py-2 last:border-0">
                            <a href="{{ route('invoices.show', $inv) }}" wire:navigate class="font-mono text-sm hover:underline">{{ $inv->number ?? 'Brouillon' }}</a>
                            <span class="font-mono text-sm">{{ Money::format((string) $inv->total, $inv->currency) }}</span>
                            <span class="lx-badge {{ $inv->status->badgeClass() }}">{{ $inv->statusLabel() }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Aucune facture.</p>
                    @endforelse
                    @can('create', App\Models\Invoice::class)
                        <a href="{{ route('invoices.create', ['case' => $case->id]) }}" wire:navigate class="lx-btn mt-3">Nouvelle facture</a>
                    @endcan
                </x-lex.panel>
            @endcanany

            <x-lex.panel title="Pièces jointes" :note="$documents->count().' pièce(s)'">
                @forelse ($documents as $doc)
                    <div class="flex items-start justify-between gap-3 border-b border-slate-100 py-2 last:border-0" wire:key="doc-{{ $doc->id }}">
                        <div class="min-w-0">
                            <a href="{{ route('cases.documents.download', [$case, $doc]) }}" class="break-all font-medium text-indigo-900 hover:underline">{{ $doc->original_name }}</a>
                            <span class="block text-xs text-slate-500">{{ $doc->category->label() }} · {{ $doc->sizeLabel() }} · {{ $doc->created_at->format('d/m/Y') }} · {{ $doc->uploader?->name }}</span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Aucune pièce jointe.</p>
                @endforelse

                @if ($canUpdate)
                    <form wire:submit="uploadDocument" class="mt-4 grid gap-3 border-t border-slate-200 pt-4">
                        <div>
                            <label for="doc-cat" class="lx-label">Catégorie</label>
                            <select id="doc-cat" wire:model="uploadCategory" class="lx-input">
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="doc-file" class="lx-label">Fichier (PDF, image ou Word, 20 Mo maximum)</label>
                            <input id="doc-file" type="file" wire:model="upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" class="mt-1 block w-full text-sm">
                            <div wire:loading wire:target="upload" class="lx-hint">Envoi en cours…</div>
                            @error('upload') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        <div><button type="submit" class="lx-btn" wire:loading.attr="disabled">Ajouter la pièce</button></div>
                    </form>
                @endif
            </x-lex.panel>
        </div>

        {{-- Colonne de droite : journal et temps --}}
        <div class="flex flex-col gap-6">
            <x-lex.panel title="Journal du dossier">
                @forelse ($events as $event)
                    <div class="flex gap-3 border-b border-slate-100 py-2 last:border-0" wire:key="event-{{ $event->id }}">
                        <span class="w-24 shrink-0 font-mono text-xs text-slate-500">{{ $event->occurred_at->format('d/m/Y') }}@if ($event->type === App\Enums\CaseEventType::Hearing)<br>{{ $event->occurred_at->format('H\hi') }}@endif</span>
                        <div class="min-w-0 flex-1">
                            <p>{{ $event->description }}</p>
                            @if ($event->chamber)<p class="text-xs text-slate-500">{{ $case->jurisdiction }}, {{ $event->chamber }}</p>@endif
                        </div>
                        <span @class(['lx-badge h-fit', 'bg-indigo-50 text-indigo-900' => $event->type === App\Enums\CaseEventType::Hearing, 'bg-slate-100 text-slate-700' => $event->type !== App\Enums\CaseEventType::Hearing])>{{ $event->type->label() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Aucun événement.</p>
                @endforelse

                @if ($canUpdate)
                    <form wire:submit="addEvent" class="mt-4 grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[10rem_14rem_minmax(0,1fr)]">
                        <div>
                            <label for="ev-type" class="lx-label">Type</label>
                            <select id="ev-type" wire:model.live="eventType" class="lx-input">
                                @foreach ($eventTypes as $t)
                                    <option value="{{ $t->value }}">{{ $t->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="ev-at" class="lx-label">Date et heure</label>
                            <input id="ev-at" type="datetime-local" wire:model="eventAt" class="lx-input">
                            @error('eventAt') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        @if ($eventType === 'hearing')
                            <div>
                                <label for="ev-chamber" class="lx-label">Chambre</label>
                                <input id="ev-chamber" type="text" wire:model="eventChamber" placeholder="Ex. : chambre 2" class="lx-input">
                                @error('eventChamber') <p class="lx-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="sm:col-span-3">
                            <label for="ev-desc" class="lx-label">Description</label>
                            <input id="ev-desc" type="text" wire:model="eventDescription" class="lx-input">
                            @error('eventDescription') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-3"><button type="submit" class="lx-btn">Ajouter au journal</button></div>
                    </form>
                @endif
            </x-lex.panel>

            <x-lex.panel title="Temps passé" :note="$unbilledMinutes ? TimeEntry::formatMinutes($unbilledMinutes).' facturables non facturées' : null">
                <div class="-mx-5 overflow-x-auto">
                    <table class="lx-table">
                        <thead>
                            <tr><th>Date</th><th>Avocat</th><th>Description</th><th class="text-right">Durée</th><th>État</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($timeEntries as $t)
                                <tr wire:key="time-{{ $t->id }}">
                                    <td class="whitespace-nowrap font-mono text-xs">{{ $t->worked_on->format('d/m/Y') }}</td>
                                    <td>{{ $t->user->name }}</td>
                                    <td>{{ $t->description }}</td>
                                    <td class="whitespace-nowrap text-right font-mono">{{ $t->hoursLabel() }}</td>
                                    <td>
                                        @if (! $t->billable)
                                            <span class="lx-badge bg-slate-100 text-slate-700">Non facturable</span>
                                        @elseif ($t->isInvoiced())
                                            <span class="lx-badge bg-emerald-50 text-emerald-800">Facturé</span>
                                        @else
                                            <span class="lx-badge bg-amber-50 text-amber-900">À facturer</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-slate-500">Aucun temps saisi.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($canLogTime)
                    <form wire:submit="logTime" class="mt-4 grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[10rem_5rem_5rem_minmax(0,1fr)]">
                        <div>
                            <label for="t-date" class="lx-label">Date</label>
                            <input id="t-date" type="date" wire:model="timeDate" max="{{ today()->toDateString() }}" class="lx-input">
                            @error('timeDate') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="t-h" class="lx-label">Heures</label>
                            <input id="t-h" type="number" min="0" max="24" wire:model="timeHours" class="lx-input">
                        </div>
                        <div>
                            <label for="t-m" class="lx-label">Minutes</label>
                            <input id="t-m" type="number" min="0" max="59" step="5" wire:model="timeMinutes" class="lx-input">
                        </div>
                        <div>
                            <label for="t-desc" class="lx-label">Description</label>
                            <input id="t-desc" type="text" wire:model="timeDescription" placeholder="Ex. : rédaction de la requête" class="lx-input">
                        </div>
                        <div class="sm:col-span-4">
                            @error('timeHours') <p class="lx-error">{{ $message }}</p> @enderror
                            @error('timeMinutes') <p class="lx-error">{{ $message }}</p> @enderror
                            @error('timeDescription') <p class="lx-error">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" wire:model="timeBillable" class="rounded border-slate-300 text-indigo-800"> Facturable
                        </label>
                        <div class="sm:col-span-2 sm:text-right"><button type="submit" class="lx-btn">Ajouter mon temps</button></div>
                    </form>
                @endif
            </x-lex.panel>
        </div>
    </div>
</div>
