<div>
    <x-lex.page-header title="E-mails"
        :subtitle="'Envoyés par la file d\'attente · '.($counts['sent'] ?? 0).' envoyé(s), '.(($counts['failed'] ?? 0) + ($counts['not_sent'] ?? 0)).' à traiter, '.($counts['queued'] ?? 0).' en attente'">
        @if ($canSend)
            <x-slot:actions>
                <button type="button" wire:click="sendRecap" wire:confirm="Envoyer maintenant le récapitulatif du jour aux avocats ?" class="lx-btn">Envoyer le récapitulatif du jour</button>
            </x-slot:actions>
        @endif
    </x-lex.page-header>
    <x-lex.email-tabs />
    <x-lex.flash />
    @if (session('error'))
        <div class="lx-crit mb-4" role="alert">{{ session('error') }}</div>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <div>
            <label for="m-status" class="sr-only">Statut</label>
            <select id="m-status" wire:model.live="status" class="lx-input mt-0 w-auto">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="m-tpl" class="sr-only">Modèle</label>
            <select id="m-tpl" wire:model.live="template" class="lx-input mt-0 w-auto">
                <option value="">Tous les modèles</option>
                @foreach ($templates as $key => $name)
                    <option value="{{ $key }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,24rem)_1fr]">
        <x-lex.panel>
            <div class="-m-5">
                @forelse ($logs as $log)
                    <button type="button" wire:key="log-{{ $log->id }}" wire:click="select({{ $log->id }})"
                            @class(['grid w-full grid-cols-[minmax(0,1fr)_auto] gap-2 border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50',
                                    'bg-indigo-50/60' => $current && $current->id === $log->id])>
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{{ $log->subject }}</span>
                            <span class="block text-xs text-slate-500">{{ $log->recipient_name }} · {{ $log->created_at->format('d/m/Y H:i') }}</span>
                        </span>
                        <span class="lx-badge h-fit {{ $log->status->badgeClass() }}">{{ $log->status->label() }}</span>
                    </button>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">Aucun e-mail.</p>
                @endforelse
            </div>
            <div class="mt-8">{{ $logs->links() }}</div>
        </x-lex.panel>

        <x-lex.panel>
            @if ($current)
                <dl class="lx-kv mb-4">
                    <dt>À</dt><dd>{{ $current->recipient_name }} @if ($current->recipient_email)&lt;{{ $current->recipient_email }}&gt;@endif</dd>
                    <dt>Modèle</dt><dd>{{ $templates[$current->template_key] ?? $current->template_key }}</dd>
                    <dt>Objet</dt><dd class="font-semibold">{{ $current->subject }}</dd>
                    <dt>État</dt>
                    <dd>
                        <span class="lx-badge {{ $current->status->badgeClass() }}">{{ $current->status->label() }}</span>
                        @if ($current->reason)<span class="text-sm text-slate-600">{{ $current->reason }}</span>@endif
                    </dd>
                    <dt>Créé le</dt><dd>{{ $current->created_at->format('d/m/Y à H:i') }}{{ $current->triggeredBy ? ' par '.$current->triggeredBy->name : ' (tâche automatique)' }}</dd>
                    @if ($current->sent_at)<dt>Envoyé le</dt><dd>{{ $current->sent_at->format('d/m/Y à H:i') }}</dd>@endif
                    @if ($current->attempts)<dt>Tentatives</dt><dd>{{ $current->attempts }}</dd>@endif
                </dl>
                <pre class="whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-50 p-4 font-sans text-sm">{{ $current->body }}</pre>
                @if ($current->attachmentNames())
                    <p class="mt-3 text-sm">Pièces jointes :
                        @foreach ($current->attachmentNames() as $name)<span class="lx-badge bg-slate-100 text-slate-700">{{ $name }}</span> @endforeach
                    </p>
                @endif
                @if ($canSend && $current->status->canResend())
                    <button type="button" wire:click="resend({{ $current->id }})" class="lx-btn mt-4">Relancer l'envoi</button>
                @endif
            @else
                <p class="text-sm text-slate-500">Aucun e-mail sélectionné.</p>
            @endif
        </x-lex.panel>
    </div>
</div>
