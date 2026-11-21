<?php

namespace App\Listeners;

use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Passe l'entrée du journal d'envoi à « Envoyé » quand le transport a accepté le message.
 *
 * Avec les transports de développement (log, array), rien n'est réellement délivré :
 * le journal le précise, pour ne pas laisser croire que le client a reçu l'e-mail.
 */
class MarkEmailAsSent
{
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    public function handle(MessageSent $event): void
    {
        $header = $event->message->getHeaders()->get(TemplatedMail::LOG_HEADER);
        if ($header === null) {
            return;
        }

        // Le transport est fixé à la mise en file (configuration du processus web), pas par le worker.
        $mailer = $event->message->getHeaders()->get(TemplatedMail::MAILER_HEADER)?->getBodyAsString()
            ?? (string) config('mail.default');

        EmailLog::query()->whereKey((int) $header->getBodyAsString())->update([
            'status' => EmailStatus::Sent->value,
            'reason' => in_array($mailer, self::NON_DELIVERING_MAILERS, true)
                ? "Non délivré : transport « {$mailer} » (développement), le message est seulement écrit dans le journal de l'application."
                : "Remis au serveur d'envoi (transport « {$mailer} »).",
            'sent_at' => now(),
        ]);
    }
}
