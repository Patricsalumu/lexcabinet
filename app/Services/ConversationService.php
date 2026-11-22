<?php

namespace App\Services;

use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailReply;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Mailbox\ImapMailbox;
use App\Services\Mailbox\InboundEmail;
use App\Support\QuotedText;
use App\Support\Tenancy\CurrentFirm;
use App\Support\Tenancy\FirmScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Conversations par e-mail : relève des réponses dans la boîte du cabinet, droits de lecture,
 * réponse depuis l'application.
 *
 * Seules les réponses à des e-mails envoyés par l'application sont importées (reconnues par le
 * Message-ID qu'elles citent) ; le reste du courrier de la boîte n'entre jamais dans l'application.
 */
class ConversationService
{
    public const DISK = 'local';

    private const LAST_FETCH_KEY = 'lexcabinet.replies.last_fetch_at';

    public function __construct(private readonly EmailService $emails, private readonly ImapMailbox $mailbox) {}

    // ------------------------------------------------------------------ Relève

    /**
     * Relève la boîte et importe les nouvelles réponses. Renvoie le nombre de réponses importées,
     * ou null si la boîte n'est pas configurée.
     */
    public function fetch(): ?int
    {
        if (! $this->mailbox->isConfigured()) {
            return null;
        }

        $startedAt = now();
        $last = Cache::get(self::LAST_FETCH_KEY);
        // IMAP SINCE est à la journée : on repart de la veille de la dernière relève (les doublons sont écartés).
        $since = $last ? Carbon::parse($last)->subDay()->startOfDay() : now()->subDays((int) config('lexcabinet.replies.initial_days', 14))->startOfDay();

        $messages = $this->mailbox->fetchSince($since, fn (string $messageId, array $cited) => $this->isWanted($messageId, $cited));

        $imported = 0;
        foreach ($messages as $message) {
            if ($this->import($message)) {
                $imported++;
            }
        }

        Cache::forever(self::LAST_FETCH_KEY, $startedAt->toIso8601String());

        return $imported;
    }

    /** Message à télécharger : nouveau, et réponse à un e-mail de l'application. */
    public function isWanted(string $messageId, array $cited): bool
    {
        // La boîte est relevée hors de tout cabinet : recherche sur l'ensemble des cabinets.
        if (EmailReply::query()->withoutGlobalScope(FirmScope::class)->where('message_id', $messageId)->exists()
            || EmailLog::query()->withoutGlobalScope(FirmScope::class)->where('message_id', $messageId)->exists()) {
            return false;
        }

        return $this->answeredLog($cited) !== null;
    }

    /** Importe une réponse ; null si ce n'est pas une réponse à nos e-mails ou si elle est déjà connue. */
    public function import(InboundEmail $mail): ?EmailReply
    {
        if (! $this->isWanted($mail->messageId, $mail->citedIds())) {
            return null;
        }

        $log = $this->answeredLog($mail->citedIds());

        // La réponse est enregistrée dans le cabinet de l'e-mail auquel elle répond.
        return app(CurrentFirm::class)->run($log->firm_id, fn () => DB::transaction(function () use ($mail, $log) {
            $sender = Client::query()->where('email', $mail->fromEmail)->first()
                ?? User::query()->where('email', $mail->fromEmail)->first();

            $reply = EmailReply::query()->create([
                'email_log_id' => $log->id,
                'thread_id' => $log->thread_id ?? $log->id,
                'message_id' => Str::limit($mail->messageId, 188, ''),
                'in_reply_to' => $mail->inReplyTo ? Str::limit($mail->inReplyTo, 188, '') : null,
                'references' => implode(' ', $mail->references) ?: null,
                'from_email' => $mail->fromEmail,
                'from_name' => $mail->fromName,
                'sender_type' => $sender?->getMorphClass(),
                'sender_id' => $sender?->getKey(),
                'subject' => Str::limit($mail->subject, 250),
                'body' => trim($mail->text) !== '' ? trim($mail->text) : QuotedText::fromHtml($mail->html),
                'folder' => $mail->folder,
                'received_at' => $mail->date,
            ]);

            $reply->update(['attachments' => $this->storeAttachments($reply, $mail->attachments)]);

            activity('emails')->performedOn($reply)
                ->withProperties(['from' => $mail->fromEmail, 'email_log_id' => $log->id])
                ->log('Réponse reçue par e-mail');

            return $reply;
        }));
    }

