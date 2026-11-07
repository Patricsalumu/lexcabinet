<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche client et historique (CLI-02). Les rendez-vous, factures et paiements
 * s'ajouteront avec les phases 3 à 5.
 */
#[Layout('layouts.app')]
class ClientShow extends Component
{
    public Client $client;

    public function mount(Client $client): void
    {
        $this->authorize('view', $client);
        $this->client = $client;
    }

    public function render(): View
    {
        $cases = $this->client->cases()
            ->visibleTo(auth()->user())
            ->with('leadLawyer')
            ->orderByDesc('opened_on')
            ->get();

        $appointments = auth()->user()->can('viewAny', \App\Models\Appointment::class)
            ? $this->client->appointments()->with('lawyer')->orderByDesc('starts_at')->limit(20)->get()
            : collect();

        $invoices = auth()->user()->can('viewAny', \App\Models\Invoice::class)
            ? $this->client->invoices()->visibleTo(auth()->user())->orderByDesc('id')->get()
            : collect();

        // CLI-02 : paiements et provisions, solde dû par devise.
        $canCash = auth()->user()->can(\App\Enums\Permission::CashView->value);
        $payments = $canCash
            ? \App\Models\CashMovement::query()->where('client_id', $this->client->id)
                ->whereIn('type', [\App\Enums\CashMovementType::Receipt->value, \App\Enums\CashMovementType::Reversal->value])
                ->where('status', \App\Enums\MovementStatus::Validated->value)
                ->with('invoice')->orderByDesc('occurred_at')->get()
            : collect();
        $due = $invoices->filter(fn ($i) => $i->status->isOpen())
            ->groupBy(fn ($i) => $i->currency->value)
            ->map(fn ($list) => $list->reduce(fn ($s, $i) => bcadd($s, $i->balanceDue(), 2), '0'));

        return view('livewire.clients.show', [
            'cases' => $cases, 'appointments' => $appointments, 'invoices' => $invoices,
            'payments' => $payments, 'canCash' => $canCash, 'due' => $due,
        ])->title($this->client->name);
    }
}
