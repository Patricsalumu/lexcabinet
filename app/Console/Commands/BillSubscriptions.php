<?php

namespace App\Console\Commands;

use App\Services\SubscriptionBillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:bill-subscriptions')]
#[Description('Crée les factures d\'abonnement des dossiers dont la période de 30 jours est échue')]
class BillSubscriptions extends Command
{
    public function handle(SubscriptionBillingService $billing): int
    {
        $created = $billing->run();

        $this->info(count($created).' facture(s) d\'abonnement créée(s).');

        return self::SUCCESS;
    }
}
