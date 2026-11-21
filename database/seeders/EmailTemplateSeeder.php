<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Support\EmailTemplateDefaults;
use Illuminate\Database\Seeder;

/**
 * Modèles d'e-mails (NOT-06). Ajoute les modèles manquants sans jamais écraser un modèle
 * déjà présent, éventuellement personnalisé par le cabinet : peut être relancé sans risque
 * (php artisan db:seed --class=EmailTemplateSeeder).
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EmailTemplateDefaults::all() as $key => $template) {
            EmailTemplate::query()->firstOrCreate(['key' => $key], $template);
        }
    }
}