    /** E-mail de l'application cité par la réponse (le plus proche d'abord). */
    private function answeredLog(array $cited): ?EmailLog
    {
        foreach ($cited as $id) {
            $log = EmailLog::query()->withoutGlobalScope(FirmScope::class)->where('message_id', $id)->first();
            if ($log) {
                return $log;
            }

            // Réponse à une réponse déjà importée : même conversation.
            $reply = EmailReply::query()->withoutGlobalScope(FirmScope::class)->where('message_id', $id)
                ->with(['emailLog' => fn ($q) => $q->withoutGlobalScope(FirmScope::class)])->first();
            if ($reply) {
                return $reply->emailLog;
            }
        }

        return null;
    }

    /** @return list<array{name: string, path: ?string, size: int, mime: ?string, skipped?: string}> */
    private function storeAttachments(EmailReply $reply, array $attachments): array
    {
        $max = (int) config('lexcabinet.replies.max_attachment_size');
        $stored = [];

        foreach ($attachments as $i => $attachment) {
            $name = Str::limit(trim(basename(str_replace('\\', '/', $attachment['name']))) ?: 'piece-jointe', 150, '');
            $size = strlen($attachment['content']);

            if ($size > $max) {
                $stored[] = ['name' => $name, 'path' => null, 'size' => $size, 'mime' => $attachment['mime'], 'skipped' => 'Trop volumineuse : consultez-la dans la boîte aux lettres.'];

                continue;
            }

            $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
            $path = "firms/{$reply->firm_id}/email-replies/{$reply->id}/".($i + 1).'-'.Str::uuid().($extension !== '' ? '.'.preg_replace('/[^a-z0-9]/', '', $extension) : '');
            Storage::disk(self::DISK)->put($path, $attachment['content']);
            $stored[] = ['name' => $name, 'path' => $path, 'size' => $size, 'mime' => $attachment['mime']];
        }

        return $stored;
    }

    // ------------------------------------------------------------------ Droits

