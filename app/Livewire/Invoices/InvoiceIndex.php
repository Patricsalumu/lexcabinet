<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Factures')]
class InvoiceIndex extends Component
{
    use WithPagination;

    public const FILTERS = [
        'all' => 'Toutes',
        'open' => 'À encaisser',
        'overdue' => 'Échues',
        'draft' => 'Brouillons',
        'paid' => 'Payées',
        'cancelled' => 'Annulées',
    ];

    #[Url]
    public string $filter = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['filter', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = trim($this->search);
        $open = [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value];

        $invoices = Invoice::query()
            ->visibleTo(auth()->user())
            ->with(['client', 'legalCase'])
            ->when($this->filter === 'open', fn ($q) => $q->whereIn('status', $open))
            ->when($this->filter === 'overdue', fn ($q) => $q->whereIn('status', $open)->whereDate('due_date', '<', today()))
            ->when($this->filter === 'draft', fn ($q) => $q->where('status', InvoiceStatus::Draft->value))
            ->when($this->filter === 'paid', fn ($q) => $q->where('status', InvoiceStatus::Paid->value))
            ->when($this->filter === 'cancelled', fn ($q) => $q->where('status', InvoiceStatus::Cancelled->value))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"))
                ->orWhereHas('legalCase', fn ($c) => $c->where('reference', 'like', "%{$term}%"))))
            ->orderByRaw('number is null desc')
            ->orderByDesc('number')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.invoices.index', [
            'invoices' => $invoices,
            'filters' => self::FILTERS,
        ]);
    }
}
