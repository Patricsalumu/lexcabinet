# E-mails de LexCabinet : mise en service sur le VPS

Tous les e-mails métier (rendez-vous, rappels, récapitulatifs, factures, reçus, dossiers, comptes) passent par la **file d'attente `database`** : l'application enregistre l'e-mail dans `email_logs` (« En file d'attente ») et un job dans la table `jobs`. **Un worker doit tourner en permanence** pour les envoyer. Sans worker, les e-mails restent « En file d'attente » indéfiniment.

La réinitialisation du mot de passe, elle, est envoyée immédiatement par Laravel (hors file) : qu'elle fonctionne prouve que le SMTP est bon, pas que le worker tourne.

## 1. Configuration `.env` (production)

```dotenv
APP_URL=https://votre-domaine            # utilisé dans les liens des e-mails
QUEUE_CONNECTION=database
MAIL_MAILER=smtp                         # surtout pas « log » ni « array »
MAIL_HOST=…
MAIL_PORT=587
MAIL_SCHEME=null                         # ou « smtps » pour le port 465
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="contact@votre-domaine"
MAIL_FROM_NAME="${APP_NAME}"
ADMIN_TWO_FACTOR=false
```

**Expéditeur** : les e-mails partent toujours de `MAIL_FROM_ADDRESS`, qui doit être **l'adresse du compte SMTP** (`MAIL_USERNAME`) ; sinon le serveur refuse l'envoi (« 550 Verification failed for <…> »). L'« E-mail du cabinet » des Paramètres sert d'adresse de réponse (Reply-To) : les clients qui répondent écrivent au cabinet.

**Données de démonstration** : en production, remplacez les adresses `….example` (utilisateurs et clients de démo) par de vraies adresses, ou désactivez ces comptes ; `mail-check` les signale.

Après toute modification du `.env` :

```bash
php artisan config:cache
php artisan queue:restart
```

Important : le transport est fixé au moment où l'e-mail est mis en file. Un e-mail mis en file avant la correction garde l'ancien transport (ex. `log`) ; utilisez « Relancer l'envoi » dans E-mails > Journal pour le renvoyer.

## 2. Installer les nouveaux modèles d'e-mails (sans écraser les existants)

```bash
php artisan migrate --force
php artisan db:seed --class=EmailTemplateSeeder --force
```

Le seeder n'ajoute que les modèles absents ; un modèle personnalisé n'est jamais modifié. (Un modèle manquant est de toute façon créé automatiquement au premier envoi.)

## 3. Worker avec Supervisor

**HestiaCP et panneaux similaires** : les fonctions `pcntl_*` y sont désactivées (`php -i | grep disable_functions`). Le worker et le planificateur plantent alors avec « Call to undefined function pcntl_signal() ». Ajoutez `-d disable_functions=` à **toutes** les commandes `php artisan queue:work` et `schedule:run` (worker Supervisor, cron, lancement manuel) : la restriction est levée pour ces seuls processus, sans toucher à la configuration PHP du serveur. Vérification : `php -d disable_functions= -r 'var_dump(function_exists("pcntl_signal"));'` doit afficher `bool(true)`.

Lancez les commandes artisan avec l'utilisateur du site (`sudo -u <utilisateur> php …`), pas en root, pour que les fichiers de `storage/` gardent le bon propriétaire.

Vérifier que Supervisor est installé : `supervisorctl version`. Sinon : `sudo apt install supervisor`.

