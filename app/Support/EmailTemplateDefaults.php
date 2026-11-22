<?php

namespace App\Support;

use App\Models\EmailTemplate;

/**
 * Textes par défaut des modèles d'e-mails (NOT-06).
 *
 * Ils servent à initialiser les modèles en base (EmailTemplateSeeder) et à créer à la volée
 * un modèle manquant lors du premier envoi. Un modèle déjà présent en base, éventuellement
 * personnalisé par le cabinet, n'est jamais écrasé.
 */
final class EmailTemplateDefaults
{
    /** @return array<string, array{name: string, subject: string, body: string}> */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------- Rendez-vous (clients)
            EmailTemplate::CONFIRMATION => [
                'name' => 'Confirmation de rendez-vous',
                'subject' => 'Confirmation de votre rendez-vous du {date}',
                'body' => "Bonjour {client},\n\nNous confirmons votre rendez-vous avec {avocat} le {date} à {heure}.\nLieu : {lieu}\nObjet : {objet}\n\nLe fichier joint ajoute ce rendez-vous à votre agenda.\nPour le reporter, répondez à cet e-mail ou appelez le {tel_cabinet}.\n\n{cabinet}",
            ],
            EmailTemplate::APPOINTMENT_UPDATED => [
                'name' => 'Modification de rendez-vous',
                'subject' => 'Votre rendez-vous a été modifié : {date} à {heure}',
                'body' => "Bonjour {client},\n\nVotre rendez-vous avec le cabinet a été modifié. Voici les nouvelles informations :\n\nDate : {date}\nHeure : {heure}\nAvocat : {avocat}\nLieu : {lieu}\nObjet : {objet}\n\nLe fichier joint met à jour ce rendez-vous dans votre agenda.\nPour toute question, répondez à cet e-mail ou appelez le {tel_cabinet}.\n\n{cabinet}",
            ],
            EmailTemplate::APPOINTMENT_LAWYER => [
                'name' => 'Nouveau rendez-vous (avocat concerné)',
                'subject' => 'Nouveau rendez-vous : {client}, le {date} à {heure}',
                'body' => "Bonjour {avocat},\n\nUn rendez-vous vient d'être inscrit à votre agenda par {createur}.\n\nClient : {client}\nContact : {contact_client}\nDate : {date}\nHeure : {heure}\nType : {type}\nLieu : {lieu}\nObjet : {objet}\nDossier : {dossier}\nNotes internes : {notes}\n\nLe fichier joint ajoute ce rendez-vous à votre agenda.\nConsulter l'agenda : {lien}\n\n{cabinet}",
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
                'body' => "Bonjour {client},\n\nVotre rendez-vous du {date} à {heure} avec {avocat} est reporté.\nNous vous recontactons pour convenir d'une nouvelle date ; vous recevrez alors une confirmation avec les nouvelles informations.\n\n{cabinet}",
            ],

            // ---------------------------------------------------------------- Facturation (clients)
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

            // ---------------------------------------------------------------- Avocats
            EmailTemplate::RECAP => [
                'name' => 'Récapitulatif quotidien de l\'avocat',
                'subject' => 'Votre journée du {date}',
                'body' => "Bonjour {avocat},\n\nVoici vos rendez-vous et audiences du jour :\n{liste}\n\nBonne journée.",
            ],

            // ---------------------------------------------------------------- Comptes utilisateurs
            EmailTemplate::ACCOUNT_CREATED => [
                'name' => 'Création de compte utilisateur',
                'subject' => 'Votre compte LexCabinet — {cabinet}',
                'body' => "Bonjour {destinataire},\n\nUn compte vient d'être créé pour vous dans LexCabinet, l'application de gestion du cabinet {cabinet}.\n\nVotre identifiant : {email}\nVotre profil : {roles}\n\nPour accéder à votre compte, définissez d'abord votre mot de passe en suivant ce lien sécurisé (valable {validite}) :\n{lien}\n\nVous pourrez ensuite vous connecter à tout moment à l'adresse suivante :\n{lien_application}\n\nSi le lien a expiré, utilisez « Mot de passe oublié ? » sur la page de connexion, ou demandez un nouveau lien à l'administrateur.\n\nBienvenue,\n{cabinet}",
            ],

