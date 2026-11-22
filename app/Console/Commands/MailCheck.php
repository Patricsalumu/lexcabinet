<?php

namespace App\Console\Commands;

use App\Enums\EmailStatus;
use App\Models\EmailLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Diagnostic de l'envoi des e-mails : transport, file d'attente, worker, échecs.
 * Avec --send=adresse, envoie immédiatement (sans file) un e-mail de test par le transport configuré.
 */
#[Signature('lexcabinet:mail-check {--send= : Adresse à laquelle envoyer un e-mail de test, sans passer par la file}')]
#[Description('Vérifie la configuration des e-mails, la file d\'attente et le worker')]
class MailCheck extends Command
{
    public function handle(): int
    {
        $problems = 0;
        $mailer = (string) config('mail.default');
        $queue = (string) config('queue.default');

        $this->components->twoColumnDetail('<fg=cyan>Transport des e-mails</>');
        $this->components->twoColumnDetail('MAIL_MAILER', $mailer);
        if ($mailer === 'smtp') {
            $smtp = config('mail.mailers.smtp');
            $this->components->twoColumnDetail('Serveur', ($smtp['host'] ?? '?').':'.($smtp['port'] ?? '?').' ('.($smtp['scheme'] ?? 'auto').')');
            $this->components->twoColumnDetail('Utilisateur', $smtp['username'] ? (string) $smtp['username'] : '<fg=yellow>aucun</>');
            $this->components->twoColumnDetail('Mot de passe', filled($smtp['password'] ?? null) ? 'renseigné' : '<fg=yellow>vide</>');
        }
        $this->components->twoColumnDetail('Expéditeur (MAIL_FROM_ADDRESS)', (string) config('mail.from.address'));
        $this->components->twoColumnDetail('Adresse de réponse (e-mail du cabinet)', (string) (app(\App\Services\SettingsService::class)->get('firm.email') ?: '—'));
        if ($mailer === 'smtp' && filled(config('mail.mailers.smtp.username')) && str_contains((string) config('mail.mailers.smtp.username'), '@')
            && strcasecmp((string) config('mail.mailers.smtp.username'), (string) config('mail.from.address')) !== 0) {
            $this->components->warn('MAIL_FROM_ADDRESS diffère du compte SMTP : de nombreux serveurs refusent alors l\'envoi (erreur 550).');
        }

        // Adresses de démonstration restées en base : les envois vers elles échoueront.
        $demoUsers = \App\Models\User::query()->where('is_active', true)->where('email', 'like', '%.example')->count();
        $demoClients = \App\Models\Client::query()->where('email_consent', true)->where('email', 'like', '%.example')->count();
        if ($demoUsers + $demoClients > 0) {
            $this->components->warn("Adresses de démonstration (.example) : {$demoUsers} utilisateur(s) actif(s) et {$demoClients} client(s). Corrigez-les ou désactivez ces comptes.");
        }
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->components->warn("MAIL_MAILER={$mailer} : les e-mails ne sont PAS délivrés, seulement écrits dans le journal. Utilisez smtp en production.");
            $problems++;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>File d\'attente</>');
        $this->components->twoColumnDetail('QUEUE_CONNECTION', $queue);
        if ($queue === 'sync') {
            $this->components->warn('QUEUE_CONNECTION=sync : les e-mails sont envoyés pendant la requête (pas de nouvelle tentative). Recommandé : database + worker.');
        }

        if ($queue === 'database') {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $failed = DB::table('failed_jobs')->count();
            $age = $oldest ? Carbon::createFromTimestamp($oldest)->diffForHumans() : '—';

            $this->components->twoColumnDetail('Jobs en attente', $pending.($oldest ? " (le plus ancien créé {$age})" : ''));
            $this->components->twoColumnDetail('Jobs en échec (failed_jobs)', (string) $failed);

            if ($oldest && Carbon::createFromTimestamp($oldest)->lt(now()->subMinutes(5))) {
                $this->components->error('Des jobs attendent depuis plus de 5 minutes : aucun worker ne traite la file. Lancez « php artisan queue:work » (Supervisor en production).');
                $problems++;
            }
            if ($failed > 0) {
                $this->components->warn('Des jobs ont échoué : « php artisan queue:failed » pour le détail, « php artisan queue:retry all » pour les relancer.');
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Journal d\'envoi (email_logs)</>');
        foreach (EmailStatus::cases() as $status) {
            $this->components->twoColumnDetail($status->label(), (string) EmailLog::query()->where('status', $status->value)->count());
        }
        $lastSent = EmailLog::query()->where('status', EmailStatus::Sent->value)->latest('sent_at')->first();
        $this->components->twoColumnDetail('Dernier envoi réussi', $lastSent?->sent_at?->format('d/m/Y H:i') ?? '—');
        $lastFailure = EmailLog::query()->where('status', EmailStatus::Failed->value)->latest('id')->first();
        if ($lastFailure) {
            $this->components->twoColumnDetail('Dernière erreur', mb_substr((string) $lastFailure->reason, 0, 120));
        }

        if ($to = $this->option('send')) {
            $this->newLine();
            try {
                Mail::raw("E-mail de test LexCabinet envoyé le ".now()->format('d/m/Y à H:i:s')." par le transport « {$mailer} ».", function ($m) use ($to) {
                    $m->to($to)->subject('Test d\'envoi LexCabinet');
                });
                $this->components->info("E-mail de test accepté par le transport « {$mailer} » pour {$to}. Vérifiez la réception (et les indésirables).");
            } catch (Throwable $e) {
                $this->components->error('Échec de l\'envoi de test : '.$e->getMessage());
                $problems++;
            }
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
