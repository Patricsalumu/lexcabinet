<?php

namespace App\Livewire\Admin;

use App\Support\Tenancy\FirmRule;
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
 * Comptes de trésorerie et moyens de paiement (CAI-01) : banques, mobile money et caisse.
 * Les comptes « affichés aux clients » forment la liste des moyens de paiement reprise dans
 * la signature des e-mails clients et sur les factures. Les deux caisses (USD et CDF) sont fixes ;
 * le type, la devise et le compte comptable d'un compte ne changent plus après sa création.
 */
#[Layout('layouts.app')]
#[Title('Comptes de trésorerie')]
class TreasuryAccounts extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $holder_name = '';

    public string $account_number = '';

    public string $payment_details = '';

    public bool $show_to_clients = true;

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

    public function edit(int $id): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $account = TreasuryAccount::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $account->id;
        $this->fill([
            'name' => $account->name,
            'holder_name' => (string) $account->holder_name,
            'account_number' => (string) $account->account_number,
            'payment_details' => (string) $account->payment_details,
            'show_to_clients' => $account->show_to_clients,
            'type' => $account->type->value,
            'currency' => $account->currency->value,
            'ledger_code' => $account->ledger_code,
        ]);
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'name', 'holder_name', 'account_number', 'payment_details', 'show_to_clients', 'type', 'currency', 'ledger_code']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $editing = $this->editingId ? TreasuryAccount::query()->findOrFail($this->editingId) : null;

        $type = $editing?->type->value ?? $this->type;
        $currency = $editing?->currency->value ?? $this->currency;

        $rules = [
            'name' => ['required', 'string', 'max:100',
                FirmRule::unique('treasury_accounts')->ignore($editing?->id)->where(fn ($q) => $q->where('type', $type)->where('currency', $currency))],
            'holder_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'payment_details' => ['nullable', 'string', 'max:200'],
            'show_to_clients' => ['boolean'],
        ];
        if (! $editing) {
            $rules += [
                'type' => ['required', Rule::in([TreasuryType::Bank->value, TreasuryType::MobileMoney->value])],
                'currency' => ['required', Rule::enum(Currency::class)],
                'ledger_code' => ['required', 'regex:/^5\d{2,5}$/'],
            ];
        }

        $data = $this->validate($rules, ['ledger_code.regex' => 'Le compte comptable doit appartenir à la classe 5 (trésorerie).'], [
            'name' => 'nom', 'holder_name' => 'intitulé du compte', 'account_number' => 'numéro', 'payment_details' => 'précisions', 'ledger_code' => 'compte comptable',
        ]);
        foreach (['holder_name', 'account_number', 'payment_details'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? trim($data[$field]) : null;
        }

        if ($editing) {
            $editing->update($data);
            session()->flash('status', 'Compte mis à jour.');
        } else {
            TreasuryAccount::query()->create($data + ['is_active' => true]);
            session()->flash('status', 'Compte ajouté.');
        }

        $this->cancel();
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
            'signature' => app(\App\Services\EmailSignature::class)->forClients(),
        ]);
    }
}