Copier `deploy/supervisor/lexcabinet-worker.conf` dans `/etc/supervisor/conf.d/`, adapter le chemin et l'utilisateur, puis :

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status lexcabinet-worker:*
```

Un seul processus suffit. À chaque déploiement de code : `php artisan queue:restart` (Supervisor relance le worker).

## 4. Planificateur (rappels, récapitulatif, abonnements)

Dans la crontab de l'utilisateur web (`sudo crontab -u www-data -e`) :

```cron
* * * * * cd /var/www/lexcabinet && /usr/bin/php -d disable_functions= artisan schedule:run >> /dev/null 2>&1
```

## 5. Diagnostic

```bash
php artisan lexcabinet:mail-check                         # transport, file, worker, échecs
php artisan lexcabinet:mail-check --send=vous@exemple.cd  # e-mail de test immédiat par le SMTP
php artisan queue:failed                                   # détail des jobs en échec
php artisan queue:retry all                                # relancer les jobs en échec
tail -f storage/logs/worker.log storage/logs/laravel.log
```

`mail-check` signale une erreur si des jobs attendent depuis plus de 5 minutes (aucun worker actif).

Permissions : `storage/` et `bootstrap/cache/` doivent appartenir à l'utilisateur du worker et de PHP-FPM :

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 6. Statuts du journal d'envoi

| Statut | Signification |
| --- | --- |
| En file d'attente | Enregistré, attend le worker |
| Envoyé | Accepté par le serveur SMTP (le motif précise le transport) |
| Échec | 3 tentatives échouées (1 min puis 5 min d'intervalle) ; erreur indiquée ; relance possible |
| Non envoyé | Aucun envoi tenté : consentement refusé, adresse absente, compte désactivé, avocat retiré du dossier… |

« Envoyé » signifie remis au serveur d'envoi ; la réception dépend ensuite du serveur du destinataire (vérifier aussi les indésirables).

## 7. Conversations : lire les réponses dans l'application

Les réponses des clients (et des avocats) aux e-mails de l'application sont relevées toutes les
5 minutes dans la boîte du cabinet et affichées dans le module **Messages**. On peut y répondre ;
la réponse part par le journal d'envoi habituel et une copie est déposée dans « Envoyés ».

La boîte est lue **sans rien y changer** : les messages ne sont ni supprimés, ni déplacés, ni
marqués comme lus. On peut donc continuer à les lire dans Roundcube, Gmail ou sur le téléphone.
Seules les réponses à des e-mails de l'application sont importées ; le reste du courrier est ignoré.

1. **Adresse de réponse** : les clients répondent à l'e-mail du cabinet (Paramètres → Cabinet),
   à défaut à `MAIL_FROM_ADDRESS`. C'est cette boîte que l'application doit lire. Sur un autre
   serveur ou un autre domaine, il suffit de changer l'e-mail du cabinet et les variables ci-dessous.
2. **`.env`** (accès de cette même boîte) :
   ```
   IMAP_HOST=mail.exemple.com
   IMAP_PORT=993
   IMAP_ENCRYPTION=ssl
   IMAP_VALIDATE_CERT=true
   IMAP_USERNAME=adresse@exemple.com
   IMAP_PASSWORD="mot-de-passe"
   IMAP_FOLDERS=INBOX
   IMAP_SENT_FOLDER=Sent
   ```
   puis `php artisan config:cache`.
3. **Vérifier** : `php artisan lexcabinet:mail-check` (section « Réponses ») puis
   `php artisan lexcabinet:fetch-replies` pour une relève immédiate.
4. La relève tourne avec le planificateur (section 4) : rien d'autre à installer.

**Gmail** : pour lire la boîte du cabinet dans Gmail, utilisez « Consulter d'autres comptes » en
cochant **« Laisser un exemplaire du message récupéré sur le serveur »**, ou une redirection.
Sinon Gmail vide la boîte et l'application ne voit plus les réponses.

Seuls les e-mails envoyés après la mise en service de cette fonction peuvent être rattachés à une
conversation (ils portent un identifiant que les réponses citent).

## 8. Messagerie propre à chaque cabinet (multi-cabinet)

Chaque cabinet peut configurer, dans **Paramètres → Messagerie** (administrateur du cabinet) :

- **son serveur d'envoi (SMTP)** : ses e-mails partent alors de sa propre adresse. Sans configuration,
  ils partent par le SMTP de la plateforme (`MAIL_*`), avec le nom du cabinet en expéditeur et
  son e-mail en adresse de réponse ;
- **sa boîte de réception (IMAP)** : les réponses de ses clients y sont relevées toutes les 5 minutes,
  sans rien y changer. Sans configuration, elles sont relevées dans la boîte de la plateforme (`IMAP_*`).

Les boutons « Envoyer un e-mail d'essai » et « Tester la connexion » vérifient la configuration sur
place. Les mots de passe sont chiffrés avec la clé de l'application (`APP_KEY`) et jamais réaffichés :
si `APP_KEY` change (ou lors d'un passage sur un autre serveur), ils doivent être ressaisis.

Pour que les réponses apparaissent dans l'application, **l'e-mail du cabinet** (Paramètres → Cabinet)
et **la boîte lue** doivent être la même adresse. `php artisan lexcabinet:mail-check` le vérifie pour
chaque cabinet.

Avec `MAIL_MAILER=log` (poste de développement), le SMTP des cabinets n'est pas utilisé pour les
e-mails automatiques ; seul le bouton d'essai l'utilise.
