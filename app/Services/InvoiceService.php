<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Émission (FAC-07, FAC-10), annulation par avoir (FAC-09) et envoi par e-mail (FAC-12).
 */
class InvoiceService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ExchangeRateService $rates,
        private readonly EmailService $emails,
    ) {}

    /**
     * Émet un brouillon : numéro continu FAC-AAAA-00001, taux du jour mémorisé, échéance,
     * heures reprises marquées « facturées ». La facture est ensuite figée.
     */
    public function issue(Invoice $draft, ?User $by, bool $sendEmail = true): Invoice
    {
        $invoice = DB::transaction(function () use ($draft, $by) {
            $invoice = Invoice::query()->with('lines')->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Cette facture est déjà émise.']);
            }
            if ($invoice->lines->isEmpty() || bccomp((string) $invoice->total, '0', 2) <= 0) {
                throw ValidationException::withMessages(['invoice' => 'Une facture sans ligne ou d\'un montant nul ne peut pas être émise.']);
            }

            $rate = $this->rates->rateFor();
            if ($rate === null) {
                throw ValidationException::withMessages(['invoice' => 'Aucun taux de change n\'est enregistré : saisissez le taux du jour avant d\'émettre.']);
            }

            // Heures au taux horaire : verrouillées, et refusées si une autre facture les a déjà reprises.
            $timeEntryIds = $invoice->lines->pluck('time_entry_ids')->filter()->flatten()->map(fn ($id) => (int) $id)->all();
            if ($timeEntryIds !== []) {
                $entries = TimeEntry::query()->whereKey($timeEntryIds)->lockForUpdate()->get();
                if ($entries->count() !== count($timeEntryIds) || $entries->contains(fn (TimeEntry $t) => $t->invoice_id !== null)) {
                    throw ValidationException::withMessages(['invoice' => 'Des heures de ce brouillon ont déjà été facturées : actualisez la ligne de temps passé.']);
                }
            }

            $today = today();
            $number = sprintf('FAC-%d-%05d', $today->year, $this->numbering->next("invoice:{$today->year}"));

            $invoice->fill([
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'issue_date' => $today,
                'due_date' => $today->copy()->addDays($invoice->payment_terms_days),
                'exchange_rate' => (string) $rate->rate,
                'issued_at' => now(),
                'issued_by' => $by?->id,
            ])->save();

            if ($timeEntryIds !== []) {
                TimeEntry::query()->whereKey($timeEntryIds)->update(['invoice_id' => $invoice->id]);
            }

            activity('invoices')->causedBy($by)->performedOn($invoice)
                ->withProperties(['total' => (string) $invoice->total, 'currency' => $invoice->currency->value, 'rate' => (string) $rate->rate])
                ->log("Facture {$number} émise : ".Money::format((string) $invoice->total, $invoice->currency));

            return $invoice;
        });

        if ($sendEmail) {
            $this->sendByEmail($invoice, $by);
        }

        return $invoice;
    }

    /**
     * Annule une facture émise par un avoir AV-AAAA-00001 du même montant (FAC-09).
     * Les heures et la consultation reprises redeviennent facturables.
     */
    public function cancel(Invoice $issued, string $reason, User $by): CreditNote
    {
        return DB::transaction(function () use ($issued, $reason, $by) {
            $invoice = Invoice::query()->whereKey($issued->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['reason' => 'Un brouillon se supprime ; seul une facture émise s\'annule par un avoir.']);
            }
            if ($invoice->status === InvoiceStatus::Cancelled) {
                throw ValidationException::withMessages(['reason' => 'Cette facture est déjà annulée.']);
            }
            if (bccomp((string) $invoice->paid_amount, '0', 2) > 0) {
                throw ValidationException::withMessages(['reason' => 'Cette facture a déjà reçu des paiements : elle ne peut pas être annulée par un avoir.']);
            }
            if (blank(trim($reason))) {
                throw ValidationException::withMessages(['reason' => 'Indiquez le motif de l\'avoir.']);
            }

            $today = today();
            $credit = CreditNote::query()->create([
                'number' => sprintf('AV-%d-%05d', $today->year, $this->numbering->next("credit_note:{$today->year}")),
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'currency' => $invoice->currency,
                'exchange_rate' => (string) $invoice->exchange_rate,
                'issue_date' => $today,
                'fees_amount' => bcsub((string) $invoice->fees_total, (string) $invoice->discount_amount, 2),
                'other_amount' => (string) $invoice->other_total,
                'vat_amount' => (string) $invoice->vat_amount,
                'total' => (string) $invoice->total,
                'reason' => trim($reason),
                'issued_by' => $by?->id,
            ]);

            $invoice->fill(['status' => InvoiceStatus::Cancelled, 'cancelled_at' => now()])->save();

            TimeEntry::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            Appointment::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

            activity('invoices')->causedBy($by)->performedOn($invoice)
                ->withProperties(['credit_note' => $credit->number, 'reason' => $credit->reason])
                ->log("Facture {$invoice->number} annulée par l'avoir {$credit->number}");

            return $credit;
        });
    }

    /** Envoi de la facture PDF au client (FAC-12, NOT-05), sous réserve de son consentement. */
    public function sendByEmail(Invoice $invoice, ?User $by): EmailLog
    {
        if ($invoice->status === InvoiceStatus::Draft || $invoice->status === InvoiceStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice' => 'Seule une facture émise et non annulée peut être envoyée.']);
        }

        $invoice->loadMissing('client');

        return $this->emails->send(EmailTemplate::INVOICE, $invoice->client, [
            'client' => $invoice->client->name,
            'numero' => $invoice->number,
            'montant' => Money::format((string) $invoice->total, $invoice->currency),
            'echeance' => $invoice->due_date->format('d/m/Y'),
        ], $invoice, [['type' => 'invoice_pdf', 'name' => $invoice->number.'.pdf']], $by);
    }
}
