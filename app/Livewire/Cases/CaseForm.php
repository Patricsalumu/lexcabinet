<?php

namespace App\Livewire\Cases;

use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Matter;
use App\Enums\Role;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\CaseService;
use App\Services\ConflictCheckService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ouverture et modification d'un dossier, avec sa convention d'honoraires (DOS-01 à DOS-04).
 */
#[Layout('layouts.app')]
class CaseForm extends Component
{
    public ?LegalCase $case = null;

    public string $client_id = '';

    public string $title = '';

    public string $adverse_party = '';

    public string $matter = 'civil';

    public string $jurisdiction = '';

    public string $docket_number = '';

    public string $lead_lawyer_id = '';

    /** @var list<string> */
    public array $collaborators = [];

    public string $fee_type = 'flat';

    public string $fee_amount = '';

    public string $currency = 'USD';

    public string $opened_on = '';

    public function mount(?LegalCase $case = null): void
    {
        $user = auth()->user();

        if ($case?->exists) {
            $this->authorize('update', $case);
            $this->case = $case;
            $this->fill([
                'client_id' => (string) $case->client_id,
                'title' => $case->title,
                'adverse_party' => (string) $case->adverse_party,
                'matter' => $case->matter->value,
                'jurisdiction' => (string) $case->jurisdiction,
                'docket_number' => (string) $case->docket_number,
                'lead_lawyer_id' => (string) $case->lead_lawyer_id,
                'collaborators' => $case->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all(),
                'fee_type' => $case->fee_type->value,
                'fee_amount' => (string) $case->fee_amount,
                'currency' => $case->currency->value,
                'opened_on' => $case->opened_on->toDateString(),
            ]);

            return;
        }

        $this->authorize('create', LegalCase::class);
        $this->opened_on = today()->toDateString();
        $this->client_id = (string) request()->query('client', '');
        // Un avocat qui ouvre un dossier en est responsable par défaut.
        $this->lead_lawyer_id = $user->hasRole(Role::Lawyer->value) ? (string) $user->id : '';
    }

    /** @return list<array{kind: string, label: string}> */
    #[Computed]
    public function adverseConflicts(): array
    {
        return app(ConflictCheckService::class)->matchesForAdverseParty($this->adverse_party);
    }

    #[Computed]
    public function selectedClient(): ?Client
    {
        return $this->client_id !== '' ? Client::query()->find((int) $this->client_id) : null;
    }

    public function save(CaseService $cases): void
    {
        $editing = $this->case !== null;
        $editing ? $this->authorize('update', $this->case) : $this->authorize('create', LegalCase::class);

        $data = $this->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'adverse_party' => ['nullable', 'string', 'max:200'],
            'matter' => ['required', Rule::enum(Matter::class)],
            'jurisdiction' => ['nullable', 'string', 'max:200'],
            'docket_number' => ['nullable', 'string', 'max:60'],
            'lead_lawyer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'collaborators' => ['array'],
            'collaborators.*' => ['integer', Rule::exists('users', 'id')],
            'fee_type' => ['required', Rule::enum(FeeType::class)],
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'opened_on' => ['required', 'date', 'before_or_equal:today'],
        ], [], [
            'client_id' => 'client',
            'title' => 'intitulé',
            'adverse_party' => 'partie adverse',
            'matter' => 'matière',
            'jurisdiction' => 'juridiction',
            'docket_number' => 'numéro de rôle',
            'lead_lawyer_id' => 'avocat responsable',
            'fee_type' => 'type de convention',
            'fee_amount' => 'montant',
            'currency' => 'devise',
            'opened_on' => 'date d\'ouverture',
        ]);

        $collaborators = array_map('intval', $data['collaborators'] ?? []);
        unset($data['collaborators']);

        if ($editing) {
            unset($data['opened_on']);
            $case = $cases->update($this->case, $data, $collaborators, auth()->user());
            session()->flash('status', 'Dossier mis à jour.');
        } else {
            $case = $cases->open($data, $collaborators, auth()->user());
            session()->flash('status', "Dossier {$case->reference} ouvert.");
        }

        $this->redirectRoute('cases.show', $case, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.cases.form', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'is_prospect']),
            'lawyers' => User::query()->role(Role::Lawyer->value)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'matters' => Matter::cases(),
            'feeTypes' => FeeType::cases(),
            'currencies' => Currency::cases(),
        ])->title($this->case ? 'Modifier '.$this->case->reference : 'Nouveau dossier');
    }
}
