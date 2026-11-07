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

        return view('livewire.clients.show', ['cases' => $cases, 'appointments' => $appointments, 'invoices' => $invoices])->title($this->client->name);
    }
}
