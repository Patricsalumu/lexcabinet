<?php

namespace App\Listeners;

use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\Mailbox\ImapMailbox;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dépose une copie des réponses écrites depuis l'application dans le dossier « Envoyés »
 * de la boîte du cabinet : la conversation est complète dans Roundcube, Gmail, etc.
 * Un échec de copie n'empêche jamais l'envoi (il est seulement noté dans le journal technique).
 */
class CopyReplyToSentFolder
{
    public function __construct(private readonly ImapMailbox $mailbox) {}

    public function handle(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(TemplatedMail::LOG_HEADER);
        if ($header === null || ! $this->mailbox->isConfigured()) {
            return;
        }

        $mailer = $event->message->getHeaders()->get(TemplatedMail::MAILER_HEADER)?->getBodyAsString();
        if (in_array($mailer, ['log', 'array'], true)) {
            return;
        }

        $templateKey = EmailLog::query()->whereKey((int) $header->getBodyAsString())->value('template_key');
        if ($templateKey !== EmailTemplate::CONVERSATION_REPLY) {
            return;
        }

        try {
            $this->mailbox->appendToSent($event->sent->toString());
        } catch (Throwable $e) {
            Log::warning('Copie de la réponse dans « Envoyés » impossible : '.$e->getMessage());
        }
    }
}
