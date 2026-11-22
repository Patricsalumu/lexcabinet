<?php

namespace App\Livewire\Agenda;

use App\Support\Tenancy\FirmRule;
use App\Enums\AppointmentType;
use App\Enums\ClientType;
use App\Enums\LocationType;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\AppointmentService;
use App\Services\ClientService;
use App\Services\ConflictCheckService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Création et modification d'un rendez-vous (RDV-01), avec un client existant
 * ou un nouveau prospect.
 */
#[Layout('layouts.app')]
class AppointmentForm extends Component
{
    public ?Appointment $appointment = null;

    public string $client_id = '';

    public bool $newProspect = false;

    public string $prospect_name = '';

    public string $prospect_email = '';

    public string $prospect_phone = '';

    public bool $prospect_consent = true;

    public bool $conflict_ack = false;

    public string $lawyer_id = '';

    public string $type = 'consultation';

    public string $date = '';

    public string $time = '10:00';

    public string $duration = '60';

    public string $location_type = 'office';

    public string $location_detail = '';

    public string $case_id = '';

    public string $subject = '';

    public string $internal_notes = '';

    public function mount(?Appointment $appointment = null): void
    {
        if ($appointment?->exists) {
            $this->authorize('update', $appointment);
            $this->appointment = $appointment;
            $this->fill([
                'client_id' => (string) $appointment->client_id,
                'lawyer_id' => (string) $appointment->lawyer_id,
                'type' => $appointment->type->value,
                'date' => $appointment->starts_at->toDateString(),
                'time' => $appointment->starts_at->format('H:i'),
                'duration' => (string) $appointment->durationMinutes(),
                'location_type' => $appointment->location_type->value,
                'location_detail' => (string) $appointment->location_detail,
                'case_id' => (string) $appointment->case_id,
                'subject' => $appointment->subject,
                'internal_notes' => (string) $appointment->internal_notes,
            ]);

            return;
        }

        $this->authorize('create', Appointment::class);
        $this->date = (string) request()->query('date', today()->addDay()->toDateString());
        $this->client_id = (string) request()->query('client', '');
        $this->lawyer_id = (string) request()->query('lawyer', '');
    }

    public function updatedClientId(): void
    {
        $this->case_id = '';
    }

    /** @return list<array{kind: string, label: string}> */
    #[Computed]
    public function prospectConflicts(): array
    {
        return $this->newProspect && mb_strlen(trim($this->prospect_name)) >= 3
            ? app(ConflictCheckService::class)->matchesForName($this->prospect_name)
            : [];
    }

    #[Computed]
    public function clientCases()
    {
        return $this->client_id !== ''
            ? LegalCase::query()->where('client_id', (int) $this->client_id)->active()->orderBy('reference')->get(['id', 'reference', 'title'])
            : collect();
    }

    public function save(AppointmentService $appointments, ClientService $clients): void
    {
        $editing = $this->appointment !== null;
        $editing ? $this->authorize('update', $this->appointment) : $this->authorize('create', Appointment::class);
        $prospect = ! $editing && $this->newProspect;

        $data = $this->validate([
            'client_id' => $prospect ? ['nullable'] : ['required', 'integer', FirmRule::exists('clients', 'id')],
            'prospect_name' => $prospect ? ['required', 'string', 'max:200'] : ['nullable'],
            'prospect_email' => ['nullable', 'email', 'max:150'],
            'prospect_phone' => ['nullable', 'string', 'max:30'],
            'lawyer_id' => ['required', 'integer', FirmRule::exists('users', 'id')],
            'type' => ['required', Rule::enum(AppointmentType::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration' => ['required', 'integer', 'min:15', 'max:600'],
            'location_type' => ['required', Rule::enum(LocationType::class)],
            'location_detail' => ['nullable', 'string', 'max:200'],
            'case_id' => ['nullable', 'integer', FirmRule::exists('cases', 'id')],
            'subject' => ['required', 'string', 'max:200'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'client_id' => 'client', 'prospect_name' => 'nom du prospect', 'prospect_email' => 'e-mail',
            'lawyer_id' => 'avocat', 'date' => 'date', 'time' => 'heure', 'duration' => 'durée',
            'location_type' => 'lieu', 'subject' => 'objet', 'case_id' => 'dossier',
        ]);

        $by = auth()->user();

        $appointment = DB::transaction(function () use ($prospect, $data, $clients, $appointments, $by, $editing) {
            if ($prospect) {
                $client = $clients->create([
                    'type' => ClientType::Individual->value,
                    'name' => $data['prospect_name'],
                    'email' => $data['prospect_email'] ?? null,
                    'phone' => $data['prospect_phone'] ?? null,
                    'email_consent' => $this->prospect_consent,
                    'is_prospect' => true,
                ], $by, $this->conflict_ack);
                $data['client_id'] = $client->id;
            }

            return $editing
                ? $appointments->update($this->appointment, $data, $by)
                : $appointments->create($data, $by);
        });

        session()->flash('status', $editing ? 'Rendez-vous modifié.' : 'Rendez-vous créé.');
        $this->redirectRoute('agenda.index', ['date' => $appointment->starts_at->toDateString(), 'view' => 'week'], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agenda.form', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'is_prospect', 'email', 'email_consent']),
            'lawyers' => User::query()->role(Role::Lawyer->value)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'types' => AppointmentType::cases(),
            'locations' => LocationType::cases(),
            'durations' => [30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30', 120 => '2 h', 180 => '3 h', 240 => '4 h'],
        ])->title($this->appointment ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous');
    }
}
