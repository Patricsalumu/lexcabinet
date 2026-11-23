# Passer un cabinet sur son propre serveur

Pendant la phase de test, plusieurs cabinets partagent la même installation (base partagée, données
cloisonnées par cabinet). Quand un cabinet dispose de son propre VPS, on y transfère ses données avec
deux commandes, sans rien perdre ni mélanger.

## Ce qui est transféré

- Toutes les données du cabinet : utilisateurs et rôles, clients, dossiers, agenda, factures, caisse,
  comptabilité, paramètres, modèles et journal des e-mails, conversations, journal d'audit.
- Ses fichiers : pièces des dossiers, logo, pièces jointes reçues.
- Les identifiants d'origine : numéros de factures, de reçus, de dossiers et liens entre les données
  restent identiques ; la numérotation continue à la suite.
- Les mots de passe de connexion des utilisateurs (sous forme chiffrée) : chacun se connecte comme avant.

**Non transférés**, à refaire sur le nouveau serveur :

- les mots de passe de messagerie du cabinet (Paramètres → Messagerie), chiffrés avec la clé de
  l'ancienne installation ;
- la double authentification : chaque utilisateur la réactive s'il le souhaite ;
- les e-mails encore en file d'attente : laissez le worker vider la file avant l'export.

## 1. Préparer le nouveau serveur

Installation habituelle (code, `.env` avec une **nouvelle** `APP_KEY`, base vide, worker, cron), puis :

```
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Ne créez **ni cabinet, ni utilisateur, ni super administrateur** avant l'import : l'import conserve les
identifiants d'origine et refuse tout conflit. Le code doit être à la même version (ou plus récente)
que sur la plateforme partagée.

## 2. Exporter depuis la plateforme partagée

Sur la plateforme, en SSH (le numéro du cabinet figure dans l'adresse de sa fiche, espace Plateforme) :

```
php artisan lexcabinet:export-firm 2
```

Le mot de passe de l'archive est demandé deux fois, en saisie masquée (12 caractères minimum).
L'archive est écrite dans `storage/app/exports/` et chiffrée (AES-256) : sans le mot de passe, son
contenu est illisible. Le cabinet garde une trace de l'export dans son propre journal d'audit.

Transmettez l'archive et son mot de passe **par deux canaux différents** (par exemple : archive par
`scp`, mot de passe par téléphone).

## 3. Importer sur le nouveau serveur

Vérification d'abord, sans rien écrire :

```
php artisan lexcabinet:import-firm /chemin/cabinet-2-20261123-101500.zip --dry-run
```

La commande contrôle le mot de passe, l'intégrité de chaque fichier (empreintes SHA-256), la version
du schéma et l'absence de conflit (cabinet, identifiants, adresses e-mail, fichiers). Puis :

```
php artisan lexcabinet:import-firm /chemin/cabinet-2-20261123-101500.zip
```

L'import se fait en une seule transaction : en cas d'erreur, rien n'est écrit.

## 4. Après l'import

1. Créez le super administrateur du nouveau serveur, si besoin :
   `php artisan lexcabinet:create-super-admin adresse@domaine`.
2. L'administrateur du cabinet ressaisit les mots de passe de messagerie (Paramètres → Messagerie)
   et vérifie avec « Envoyer un e-mail d'essai » et « Tester la connexion ».
3. Vérifiez : `php artisan lexcabinet:mail-check`, connexion d'un utilisateur, une facture et son PDF.
4. Sur la plateforme partagée, **suspendez** le cabinet (espace Plateforme → fiche du cabinet) pour
   que plus personne n'y travaille. Ses données y restent conservées ; leur suppression ne se fait
   que sur demande explicite.
5. Supprimez l'archive des deux serveurs une fois le transfert vérifié.
