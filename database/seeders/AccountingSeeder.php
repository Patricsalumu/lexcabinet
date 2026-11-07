<?php

namespace Database\Seeders;

use App\Services\AccountingPoster;
use Illuminate\Database\Seeder;

/**
 * Reprise comptable des données de démonstration : chaque facture émise, avoir, mouvement
 * de caisse validé et imputation reçoit son écriture, dans l'ordre chronologique.
 */
class AccountingSeeder extends Seeder
{
    public function run(AccountingPoster $poster): void
    {
        $poster->backfill();
    }
}
