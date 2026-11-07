<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\AuditActivity;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal d'audit (ADM-04) : consultation seule, aucune modification possible.
 */
#[Layout('layouts.app')]
#[Title('Journal d\'audit')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $userId = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize(Permission::AuditView->value);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'userId', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $this->authorize(Permission::AuditView->value);

        $entries = AuditActivity::query()
            ->with(['causer', 'subject'])
            ->when($this->search !== '', fn ($q) => $q->where('description', 'like', '%'.$this->search.'%'))
            ->when($this->userId !== '', fn ($q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $this->userId))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id')
            ->paginate(25);

        return view('livewire.admin.audit-log', [
            'entries' => $entries,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
