<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\EmailTemplate;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rendez-vous (RDV-01 à RDV-04) et e-mails associés (NOT-01, NOT-03, NOT-08).
 */
class AppointmentService
{
    /** Champs dont la modification est notifiée au client (NOT-01). */
    private const NOTIFIED_FIELDS = ['starts_at', 'ends_at', 'lawyer_id', 'location_type', 'location_detail', 'subject', 'type'];

    public function __construct(private readonly EmailService $emails) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): Appointment
    {
        $attributes = $this->attributes($data);

        return DB::transaction(function () use ($attributes, $by) {
            $this->assertSlotIsFree($attributes['lawyer_id'], $attributes['starts_at'], $attributes['ends_at']);

            $appointment = Appointment::query()->create($attributes + [
                'status' => AppointmentStatus::Planned,
                'created_by' => $by->id,
            ]);

            // Deux e-mails, journalisés avec le rendez-vous et envoyés après le commit :
            // la confirmation au client et l'information de l'avocat concerné.
            $this->sendConfirmation($appointment, $by);
            $this->notifyLawyer($appointment, $by);

            return $appointment;
        });
    }

    /** Informe l'avocat du rendez-vous inscrit à son agenda, avec le fichier .ics. */
    public function notifyLawyer(Appointment $appointment, ?User $by = null): void
    {
        $appointment->loadMissing(['client', 'lawyer', 'legalCase']);
        $client = $appointment->client;

        $this->emails->send(EmailTemplate::APPOINTMENT_LAWYER, $appointment->lawyer, $this->emails->appointmentVars($appointment) + [
            'destinataire' => $appointment->lawyer->name,
            'type' => $appointment->type->label(),
            'contact_client' => implode(' · ', array_filter([$client->phone, $client->email])) ?: '—',
            'dossier' => $appointment->legalCase ? $appointment->legalCase->reference.' · '.$appointment->legalCase->title : 'aucun',
            'notes' => $appointment->internal_notes ?: '—',
            'createur' => $by?->name ?? 'LexCabinet',
            'lien' => route('agenda.index', ['date' => $appointment->starts_at->toDateString(), 'view' => 'day']),
        ], $appointment, [['type' => 'ics', 'method' => IcsGenerator::REQUEST, 'name' => 'rendez-vous.ics']], $by);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Appointment $appointment, array $data, User $by): Appointment
    {
        $attributes = $this->attributes($data);

        DB::transaction(function () use ($appointment, $attributes, $by) {
            $this->assertSlotIsFree($attributes['lawyer_id'], $attributes['starts_at'], $attributes['ends_at'], $appointment->id);
            $appointment->fill($attributes);

            if ($appointment->isDirty(self::NOTIFIED_FIELDS)) {
                $appointment->ics_sequence++;
                // Nouvelle date : les rappels repartent.
                if ($appointment->isDirty('starts_at')) {
                    $appointment->reminder_sent_at = null;
                    $appointment->short_reminder_sent_at = null;

                    // Report : un rendez-vous « reporté » qui reçoit une nouvelle date est replanifié.
                    if ($appointment->status === AppointmentStatus::Postponed) {
                        $appointment->status = AppointmentStatus::Planned;
                    }
                }
            }

            $appointment->save();

            // Le client reçoit les nouvelles informations et un .ics mis à jour (SEQUENCE incrémentée).
            // Journalisé dans la transaction, envoyé après son commit.
            if ($appointment->wasChanged(self::NOTIFIED_FIELDS) && $appointment->status->expectsReminder()) {
                $this->emails->send(EmailTemplate::APPOINTMENT_UPDATED, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
                    [['type' => 'ics', 'method' => IcsGenerator::REQUEST, 'name' => 'rendez-vous.ics']], $by);
            }
        });

        return $appointment;
    }

    /** RDV-04 ; annulation et report préviennent le client (NOT-03). */
    public function changeStatus(Appointment $appointment, AppointmentStatus $status, User $by): void
    {
        if ($appointment->status === $status) {
            return;
        }

        DB::transaction(function () use ($appointment, $status, $by) {
            if ($status->occupiesSlot() && ! $appointment->status->occupiesSlot()) {
                $this->assertSlotIsFree($appointment->lawyer_id, $appointment->starts_at, $appointment->ends_at, $appointment->id);
            }

            $appointment->status = $status;
            if (in_array($status, [AppointmentStatus::Cancelled, AppointmentStatus::Postponed], true)) {
                $appointment->ics_sequence++;
            }
            $appointment->save();

            $template = match ($status) {
                AppointmentStatus::Cancelled => EmailTemplate::CANCELLATION,
                AppointmentStatus::Postponed => EmailTemplate::POSTPONEMENT,
                default => null,
            };

            if ($template) {
                $this->emails->send($template, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
                    [['type' => 'ics', 'method' => IcsGenerator::CANCEL, 'name' => 'rendez-vous.ics']], $by);
            }
        });
    }

    /** Confirmation avec .ics, à la création ou à la modification (NOT-01, NOT-08). */
    public function sendConfirmation(Appointment $appointment, ?User $by = null): void
    {
        $this->emails->send(EmailTemplate::CONFIRMATION, $appointment->client, $this->emails->appointmentVars($appointment), $appointment,
            [['type' => 'ics', 'method' => IcsGenerator::REQUEST, 'name' => 'rendez-vous.ics']], $by);
    }

    /**
     * RDV-03 : refuse un chevauchement sur l'agenda d'un même avocat.
     * Le verrou sur la ligne de l'avocat sérialise les créations concurrentes.
     */
    private function assertSlotIsFree(int $lawyerId, Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        User::query()->whereKey($lawyerId)->lockForUpdate()->first();

        $clash = Appointment::query()
            ->overlapping($lawyerId, $start, $end)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with(['client', 'lawyer'])
            ->orderBy('starts_at')
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'time' => sprintf(
                    'Conflit d\'agenda : %s a déjà « %s » avec %s de %s à %s. Choisissez un autre créneau.',
                    $clash->lawyer->name, $clash->subject, $clash->client->name,
                    $clash->starts_at->format('H\hi'), $clash->ends_at->format('H\hi'),
                ),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $lawyer = User::query()->whereKey((int) $data['lawyer_id'])->where('is_active', true)->role(Role::Lawyer->value)->first();
        if ($lawyer === null) {
            throw ValidationException::withMessages(['lawyer_id' => 'Choisissez un avocat actif du cabinet.']);
        }

        $caseId = filled($data['case_id'] ?? null) ? (int) $data['case_id'] : null;
        if ($caseId && ! LegalCase::query()->whereKey($caseId)->where('client_id', (int) $data['client_id'])->exists()) {
            throw ValidationException::withMessages(['case_id' => 'Ce dossier n\'appartient pas au client choisi.']);
        }

        $start = Carbon::parse($data['date'].' '.$data['time']);
        $minutes = (int) $data['duration'];

        return [
            'client_id' => (int) $data['client_id'],
            'case_id' => $caseId,
            'lawyer_id' => $lawyer->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($minutes),
            'type' => $data['type'],
            'location_type' => $data['location_type'],
            'location_detail' => filled($data['location_detail'] ?? null) ? trim($data['location_detail']) : null,
            'subject' => trim($data['subject']),
            'internal_notes' => filled($data['internal_notes'] ?? null) ? trim($data['internal_notes']) : null,
        ];
    }
}
