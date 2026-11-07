<?php

namespace App\Livewire\Accounting;

use App\Enums\Journal;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\AccountingReports;
use App\Services\LedgerService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Comptabilité : journal, grand livre, balance, compte de résultat, périodes (CPT-02 à CPT-07).
 */
#[Layout('layouts.app')]
#[Title('Comptabilité')]
class AccountingIndex extends Component
{
    public const TABS = [
        'journal' => 'Journal',
        'ledger' => 'Grand livre',
        'balance' => 'Balance',
        'result' => 'Résultat',
        'periods' => 'Périodes',
    ];

    #[Url]
    public string $tab = 'journal';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $journal = '';

    #[Url]
    public string $account = '';

    public string $reverseReason = '';

    public ?int $reverseId = null;

    public function mount(): void
    {
        $this->authorize(Permission::AccountingView->value);
        $this->from = $this->validDate($this->from) ?? today()->startOfMonth()->toDateString();
        $this->to = $this->validDate($this->to) ?? today()->endOfMonth()->toDateString();
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'journal';
        }
    }

    public function month(int $offset): void
    {
        $start = Carbon::parse($this->from)->startOfMonth()->addMonths($offset);
        $this->from = $start->toDateString();
        $this->to = $start->copy()->endOfMonth()->toDateString();
    }

    public function closePeriod(int $year, int $month, LedgerService $ledger): void
    {
        $this->authorize(Permission::AccountingClose->value);
        $period = $ledger->closePeriod($year, $month, auth()->user());
        session()->flash('status', 'Période '.$period->label().' clôturée.');
    }

    public function askReverse(int $entryId): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $this->reverseId = $entryId;
        $this->reverseReason = '';
    }

    /** Seules les écritures manuelles se contre-passent ici ; les automatiques suivent leur pièce d'origine. */
    public function reverseEntry(LedgerService $ledger): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $this->validate(['reverseReason' => ['required', 'string', 'max:255']], [], ['reverseReason' => 'motif']);

        $entry = JournalEntry::query()->findOrFail($this->reverseId);
        abort_unless($entry->is_manual, 403);

        $reversal = $ledger->reverse($entry, 'Contre-passation de '.$entry->number.' : '.$this->reverseReason, by: auth()->user());
        $this->reset(['reverseId', 'reverseReason']);
        session()->flash('status', "Écriture {$entry->number} contre-passée par {$reversal->number}.");
    }

    public function render(AccountingReports $reports): View
    {
        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to)->endOfDay();
        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $data = match ($this->tab) {
            'journal' => ['entries' => $reports->journal($from, $to, Journal::tryFrom($this->journal))],
            'ledger' => ['ledger' => $reports->generalLedger($from, $to, $this->account !== '' ? (int) $this->account : null)],
            'balance' => ['balance' => $reports->trialBalance($from, $to)],
            'result' => ['result' => $reports->incomeStatement($from, $to)],
            'periods' => ['periods' => $this->periods()],
        };

        return view('livewire.accounting.index', $data + [
            'tabs' => self::TABS,
            'journals' => Journal::cases(),
            'accounts' => Account::query()->orderBy('code')->get(['id', 'code', 'label']),
            'canManage' => auth()->user()->can(Permission::AccountingManage->value),
            'canClose' => auth()->user()->can(Permission::AccountingClose->value),
            'exportQuery' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'journal' => $this->journal ?: null, 'account' => $this->account ?: null],
        ]);
    }

    /** Les 12 derniers mois, avec leur statut et l'équilibre de leur balance. */
    private function periods(): array
    {
        $ledger = app(LedgerService::class);
        $stored = AccountingPeriod::query()->with('closedBy')->get()->keyBy(fn ($p) => $p->year.'-'.$p->month);

        return collect(range(0, 11))->map(function ($i) use ($stored, $ledger) {
            $start = today()->startOfMonth()->subMonths($i);
            [$d, $c] = $ledger->totals($start, $start->copy()->endOfMonth());

            return [
                'year' => $start->year,
                'month' => $start->month,
                'label' => ucfirst($start->translatedFormat('F Y')),
                'period' => $stored->get($start->year.'-'.$start->month),
                'debit' => $d,
                'credit' => $c,
                'elapsed' => $start->copy()->endOfMonth()->lt(today()),
            ];
        })->all();
    }

    private function validDate(string $value): ?string
    {
        return $value !== '' && strtotime($value) ? Carbon::parse($value)->toDateString() : null;
    }
}
