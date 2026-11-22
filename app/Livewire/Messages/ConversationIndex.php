<?php

namespace App\Livewire\Messages;

use App\Enums\EmailStatus;
use App\Enums\Module;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Services\ConversationService;
use App\Services\Mailbox\ImapMailbox;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Messagerie : conversations nées des e-mails de l'application, avec les réponses reçues
 * dans la boîte du cabinet et les réponses envoyées depuis l'application.
 */
#[Layout('layouts.app')]
#[Title('Messages')]
class ConversationIndex extends Component
{
    use WithPagination;

    #[Url(as: 'c')]
    public ?int $selected = null;

    #[Url]
    public bool $unread = false;

    #[Validate('required|string|max:10000', as: 'réponse')]
    public string $message = '';

    public function mount(): void
    {
        $this->authorize('view-module', Module::Messages);
    }

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->message = '';
        $this->resetValidation();
    }

    public function updatingUnread(): void
    {
        $this->resetPage();
    }

    public function send(ConversationService $conversations): void
    {
        $thread = $this->thread($conversations) ?? $this->firstThread($conversations);
        abort_unless($thread && $conversations->canReply(auth()->user(), $thread), 403);
        $this->validate();

        $log = $conversations->reply($thread, $this->message, auth()->user());
        $this->message = '';

        $log->status === EmailStatus::NotSent
            ? session()->flash('error', 'Réponse non envoyée : '.$log->reason)
            : session()->flash('status', 'Réponse envoyée.');
    }

    public function render(ConversationService $conversations, ImapMailbox $mailbox): View
    {
        $user = auth()->user();

        $threads = $conversations->visibleThreads($user)
            ->withMax('threadReplies', 'received_at')
            ->withCount(['threadReplies as unread_count' => fn ($q) => $q->whereNull('read_at')])
            ->when($this->unread, fn ($q) => $q->whereHas('threadReplies', fn ($r) => $r->whereNull('read_at')))
            ->orderByDesc('thread_replies_max_received_at')
            ->paginate(20);

        $current = $this->thread($conversations) ?? ($this->selected === null ? $threads->first() : null);
        if ($current) {
            $conversations->markRead($current, $user);
        }

        return view('livewire.messages.index', [
            'threads' => $threads,
            'current' => $current,
            'messages' => $current ? $conversations->messages($current) : collect(),
            'canReply' => $current && $conversations->canReply($user, $current),
            'replyTo' => $current ? $conversations->replyRecipient($current) : null,
            'mailboxWarning' => $this->mailboxWarning($mailbox),
        ]);
    }

    private function thread(ConversationService $conversations): ?EmailLog
    {
        if ($this->selected === null) {
            return null;
        }

        return $conversations->visibleThreads(auth()->user())->with('recipient')->find($this->selected);
    }

    /** Conversation affichée par défaut : la plus récente (même ordre que la liste). */
    private function firstThread(ConversationService $conversations): ?EmailLog
    {
        if ($this->selected !== null) {
            return null;
        }

        return $conversations->visibleThreads(auth()->user())->with('recipient')
            ->withMax('threadReplies', 'received_at')->orderByDesc('thread_replies_max_received_at')->first();
    }

    /** Signale une boîte non configurée, ou des réponses qui arrivent ailleurs que dans la boîte lue. */
    private function mailboxWarning(ImapMailbox $mailbox): ?string
    {
        if (! $mailbox->isConfigured()) {
            return 'La boîte de réception n\'est pas configurée (IMAP_HOST, IMAP_USERNAME dans .env) : les réponses ne sont pas relevées.';
        }

        $replyTo = TemplatedMail::replyAddress();
        $read = $mailbox->address();
        if ($read !== null && strcasecmp($read, $replyTo) !== 0) {
            return "Les clients répondent à {$replyTo}, mais l'application lit la boîte {$read} : leurs réponses n'apparaîtront pas ici. Alignez l'e-mail du cabinet (Paramètres) et IMAP_USERNAME.";
        }

        return null;
    }
}
