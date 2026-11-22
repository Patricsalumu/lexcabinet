<div>
    <x-lex.page-header title="Messages"
        subtitle="Réponses reçues aux e-mails du cabinet ; elles restent aussi dans la boîte aux lettres (Roundcube, Gmail…)." />
    <x-lex.flash />
    @if (session('error'))
        <div class="lx-crit mb-4" role="alert">{{ session('error') }}</div>
    @endif
    @if ($mailboxWarning)
        <div class="lx-warn mb-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">{{ $mailboxWarning }}</div>
    @endif

    <div class="mb-4">
        <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="unread" class="rounded border-slate-300"> Non lues seulement
        </label>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,24rem)_1fr]">
        <x-lex.panel>
            <div class="-m-5">
                @forelse ($threads as $thread)
                    <button type="button" wire:click="select({{ $thread->id }})" wire:key="t-{{ $thread->id }}"
                            @class(['flex w-full items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 text-left hover:bg-slate-50',
                                    'bg-indigo-50/60' => $current && $current->id === $thread->id])>
                        <span class="min-w-0">
                            <span @class(['block truncate', 'font-semibold' => $thread->unread_count > 0, 'font-medium' => $thread->unread_count === 0])>{{ $thread->subject }}</span>
                            <span class="block text-xs text-slate-500">{{ $thread->recipient_name }} · {{ \Illuminate\Support\Carbon::parse($thread->thread_replies_max_received_at)->format('d/m/Y H:i') }}</span>
                        </span>
                        @if ($thread->unread_count > 0)
                            <span class="lx-badge h-fit bg-indigo-700 text-white">{{ $thread->unread_count }}</span>
                        @endif
                    </button>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">Aucune réponse reçue pour l'instant.</p>
                @endforelse
            </div>
            <div class="mt-8">{{ $threads->links() }}</div>
        </x-lex.panel>

        <x-lex.panel>
            @if ($current)
                <h2 class="mb-4 font-display text-lg font-semibold text-slate-900">{{ $current->subject }}</h2>
                <ol class="space-y-4">
                    @foreach ($messages as $item)
                        @php $m = $item['model']; @endphp
                        @if ($item['type'] === 'sent')
                            <li class="rounded-md border border-slate-200 bg-slate-50 p-4" wire:key="s-{{ $m->id }}">
                                <p class="mb-2 text-xs text-slate-500">
                                    <span class="font-semibold text-slate-700">{{ $m->triggeredBy?->name ? $m->triggeredBy->name.' (cabinet)' : 'Cabinet' }}</span>
                                    → {{ $m->recipient_name }} · {{ $item['at']->format('d/m/Y à H:i') }}
                                    · {{ \App\Services\ConversationService::sentStatus($m) }}
                                </p>
                                <details>
                                    <summary class="cursor-pointer text-sm text-slate-700">{{ \Illuminate\Support\Str::limit(strtok($m->body, "\n"), 140) }}</summary>
                                    <pre class="mt-2 whitespace-pre-wrap font-sans text-sm">{{ $m->body }}</pre>
                                </details>
                            </li>
                        @else
                            <li class="rounded-md border border-indigo-200 bg-white p-4" wire:key="r-{{ $m->id }}">
                                <p class="mb-2 text-xs text-slate-500">
                                    <span class="font-semibold text-slate-900">{{ $m->from_name ?: $m->from_email }}</span>
                                    &lt;{{ $m->from_email }}&gt; · {{ $item['at']->format('d/m/Y à H:i') }}
                                    @if (! $m->sender)<span class="lx-badge bg-amber-100 text-amber-900">adresse inconnue</span>@endif
                                </p>
                                <pre class="whitespace-pre-wrap font-sans text-sm">{{ $m->excerpt() }}</pre>
                                @if ($m->excerpt() !== trim($m->body))
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs text-slate-500">Afficher le message complet</summary>
                                        <pre class="mt-2 whitespace-pre-wrap font-sans text-xs text-slate-600">{{ $m->body }}</pre>
                                    </details>
                                @endif
                                @if ($m->attachments)
                                    <p class="mt-3 flex flex-wrap gap-2 text-sm">
                                        @foreach ($m->attachments as $i => $file)
                                            @if ($file['path'])
                                                <a href="{{ route('messages.attachment', [$m->id, $i]) }}" class="lx-badge bg-slate-100 text-indigo-800 underline">{{ $file['name'] }}</a>
                                            @else
                                                <span class="lx-badge bg-slate-100 text-slate-600" title="{{ $file['skipped'] ?? '' }}">{{ $file['name'] }} (non conservée)</span>
                                            @endif
                                        @endforeach
                                    </p>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ol>

                @if ($canReply)
                    <form wire:submit="send" class="mt-6">
                        <label for="reply" class="block text-sm font-medium text-slate-700">
                            Répondre à {{ $replyTo?->name ?? '—' }} @if ($replyTo?->email)&lt;{{ $replyTo->email }}&gt;@endif
                        </label>
                        <textarea id="reply" wire:model="message" rows="6" class="lx-input" placeholder="Votre réponse…"></textarea>
                        @error('message')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-slate-500">Votre nom et celui du cabinet sont ajoutés en signature ; pour un client, les moyens de paiement et les coordonnées aussi.</p>
                        <button type="submit" class="lx-btn mt-3" wire:loading.attr="disabled">Envoyer la réponse</button>
                    </form>
                @endif
            @else
                <p class="text-sm text-slate-500">Aucune conversation sélectionnée.</p>
            @endif
        </x-lex.panel>
    </div>
</div>
