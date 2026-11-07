<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Brouillons de facture (FAC-01 à FAC-06) : la facture part du dossier et la ligne
 * d'honoraires est générée selon la convention ; l'utilisateur n'ajoute que des
 * lignes complémentaires (débours, greffe, transport, frais de dossier).
 */
class InvoiceBuilder
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly SettingsService $settings,
        private readonly ExchangeRateService $rates,
    ) {}

    /** Crée un brouillon pour un dossier, avec sa ligne d'honoraires automatique. */
    public function forCase(LegalCase $case, ?User $by, ?array $period = null): Invoice
    {
        return DB::transaction(function () use ($case, $by, $period) {
            // Verrou sur le dossier : sérialise les créations concurrentes (forfait unique, période unique).
            $case = LegalCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($case->fee_type === FeeType::Flat && $this->activeInvoices($case)->exists()) {
                throw ValidationException::withMessages([
                    'case_id' => 'Ce dossier au forfait a déjà une facture : une seconde facture est refusée tant que la première n\'est pas annulée.',
                ]);
            }

            if ($case->fee_type === FeeType::Subscription) {
                $period ??= $this->nextSubscriptionPeriod($case);
                if ($this->activeInvoices($case)->whereDate('period_start', $period[0])->exists()) {
                    throw ValidationException::withMessages([
                        'case_id' => 'La période du '.$period[0]->format('d/m/Y').' au '.$period[1]->format('d/m/Y').' est déjà facturée.',
                    ]);
                }
            }

            $invoice = $this->newDraft([
                'case_id' => $case->id,
                'client_id' => $case->client_id,
                'fee_type' => $case->fee_type,
                'currency' => $case->currency,
                'period_start' => $period[0] ?? null,
                'period_end' => $period[1] ?? null,
            ], $by);

            $this->writeAutoLines($invoice, $case);

            return $this->calculator->refresh($invoice);
        });
    }

    /**
     * FAC-06 : consultation honorée facturée depuis l'agenda, sans dossier,
     * au tarif de consultation par défaut.
     */
    public function forConsultation(Appointment $appointment, User $by): Invoice
    {
        return DB::transaction(function () use ($appointment, $by) {
            $appointment = Appointment::query()->with('lawyer')->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $appointment->isBillableConsultation()) {
                throw ValidationException::withMessages([
                    'appointment_id' => 'Seule une consultation honorée et pas encore facturée peut être facturée.',
                ]);
            }

            $invoice = $this->newDraft([
                'case_id' => null,
                'client_id' => $appointment->client_id,
                'appointment_id' => $appointment->id,
                'currency' => Currency::from((string) $this->settings->get('consultation.default_currency')),
            ], $by);

            $fee = (string) $this->settings->get('consultation.default_fee');
            $this->addLine($invoice, InvoiceLineType::Consultation,
                'Consultation du '.$appointment->starts_at->format('d/m/Y').' avec '.$appointment->lawyer->name, '1', $fee, InvoiceLine::AUTO);

            $appointment->forceFill(['invoice_id' => $invoice->id])->saveQuietly();

            return $this->calculator->refresh($invoice);
        });
    }

    /** Régénère la ligne automatique d'un brouillon (ex. nouvelles heures saisies). */
    public function refreshAutoLines(Invoice $invoice): Invoice
    {
        $this->assertDraft($invoice);
        if ($invoice->legalCase === null) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->lines()->where('origin', InvoiceLine::AUTO)->get()->each->delete();
            $this->writeAutoLines($invoice, $invoice->legalCase);

            return $this->calculator->refresh($invoice);
        });
    }

    /** Ligne complémentaire saisie à la main (FAC-04). */
    public function addManualLine(Invoice $invoice, InvoiceLineType $type, string $label, string $quantity, string $unitPrice): InvoiceLine
    {
        $this->assertDraft($invoice);
        if (! in_array($type, InvoiceLineType::manualTypes(), true)) {
            throw ValidationException::withMessages(['type' => 'Les honoraires sont générés automatiquement depuis la convention du dossier.']);
        }

        return DB::transaction(function () use ($invoice, $type, $label, $quantity, $unitPrice) {
            $line = $this->addLine($invoice, $type, $label, $quantity, $unitPrice, InvoiceLine::MANUAL);
            $this->calculator->refresh($invoice);

            return $line;
        });
    }

    public function removeManualLine(Invoice $invoice, InvoiceLine $line): void
    {
        $this->assertDraft($invoice);
        if ($line->invoice_id !== $invoice->id || $line->isAuto()) {
            throw new LogicException('Seules les lignes complémentaires peuvent être retirées.');
        }

        DB::transaction(function () use ($invoice, $line) {
            $line->delete();
            $this->calculator->refresh($invoice);
        });
    }

    /** Réduction (0 à 100 %), TVA, délai de paiement et mentions. */
    public function updateOptions(Invoice $invoice, string $discountPercent, bool $vatEnabled, int $termsDays, ?string $notes): Invoice
    {
        $this->assertDraft($invoice);

        $discount = Money::normalize($discountPercent);
        if (bccomp($discount, '0', 2) < 0 || bccomp($discount, '100', 2) > 0) {
            throw ValidationException::withMessages(['discount' => 'La réduction doit être comprise entre 0 et 100 %.']);
        }

        $invoice->fill([
            'discount_percent' => $discount,
            'vat_enabled' => $vatEnabled,
            'vat_rate' => $vatEnabled ? (string) $this->settings->get('vat.rate') : '0',
            'payment_terms_days' => $termsDays,
            'notes' => filled($notes) ? trim($notes) : null,
        ]);

        return $this->calculator->refresh($invoice);
    }

    /** Un brouillon se supprime ; la consultation éventuelle redevient facturable. */
    public function deleteDraft(Invoice $invoice): void
    {
        $this->assertDraft($invoice);

        DB::transaction(function () use ($invoice) {
            Appointment::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->delete();
        });
    }

    /**
     * Prochaine période d'abonnement non facturée : 30 jours à partir de l'ouverture,
     * puis à la suite de la dernière période facturée (hors factures annulées).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function nextSubscriptionPeriod(LegalCase $case): array
    {
        $last = $this->activeInvoices($case)->max('period_end');
        $start = $last ? Carbon::parse($last)->addDay() : $case->opened_on->copy();

        return [$start->startOfDay(), $start->copy()->addDays(CaseService::SUBSCRIPTION_PERIOD_DAYS - 1)->startOfDay()];
    }

    /** Factures non annulées d'un dossier (brouillons compris). */
    public function activeInvoices(LegalCase $case)
    {
        return Invoice::query()->where('case_id', $case->id)->where('status', '!=', InvoiceStatus::Cancelled->value);
    }

    private function writeAutoLines(Invoice $invoice, LegalCase $case): void
    {
        $amount = (string) $case->fee_amount;

        match ($case->fee_type) {
            FeeType::Flat => $this->addLine($invoice, InvoiceLineType::Fee, "{$case->title}, honoraires forfaitaires", '1', $amount, InvoiceLine::AUTO),

            FeeType::Subscription => $this->addLine($invoice, InvoiceLineType::Fee,
                "{$case->title}, abonnement du {$invoice->period_start->format('d/m/Y')} au {$invoice->period_end->format('d/m/Y')}",
                '1', $amount, InvoiceLine::AUTO),

            FeeType::RetainerSuccess => $this->addLine($invoice, InvoiceLineType::Fee,
                "Provision sur honoraires, dossier {$case->reference}", '1', $amount, InvoiceLine::AUTO),

            FeeType::Hourly => $this->writeHourlyLine($invoice, $case),
        };
    }

    /**
     * Taux horaire : toutes les heures facturables non encore facturées × le taux,
     * avec la période couverte. Sans heures nouvelles, pas de ligne d'honoraires (FAC-03).
     */
    private function writeHourlyLine(Invoice $invoice, LegalCase $case): void
    {
        $entries = TimeEntry::query()
            ->where('case_id', $case->id)
            ->where('billable', true)
            ->whereNull('invoice_id')
            ->orderBy('worked_on')
            ->get();

        if ($entries->isEmpty()) {
            $invoice->fill(['period_start' => null, 'period_end' => null])->save();

            return;
        }

        $minutes = (int) $entries->sum('minutes');
        $hours = bcdiv((string) $minutes, '60', 4);
        $from = $entries->first()->worked_on;
        $to = $entries->last()->worked_on;

        $line = $this->addLine($invoice, InvoiceLineType::Fee,
            sprintf('Temps passé du %s au %s : %s h au taux de %s',
                $from->format('d/m/Y'), $to->format('d/m/Y'), str_replace('.', ',', rtrim(rtrim($hours, '0'), '.')),
                Money::format((string) $case->fee_amount, $case->currency)),
            $hours, (string) $case->fee_amount, InvoiceLine::AUTO);

        $line->forceFill(['time_entry_ids' => $entries->pluck('id')->all()])->save();
        $invoice->fill(['period_start' => $from, 'period_end' => $to])->save();
    }

    private function addLine(Invoice $invoice, InvoiceLineType $type, string $label, string $quantity, string $unitPrice, string $origin): InvoiceLine
    {
        $quantity = Money::normalize($quantity, 4);
        $unitPrice = Money::normalize($unitPrice);

        if (bccomp($quantity, '0', 4) <= 0 || bccomp($unitPrice, '0', 2) < 0) {
            throw ValidationException::withMessages(['line' => 'Quantité et prix unitaire doivent être positifs.']);
        }

        return $invoice->lines()->create([
            'position' => ((int) $invoice->lines()->max('position')) + 1,
            'origin' => $origin,
            'type' => $type,
            'label' => trim($label),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => InvoiceCalculator::lineAmount($quantity, $unitPrice),
            'taxable' => $type->isTaxable(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function newDraft(array $attributes, ?User $by): Invoice
    {
        $vat = (bool) $this->settings->get('vat.enabled');

        return Invoice::query()->create($attributes + [
            'status' => InvoiceStatus::Draft,
            'exchange_rate' => $this->rates->rateFor()?->rate,
            'payment_terms_days' => 15,
            'discount_percent' => '0',
            'vat_enabled' => $vat,
            'vat_rate' => $vat ? (string) $this->settings->get('vat.rate') : '0',
            'created_by' => $by?->id,
        ]);
    }

    private function assertDraft(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new LogicException('Une facture émise ne peut pas être modifiée : annulez-la par un avoir.');
        }
    }
}
