<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForEachFirm;
use App\Services\SubscriptionBillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:bill-subscriptions {--firm= : Ne traiter que ce cabinet (identifiant)}')]
#[Description('Crée les factures d\'abonnement des dossiers dont la période de 30 jours est échue')]
class BillSubscriptions extends Command
{
    use RunsForEachFirm;

    public function handle(SubscriptionBillingService $billing): int
    {
        return $this->forEachFirm(fn () => count($billing->run()).' facture(s) d\'abonnement créée(s).');
    }
}
