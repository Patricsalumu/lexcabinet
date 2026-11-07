<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tâches planifiées (cahier des charges, section 6). Sur le serveur, une entrée cron
| lance « php artisan schedule:run » chaque minute.
|
| Les heures d'envoi (rappel J-1 à 18 h, récapitulatif à 7 h) sont des paramètres du cabinet :
| les commandes tournent toutes les 15 minutes et n'envoient qu'une fois l'heure atteinte,
| une seule fois par rendez-vous ou par avocat et par jour.
*/

Schedule::command('lexcabinet:send-reminders')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('lexcabinet:send-daily-recap')->everyFifteenMinutes()->withoutOverlapping();
