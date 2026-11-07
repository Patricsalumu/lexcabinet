<?php

namespace App\Services;

use App\Enums\FeeType;
use App\Models\Invoice;
use App\Models\LegalCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FAC-14 / DOS-08 : chaque jour, pour les dossiers en abonnement non clôturés dont la date de
 * prochaine facture est atteinte, crée la facture de la période écoulée (30 jours), sans doublon.
 * La facture reste un brouillon tant que « subscription_auto_issue » est désactivé (question ouverte).
 */
class SubscriptionBillingService
{
    public function __construct(
        private readonly InvoiceBuilder $builder,
        private readonly InvoiceService $invoices,
        private readonly SettingsService $settings,
    ) {}

    /** @return list<Invoice> factures créées */
    public function run(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $created = [];

        $cases = LegalCase::query()
            ->active()
            ->where('fee_type', FeeType::Subscription->value)
            ->whereNotNull('next_subscription_invoice_on')
            ->whereDate('next_subscription_invoice_on', '<=', $today)
            ->get();

        foreach ($cases as $case) {
            try {
                $created = array_merge($created, $this->billCase($case, $today));
            } catch (Throwable $e) {
                Log::error("Facturation d'abonnement impossible pour {$case->reference} : {$e->getMessage()}");
            }
        }

        return $created;
    }

    /** @return list<Invoice> */
    private function billCase(LegalCase $case, Carbon $today): array
    {
        $created = [];
        $days = CaseService::SUBSCRIPTION_PERIOD_DAYS;

        DB::transaction(function () use ($case, $today, $days, &$created) {
            $case = LegalCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $next = $case->next_subscription_invoice_on->copy();

            // Rattrapage si la tâche n'a pas tourné pendant plusieurs périodes.
            while ($next->lte($today)) {
                $period = [$next->copy()->subDays($days), $next->copy()->subDay()];

                $alreadyBilled = $this->builder->activeInvoices($case)->whereDate('period_start', $period[0])->exists();
                if (! $alreadyBilled) {
                    $created[] = $this->builder->forCase($case, null, $period);
                }

                $next->addDays($days);
            }

            $case->forceFill(['next_subscription_invoice_on' => $next->toDateString()])->saveQuietly();
            activity('invoices')->performedOn($case)
                ->withProperties(['next' => $next->toDateString(), 'created' => count($created)])
                ->log('Facturation d\'abonnement : '.count($created).' facture(s) créée(s)');
        });

        if ($this->settings->get('subscription_auto_issue')) {
            foreach ($created as $i => $invoice) {
                $created[$i] = $this->invoices->issue($invoice, null);
            }
        }

        return $created;
    }
}
