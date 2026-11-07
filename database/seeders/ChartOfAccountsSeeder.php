<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Plan comptable inspiré du SYSCOHADA révisé, classes 1 à 7 (CPT-01), limité aux comptes
 * utiles à un cabinet d'avocats ; modifiable dans l'application. N'écrase rien d'existant.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public const ACCOUNTS = [
        // Classe 1 : ressources durables
        '101' => 'Capital social',
        '12' => 'Report à nouveau',
        '13' => 'Résultat net de l\'exercice',
        '16' => 'Emprunts et dettes assimilées',
        // Classe 2 : actif immobilisé
        '2441' => 'Matériel de bureau',
        '2444' => 'Matériel informatique',
        '2445' => 'Matériel de transport',
        '2446' => 'Mobilier de bureau',
        '284' => 'Amortissements du matériel',
        // Classe 3 : stocks
        '33' => 'Autres approvisionnements',
        // Classe 4 : tiers
        '401' => 'Fournisseurs',
        '411' => 'Clients',
        '4191' => 'Clients, avances reçues',
        '421' => 'Personnel, rémunérations dues',
        '431' => 'Sécurité sociale (CNSS)',
        '4431' => 'TVA facturée',
        '4452' => 'TVA récupérable sur achats',
        '447' => 'État, impôts retenus à la source',
        '4711' => 'Débours à refacturer',
        // Classe 5 : trésorerie
        '521' => 'Banques',
        '5216' => 'Monnaie électronique (mobile money)',
        '5711' => 'Caisse USD',
        '5712' => 'Caisse CDF',
        '585' => 'Virements de fonds',
        // Classe 6 : charges
        '6054' => 'Petit matériel et équipement',
        '6055' => 'Fournitures de bureau',
        '618' => 'Frais de transport',
        '622' => 'Locations et charges locatives',
        '628' => 'Frais de télécommunications',
        '632' => 'Honoraires et rémunérations d\'intermédiaires',
        '638' => 'Autres charges externes',
        '646' => 'Droits d\'enregistrement et timbres',
        '658' => 'Écarts de caisse (charges)',
        '661' => 'Rémunérations du personnel',
        '664' => 'Charges sociales',
        '676' => 'Pertes de change',
        // Classe 7 : produits
        '706' => 'Honoraires et prestations de services',
        '758' => 'Écarts de caisse (produits)',
        '776' => 'Gains de change',
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $code => $label) {
            Account::query()->firstOrCreate(['code' => (string) $code], ['label' => $label, 'class' => (int) substr((string) $code, 0, 1)]);
        }
    }
}
