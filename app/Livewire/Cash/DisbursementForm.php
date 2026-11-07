<?php

namespace App\Livewire\Cash;

use App\Enums\Currency;
use App\Enums\DisbursementCategory;
use App\Enums\MovementStatus;
use App\Enums\PaymentMode;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\TreasuryAccount;
use App\Services\CashService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Décaissement (CAI-05) : catégorie, bénéficiaire, pièce justificative ;
 * au-delà du seuil, en attente de validation par l'administrateur.
 */
#[Layout('layouts.app')]
#[Title('Décaisser')]
class DisbursementForm extends Component
{
    public string $category = 'transport';

    public string $beneficiary = '';

    public string $label = '';

    public string $voucher = '';

    public string $currency = 'USD';

    public string $amount = '';

    public string $mode = 'cash';

    public string $treasury_account_id = '';

    public string $client_id = '';

    public function mount(): void
    {
        $this->authorize(Permission::CashOperate->value);
    }

    #[Computed]
    public function accounts()
    {
        return TreasuryAccount::query()->where('is_active', true)
            ->where('type', PaymentMode::from($this->mode)->treasuryType()->value)
            ->where('currency', $this->currency)->orderBy('id')->get();
    }

    public function save(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);

        $data = $this->validate([
            'category' => ['required', Rule::enum(DisbursementCategory::class)],
            'beneficiary' => ['required', 'string', 'max:200'],
            'label' => ['required', 'string', 'max:200'],
            'voucher' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'mode' => ['required', Rule::enum(PaymentMode::class)],
            'treasury_account_id' => ['nullable', 'integer'],
            'client_id' => [$this->category === 'client_disbursement' ? 'required' : 'nullable', 'integer', Rule::exists('clients', 'id')],
        ], [], [
            'category' => 'catégorie', 'beneficiary' => 'bénéficiaire', 'label' => 'motif', 'voucher' => 'pièce justificative',
            'amount' => 'montant', 'client_id' => 'client',
        ]);

        $movement = $cash->recordDisbursement($data, auth()->user());

        session()->flash('status', $movement->status === MovementStatus::Pending
            ? 'Sortie enregistrée, en attente de validation par l\'administrateur.'
            : 'Sortie enregistrée.');
        $this->redirectRoute('cash.index', navigate: true);
    }

    public function render(SettingsService $settings): View
    {
        return view('livewire.cash.disbursement', [
            'categories' => DisbursementCategory::cases(),
            'modes' => PaymentMode::cases(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'threshold' => (string) $settings->get('cash.disbursement_threshold_usd'),
        ]);
    }
}
