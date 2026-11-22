<?php

namespace App\Services;

use App\Enums\EmailStatus;
use App\Mail\TemplatedMail;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\EmailTemplateDefaults;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Envoi des e-mails à partir des modèles (NOT-01 à NOT-07).
 *
 * Chaque envoi est journalisé dans email_logs :
 * - « En file d'attente » : le message attend le worker (php artisan queue:work) ;
 * - « Envoyé » : le transport (SMTP…) a accepté le message ;
 * - « Échec » : les 3 tentatives ont échoué (relance possible) ;
 * - « Non envoyé » : aucun envoi tenté, avec son motif (consentement, adresse…).
 *
 * Le message ne part dans la file qu'après la validation (commit) de la transaction en cours :
 * aucune notification pour une opération finalement annulée. Aucun e-mail ne part vers un
 * client qui n'a pas donné son consentement (CLI-03).
 */
class EmailService
{
    /** Nombre maximal d'envois automatiques d'un même rappel après échec. */
    public const MAX_AUTOMATIC_ATTEMPTS = 3;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, string|null>  $vars
     * @param  list<array<string, string>>  $attachments
     */
    public function send(string $templateKey, Client|User $recipient, array $vars, ?Model $related = null, array $attachments = [], ?User $by = null, ?EmailLog $resentFrom = null): EmailLog
    {
        $template = $this->template($templateKey);

        $vars = $vars + $this->baseVars();
        $reason = $this->refusalReason($recipient);

        // Tout e-mail destiné à un client se termine par la signature du cabinet
        // (moyens de paiement, adresse, contacts) ; elle est journalisée avec le message.
        $body = EmailTemplate::render($template->body, $vars);
        if ($recipient instanceof Client) {
            $body = app(EmailSignature::class)->append($body);
        }

        $log = EmailLog::query()->create([
            'template_key' => $templateKey,
            'recipient_name' => $recipient->name,
            'recipient_email' => $recipient->email,
            'subject' => EmailTemplate::render($template->subject, $vars),
            'body' => $body,
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
     * Relance manuelle (NOT-07) : nouvel envoi avec les coordonnées, le consentement
     * et les droits actuels du destinataire.
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

        $reason = $this->refusalReason($recipient) ?? $this->confidentialityReason($log, $recipient);
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

    /**
     * Appelé par le worker après la dernière tentative en échec : l'e-mail passe en « Échec »
     * avec l'erreur. Un rappel automatique (J-1, H-N) redevient alors « à envoyer » pour que la
     * tâche planifiée le retente, dans la limite de MAX_AUTOMATIC_ATTEMPTS envois.
     */
    public function recordFailure(int $logId, Throwable $e): void
    {
        $log = EmailLog::query()->find($logId);
        if ($log === null) {
            return;
        }

        $log->forceFill(['status' => EmailStatus::Failed, 'reason' => mb_substr($e->getMessage(), 0, 1000)])->save();

        $marker = match ($log->template_key) {
            EmailTemplate::REMINDER => 'reminder_sent_at',
            EmailTemplate::REMINDER_SOON => 'short_reminder_sent_at',
            default => null,
        };

        if ($marker && $log->related_type === (new Appointment)->getMorphClass()
            && $this->automaticAttempts($log->template_key, $log->related_type, $log->related_id) < self::MAX_AUTOMATIC_ATTEMPTS) {
            Appointment::query()->whereKey($log->related_id)->update([$marker => null]);
        }
    }

    /** Nombre d'envois automatiques en échec pour un même modèle et un même objet. */
    public function automaticAttempts(string $templateKey, string $relatedType, int $relatedId): int
    {
        return EmailLog::query()
            ->where('template_key', $templateKey)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->whereNull('resent_from_id')
            ->where('status', EmailStatus::Failed->value)
            ->count();
    }

    /** Variables d'un rendez-vous : {client} {avocat} {date} {heure} {lieu} {objet}. */
    public function appointmentVars(Appointment $appointment): array
    {
        $appointment->loadMissing(['client', 'lawyer']);

        return [
            'client' => $appointment->client->name,
            'destinataire' => $appointment->client->name,
            'avocat' => $appointment->lawyer->name,
            'date' => $appointment->starts_at->translatedFormat('l j F Y'),
            'heure' => $appointment->starts_at->format('H\hi'),
            'lieu' => $appointment->locationLabel(),
            'objet' => $appointment->subject,
        ];
    }

    /** @return array<string, string|null> Variables du cabinet, disponibles dans tous les modèles. */
    public function baseVars(): array
    {
        $firm = $this->settings->all();
        $contacts = array_filter([
            $firm['firm.address'],
            $firm['firm.phone'] ? 'Téléphone : '.$firm['firm.phone'] : null,
            $firm['firm.email'] ? 'E-mail : '.$firm['firm.email'] : null,
        ]);

        return [
            'cabinet' => $firm['firm.name'],
            'tel_cabinet' => $firm['firm.phone'],
            'email_cabinet' => $firm['firm.email'],
            'adresse_cabinet' => $firm['firm.address'],
            'coordonnees_cabinet' => implode("\n", $contacts),
            'banque' => $firm['firm.bank_details'],
            'lien_application' => rtrim((string) config('app.url'), '/'),
        ];
    }

    /**
     * Modèle en base ; s'il manque (nouveau modèle pas encore initialisé), il est créé
     * à partir des textes par défaut, sans toucher aux modèles existants.
     */
    private function template(string $key): EmailTemplate
    {
        $template = EmailTemplate::query()->where('key', $key)->first();
        if ($template) {
            return $template;
        }

        $defaults = EmailTemplateDefaults::for($key) ?? throw new InvalidArgumentException("Modèle d'e-mail inconnu : {$key}");

        return EmailTemplate::query()->firstOrCreate(['key' => $key], $defaults);
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

    /**
     * Confidentialité des relances : un e-mail sur un dossier ne repart pas vers un avocat
     * qui n'y est plus associé, ni vers un ancien administrateur.
     */
    private function confidentialityReason(EmailLog $log, Client|User $recipient): ?string
    {
        if ($recipient instanceof User && in_array($log->template_key, EmailTemplate::APPOINTMENT_LAWYER_TEMPLATES, true)) {
            $appointment = Appointment::query()->find($log->related_id);

            return $appointment && $appointment->lawyer_id === $recipient->id ? null : 'Ce rendez-vous n\'est plus attribué à ce destinataire.';
        }

        if (! $recipient instanceof User || $log->related_type !== (new LegalCase)->getMorphClass()) {
            return null;
        }

        $case = LegalCase::query()->find($log->related_id);
        if ($case === null) {
            return 'Dossier introuvable.';
        }

        if (in_array($log->template_key, EmailTemplate::CASE_LAWYER_TEMPLATES, true) && ! $case->isAssignedTo($recipient)) {
            return 'Ce destinataire n\'est plus associé au dossier.';
        }

        if ($log->template_key === EmailTemplate::CASE_CREATED_ADMIN && ! $recipient->isAdmin()) {
            return 'Ce destinataire n\'est plus administrateur.';
        }

        return null;
    }
}
