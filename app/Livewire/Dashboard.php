<?php

namespace App\Livewire;

use App\Enums\AppointmentStatus;
use App\Enums\CaseEventType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
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

        // DB-01 : rendez-vous du jour (ceux de l'avocat connecté s'il ne gère pas l'agenda).
        $canSeeAgenda = $user->can('viewAny', Appointment::class);
        $todayAppointments = $canSeeAgenda
            ? Appointment::query()->with(['client', 'lawyer'])
                ->whereBetween('starts_at', [today(), today()->endOfDay()])
                ->whereIn('status', AppointmentStatus::slotValues())
                ->when(! $user->can(Permission::AgendaManage->value) && $user->hasRole(Role::Lawyer->value), fn ($q) => $q->where('lawyer_id', $user->id))
                ->orderBy('starts_at')->get()
            : collect();

        // DB-03 : montant des factures impayées (par devise) et factures échues.
        $canSeeInvoices = $user->can('viewAny', \App\Models\Invoice::class);
        $openInvoices = $canSeeInvoices
            ? \App\Models\Invoice::query()->visibleTo($user)->with('client')
                ->whereIn('status', [\App\Enums\InvoiceStatus::Issued->value, \App\Enums\InvoiceStatus::PartiallyPaid->value])
                ->orderBy('due_date')->get()
            : collect();
        $unpaid = $openInvoices->groupBy(fn ($i) => $i->currency->value)
            ->map(fn ($list) => $list->reduce(fn ($sum, $i) => bcadd($sum, $i->balanceDue(), 2), '0'));

        return view('livewire.dashboard', [
            'canSeeInvoices' => $canSeeInvoices,
            'unpaid' => $unpaid,
            'overdueInvoices' => $openInvoices->filter->isOverdue()->values(),
            'canSeeAgenda' => $canSeeAgenda,
            'todayAppointments' => $todayAppointments,
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
