<?php

namespace App\Livewire\Agenda;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\CaseEventType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseEvent;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\AppointmentService;
use App\Services\ReminderService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Agenda : vues jour, semaine et liste, filtrables par avocat (RDV-02),
 * avec les audiences des dossiers (RDV-06).
 */
#[Layout('layouts.app')]
#[Title('Agenda')]
class AgendaIndex extends Component
{
    #[Url]
    public string $view = 'week';

    #[Url]
    public string $date = '';

    #[Url]
    public string $lawyer = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Appointment::class);

        $user = auth()->user();
        if ($this->date === '' || ! strtotime($this->date)) {
            $this->date = today()->toDateString();
        }
        if (! in_array($this->view, ['day', 'week', 'list'], true)) {
            $this->view = 'week';
        }
        // Un avocat voit d'abord son propre agenda.
        if ($this->lawyer === '' && ! $user->can(Permission::AgendaManage->value) && $user->hasRole(Role::Lawyer->value)) {
            $this->lawyer = (string) $user->id;
        }
    }

    public function move(int $direction): void
    {
        $step = $this->view === 'day' ? 1 : 7;
        $this->date = Carbon::parse($this->date)->addDays($direction * $step)->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function setStatus(int $appointmentId, string $status, AppointmentService $appointments): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);
        $this->authorize('update', $appointment);

        validator(['status' => $status], ['status' => ['required', Rule::enum(AppointmentStatus::class)]])->validate();

        try {
            $appointments->changeStatus($appointment, AppointmentStatus::from($status), auth()->user());
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('status', 'Statut mis à jour : '.$appointment->status->label().'.');
    }

    /** Lancement manuel des rappels J-1 (droit « envoyer les rappels » du secrétariat). */
    public function sendReminders(ReminderService $reminders): void
    {
        $this->authorize(Permission::RemindersSend->value);

        $count = $reminders->sendDayBeforeReminders(by: auth()->user(), ignoreTime: true);
        activity('emails')->causedBy(auth()->user())->log("Rappels J-1 lancés à la main : {$count} rendez-vous");

        session()->flash('status', $count
            ? "{$count} rappel(s) traité(s) pour les rendez-vous de demain. Voir le journal des e-mails."
            : 'Aucun rappel à envoyer pour demain.');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $date = Carbon::parse($this->date);

        return match ($this->view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'list' => [$date->copy()->startOfDay(), $date->copy()->addDays(29)->endOfDay()],
            default => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
        };
    }

    /**
     * Rendez-vous et audiences de la période, regroupés par jour.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function entries(Carbon $from, Carbon $to): Collection
    {
        $user = auth()->user();
        $lawyerId = $this->lawyer !== '' ? (int) $this->lawyer : null;

        $appointments = Appointment::query()
            ->with(['client', 'lawyer', 'legalCase'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($lawyerId, fn ($q) => $q->where('lawyer_id', $lawyerId))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => ['kind' => 'appointment', 'at' => $a->starts_at, 'appointment' => $a]);

        // Audiences des dossiers visibles par l'utilisateur, sans doublon avec un RDV « audience ».
        $hearingSlots = $appointments
            ->filter(fn ($e) => $e['appointment']->type === AppointmentType::Hearing && $e['appointment']->case_id)
            ->map(fn ($e) => $e['appointment']->case_id.'@'.$e['at']->format('Y-m-d H:i'))
            ->all();

        $hearings = CaseEvent::query()
            ->with(['legalCase.leadLawyer', 'legalCase.client'])
            ->where('type', CaseEventType::Hearing->value)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereHas('legalCase', fn ($q) => $q->visibleTo($user)->when($lawyerId, fn ($q) => $q->where('lead_lawyer_id', $lawyerId)))
            ->get()
            ->reject(fn (CaseEvent $e) => in_array($e->case_id.'@'.$e->occurred_at->format('Y-m-d H:i'), $hearingSlots, true))
            ->map(fn (CaseEvent $e) => ['kind' => 'hearing', 'at' => $e->occurred_at, 'event' => $e]);

        return $appointments->concat($hearings)
            ->sortBy(fn ($e) => $e['at']->timestamp)
            ->groupBy(fn ($e) => $e['at']->toDateString());
    }

    public function render(): View
    {
        [$from, $to] = $this->period();
        $user = auth()->user();

        $days = $this->view === 'week'
            ? collect(range(0, 6))->map(fn ($i) => $from->copy()->addDays($i))
            : ($this->view === 'day' ? collect([$from->copy()]) : null);

        $tomorrow = today()->addDay();

        return view('livewire.agenda.index', [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'entries' => $this->entries($from, $to),
            'lawyers' => User::query()->role(Role::Lawyer->value)->orderBy('name')->get(['id', 'name']),
            'statuses' => AppointmentStatus::cases(),
            'canManage' => $user->can('create', Appointment::class),
            'canRemind' => $user->can(Permission::RemindersSend->value),
            'canBill' => $user->can('create', \App\Models\Invoice::class),
            'pendingReminders' => Appointment::query()
                ->whereIn('status', [AppointmentStatus::Planned->value, AppointmentStatus::Confirmed->value])
                ->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [$tomorrow->copy()->startOfDay(), $tomorrow->copy()->endOfDay()])
                ->count(),
            'visibleCaseIds' => LegalCase::query()->visibleTo($user)->pluck('id')->all(),
        ]);
    }
}
