<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Services\EmailService;
use App\Services\IcsGenerator;
use App\Services\InvoicePdf;
use App\Services\SettingsService;
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
    public function __construct(public readonly int $emailLogId)
    {
        $this->afterCommit();
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
        $firmEmail = (string) $settings->get('firm.email');

        return new Envelope(
            from: new Address($from, (string) $settings->get('firm.name')),
            replyTo: filter_var($firmEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($firmEmail, $from) !== 0
                ? [new Address($firmEmail, (string) $settings->get('firm.name'))]
                : [],
            subject: $log->subject,
        );
    }

    /** Transport réellement utilisé, fixé par Laravel au moment de la mise en file. */
    public const MAILER_HEADER = 'X-LexCabinet-Mailer';

    public function headers(): Headers
    {
        return new Headers(text: [
            self::LOG_HEADER => (string) $this->emailLogId,
            self::MAILER_HEADER => (string) ($this->mailer ?? config('mail.default')),
        ]);
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
        app(EmailService::class)->recordFailure($this->emailLogId, $e);
    }

    private function log(): EmailLog
    {
        return EmailLog::query()->with('related')->findOrFail($this->emailLogId);
    }
}
