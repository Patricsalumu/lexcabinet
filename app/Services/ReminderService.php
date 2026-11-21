<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\CaseEventType;
use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseEvent;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rappels automatiques (NOT-02) et récapitulatif quotidien des avocats (NOT-04).
 * Les commandes tournent toutes les 15 minutes ; chaque envoi n'a lieu qu'une fois.
 */
class ReminderService
{
    public function __construct(
        private readonly EmailService $emails,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Rappel J-1 : à partir de l'heure paramétrée (18 h par défaut), pour les rendez-vous du lendemain.
     *
     * @return int nombre de rendez-vous traités
     */
    public function sendDayBeforeReminders(?Carbon $now = null, ?User $by = null, bool $ignoreTime = false): int
    {
        $now ??= now();
        $sendAt = $now->copy()->setTimeFromTimeString((string) $this->settings->get('reminders.day_before_time'));
        if (! $ignoreTime && $now->lt($sendAt)) {
            return 0;
        }

        $tomorrow = $now->copy()->addDay();
        $appointments = Appointment::query()
            ->with(['client', 'lawyer'])
            ->whereIn('status', [AppointmentStatus::Planned->value, AppointmentStatus::Confirmed->value])
            ->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        $done = 0;
        foreach ($appointments as $appointment) {
            $done += $this->remind($appointment, 'reminder_sent_at', $now, fn () => $this->emails->send(
                EmailTemplate::REMINDER, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
                [['type' => 'ics', 'method' => IcsGenerator::REQUEST, 'name' => 'rendez-vous.ics']], $by,
            ));
        }

        return $done;
    }

    /**
     * Crée l'e-mail et marque le rappel « déclenché » dans une même transaction : le marqueur
     * n'est posé que si l'e-mail est bien journalisé et mis en file (après commit). Si le worker
     * échoue définitivement, EmailService::recordFailure efface le marqueur pour une nouvelle
     * tentative au prochain passage. Une erreur sur un rendez-vous n'arrête pas les suivants.
     */
    private function remind(Appointment $appointment, string $marker, Carbon $now, \Closure $send): int
    {
        $template = $marker === 'reminder_sent_at' ? EmailTemplate::REMINDER : EmailTemplate::REMINDER_SOON;
        if ($this->emails->automaticAttempts($template, $appointment->getMorphClass(), $appointment->id) >= EmailService::MAX_AUTOMATIC_ATTEMPTS) {
            return 0;
        }

        try {
            DB::transaction(function () use ($appointment, $marker, $now, $send) {
                $send();
                $appointment->forceFill([$marker => $now])->saveQuietly();
            });

            return 1;
        } catch (Throwable $e) {
            Log::error("Rappel non créé pour le rendez-vous {$appointment->id} : {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Rappel H-N (2 h par défaut ; 0 désactive) pour les rendez-vous qui commencent bientôt,
     * sauf s'ils ont été pris dans ce délai.
     */
    public function sendShortReminders(?Carbon $now = null): int
    {
        $now ??= now();
        $hours = (int) $this->settings->get('reminders.hours_before');
        if ($hours <= 0) {
            return 0;
        }

        $appointments = Appointment::query()
            ->with(['client', 'lawyer'])
            ->whereIn('status', [AppointmentStatus::Planned->value, AppointmentStatus::Confirmed->value])
            ->whereNull('short_reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addHours($hours))
            ->get()
            ->filter(fn (Appointment $a) => $a->created_at->lt($a->starts_at->copy()->subHours($hours)));

        $done = 0;
        foreach ($appointments as $appointment) {
            $done += $this->remind($appointment, 'short_reminder_sent_at', $now, fn () => $this->emails->send(
                EmailTemplate::REMINDER_SOON, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
            ));
        }

        return $done;
    }

    /**
     * Récapitulatif du jour (NOT-04) : à partir de l'heure paramétrée (7 h), une fois par jour
     * et par avocat ayant au moins un rendez-vous ou une audience.
     */
    public function sendDailyRecaps(?Carbon $now = null, ?User $by = null, bool $ignoreTime = false): int
    {
        $now ??= now();
        $sendAt = $now->copy()->setTimeFromTimeString((string) $this->settings->get('recap.time'));
        if (! $ignoreTime && $now->lt($sendAt)) {
            return 0;
        }

        $sent = 0;
        $lawyers = User::query()->role(Role::Lawyer->value)->where('is_active', true)->orderBy('name')->get();

        foreach ($lawyers as $lawyer) {
            // Un seul récapitulatif par jour ; s'il a échoué, nouvelle tentative (3 au plus).
            $today = EmailLog::query()
                ->where('template_key', EmailTemplate::RECAP)
                ->where('related_type', $lawyer->getMorphClass())
                ->where('related_id', $lawyer->id)
                ->whereDate('created_at', $now->toDateString())
                ->pluck('status');
            if ($today->contains(fn ($s) => $s !== EmailStatus::Failed) || $today->count() >= EmailService::MAX_AUTOMATIC_ATTEMPTS) {
                continue;
            }

            $items = $this->agendaFor($lawyer, $now);
            if ($items === []) {
                continue;
            }

            try {
                $this->emails->send(EmailTemplate::RECAP, $lawyer, [
                    'avocat' => $lawyer->name,
                    'destinataire' => $lawyer->name,
                    'date' => $now->translatedFormat('l j F Y'),
                    'liste' => implode("\n", $items),
                ], $lawyer, [], $by);
                $sent++;
            } catch (Throwable $e) {
                Log::error("Récapitulatif non créé pour {$lawyer->email} : {$e->getMessage()}");
            }
        }

        return $sent;
    }

    /** @return list<string> Lignes du récapitulatif : rendez-vous et audiences du jour. */
    public function agendaFor(User $lawyer, Carbon $day): array
    {
        $rows = [];

        Appointment::query()->with('client')
            ->where('lawyer_id', $lawyer->id)
            ->whereIn('status', AppointmentStatus::slotValues())
            ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get()
            ->each(function (Appointment $a) use (&$rows) {
                $rows[$a->starts_at->format('Hi').'a'.$a->id] = sprintf('- %s · %s · %s · %s', $a->starts_at->format('H\hi'), $a->type->label(), $a->client->name, $a->locationLabel());
            });

        CaseEvent::query()->with('legalCase')
            ->where('type', CaseEventType::Hearing->value)
            ->whereBetween('occurred_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereHas('legalCase', fn ($q) => $q->where('lead_lawyer_id', $lawyer->id))
            ->get()
            ->each(function (CaseEvent $e) use (&$rows) {
                $key = $e->occurred_at->format('Hi');
                // Évite le doublon avec un rendez-vous « audience » déjà saisi à la même heure.
                if (collect($rows)->keys()->contains(fn ($k) => str_starts_with($k, $key))) {
                    return;
                }
                $rows[$key.'e'.$e->id] = sprintf('- %s · Audience · %s · %s%s', $e->occurred_at->format('H\hi'), $e->legalCase->title,
                    $e->legalCase->jurisdiction ?? 'juridiction non précisée', $e->chamber ? ', '.$e->chamber : '');
            });

        ksort($rows);

        return array_values($rows);
    }
}
