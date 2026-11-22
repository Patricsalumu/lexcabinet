<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Services\EmailService;
use App\Services\IcsGenerator;
use App\Services\InvoicePdf;
use App\Services\SettingsService;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * E-mail construit à partir d'une entrée du journal d'envoi (objet, corps, pièces jointes).
 * Envoyé par la file d'attente, réessayé 3 fois ; l'issue est reportée dans email_logs.
 */
class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const LOG_HEADER = 'X-LexCabinet-Email-Log';

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [60, 300];

    /**
     * Le job n'entre dans la file qu'après le commit de la transaction en cours : jamais
     * d'e-mail pour une opération annulée, ni de job traité avant que son journal soit visible.
     */
    /** Cabinet de l'e-mail : l'envoi (construction, transport, journal) s'y déroule, quel que soit le worker. */
    public ?int $firmId;

    public function __construct(public readonly int $emailLogId)
    {
        $this->firmId = app(CurrentFirm::class)->id();
        $this->afterCommit();
    }

    /** @param  \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer  $mailer */
    public function send($mailer)
    {
        return $this->inFirm(fn () => parent::send($mailer));
    }

    /**
     * Expéditeur : toujours MAIL_FROM_ADDRESS, l'adresse du compte SMTP authentifié (sinon le
     * serveur refuse le message, et SPF/DMARC le rejetteraient). L'e-mail du cabinet, s'il est
     * différent, devient l'adresse de réponse : les clients répondent bien au cabinet.
     */
    public function envelope(): Envelope
    {
        $log = $this->log();
        $settings = app(SettingsService::class);
        $from = (string) config('mail.from.address');
        $replyTo = self::replyAddress();

        return new Envelope(
            from: new Address($from, (string) $settings->get('firm.name')),
            replyTo: strcasecmp($replyTo, $from) !== 0 ? [new Address($replyTo, (string) $settings->get('firm.name'))] : [],
            subject: $log->subject,
        );
    }

    /**
     * Adresse où arrivent les réponses des destinataires : l'e-mail du cabinet (paramètres),
     * à défaut l'expéditeur. C'est la boîte que l'application doit lire (IMAP_USERNAME).
     */
    public static function replyAddress(): string
    {
        $firmEmail = (string) app(SettingsService::class)->get('firm.email');

        return filter_var($firmEmail, FILTER_VALIDATE_EMAIL) ? $firmEmail : (string) config('mail.from.address');
    }

    /** Transport réellement utilisé, fixé par Laravel au moment de la mise en file. */
    public const MAILER_HEADER = 'X-LexCabinet-Mailer';

    public function headers(): Headers
    {
        $log = EmailLog::query()->find($this->emailLogId);
        $text = [
            self::LOG_HEADER => (string) $this->emailLogId,
            self::MAILER_HEADER => (string) ($this->mailer ?? config('mail.default')),
        ];
        // Réponse dans une conversation : le logiciel de messagerie du destinataire range le message dans le fil.
        if ($log?->in_reply_to) {
            $text['In-Reply-To'] = '<'.$log->in_reply_to.'>';
        }

        return new Headers(
            messageId: $log?->message_id,
            references: $log?->referenceIds() ?? [],
            text: $text,
        );
    }

    public function content(): Content
    {
        $log = $this->log();
        $log->increment('attempts');

        return new Content(
            view: 'mail.templated',
            text: 'mail.templated-text',
            with: [
                'body' => $log->body,
                'firm' => app(SettingsService::class)->all(),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $log = $this->log();
        $attachments = [];

        foreach ($log->attachments ?? [] as $spec) {
            if (($spec['type'] ?? null) === 'ics' && $log->related instanceof Appointment) {
                $ics = app(IcsGenerator::class)->forAppointment($log->related, $spec['method'] ?? IcsGenerator::REQUEST);
                $attachments[] = Attachment::fromData(fn () => $ics, $spec['name'] ?? 'rendez-vous.ics')
                    ->withMime('text/calendar');
            }

            if (($spec['type'] ?? null) === 'receipt_pdf' && $log->related instanceof \App\Models\CashMovement) {
                $pdf = app(InvoicePdf::class)->receipt($log->related);
                $attachments[] = Attachment::fromData(fn () => $pdf, $spec['name'] ?? 'recu.pdf')
                    ->withMime('application/pdf');
            }

            if (($spec['type'] ?? null) === 'invoice_pdf' && $log->related instanceof Invoice) {
                $pdf = app(InvoicePdf::class)->invoice($log->related);
                $attachments[] = Attachment::fromData(fn () => $pdf, $spec['name'] ?? 'facture.pdf')
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }

    /** Appelé après le dernier échec : l'e-mail est marqué « Échec » avec l'erreur. */
    public function failed(Throwable $e): void
    {
        $this->inFirm(fn () => app(EmailService::class)->recordFailure($this->emailLogId, $e));
    }

    private function inFirm(callable $callback): mixed
    {
        return $this->firmId === null ? $callback() : app(CurrentFirm::class)->run($this->firmId, $callback);
    }

    private function log(): EmailLog
    {
        return EmailLog::query()->with('related')->findOrFail($this->emailLogId);
    }
}
