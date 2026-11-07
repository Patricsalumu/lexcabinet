<?php

namespace App\Livewire\Accounting;

use App\Enums\Permission;
use App\Models\Account;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Plan comptable modifiable (CPT-01) : ajout de comptes, libellés, activation.
 */
#[Layout('layouts.app')]
#[Title('Plan comptable')]
class ChartOfAccounts extends Component
{
    public string $code = '';

    public string $label = '';

    public ?int $editingId = null;

    public string $editLabel = '';

    public function mount(): void
    {
        $this->authorize(Permission::AccountingView->value);
    }

    public function add(): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $this->validate([
            'code' => ['required', 'regex:/^[1-7]\d{0,7}$/', Rule::unique('accounts', 'code')],
            'label' => ['required', 'string', 'max:150'],
        ], ['code.regex' => 'Le numéro de compte commence par la classe (1 à 7) et ne contient que des chiffres.'], ['code' => 'numéro', 'label' => 'intitulé']);

        Account::query()->create(['code' => $this->code, 'label' => trim($this->label), 'class' => (int) $this->code[0]]);
        $this->reset(['code', 'label']);
        session()->flash('status', 'Compte ajouté.');
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $account = Account::query()->findOrFail($id);
        $this->editingId = $account->id;
        $this->editLabel = $account->label;
    }

    public function saveLabel(): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $this->validate(['editLabel' => ['required', 'string', 'max:150']], [], ['editLabel' => 'intitulé']);
        Account::query()->findOrFail($this->editingId)->update(['label' => trim($this->editLabel)]);
        $this->reset(['editingId', 'editLabel']);
    }

    public function toggle(int $id): void
    {
        $this->authorize(Permission::AccountingManage->value);
        $account = Account::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active]);
    }

    public function render(): View
    {
        return view('livewire.accounting.chart', [
            'accounts' => Account::query()->withCount('lines')->orderBy('code')->get()->groupBy('class'),
            'canManage' => auth()->user()->can(Permission::AccountingManage->value),
            'classes' => [1 => 'Ressources durables', 2 => 'Actif immobilisé', 3 => 'Stocks', 4 => 'Tiers', 5 => 'Trésorerie', 6 => 'Charges', 7 => 'Produits'],
        ]);
    }
}