    /**
     * Conversations visibles : toutes pour le journal des e-mails (secrétariat, administrateur) ;
     * pour un avocat, celles qui lui sont adressées ou qui portent sur ses rendez-vous,
     * ses dossiers et leurs factures (secret professionnel).
     */
    public function visibleThreads(User $user): Builder
    {
        $query = EmailLog::query()->whereNull('thread_id')->whereHas('threadReplies');

        if ($user->can(Permission::EmailsView->value)) {
            return $query;
        }

        if (! $user->can(Permission::CasesViewOwn->value)) {
            return $query->whereRaw('1 = 0');
        }

        $cases = LegalCase::query()->assignedTo($user)->select('id');

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $r) => $r->where('recipient_type', $user->getMorphClass())->where('recipient_id', $user->id))
            ->orWhere(fn (Builder $r) => $r->where('related_type', (new Appointment)->getMorphClass())
                ->whereIn('related_id', Appointment::query()->where('lawyer_id', $user->id)->select('id')))
            ->orWhere(fn (Builder $r) => $r->where('related_type', (new LegalCase)->getMorphClass())->whereIn('related_id', $cases))
            ->orWhere(fn (Builder $r) => $r->where('related_type', (new Invoice)->getMorphClass())
                ->whereIn('related_id', Invoice::query()->whereIn('case_id', $cases)->select('id'))));
    }

    public function canView(User $user, EmailLog $thread): bool
    {
        return $this->visibleThreads($user)->whereKey($thread->id)->exists();
    }

    public function canReply(User $user, EmailLog $thread): bool
    {
        return ($user->can(Permission::RemindersSend->value) || $user->can(Permission::CasesViewOwn->value))
            && $this->canView($user, $thread);
    }

    public function unreadCount(User $user): int
    {
        return EmailReply::query()->whereNull('read_at')
            ->whereIn('thread_id', $this->visibleThreads($user)->select('email_logs.id'))
            ->count();
    }

    // ------------------------------------------------------------------ Lecture et réponse

    /**
     * Messages de la conversation dans l'ordre chronologique : e-mails envoyés et réponses reçues.
     *
     * @return Collection<int, array{type: string, at: Carbon, model: EmailLog|EmailReply}>
     */
    public function messages(EmailLog $thread): Collection
    {
        $sent = collect([$thread])->concat($thread->threadMessages()->with('triggeredBy')->get())
            ->map(fn (EmailLog $log) => ['type' => 'sent', 'at' => $log->sent_at ?? $log->created_at, 'model' => $log]);
        $received = $thread->threadReplies()->with('sender')->get()
            ->map(fn (EmailReply $reply) => ['type' => 'received', 'at' => $reply->received_at, 'model' => $reply]);

        return $sent->concat($received)->sortBy(fn (array $m) => $m['at']->getTimestamp())->values();
    }

    public function markRead(EmailLog $thread, User $user): void
    {
        $thread->threadReplies()->whereNull('read_at')->update(['read_at' => now(), 'read_by' => $user->id]);
    }

    /**
     * Répond dans la conversation, au dernier correspondant (ou au destinataire d'origine).
     * L'e-mail passe par le journal d'envoi habituel (consentement, file d'attente, relance).
     */
    public function reply(EmailLog $thread, string $message, User $by): EmailLog
    {
        if (! $this->canReply($by, $thread)) {
            abort(403);
        }

        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Écrivez votre réponse.']);
        }

        $last = $thread->threadReplies()->latest('received_at')->latest('id')->first();
        $recipient = $this->replyRecipient($thread, $last);
        if ($recipient === null) {
            throw ValidationException::withMessages(['message' => 'Destinataire introuvable : ni la fiche du correspondant, ni celle du destinataire d\'origine.']);
        }

        // Fil de discussion : on répond au dernier message reçu, en reprenant toute la chaîne.
        $previous = $last?->message_id ?? $thread->message_id;
        $references = array_values(array_unique(array_filter([
            ...($last ? $last->referenceIds() : $thread->referenceIds()),
            $thread->message_id,
            $previous,
        ])));

        $subject = preg_match('/^(re|tr|fwd?)\s*:/i', $thread->subject) ? $thread->subject : 'RE : '.$thread->subject;

        return DB::transaction(function () use ($thread, $message, $by, $recipient, $previous, $references, $subject) {
            $log = $this->emails->send(EmailTemplate::CONVERSATION_REPLY, $recipient, [
                'sujet' => $subject,
                'message' => $message,
                'destinataire' => $recipient->name,
                'createur' => $by->name,
            ], $thread->related, [], $by, threading: [
                'thread_id' => $thread->id,
                'in_reply_to' => $previous,
                'references' => implode(' ', $references),
            ]);

            $this->markRead($thread, $by);
            activity('emails')->causedBy($by)->performedOn($log)->log('Réponse envoyée depuis la messagerie');

            return $log;
        });
    }

    /** Destinataire de la réponse : l'auteur du dernier message s'il est connu, sinon le destinataire d'origine. */
    public function replyRecipient(EmailLog $thread, ?EmailReply $last = null): Client|User|null
    {
        $last ??= $thread->threadReplies()->latest('received_at')->latest('id')->first();
        $sender = $last?->sender;
        if ($sender instanceof Client || $sender instanceof User) {
            return $sender;
        }

        $recipient = $thread->recipient;

        return $recipient instanceof Client || $recipient instanceof User ? $recipient : null;
    }

    /** Statut lisible d'un e-mail envoyé, pour le fil. */
    public static function sentStatus(EmailLog $log): string
    {
        return match ($log->status) {
            EmailStatus::Sent => 'Envoyé',
            EmailStatus::Queued => 'En file d\'attente',
            default => $log->status->label().($log->reason ? ' : '.$log->reason : ''),
        };
    }
}
