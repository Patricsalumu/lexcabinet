<?php

namespace App\Livewire\Platform;

use App\Enums\Role;
use App\Models\Firm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Plateforme : liste des cabinets. Seules des informations de compte sont affichées
 * (nom, état, nombre d'utilisateurs, administrateurs) ; aucune donnée métier.
 */
#[Layout('layouts.app')]
#[Title('Cabinets')]
class FirmIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('platform.manage');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorize('platform.manage');

        $firms = Firm::query()
            ->withCount(['users' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(25);

        $admins = User::query()->withoutGlobalScopes()->whereIn('firm_id', $firms->pluck('id'))->role(Role::Admin->value)
            ->orderBy('name')->get(['id', 'firm_id', 'name', 'email'])->groupBy('firm_id');

        return view('livewire.platform.firm-index', compact('firms', 'admins'));
    }
}
