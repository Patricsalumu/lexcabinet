# LexCabinet — consignes du projet

Application web de gestion d'un cabinet d'avocats en RDC : rendez-vous, clients, dossiers, honoraires et facturation, caisse en USD et CDF, comptabilité inspirée du SYSCOHADA, notifications par e-mail.

Documents de référence (à lire avant de coder) :
- `docs/cahier-des-charges.md` : exigences numérotées (RDV-xx, DOS-xx, FAC-xx, CAI-xx, CPT-xx, NOT-xx, ADM-xx). P1 = à livrer, P2 = hors périmètre.
- `docs/prototype.html` : prototype cliquable validé par le client. Il sert de référence pour les écrans et les parcours, pas pour le code.

En cas de contradiction, ce fichier `CLAUDE.md` fait foi, puis le cahier des charges, puis le prototype.

## Stack

- Dernière version stable de Laravel, PHP 8.3 ou plus, MySQL 8 (ou MariaDB 10.11).
- Interface : Blade + Livewire 3 + Tailwind CSS (Vite). Pas de SPA séparée.
- Paquets autorisés : `spatie/laravel-permission`, `spatie/laravel-activitylog`, `barryvdh/laravel-dompdf`, `maatwebsite/excel`, `pestphp/pest`, et Laravel Breeze (pile Livewire) pour l'authentification. Demander avant d'ajouter tout autre paquet.
- E-mails : Mailables en file d'attente (`ShouldQueue`), driver `database` pour la queue. En local, `MAIL_MAILER=log`.
- Planificateur : `routes/console.php` (Schedule).

## Conventions

- Code (classes, tables, colonnes, méthodes) en anglais. Interface, messages, e-mails et PDF en français.
- `APP_LOCALE=fr`, `APP_TIMEZONE=Africa/Kinshasa`.
- Montants : colonnes `DECIMAL(15,2)`, toujours accompagnées d'une colonne `currency` (`USD` ou `CDF`). Calculs avec `bcmath` ou des entiers en centimes, jamais en float. Affichage : `1 250,00 $` et `3 500 000 FC`.
- Taux de change : nombre de CDF pour 1 USD, mémorisé sur chaque facture et chaque mouvement de caisse.
- Un service par règle métier (ex. `InvoiceBuilder`, `CashService`, `LedgerService`) ; les composants Livewire restent minces.
- Toute opération qui touche à l'argent s'exécute dans une transaction DB.
- Aucune suppression de facture émise, de mouvement de caisse ni d'écriture comptable. Correction par avoir ou contre-passation.
- Chaque action métier est tracée dans le journal d'audit (activitylog).

## Rôles et droits

Un utilisateur peut avoir **plusieurs rôles** ; ses droits sont l'union des droits de ses rôles. Les contrôles se font côté serveur (Policies, middleware `can:`), puis `@can` dans Blade pour masquer menus et boutons. Masquer un bouton ne suffit jamais.

| Rôle | Peut | Ne peut pas |
| --- | --- | --- |
| admin | Tout, dont valider les décaissements au-delà du seuil et gérer les utilisateurs | — |
| avocat | Lire l'agenda ; lire ses dossiers et ceux où il est collaborateur ; saisir son temps passé | Créer un dossier, un rendez-vous ou une facture ; voir les dossiers des autres |
| secretaire | Gérer l'agenda, les clients, créer et modifier les dossiers, envoyer les rappels | Facturer, encaisser |
| caissier | Ouvrir et clôturer la caisse, encaisser, décaisser, faire un change | Créer un dossier, facturer |
| comptable | Créer et émettre les factures, comptabilité, rapports, lecture de la caisse | Créer un dossier |

## Règles de facturation (FAC-01 à FAC-15)

Convention d'honoraires portée par le dossier : `fee_type`, `fee_amount`, `fee_currency`.

| fee_type | Sens du montant | Factures autorisées | Ligne générée automatiquement |
| --- | --- | --- | --- |
| `flat` (forfait) | Prix total de l'affaire | Une seule (hors factures annulées) ; payée en plusieurs tranches | « {intitulé du dossier}, honoraires forfaitaires » × 1 |
| `hourly` (taux horaire) | Prix d'une heure | Autant que nécessaire | Somme des heures facturables non facturées × taux, avec la période couverte |
| `subscription` (abonnement) | Prix d'une période de 30 jours | Une par période | « {intitulé}, abonnement du {début} au {fin} » |
| `retainer_success` (provision + résultat) | Provision demandée | Libre | Ligne de provision ; le résultat sera spécifié plus tard |

- La création d'une facture part du **dossier** : le choix du dossier affiche client, adresse, téléphone et type de convention, puis génère la ligne d'honoraires. L'utilisateur ne saisit pas les honoraires.
- Lignes complémentaires saisies à la main : débours, frais de greffe, transport, frais de dossier.
- Réduction en **pourcentage** (0 à 100), appliquée aux seules lignes d'honoraires, affichée sur la facture.
- TVA 16 % activable dans les paramètres, calculée après réduction, jamais sur les débours.
- Taux horaire : à l'émission, les `time_entries` reprises reçoivent `invoice_id`. Une facture suivante ne reprend que les nouvelles heures.
- Exception : une consultation honorée peut être facturée depuis l'agenda **sans dossier**. C'est le seul cas où `invoices.case_id` est nul.
- Statuts : `draft`, `issued`, `partially_paid`, `paid`, `cancelled`. Un brouillon est modifiable et supprimable. Une facture émise est figée.
- Numéro `FAC-AAAA-00001`, attribué à l'émission, continu et sans trou (verrou `lockForUpdate` sur un compteur).
- Abonnement : une commande planifiée quotidienne crée la facture 30 jours après l'ouverture du dossier, puis tous les 30 jours tant que le dossier n'est pas clôturé, sans doublon de période. Elle est créée en **brouillon** (question encore ouverte, paramètre `subscription_auto_issue = false`).

