<?php

namespace App\Livewire\Admin;

use App\Enums\Currency;
use App\Enums\Permission;
use App\Enums\TreasuryType;
use App\Models\TreasuryAccount;
use App\Services\CashService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Comptes bancaires et mobile money utilisés comme moyens de paiement (CAI-01).
 * Les deux comptes de caisse (USD et CDF) sont fixes.
 */
#[Layout('layouts.app')]
#[Title('Comptes de trésorerie')]
class TreasuryAccounts extends Component
{
    public string $name = '';

    public string $type = 'bank';

    public string $currency = 'USD';

    public string $ledger_code = '521';

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);
    }

    public function updatedType(): void
    {
        $this->ledger_code = $this->type === TreasuryType::MobileMoney->value ? '5216' : '521';
    }

    public function save(): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('treasury_accounts')->where(fn ($q) => $q->where('type', $this->type)->where('currency', $this->currency))],
            'type' => ['required', Rule::in([TreasuryType::Bank->value, TreasuryType::MobileMoney->value])],
            'currency' => ['required', Rule::enum(Currency::class)],
            'ledger_code' => ['required', 'regex:/^5\d{2,5}$/'],
        ], ['ledger_code.regex' => 'Le compte comptable doit appartenir à la classe 5 (trésorerie).'], ['name' => 'nom', 'ledger_code' => 'compte comptable']);

        TreasuryAccount::query()->create($data + ['is_active' => true]);
        $this->reset(['name']);
        session()->flash('status', 'Compte ajouté.');
    }

    public function toggle(int $id, CashService $cash): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $account = TreasuryAccount::query()->findOrFail($id);

        if ($account->isCash()) {
            return;
        }
        if ($account->is_active && bccomp($cash->balance($account), '0', 2) !== 0) {
            $this->addError('toggle', 'Un compte dont le solde n\'est pas nul ne peut pas être désactivé.');

            return;
        }

        $account->update(['is_active' => ! $account->is_active]);
    }

    public function render(CashService $cash): View
    {
        return view('livewire.admin.treasury-accounts', [
            'accounts' => TreasuryAccount::query()->orderBy('type')->orderBy('id')->get()->map(fn ($a) => ['account' => $a, 'balance' => $cash->balance($a)]),
            'currencies' => Currency::cases(),
        ]);
    }
}
