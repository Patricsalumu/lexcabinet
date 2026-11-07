<?php

namespace App\Livewire;

use App\Enums\CaseEventType;
use App\Enums\CaseStatus;
use App\Models\CaseEvent;
use App\Models\LegalCase;
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
        $user = auth()->user();
        $canSeeCases = $user->can('viewAny', LegalCase::class);

        // DB-04 : dossiers actifs par statut et prochaines audiences (dossiers visibles seulement).
        $casesByStatus = $canSeeCases
            ? LegalCase::query()->visibleTo($user)->active()
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            : collect();

        $hearings = $canSeeCases
            ? CaseEvent::query()
                ->where('type', CaseEventType::Hearing->value)
                ->where('occurred_at', '>=', today())
                ->whereHas('legalCase', fn ($q) => $q->visibleTo($user))
                ->with('legalCase')
                ->orderBy('occurred_at')
                ->limit(8)
                ->get()
            : collect();

        return view('livewire.dashboard', [
            'rate' => $rates->rateFor(),
            'rateMissing' => $rates->isMissingFor(),
            'user' => $user,
            'canSeeCases' => $canSeeCases,
            'casesByStatus' => $casesByStatus,
            'activeStatuses' => array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s->isActive()),
            'hearings' => $hearings,
        ]);
    }
}
