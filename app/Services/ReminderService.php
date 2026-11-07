<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\CaseEventType;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseEvent;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

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

        foreach ($appointments as $appointment) {
            $this->emails->send(EmailTemplate::REMINDER, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
                [['type' => 'ics', 'method' => IcsGenerator::REQUEST, 'name' => 'rendez-vous.ics']], $by);
            $appointment->forceFill(['reminder_sent_at' => $now])->saveQuietly();
        }

        return $appointments->count();
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

        foreach ($appointments as $appointment) {
            $this->emails->send(EmailTemplate::REMINDER_SOON, $appointment->client, $this->emails->appointmentVars($appointment), $appointment);
            $appointment->forceFill(['short_reminder_sent_at' => $now])->saveQuietly();
        }

        return $appointments->count();
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
            $already = EmailLog::query()
                ->where('template_key', EmailTemplate::RECAP)
                ->where('related_type', $lawyer->getMorphClass())
                ->where('related_id', $lawyer->id)
                ->whereDate('created_at', $now->toDateString())
                ->exists();
            if ($already) {
                continue;
            }

            $items = $this->agendaFor($lawyer, $now);
            if ($items === []) {
                continue;
            }

            $this->emails->send(EmailTemplate::RECAP, $lawyer, [
                'avocat' => $lawyer->name,
                'date' => $now->translatedFormat('l j F Y'),
                'liste' => implode("\n", $items),
            ], $lawyer, [], $by);
            $sent++;
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
