<?php

namespace App\Services;

use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Envoi des e-mails à partir des modèles (NOT-01 à NOT-07).
 * Chaque envoi est journalisé : en file d'attente, envoyé, échec, ou non envoyé avec son motif.
 * Aucun e-mail ne part vers un client qui n'a pas donné son consentement (CLI-03).
 */
class EmailService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, string|null>  $vars
     * @param  list<array<string, string>>  $attachments
     */
    public function send(string $templateKey, Client|User $recipient, array $vars, ?Model $related = null, array $attachments = [], ?User $by = null, ?EmailLog $resentFrom = null): EmailLog
    {
        $template = EmailTemplate::query()->where('key', $templateKey)->first()
            ?? throw new InvalidArgumentException("Modèle d'e-mail inconnu : {$templateKey}");

        $vars = $this->baseVars() + $vars;
        $reason = $this->refusalReason($recipient);

        $log = EmailLog::query()->create([
            'template_key' => $templateKey,
            'recipient_name' => $recipient->name,
            'recipient_email' => $recipient->email,
            'subject' => EmailTemplate::render($template->subject, $vars),
            'body' => EmailTemplate::render($template->body, $vars),
            'attachments' => $attachments,
            'status' => $reason ? EmailStatus::NotSent : EmailStatus::Queued,
            'reason' => $reason,
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'triggered_by' => $by?->id,
            'resent_from_id' => $resentFrom?->id,
        ]);

        if ($reason === null) {
            Mail::to($recipient->email, $recipient->name)->queue(new TemplatedMail($log->id));
        }

        return $log->refresh();
    }

    /**
     * Relance manuelle (NOT-07) : nouvel envoi avec les coordonnées et le consentement actuels.
     */
    public function resend(EmailLog $log, User $by): EmailLog
    {
        if (! $log->status->canResend()) {
            throw new InvalidArgumentException('Seul un e-mail en échec ou non envoyé peut être relancé.');
        }

        $recipient = $log->recipient;
        if (! $recipient instanceof Client && ! $recipient instanceof User) {
            throw new InvalidArgumentException('Destinataire introuvable.');
        }
        $recipient->refresh();

        $reason = $this->refusalReason($recipient);
        $new = $log->replicate(['status', 'reason', 'attempts', 'sent_at', 'resent_from_id', 'triggered_by']);
        $new->fill([
            'recipient_name' => $recipient->name,
            'recipient_email' => $recipient->email,
            'status' => $reason ? EmailStatus::NotSent : EmailStatus::Queued,
            'reason' => $reason,
            'attempts' => 0,
            'triggered_by' => $by->id,
            'resent_from_id' => $log->id,
        ])->save();

        if ($reason === null) {
            Mail::to($recipient->email, $recipient->name)->queue(new TemplatedMail($new->id));
        }

        return $new->refresh();
    }

    /** Variables d'un rendez-vous : {client} {avocat} {date} {heure} {lieu} {objet}. */
    public function appointmentVars(Appointment $appointment): array
    {
        $appointment->loadMissing(['client', 'lawyer']);

        return [
            'client' => $appointment->client->name,
            'avocat' => $appointment->lawyer->name,
            'date' => $appointment->starts_at->translatedFormat('l j F Y'),
            'heure' => $appointment->starts_at->format('H\hi'),
            'lieu' => $appointment->locationLabel(),
            'objet' => $appointment->subject,
        ];
    }

    /** @return array<string, string|null> {cabinet} {tel_cabinet} {banque} */
    public function baseVars(): array
    {
        return [
            'cabinet' => $this->settings->get('firm.name'),
            'tel_cabinet' => $this->settings->get('firm.phone'),
            'banque' => $this->settings->get('firm.bank_details'),
        ];
    }

    private function refusalReason(Client|User $recipient): ?string
    {
        if ($recipient instanceof Client && ! $recipient->email_consent) {
            return 'Le client n\'a pas accepté les notifications par e-mail.';
        }

        if (blank($recipient->email)) {
            return 'Aucune adresse e-mail sur la fiche.';
        }

        if ($recipient instanceof User && ! $recipient->is_active) {
            return 'Compte utilisateur désactivé.';
        }

        return null;
    }
}