            // ---------------------------------------------------------------- Dossiers : création
            EmailTemplate::CASE_CREATED_ADMIN => [
                'name' => 'Nouveau dossier (administrateurs)',
                'subject' => 'Nouveau dossier {reference} : {dossier}',
                'body' => "Bonjour {destinataire},\n\nUn nouveau dossier vient d'être ouvert dans LexCabinet.\n\nRéférence : {reference}\nIntitulé : {dossier}\nClient : {client}\nDate d'ouverture : {date}\nAvocat(s) en charge : {avocats}\nOuvert par : {createur}\n\n{prise_en_charge}\n\nConsulter le dossier : {lien}\n\n{cabinet}",
            ],
            EmailTemplate::CASE_CREATED_CLIENT => [
                'name' => 'Ouverture de dossier (client)',
                'subject' => 'Votre dossier {reference} est ouvert — {cabinet}',
                'body' => "Bonjour {client},\n\nNous vous remercions de votre confiance et vous souhaitons la bienvenue au sein de {cabinet}.\n\nNous vous confirmons l'ouverture de votre dossier :\nRéférence : {reference}\nIntitulé : {dossier}\n\nVotre dossier est suivi par {avocats}.\n\nNotre équipe étudie dès à présent votre situation et reviendra vers vous pour les prochaines étapes. Merci de rappeler la référence {reference} dans vos échanges avec le cabinet.\n\nPour toute question :\n{coordonnees_cabinet}\n\nNous vous prions d'agréer nos salutations distinguées.\n\n{cabinet}",
            ],
            EmailTemplate::CASE_CREATED_LAWYER => [
                'name' => 'Nouveau dossier (avocats du dossier)',
                'subject' => 'Vous êtes associé au dossier {reference} : {dossier}',
                'body' => "Bonjour {destinataire},\n\nVous venez d'être associé(e) à un nouveau dossier en qualité de {qualite}.\n\nRéférence : {reference}\nIntitulé : {dossier}\nClient : {client}\nDate d'ouverture : {date}\nÉquipe : {avocats}\n\n{prise_en_charge}\n\nConsulter le dossier : {lien}\n\n{cabinet}",
            ],

            // ---------------------------------------------------------------- Dossiers : modification
            EmailTemplate::CASE_UPDATED_CLIENT => [
                'name' => 'Mise à jour de dossier (client)',
                'subject' => 'Votre dossier {reference} a été mis à jour',
                'body' => "Bonjour {client},\n\nNous vous informons d'une mise à jour de votre dossier {reference} ({dossier}) :\n\n{modifications}\n\nVotre dossier est suivi par {avocats}.\n\nPour toute question :\n{coordonnees_cabinet}\n\n{cabinet}",
            ],
            EmailTemplate::CASE_UPDATED_LAWYER => [
                'name' => 'Modification de dossier (avocats du dossier)',
                'subject' => 'Dossier {reference} modifié',
                'body' => "Bonjour {destinataire},\n\nLe dossier {reference} ({dossier}, client {client}) a été modifié par {createur} :\n\n{modifications}\n\nÉquipe actuelle : {avocats}\n\nConsulter le dossier : {lien}\n\n{cabinet}",
            ],
            EmailTemplate::CASE_LAWYER_ADDED => [
                'name' => 'Ajout d\'un avocat à un dossier',
                'subject' => 'Vous êtes désormais associé au dossier {reference}',
                'body' => "Bonjour {destinataire},\n\nVous êtes désormais associé(e) au dossier suivant en qualité de {qualite} :\n\nRéférence : {reference}\nIntitulé : {dossier}\nClient : {client}\nDate d'ouverture : {date}\nÉquipe : {avocats}\n\n{prise_en_charge}\n\nConsulter le dossier : {lien}\n\n{cabinet}",
            ],
            EmailTemplate::CASE_LAWYER_REMOVED => [
                'name' => 'Retrait d\'un avocat d\'un dossier',
                'subject' => 'Vous n\'êtes plus associé au dossier {reference}',
                'body' => "Bonjour {destinataire},\n\nNous vous informons que vous n'êtes plus associé(e) au dossier {reference} ({dossier}) depuis le {date}.\nVous ne recevrez plus de notification le concernant.\n\n{cabinet}",
            ],
        ];
    }

    /** @return array{name: string, subject: string, body: string}|null */
    public static function for(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
