@php
    $title = match ($view) {
        'day' => ucfirst($from->translatedFormat('l j F Y')),
        'list' => 'Du '.$from->translatedFormat('j F').' au '.$to->translatedFormat('j F Y'),
        default => 'Semaine du '.$from->translatedFormat('j F').' au '.$to->translatedFormat('j F Y'),
    };
@endphp

<div>
    <x-lex.page-header title="Agenda" :subtitle="$title">
        <x-slot:actions>
            @if ($canRemind)
                <button type="button" wire:click="sendReminders" wire:confirm="Envoyer maintenant les rappels des rendez-vous de demain ?" class="lx-btn">
                    Envoyer les rappels de demain @if ($pendingReminders) ({{ $pendingReminders }}) @endif
                </button>
            @endif
            @if ($canManage)
                <a href="{{ route('agenda.create', ['date' => $view === 'day' ? $from->toDateString() : null, 'lawyer' => $lawyer ?: null]) }}" wire:navigate class="lx-btn-primary">Nouveau rendez-vous</a>
            @endif
        </x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />
    @if (session('error'))
        <div class="lx-crit mb-4" role="alert">{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Vue">
            @foreach (['day' => 'Jour', 'week' => 'Semaine', 'list' => 'Liste'] as $key => $label)
                <button type="button" wire:click="$set('view', '{{ $key }}')"
                        @class(['rounded px-3 py-1 text-sm', 'bg-indigo-800 text-white' => $view === $key, 'text-slate-700 hover:bg-slate-100' => $view !== $key])
                        @if ($view === $key) aria-pressed="true" @endif>{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="move(-1)" class="lx-btn" aria-label="Période précédente">‹ Précédent</button>
        <button type="button" wire:click="today" class="lx-btn">Aujourd'hui</button>
        <button type="button" wire:click="move(1)" class="lx-btn" aria-label="Période suivante">Suivant ›</button>
        <label for="agenda-date" class="sr-only">Date</label>
        <input id="agenda-date" type="date" wire:model.live="date" class="lx-input mt-0 w-auto py-1.5">
        <span class="grow"></span>
        <label for="agenda-lawyer" class="text-sm text-slate-600">Avocat</label>
        <select id="agenda-lawyer" wire:model.live="lawyer" class="lx-input mt-0 w-auto py-1.5">
            <option value="">Tous les avocats</option>
            @foreach ($lawyers as $l)
                <option value="{{ $l->id }}">{{ $l->name }}</option>
            @endforeach
        </select>
    </div>

    <x-lex.panel>
        @php
            $groups = $days
                ? $days->mapWithKeys(fn ($d) => [$d->toDateString() => $entries->get($d->toDateString(), collect())])
                : $entries;
        @endphp

        @forelse ($groups as $dayKey => $items)
            @php $day = \Illuminate\Support\Carbon::parse($dayKey); @endphp
            <section @class(['border-b border-slate-100 py-3 first:pt-0 last:border-0 last:pb-0', 'bg-indigo-50/40 -mx-5 px-5' => $day->isToday()])>
                <h2 class="mb-2 text-sm font-semibold text-slate-900">
                    {{ ucfirst($day->translatedFormat('l')) }}
                    <span class="font-normal text-slate-500">{{ $day->translatedFormat('j F') }}{{ $day->isToday() ? ' · aujourd\'hui' : '' }}</span>
                </h2>

                @forelse ($items as $entry)
                    @if ($entry['kind'] === 'appointment')
                        @php $a = $entry['appointment']; @endphp
                        <div wire:key="appt-{{ $a->id }}" @class(['grid gap-3 border-t border-slate-100 py-2 sm:grid-cols-[5rem_minmax(0,1fr)_auto]', 'opacity-60' => ! $a->status->occupiesSlot()])>
                            <div class="font-mono text-sm">{{ $a->starts_at->format('H\hi') }}<span class="block text-xs text-slate-500">{{ $a->ends_at->format('H\hi') }}</span></div>
                            <div class="min-w-0">
                                <p>
                                    <b>{{ $a->client->name }}</b>
                                    @if ($a->client->is_prospect)<span class="lx-badge bg-amber-50 text-amber-900">Prospect</span>@endif
                                    <span class="lx-badge bg-slate-100 text-slate-700">{{ $a->type->label() }}</span>
                                </p>
                                <p class="text-slate-700">{{ $a->subject }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $a->lawyer->name }} · {{ $a->locationLabel() }}
                                    @if ($a->legalCase)
                                        ·
                                        @if (in_array($a->case_id, $visibleCaseIds, true))
                                            <a href="{{ route('cases.show', $a->legalCase) }}" wire:navigate class="font-mono hover:underline">{{ $a->legalCase->reference }}</a>
                                        @else
                                            <span class="font-mono">{{ $a->legalCase->reference }}</span>
                                        @endif
                                    @endif
                                    @if ($a->reminder_sent_at) · rappel envoyé @endif
                                </p>
                                @if ($canManage && $a->internal_notes)
                                    <p class="mt-1 text-xs italic text-slate-500">Note interne : {{ $a->internal_notes }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                                @if ($canManage)
                                    <label for="st-{{ $a->id }}" class="sr-only">Statut</label>
                                    <select id="st-{{ $a->id }}" class="lx-input mt-0 w-auto py-1 text-xs"
                                            x-on:change="if (['cancelled','postponed'].includes($event.target.value) && ! confirm('Le client sera prévenu par e-mail. Continuer ?')) { $event.target.value = '{{ $a->status->value }}'; return; } $wire.setStatus({{ $a->id }}, $event.target.value)">
                                        @foreach ($statuses as $s)
                                            <option value="{{ $s->value }}" @selected($a->status === $s)>{{ $s->label() }}</option>
                                        @endforeach
                                    </select>
                                    <a href="{{ route('agenda.edit', $a) }}" wire:navigate class="lx-btn py-1 text-xs">Modifier</a>
                                @else
                                    <span class="lx-badge {{ $a->status->badgeClass() }}">{{ $a->status->label() }}</span>
                                @endif
                            </div>
                        </div>
                    @else
                        @php $e = $entry['event']; @endphp
                        <div wire:key="hearing-{{ $e->id }}" class="grid gap-3 border-t border-slate-100 py-2 sm:grid-cols-[5rem_minmax(0,1fr)_auto]">
                            <div class="font-mono text-sm">{{ $e->occurred_at->format('H\hi') }}</div>
                            <div class="min-w-0">
                                <p><b>{{ $e->legalCase->client->name }}</b> <span class="lx-badge bg-indigo-50 text-indigo-900">Audience du dossier</span></p>
                                <p class="text-slate-700">{{ $e->description }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $e->legalCase->leadLawyer->name }} · {{ $e->legalCase->jurisdiction ?? 'Juridiction non précisée' }}{{ $e->chamber ? ', '.$e->chamber : '' }}
                                    · <a href="{{ route('cases.show', $e->legalCase) }}" wire:navigate class="font-mono hover:underline">{{ $e->legalCase->reference }}</a>
                                </p>
                            </div>
                            <div></div>
                        </div>
                    @endif
                @empty
                    <p class="text-sm text-slate-500">Aucun rendez-vous.</p>
                @endforelse
            </section>
        @empty
            <p class="text-sm text-slate-500">Aucun rendez-vous sur les 30 prochains jours.</p>
        @endforelse
    </x-lex.panel>
</div>