## Caisse

- Une caisse physique en deux devises, plus des comptes Banque et Mobile money.
- Session : ouverture avec fonds comptés, clôture avec comptage USD et CDF, écart calculé, motif obligatoire si écart ; l'écart génère un mouvement d'ajustement.
- Encaissement : client, facture (ou provision sans facture), devise, montant, mode, référence, reçu `RC-AAAA-0001`. Un paiement en CDF sur une facture USD (et l'inverse) est converti au taux saisi et ne peut pas dépasser le reste dû.
- Décaissement : catégorie, bénéficiaire, pièce. Au-delà du seuil (500 USD par défaut, équivalent CDF), statut `pending` jusqu'à validation par un admin.
- Change interne USD ↔ CDF : un mouvement avec montant cédé et montant reçu.

## Écritures comptables automatiques (montants en USD, CDF convertis au taux de l'opération)

| Événement | Débit | Crédit |
| --- | --- | --- |
| Émission de facture | 411 Clients (TTC) | 706 Honoraires (HT après réduction), 4431 TVA |
| Avoir | 706, 4431 | 411 |
| Encaissement sur facture | 5711 Caisse USD, 5712 Caisse CDF, 521 Banque ou 5216 Mobile money | 411 |
| Provision sans facture | idem | 4191 Clients, avances reçues |
| Décaissement | Compte de charge de la catégorie (622, 661, 646, 618, 6055, 6054, 638) ou 4711 débours | Compte de trésorerie |
| Change | Trésorerie reçue | Trésorerie cédée |
| Écart de caisse | 658 / trésorerie ou trésorerie / 758 | |

Chaque écriture doit être équilibrée ; un test le vérifie. Une période clôturée n'accepte plus d'écriture.

## E-mails

Modèles éditables en base (variables `{client}`, `{avocat}`, `{date}`, `{heure}`, `{lieu}`, `{objet}`, `{cabinet}`, `{numero}`, `{montant}`, `{echeance}`) : confirmation de RDV avec `.ics`, rappel J-1 à 18 h, annulation, envoi de facture PDF, reçu de paiement, récapitulatif quotidien des avocats à 7 h. Pas d'envoi si le client n'a pas donné son consentement. Chaque envoi est journalisé dans `email_logs` (envoyé, échec, non envoyé et motif).

## Façon de travailler

Le développement suit les phases ci-dessous, **une à la fois**. Pour chaque phase :
1. Relire les exigences concernées du cahier des charges.
2. Écrire migrations, modèles, factories, seeders de démonstration, policies, services, composants Livewire et vues.
3. Écrire des tests Pest pour les règles métier et les droits (au minimum les scénarios de recette de la section 8 qui concernent la phase).
4. Lancer `php artisan migrate:fresh --seed` puis `php artisan test` ; tout doit passer.
5. Faire un commit Git par phase, message en français (`Phase 2 : clients et dossiers`).
6. **S'arrêter** et résumer : ce qui est fait, comment le tester dans le navigateur (identifiants de démo), points en suspens. Attendre la validation avant la phase suivante.

| Phase | Contenu |
| --- | --- |
| 1. Socle | Projet Laravel, Breeze Livewire, rôles multiples, utilisateurs de démo (un par rôle + un avocat/secrétaire), paramètres du cabinet, taux de change, layout avec navigation selon les droits, journal d'audit |
| 2. Clients et dossiers | Clients physiques et morales, contrôle des conflits d'intérêts, dossiers avec convention d'honoraires, journal, temps passé, pièces jointes sur disque privé |
| 3. Agenda et e-mails | RDV, contrôle de chevauchement, statuts, modèles d'e-mails, confirmation `.ics`, rappels J-1 et récapitulatif planifiés, journal d'envoi |
| 4. Facturation | Création depuis le dossier, lignes automatiques, lignes complémentaires, réduction, TVA, émission et numérotation, PDF, avoir, facture d'abonnement planifiée, facture de consultation |
| 5. Caisse | Sessions, encaissements multi-devises, reçus PDF, décaissements avec validation, change, clôture et écarts |
| 6. Comptabilité | Plan comptable, génération des écritures, journal, grand livre, balance, résultat, clôture de période, exports Excel et PDF |

Données de démonstration : reprendre celles du prototype (Cabinet Mukendi & Associés, clients Kivu Agro SARL, Jean-Pierre Lumbala, etc.). Mot de passe de démo `password`, uniquement dans les seeders.

## Interdits

- Ne jamais committer `.env`, de mot de passe réel ni de clé.
- Ne pas modifier une migration déjà commitée : créer une nouvelle migration.
- Ne pas implémenter les exigences P2.
- Ne pas inventer une règle métier absente de ce fichier ou du cahier des charges : la noter comme question ouverte et demander.
