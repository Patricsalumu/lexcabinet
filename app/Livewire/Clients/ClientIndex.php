<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Clients')]
class ClientIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Client::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();
        $term = trim($this->search);

        $clients = Client::query()
            ->visibleTo($user)
            ->withCount(['cases' => fn ($q) => $q->visibleTo($user)])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('rccm', 'like', "%{$term}%")))
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.clients.index', [
            'clients' => $clients,
            'total' => Client::query()->visibleTo($user)->count(),
        ]);
    }
}
