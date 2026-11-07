<?php

namespace App\Livewire\Cash;

use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\MovementStatus;
use App\Enums\Permission;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\TreasuryAccount;
use App\Services\CashService;
use App\Services\ExchangeRateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Caisse : soldes par compte, session (ouverture, clôture), change, décaissements à valider,
 * opérations et contre-passation (CAI-01, CAI-02, CAI-05, CAI-06, CAI-08).
 */
#[Layout('layouts.app')]
#[Title('Caisse')]
class CashDesk extends Component
{
    use WithPagination;

    /** Panneau ouvert : open | close | exchange | reverse | reject */
    #[Url]
    public string $panel = '';

    public string $countUsd = '';

    public string $countCdf = '';

    public string $reason = '';

    public string $fxFrom = 'USD';

    public string $fxAmount = '';

    public string $fxRate = '';

    public ?int $targetId = null;

    public function mount(ExchangeRateService $rates): void
    {
        $this->authorize(Permission::CashView->value);
        $this->fxRate = $rates->rateFor() ? rtrim(rtrim((string) $rates->rateFor()->rate, '0'), '.') : '';
    }

    public function show(string $panel, ?int $targetId = null): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->resetValidation();
        $this->reset(['countUsd', 'countCdf', 'reason', 'fxAmount']);
        $this->panel = $panel;
        $this->targetId = $targetId;
    }

    public function cancelPanel(): void
    {
        $this->reset(['panel', 'targetId', 'reason']);
        $this->resetValidation();
    }

    public function openSession(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->validate(['countUsd' => ['required', 'numeric', 'min:0'], 'countCdf' => ['required', 'numeric', 'min:0']], [], ['countUsd' => 'fonds USD', 'countCdf' => 'fonds CDF']);

        $cash->openSession((string) $this->countUsd, (string) $this->countCdf, auth()->user());
        $this->cancelPanel();
        session()->flash('status', 'Caisse ouverte.');
    }

    public function closeSession(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->validate(['countUsd' => ['required', 'numeric', 'min:0'], 'countCdf' => ['required', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:1000']],
            [], ['countUsd' => 'comptage USD', 'countCdf' => 'comptage CDF', 'reason' => 'motif']);

        $session = $cash->currentSession() ?? abort(404);
        $closed = $cash->closeSession($session, (string) $this->countUsd, (string) $this->countCdf, $this->reason, auth()->user());

        $this->cancelPanel();
        session()->flash('status', bccomp((string) $closed->difference_usd, '0', 2) === 0 && bccomp((string) $closed->difference_cdf, '0', 2) === 0
            ? 'Caisse clôturée sans écart.'
            : 'Caisse clôturée ; l\'écart a été enregistré comme ajustement.');
    }

    public function exchange(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->validate([
            'fxFrom' => ['required', 'in:USD,CDF'],
            'fxAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'fxRate' => ['required', 'numeric', 'gt:0'],
        ], [], ['fxAmount' => 'montant cédé', 'fxRate' => 'taux']);

        $movement = $cash->exchange(Currency::from($this->fxFrom), (string) $this->fxAmount, (string) $this->fxRate, auth()->user());
        $this->cancelPanel();
        session()->flash('status', 'Change enregistré : '.\App\Support\Money::format((string) $movement->amount, $movement->currency).' contre '
            .\App\Support\Money::format((string) $movement->counter_amount, $movement->counter_currency).'.');
    }

    public function approve(int $id, CashService $cash): void
    {
        $this->authorize(Permission::CashValidate->value);
        $cash->validateDisbursement(CashMovement::query()->findOrFail($id), auth()->user());
        session()->flash('status', 'Décaissement validé.');
    }

    public function reject(CashService $cash): void
    {
        $this->authorize(Permission::CashValidate->value);
        $this->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif']);
        $cash->rejectDisbursement(CashMovement::query()->findOrFail($this->targetId), $this->reason, auth()->user());
        $this->cancelPanel();
        session()->flash('status', 'Décaissement refusé.');
    }

    public function reverse(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif']);
        $cash->reverse(CashMovement::query()->findOrFail($this->targetId), $this->reason, auth()->user());
        $this->cancelPanel();
        session()->flash('status', 'Opération contre-passée.');
    }

    public function render(CashService $cash): View
    {
        $accounts = TreasuryAccount::query()->where('is_active', true)->orderBy('id')->get()
            ->map(fn (TreasuryAccount $a) => ['account' => $a, 'balance' => $cash->balance($a)]);

        $session = $cash->currentSession();
        $closeExpected = $this->panel === 'close' ? $cash->cashBalances() : null;

        return view('livewire.cash.desk', [
            'accounts' => $accounts,
            'session' => $session?->load('openedBy'),
            'closeExpected' => $closeExpected,
            'todayReceipts' => CashMovement::query()->where('type', CashMovementType::Receipt->value)
                ->where('status', MovementStatus::Validated->value)->whereDate('occurred_at', today())
                ->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency'),
            'pending' => CashMovement::query()->with(['account', 'creator'])->where('status', MovementStatus::Pending->value)->orderBy('occurred_at')->get(),
            'movements' => CashMovement::query()->with(['account', 'counterAccount', 'client', 'invoice', 'reversal'])
                ->latest('occurred_at')->latest('id')->paginate(25),
            'sessions' => CashSession::query()->with('closedBy')->where('status', CashSession::CLOSED)->latest('closed_at')->limit(10)->get(),
            'target' => $this->targetId ? CashMovement::query()->find($this->targetId) : null,
            'canOperate' => auth()->user()->can(Permission::CashOperate->value),
            'canValidate' => auth()->user()->can(Permission::CashValidate->value),
        ]);
    }
}
