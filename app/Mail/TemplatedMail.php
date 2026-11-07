<?php

namespace App\Mail;

use App\Enums\EmailStatus;
use App\Models\Appointment;
use App\Models\EmailLog;
use App\Models\Invoice;
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

    public function __construct(public readonly int $emailLogId) {}

    public function envelope(): Envelope
    {
        $log = $this->log();
        $settings = app(SettingsService::class);

        return new Envelope(
            from: new Address(
                (string) ($settings->get('firm.email') ?: config('mail.from.address')),
                (string) $settings->get('firm.name'),
            ),
            subject: $log->subject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [self::LOG_HEADER => (string) $this->emailLogId]);
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
        EmailLog::query()->whereKey($this->emailLogId)->update([
            'status' => EmailStatus::Failed->value,
            'reason' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }

    private function log(): EmailLog
    {
        return EmailLog::query()->with('related')->findOrFail($this->emailLogId);
    }
}
