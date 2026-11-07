<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * Modèles d'e-mails repris du prototype (NOT-06), complétés du rappel rapproché et du report.
 * N'écrase pas un modèle déjà personnalisé par le cabinet.
 */
class EmailTemplateSeeder extends Seeder
{
    public const TEMPLATES = [
        EmailTemplate::CONFIRMATION => [
            'name' => 'Confirmation de rendez-vous',
            'subject' => 'Confirmation de votre rendez-vous du {date}',
            'body' => "Bonjour {client},\n\nNous confirmons votre rendez-vous avec {avocat} le {date} à {heure}.\nLieu : {lieu}\nObjet : {objet}\n\nLe fichier joint ajoute ce rendez-vous à votre agenda.\nPour le reporter, répondez à cet e-mail ou appelez le {tel_cabinet}.\n\n{cabinet}",
        ],
        EmailTemplate::REMINDER => [
            'name' => 'Rappel de rendez-vous (J-1)',
            'subject' => 'Rappel : votre rendez-vous de demain à {heure}',
            'body' => "Bonjour {client},\n\nNous vous rappelons votre rendez-vous de demain, {date} à {heure}, avec {avocat}.\nLieu : {lieu}\n\nMerci d'apporter les pièces utiles à votre dossier.\n\n{cabinet}",
        ],
        EmailTemplate::REMINDER_SOON => [
            'name' => 'Rappel de rendez-vous (quelques heures avant)',
            'subject' => 'Rappel : votre rendez-vous de {heure}',
            'body' => "Bonjour {client},\n\nNous vous attendons aujourd'hui à {heure} pour votre rendez-vous avec {avocat}.\nLieu : {lieu}\n\n{cabinet}",
        ],
        EmailTemplate::CANCELLATION => [
            'name' => 'Annulation de rendez-vous',
            'subject' => 'Annulation de votre rendez-vous du {date}',
            'body' => "Bonjour {client},\n\nVotre rendez-vous du {date} à {heure} avec {avocat} est annulé.\nNous vous recontactons pour fixer une nouvelle date.\n\n{cabinet}",
        ],
        EmailTemplate::POSTPONEMENT => [
            'name' => 'Report de rendez-vous',
            'subject' => 'Report de votre rendez-vous du {date}',
            'body' => "Bonjour {client},\n\nVotre rendez-vous du {date} à {heure} avec {avocat} est reporté.\nNous vous recontactons pour convenir d'une nouvelle date.\n\n{cabinet}",
        ],
        EmailTemplate::INVOICE => [
            'name' => 'Envoi de facture',
            'subject' => 'Facture {numero} — {cabinet}',
            'body' => "Bonjour {client},\n\nVeuillez trouver ci-joint la facture {numero} d'un montant de {montant}, payable au plus tard le {echeance}.\n\nRèglement en espèces au cabinet, par virement ({banque}) ou par mobile money.\n\n{cabinet}",
        ],
        EmailTemplate::RECEIPT => [
            'name' => 'Reçu de paiement',
            'subject' => 'Reçu {numero} : paiement reçu',
            'body' => "Bonjour {client},\n\nNous accusons réception de votre paiement de {montant} ({mode}).\nLe reçu {numero} est joint à cet e-mail.\n\n{cabinet}",
        ],
        EmailTemplate::RECAP => [
            'name' => 'Récapitulatif quotidien de l\'avocat',
            'subject' => 'Votre journée du {date}',
            'body' => "Bonjour {avocat},\n\nVoici vos rendez-vous et audiences du jour :\n{liste}\n\nBonne journée.",
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $key => $template) {
            EmailTemplate::query()->firstOrCreate(['key' => $key], $template);
        }
    }
}
