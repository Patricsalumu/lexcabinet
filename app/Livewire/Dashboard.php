<?php

namespace App\Livewire;

use App\Services\ExchangeRateService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Tableau de bord')]
class Dashboard extends Component
{
    public function render(ExchangeRateService $rates): View
    {
        return view('livewire.dashboard', [
            'rate' => $rates->rateFor(),
            'rateMissing' => $rates->isMissingFor(),
            'user' => auth()->user(),
        ]);
    }
}
