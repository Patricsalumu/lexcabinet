<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Dossiers')]
class CaseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $lawyer = '';

    public function mount(): void
    {
        $this->authorize('viewAny', LegalCase::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status', 'lawyer'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $term = trim($this->search);
        $status = in_array($this->status, array_column(CaseStatus::cases(), 'value'), true) ? $this->status : null;

        $cases = LegalCase::query()
            ->visibleTo($user)
            ->with(['client', 'leadLawyer'])
            ->withSum(['timeEntries as unbilled_minutes' => fn ($q) => $q->where('billable', true)->whereNull('invoice_id')], 'minutes')
            ->when($this->status === 'active', fn ($q) => $q->active())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($this->lawyer !== '', fn ($q) => $q->where('lead_lawyer_id', (int) $this->lawyer))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('reference', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhere('adverse_party', 'like', "%{$term}%")
                ->orWhere('docket_number', 'like', "%{$term}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->orderByDesc('opened_on')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.cases.index', [
            'cases' => $cases,
            'statuses' => CaseStatus::cases(),
            'lawyers' => User::query()->role(Role::Lawyer->value)->orderBy('name')->get(['id', 'name']),
            'ownOnly' => ! $user->can(Permission::CasesViewAll->value),
        ]);
    }
}
