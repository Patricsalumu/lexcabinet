# Cahier des charges — Application de gestion de cabinet d'avocats

Version du 7 novembre 2026

## 1. Contexte et objectifs

L'application, nommée provisoirement **LexCabinet**, centralise la gestion d'un cabinet d'avocats en RDC : rendez-vous, clients, dossiers, honoraires, caisse en USD et CDF, comptabilité et rappels par e-mail.

Aujourd'hui, la plupart des cabinets gèrent ces activités avec un agenda papier, Excel et WhatsApp. Il en résulte des rendez-vous oubliés, des honoraires non suivis et une caisse difficile à rapprocher.

**Objectifs mesurables**

- Réduire les rendez-vous manqués grâce aux rappels automatiques (cible : moins de 10 % d'absences).
- Savoir à tout moment, par client et par dossier, ce qui est facturé, encaissé et restant dû.
- Clôturer la caisse chaque jour en USD et en CDF, avec écart constaté et justifié.
- Produire le journal, le grand livre simplifié et la balance du mois sans ressaisie.
- Mettre en production un MVP utilisable par un cabinet de 2 à 15 personnes en 10 semaines.

**Hors périmètre de la V1** : signature électronique, portail client en ligne, paiement mobile intégré (M-Pesa, Orange Money, Airtel Money), gestion documentaire avancée avec OCR. Ces points sont prévus en V2.

## 2. Acteurs et rôles

Cinq rôles internes partagent l'application ; le client n'y accède pas en V1 mais reçoit les e-mails.

| Rôle | Missions principales | Droits clés | Ne peut pas |
| --- | --- | --- | --- |
| Administrateur (associé gérant) | Paramètre le cabinet, gère les utilisateurs, valide les clôtures | Tous les modules, export, journal d'audit, validation des décaissements | — |
| Avocat | Consulte son agenda et ses dossiers, saisit le temps passé | Lecture de l'agenda, lecture de ses dossiers et de ceux où il est collaborateur, saisie du temps passé | Créer un dossier, un rendez-vous ou une facture |
| Secrétaire / assistant(e) | Accueille, prend les RDV, crée clients et dossiers | Agenda de tous, clients, création et modification des dossiers, envoi des rappels | Facturer, encaisser |
| Caissier(ère) | Encaisse, décaisse, ouvre et clôture la caisse | Module caisse, enregistrement des paiements sur factures | Créer un dossier, facturer |
| Comptable | Émet les factures, contrôle les écritures, produit les états | Facturation, comptabilité, rapports, lecture de la caisse | Créer un dossier |
| Client (externe) | Reçoit confirmations, rappels et factures | Aucun accès à l'application en V1 | — |

Un utilisateur peut cumuler plusieurs rôles ; ses droits sont alors la somme des droits de chaque rôle. Exemple : un avocat qui a aussi le rôle secrétaire peut créer des dossiers ; une secrétaire qui a aussi le rôle caissière peut encaisser.

## 3. Périmètre fonctionnel

L'application compte neuf modules ; chaque exigence porte un identifiant pour la recette (P1 = MVP, P2 = V2).

### 3.1 Tableau de bord

- **DB-01 (P1)** Rendez-vous du jour et de la semaine, par avocat.
- **DB-02 (P1)** Solde de caisse en USD et en CDF, encaissements du jour.
- **DB-03 (P1)** Montant des factures impayées et liste des factures échues.
- **DB-04 (P1)** Dossiers actifs par statut et prochaines audiences.
- **DB-05 (P2)** Chiffre d'affaires mensuel par avocat et par type d'affaire.

### 3.2 Rendez-vous et agenda

- **RDV-01 (P1)** Créer un RDV : client (existant ou nouveau prospect), avocat, date, heure, durée, type (consultation, suivi de dossier, signature, audience), lieu (cabinet, tribunal, visioconférence), objet, notes internes.
- **RDV-02 (P1)** Vue jour / semaine / liste, filtrable par avocat.
- **RDV-03 (P1)** Contrôle des conflits : refus d'un chevauchement sur l'agenda d'un même avocat.
- **RDV-04 (P1)** Statuts : planifié, confirmé, honoré, annulé, absent (no-show), reporté.
- **RDV-05 (P1)** Une consultation honorée peut générer une facture de consultation en un clic.
- **RDV-06 (P1)** Les audiences d'un dossier apparaissent dans l'agenda avec la juridiction et la chambre.
- **RDV-07 (P2)** Prise de RDV en ligne par le client via un lien public, avec créneaux disponibles.
- **RDV-08 (P2)** Synchronisation Google Calendar / Outlook.

### 3.3 Clients

- **CLI-01 (P1)** Fiche personne physique (nom, téléphone, e-mail, adresse, pièce d'identité) ou personne morale (raison sociale, RCCM, ID. Nat., représentant).
- **CLI-02 (P1)** Historique : RDV, dossiers, factures, paiements, solde dû.
- **CLI-03 (P1)** Consentement aux notifications par e-mail (oui / non).
- **CLI-04 (P1)** Contrôle des conflits d'intérêts : à la création, recherche du nom dans les clients et les parties adverses existantes, avec alerte.

### 3.4 Dossiers (affaires)

- **DOS-01 (P1)** Numérotation automatique (ex. LEX-2026-0042), intitulé, client, partie adverse, avocat responsable et collaborateurs.
- **DOS-02 (P1)** Matière (civil, commercial, pénal, social, foncier, OHADA, conseil), juridiction, numéro de rôle (RG).
- **DOS-03 (P1)** Statuts : ouvert, en cours, en délibéré, clôturé, archivé.
- **DOS-04 (P1)** Convention d'honoraires : type, montant et devise (USD ou CDF). Le sens du montant dépend du type :
  - **Forfait** : prix total de l'affaire ;
  - **Taux horaire** : prix d'une heure de travail ;
  - **Abonnement mensuel** : prix d'une période de 30 jours ;
  - **Provision + résultat** : provision demandée au départ ; l'honoraire de résultat sera précisé avec le cabinet pilote.
- **DOS-05 (P1)** Journal du dossier : actes, audiences, temps passé (date, durée, avocat, description, facturable oui / non). Les heures facturables alimentent la facturation au taux horaire.
- **DOS-06 (P1)** Pièces jointes (PDF, images, Word) avec catégorie, 20 Mo maximum par fichier.
- **DOS-07 (P2)** Échéancier des délais de procédure avec alertes.
- **DOS-08 (P1)** L'abonnement mensuel est porté par le dossier en V1 : date de début (ouverture du dossier), date de la prochaine facture, arrêt automatique à la clôture du dossier.

### 3.5 Honoraires et facturation

- **FAC-01 (P1)** Une facture se crée à partir d'un **dossier**. Le choix du dossier affiche le client, son adresse, son téléphone et le type de facturation de la convention.
- **FAC-02 (P1)** Les lignes d'honoraires sont générées automatiquement selon la convention ; l'utilisateur ne les saisit pas :
  - **Forfait** : une ligne « intitulé du dossier, honoraires forfaitaires » au montant du forfait ;
  - **Taux horaire** : une ligne reprenant toutes les heures facturables non encore facturées × le taux du dossier, avec la période couverte ;
  - **Abonnement mensuel** : une ligne « intitulé du dossier, abonnement du … au … » au montant mensuel ;
  - **Provision + résultat** : une ligne de provision ; le calcul du résultat sera défini plus tard.
- **FAC-03 (P1)** Nombre de factures par dossier :
  - **Forfait** : une seule facture, réglée en une ou plusieurs tranches ; une seconde facture de forfait est refusée tant que la première n'est pas annulée ;
  - **Taux horaire** : autant de factures que nécessaire ; chaque facture reprend les heures non facturées, marquées « facturées » à l'émission. La suivante ne contient que les nouvelles heures, ou seulement des lignes complémentaires s'il n'y en a pas ;
  - **Abonnement mensuel** : une facture par période de 30 jours, sans doublon sur une même période.
- **FAC-04 (P1)** Lignes complémentaires ajoutées à la main : débours, frais de greffe, transport, frais de dossier, avec libellé, quantité et prix unitaire.
- **FAC-05 (P1)** Réduction en **pourcentage** (0 à 100 %), appliquée aux seules lignes d'honoraires (pas aux débours ni aux frais), affichée sur la facture avec son montant.
- **FAC-06 (P1)** Exception : une consultation honorée peut être facturée depuis l'agenda **sans dossier** (client du rendez-vous, ligne de consultation au tarif par défaut).
- **FAC-07 (P1)** Devise de facture : celle de la convention (USD ou CDF) ; taux de change du jour mémorisé sur la facture.
- **FAC-08 (P1)** Taxe paramétrable (TVA 16 % activable selon le régime du cabinet), calculée après réduction ; débours hors taxe.
- **FAC-09 (P1)** Statuts : brouillon, émise, partiellement payée, payée, annulée. Une facture émise n'est plus modifiable ; elle s'annule par un avoir.
- **FAC-10 (P1)** Numérotation chronologique continue sans trou (ex. FAC-2026-00118), attribuée à l'émission.
- **FAC-11 (P1)** Paiement en plusieurs tranches sur une même facture, chacune avec son reçu ; le reste dû se met à jour à chaque encaissement.
- **FAC-12 (P1)** Génération PDF à l'en-tête du cabinet et envoi par e-mail.
- **FAC-13 (P1)** Provisions reçues sans facture : enregistrées comme avance client, puis imputées sur une facture du même client.
- **FAC-14 (P1)** Abonnement : une tâche planifiée quotidienne génère la facture 30 jours après l'ouverture du dossier, puis tous les 30 jours tant que le dossier n'est pas clôturé.
- **FAC-15 (P2)** Relances automatiques des impayés (J+7, J+15, J+30).

### 3.6 Caisse (USD et CDF)

- **CAI-01 (P1)** Une caisse physique tenue en deux devises, avec un solde distinct par devise ; possibilité d'ajouter des comptes bancaires et mobile money comme moyens de paiement.
- **CAI-02 (P1)** Session de caisse : ouverture avec fond initial compté, clôture avec comptage réel, calcul de l'écart et motif obligatoire si écart.
- **CAI-03 (P1)** Encaissement : client, facture(s) réglée(s), devise, montant, mode (espèces, virement, chèque, mobile money), référence, reçu imprimable numéroté.
- **CAI-04 (P1)** Paiement d'une facture USD en CDF (et inversement) converti au taux du jour ; le taux est enregistré sur l'opération.
- **CAI-05 (P1)** Décaissement : catégorie (loyer, salaires, frais de greffe, transport, fournitures, débours client), bénéficiaire, pièce justificative ; validation par l'administrateur au-delà d'un seuil paramétrable (ex. 500 USD).
- **CAI-06 (P1)** Opération de change interne (USD vers CDF ou inverse) tracée comme deux mouvements liés.
- **CAI-07 (P1)** Table des taux de change journaliers saisie par l'administrateur (taux BCC ou taux marché).
- **CAI-08 (P1)** Aucune suppression d'opération : correction par contre-passation.

### 3.7 Comptabilité

- **CPT-01 (P1)** Plan comptable inspiré du SYSCOHADA révisé, livré pré-paramétré (classes 1 à 7) et modifiable.
- **CPT-02 (P1)** Écritures générées automatiquement : émission de facture (411 / 706 / 443), encaissement (571 ou 521 / 411), décaissement (6x / 571), provision client (571 / 4191).
- **CPT-03 (P1)** Saisie d'écritures manuelles équilibrées (débit = crédit), avec journal (ventes, caisse, banque, opérations diverses).
- **CPT-04 (P1)** Tenue en devise de référence USD, avec conservation du montant et du taux d'origine en CDF.
- **CPT-05 (P1)** États : journal, grand livre, balance, compte de résultat simplifié, par période.
- **CPT-06 (P1)** Clôture mensuelle : période verrouillée, plus aucune écriture possible.
- **CPT-07 (P1)** Export Excel et PDF de tous les états.
- **CPT-08 (P2)** Bilan, déclarations fiscales, rapprochement bancaire.

### 3.8 Notifications par e-mail

- **NOT-01 (P1)** Confirmation envoyée au client dès la création ou la modification d'un RDV.
- **NOT-02 (P1)** Rappels automatiques à J-1 (18 h) et H-2 avant le RDV ; délais paramétrables.
- **NOT-03 (P1)** E-mail d'annulation ou de report.
- **NOT-04 (P1)** Récapitulatif quotidien envoyé à chaque avocat à 7 h : RDV et audiences du jour.
- **NOT-05 (P1)** Envoi de facture et de reçu de paiement en pièce jointe PDF.
- **NOT-06 (P1)** Modèles d'e-mails modifiables avec variables ({client}, {date}, {heure}, {avocat}, {lieu}, {cabinet}).
- **NOT-07 (P1)** Journal d'envoi : destinataire, modèle, date, statut (envoyé, échec), avec relance manuelle.
- **NOT-08 (P1)** Pièce jointe .ics pour ajouter le RDV à l'agenda du client.
- **NOT-09 (P2)** Rappels par SMS et WhatsApp.

### 3.9 Paramètres et administration

- **ADM-01 (P1)** Identité du cabinet : nom, logo, adresse, numéro d'inscription au barreau, RCCM, ID. Nat., NIF, coordonnées bancaires pour les factures.
- **ADM-02 (P1)** Utilisateurs, rôles et permissions ; un utilisateur peut recevoir plusieurs rôles, dont les droits s'additionnent.
- **ADM-03 (P1)** Paramètres e-mail (SMTP), devises, taux, taxes, seuils de validation, heures d'ouverture.
- **ADM-04 (P1)** Journal d'audit : qui a créé, modifié, annulé quoi et quand.

## 4. Exigences non fonctionnelles

Le secret professionnel de l'avocat impose un niveau de sécurité supérieur à celui d'un logiciel de gestion classique.

| Domaine | Exigence |
| --- | --- |
| Confidentialité | Un avocat ne voit que ses dossiers et ceux où il est collaborateur ; pièces jointes stockées hors du dossier public et servies après contrôle des droits |
| Authentification | Mots de passe hachés (bcrypt), verrouillage après 5 échecs, double authentification (2FA) pour l'administrateur |
| Chiffrement | HTTPS obligatoire (TLS), sauvegardes chiffrées |
| Traçabilité | Journal d'audit non modifiable sur clients, dossiers, factures, caisse et écritures |
| Intégrité financière | Pas de suppression de facture émise, d'opération de caisse ni d'écriture : annulation par avoir ou contre-passation |
| Sauvegardes | Base de données quotidienne, conservation 30 jours, copie hors serveur ; test de restauration mensuel |
| Performance | Pages en moins de 2 s sur une connexion 3G ; jusqu'à 50 utilisateurs simultanés |
| Disponibilité | 99 % en heures ouvrées ; envois d'e-mails en file d'attente pour ne pas bloquer l'interface |
| Ergonomie | Interface en français, responsive (ordinateur, tablette, smartphone) ; montants affichés avec le symbole de la devise |
| Conformité | Données personnelles traitées selon la loi congolaise sur le numérique ; conservation des pièces comptables 10 ans (OHADA) |

## 5. Modèle de données

Quinze tables principales suffisent pour le MVP ; tous les montants sont stockés en DECIMAL(15,2) avec leur devise et, si besoin, le taux appliqué.

| Table | Champs principaux | Relations |
| --- | --- | --- |
| users | nom, e-mail, mot de passe, rôle(s), actif | possède appointments, cases, cash_sessions |
| clients | type (physique / morale), nom ou raison sociale, téléphone, e-mail, adresse, RCCM, ID. Nat., consentement e-mail | a plusieurs cases, appointments, invoices |
| cases (dossiers) | référence, intitulé, matière, juridiction, n° RG, partie adverse, statut, type d'honoraires (forfait, taux horaire, abonnement, provision + résultat), montant, devise, date de la prochaine facture d'abonnement | client, avocat responsable, collaborateurs |
| case_events | type (acte, audience, note), date, description | case |
| time_entries | date, durée (min), description, facturable, facturée | case, user, invoice (une fois facturée) |
| documents | nom, catégorie, chemin, taille | case |
| appointments | début, fin, type, lieu, objet, statut, notes, facturé | client, case (option), avocat |
| invoices | numéro, date, échéance, devise, taux, période (abonnement), réduction %, total honoraires, montant réduction, total frais, taxe, total TTC, payé, statut | case (obligatoire sauf consultation), client |
| invoice_lines | libellé, origine (automatique / complémentaire), type (honoraires, débours, greffe, transport, frais de dossier, consultation), quantité, prix unitaire, taxable | invoice |
| exchange_rates | date, taux USD→CDF, source | — |
| cash_sessions | ouverture, clôture, fonds initiaux USD / CDF, comptages USD / CDF, écarts, motif | user |
| cash_movements | sens (entrée / sortie / change), devise, montant, taux, mode, catégorie, référence, n° reçu, validé par | cash_session, client, invoice (option) |
| accounts | numéro, libellé, classe, type | plan comptable |
| journal_entries + journal_lines | journal, date, libellé, pièce ; lignes compte, débit, crédit, devise d'origine, montant d'origine | source : invoice, cash_movement ou saisie manuelle |
| email_logs | destinataire, modèle, objet, statut, erreur, date d'envoi | appointment ou invoice |

Tables techniques complémentaires : settings (paramètres du cabinet), email_templates, audit_logs, jobs (file d'attente Laravel).

## 6. Architecture technique

Recommandation : une application monolithique Laravel sur un VPS Linux, la stack la plus simple à maintenir pour une petite équipe.

Schéma : les utilisateurs du cabinet accèdent en HTTPS à un serveur VPS (Nginx, PHP 8.3, Laravel, Supervisor, cron) qui héberge l'interface (Blade + Livewire), les modules métier, le planificateur (rappels J-1, récapitulatif de 7 h) et la file d'attente (envoi des e-mails, génération des PDF). L'application s'appuie sur MySQL 8, un stockage privé pour les pièces et les PDF, et un service e-mail (SMTP du domaine, Brevo ou Amazon SES) qui délivre confirmations, rappels et factures aux clients.

Les e-mails et les PDF partent par la file d'attente : l'interface ne bloque jamais, et un envoi en échec est réessayé trois fois puis signalé dans le journal.

| Composant | Choix | Raison |
| --- | --- | --- |
| Back-end | Laravel, PHP 8.3 | Authentification, files d'attente, planificateur et mails intégrés |
| Interface | Blade + Livewire 3, Tailwind CSS | Pages réactives sans application JavaScript séparée |
| Base de données | MySQL 8 (ou MariaDB 10.11) | Transactions pour la caisse et la comptabilité |
| PDF | barryvdh/laravel-dompdf | Factures, reçus et états |
| Droits | spatie/laravel-permission | Rôles et permissions par module |
| Audit | spatie/laravel-activitylog | Journal des actions |
| Export | maatwebsite/excel | États comptables en Excel |
| Serveur | Nginx, Supervisor (worker), cron (`schedule:run`), Let's Encrypt | Fonctionne sur un VPS de 2 vCPU / 4 Go de RAM |

Tâches planifiées : rappels de RDV toutes les 15 minutes, récapitulatif des avocats à 7 h, factures d'abonnement chaque jour, sauvegarde à 2 h, purge des journaux techniques chaque semaine.

## 7. Périmètre du MVP et feuille de route

Le MVP contient toutes les exigences P1 de la section 3 ; il est livré en 10 semaines par un développeur full-stack Laravel, avec un référent côté cabinet pour les validations.

**Ce que le MVP permet le jour de la mise en production**

1. Créer clients et dossiers, avec contrôle des conflits d'intérêts.
2. Gérer l'agenda des avocats ; le client reçoit confirmation et rappels par e-mail.
3. Facturer consultations, forfaits, abonnements et temps passé, en USD ou en CDF, avec PDF envoyé par e-mail.
4. Encaisser et décaisser en deux devises, ouvrir et clôturer la caisse chaque jour.
5. Obtenir automatiquement journal, grand livre, balance et résultat du mois.

**Prototype interactif** : une maquette fonctionnelle accompagne ce document (`prototype.html`). Elle simule tous les écrans du MVP avec des données de démonstration pour valider les parcours avant le développement.

| Semaines | Phase | Contenu |
| --- | --- | --- |
| S1–S2 | Socle | Authentification, rôles, paramètres, clients |
| S3–S4 | Rendez-vous et notifications | Agenda, conflits, rappels e-mail |
| S5–S6 | Dossiers et facturation | Temps passé, factures PDF, avoirs |
| S7 | Caisse USD / CDF | Sessions, change, reçus |
| S8 | Comptabilité | Écritures automatiques, états, clôture |
| S9 | Recette pilote | Cabinet pilote, corrections |
| S10 | Mise en production | Déploiement, formation, sauvegardes |

Le passage en production en S10 n'a lieu que si les seize scénarios de recette de la section 8 sont validés par le cabinet pilote.

**V2 envisagée (après 3 mois d'usage)** : prise de RDV en ligne, rappels SMS et WhatsApp, paiement mobile money, relances automatiques des impayés, échéancier des délais de procédure, application mobile pour les avocats.

## 8. Livrables et critères de recette

Le MVP est accepté quand les scénarios ci-dessous passent sur l'environnement de préproduction, avec des données d'un cabinet pilote.

**Livrables**

- Prototype interactif cliquable (fourni avec ce cahier des charges).
- Code source versionné (Git), migrations et données de démonstration.
- Application déployée en préproduction puis en production, HTTPS actif.
- Guide utilisateur par rôle (PDF, 10 à 15 pages) et session de formation de 2 heures.
- Procédure de sauvegarde et de restauration testée.

**Scénarios de recette**

- [ ] Une secrétaire crée un RDV ; le client reçoit la confirmation avec le fichier .ics en moins de 2 minutes.
- [ ] Un second RDV au même créneau pour le même avocat est refusé.
- [ ] Le rappel J-1 part automatiquement à l'heure paramétrée ; son envoi apparaît dans le journal.
- [ ] Le choix d'un dossier au forfait de 2 500 $ affiche le client, son adresse, son téléphone et une ligne d'honoraires de 2 500 $ ; une seconde facture de forfait sur ce dossier est refusée.
- [ ] Sur un dossier à 120 $/h avec 2 h 30 non facturées, la facture propose 300 $ ; à l'émission, ces heures passent « facturées » et la facture suivante ne les reprend pas.
- [ ] Une réduction de 10 % sur 1 000 $ d'honoraires et 50 $ de débours donne 950 $ : la réduction ne touche pas les débours.
- [ ] Un dossier en abonnement de 800 $ génère sa facture 30 jours après l'ouverture, une seule par période.
- [ ] Une facture de 300 USD payée 150 USD en espèces puis l'équivalent de 150 USD en CDF passe au statut « payée », chaque paiement portant son taux.
- [ ] La clôture de caisse affiche le solde théorique et l'écart par devise ; un écart sans motif bloque la clôture.
- [ ] Un décaissement au-dessus du seuil reste « en attente » tant que l'administrateur ne l'a pas validé.
- [ ] Chaque facture émise et chaque mouvement de caisse produit une écriture équilibrée ; la balance du mois est équilibrée.
- [ ] Un avocat ne peut ouvrir ni le dossier ni les pièces d'un dossier qui ne lui est pas attribué ; il peut y saisir son temps, mais ne peut créer ni dossier, ni rendez-vous, ni facture.
- [ ] Une facture émise ne peut être ni modifiée ni supprimée ; seul un avoir l'annule.
- [ ] Le journal d'audit montre l'auteur et l'heure de chacune des actions précédentes.
- [ ] Une secrétaire crée un dossier mais ne voit aucun bouton de facturation ; si la route de création de facture est appelée directement, le serveur la refuse.
- [ ] Un utilisateur qui a les rôles avocat et secrétaire peut créer un dossier et saisir son temps.

**Questions ouvertes**

- Le cabinet pilote est-il assujetti à la TVA ?
- Quel taux de change de référence : BCC ou marché ?
- Quel service d'envoi d'e-mails : SMTP du domaine, Brevo, Mailgun ou Amazon SES ?
- Provision + résultat : comment calculer l'honoraire de résultat (pourcentage du montant obtenu ou montant fixe) ?
- Abonnement : la facture générée part-elle directement au client, ou reste-t-elle en brouillon pour validation ?
