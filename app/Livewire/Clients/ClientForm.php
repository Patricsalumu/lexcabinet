<?php

namespace App\Livewire\Clients;

use App\Enums\ClientType;
use App\Enums\IdDocumentType;
use App\Models\Client;
use App\Services\ClientService;
use App\Services\ConflictCheckService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Création et modification d'une fiche client, avec contrôle des conflits d'intérêts (CLI-04).
 */
#[Layout('layouts.app')]
class ClientForm extends Component
{
    public ?Client $client = null;

    public string $type = 'individual';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $id_document_type = '';

    public string $id_document_number = '';

    public string $rccm = '';

    public string $id_nat = '';

    public string $representative = '';

    public bool $email_consent = true;

    public bool $is_prospect = false;

    public bool $conflict_ack = false;

    public function mount(?Client $client = null): void
    {
        if ($client?->exists) {
            $this->authorize('update', $client);
            $this->client = $client;
            $this->fill([
                'type' => $client->type->value,
                'name' => $client->name,
                'phone' => (string) $client->phone,
                'email' => (string) $client->email,
                'address' => (string) $client->address,
                'id_document_type' => (string) $client->id_document_type?->value,
                'id_document_number' => (string) $client->id_document_number,
                'rccm' => (string) $client->rccm,
                'id_nat' => (string) $client->id_nat,
                'representative' => (string) $client->representative,
                'email_consent' => $client->email_consent,
                'is_prospect' => $client->is_prospect,
            ]);
        } else {
            $this->authorize('create', Client::class);
        }
    }

    /** @return list<array{kind: string, label: string}> */
    #[Computed]
    public function conflicts(): array
    {
        return mb_strlen(trim($this->name)) < 3
            ? []
            : app(ConflictCheckService::class)->matchesForName($this->name, $this->client?->id);
    }

    public function updatedName(): void
    {
        $this->conflict_ack = false;
    }

    public function save(ClientService $clients): void
    {
        $editing = $this->client !== null;
        $editing ? $this->authorize('update', $this->client) : $this->authorize('create', Client::class);

        $company = $this->type === ClientType::Company->value;

        $data = $this->validate([
            'type' => ['required', Rule::enum(ClientType::class)],
            'name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'id_document_type' => ['nullable', Rule::enum(IdDocumentType::class)],
            'id_document_number' => ['nullable', 'string', 'max:60'],
            'rccm' => ['nullable', 'string', 'max:60'],
            'id_nat' => ['nullable', 'string', 'max:60'],
            'representative' => ['nullable', 'string', 'max:200'],
            'email_consent' => ['boolean'],
            'is_prospect' => ['boolean'],
        ], [], [
            'name' => $company ? 'raison sociale' : 'nom',
            'id_document_number' => 'numéro de pièce',
            'representative' => 'représentant',
        ]);

        if ($editing) {
            $clients->update($this->client, $data);
            session()->flash('status', 'Fiche client mise à jour.');
            $client = $this->client;
        } else {
            $client = $clients->create($data, auth()->user(), $this->conflict_ack);
            session()->flash('status', 'Fiche client créée.');
        }

        $this->redirectRoute('clients.show', $client, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.clients.form', [
            'types' => ClientType::cases(),
            'idTypes' => IdDocumentType::cases(),
        ])->title($this->client ? 'Modifier '.$this->client->name : 'Nouveau client');
    }
}
