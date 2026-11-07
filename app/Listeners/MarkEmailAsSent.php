<?php

namespace App\Listeners;

use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Passe l'entrée du journal d'envoi à « Envoyé » quand le message est parti.
 */
class MarkEmailAsSent
{
    public function handle(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(TemplatedMail::LOG_HEADER);
        if ($header === null) {
            return;
        }

        EmailLog::query()->whereKey((int) $header->getBodyAsString())->update([
            'status' => EmailStatus::Sent->value,
            'reason' => null,
            'sent_at' => now(),
        ]);
    }
}
