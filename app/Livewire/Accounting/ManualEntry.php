<?php

namespace App\Livewire\Accounting;

use App\Enums\Journal;
use App\Enums\Permission;
use App\Models\Account;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Écriture manuelle équilibrée (CPT-03), en USD.
 */
#[Layout('layouts.app')]
#[Title('Écriture manuelle')]
class ManualEntry extends Component
{
    public string $journal = 'OD';

    public string $date = '';

    public string $label = '';

    public string $reference = '';

    /** @var list<array{code: string, debit: string, credit: string, label: string}> */
    public array $lines = [];

    public function mount(): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $this->date = today()->toDateString();
        $this->lines = [$this->emptyLine(), $this->emptyLine()];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 2) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    /** @return array{debit: string, credit: string} */
    #[Computed]
    public function totals(): array
    {
        $d = '0';
        $c = '0';
        foreach ($this->lines as $l) {
            $d = bcadd($d, is_numeric($l['debit']) ? Money::normalize((string) $l['debit']) : '0', 2);
            $c = bcadd($c, is_numeric($l['credit']) ? Money::normalize((string) $l['credit']) : '0', 2);
        }

        return ['debit' => $d, 'credit' => $c];
    }

    public function save(LedgerService $ledger): void
    {
        $this->authorize(Permission::AccountingManage->value);

        $this->validate([
            'journal' => ['required', Rule::enum(Journal::class)],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'label' => ['required', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:50'],
            'lines' => ['array', 'min:2'],
            'lines.*.code' => ['required', Rule::exists('accounts', 'code')->where('is_active', true)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.label' => ['nullable', 'string', 'max:150'],
        ], [], ['label' => 'libellé', 'lines.*.code' => 'compte', 'lines.*.debit' => 'débit', 'lines.*.credit' => 'crédit']);

        $entry = $ledger->post(
            Journal::from($this->journal),
            $this->date,
            $this->label,
            array_map(fn ($l) => [
                'code' => $l['code'],
                'debit' => $l['debit'] !== '' ? (string) $l['debit'] : '0',
                'credit' => $l['credit'] !== '' ? (string) $l['credit'] : '0',
                'label' => $l['label'] ?: null,
            ], $this->lines),
            $this->reference ?: null,
            by: auth()->user(),
            manual: true,
        );

        session()->flash('status', "Écriture {$entry->number} enregistrée.");
        $this->redirectRoute('accounting.index', ['tab' => 'journal', 'from' => $entry->entry_date->copy()->startOfMonth()->toDateString(), 'to' => $entry->entry_date->copy()->endOfMonth()->toDateString()], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.accounting.manual-entry', [
            'journals' => Journal::cases(),
            'accounts' => Account::query()->where('is_active', true)->orderBy('code')->get(['code', 'label']),
        ]);
    }

    private function emptyLine(): array
    {
        return ['code' => '', 'debit' => '', 'credit' => '', 'label' => ''];
    }
}
