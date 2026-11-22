<?php

namespace App\Livewire\Platform;

use App\Models\AuditActivity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal de la plateforme : actions du super administrateur (cabinets créés, suspendus,
 * administrateurs ajoutés…). Les journaux d'audit des cabinets n'y figurent pas.
 */
#[Layout('layouts.app')]
#[Title('Journal de la plateforme')]
class PlatformAuditLog extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('platform.manage');
    }

    public function render(): View
    {
        $this->authorize('platform.manage');

        return view('livewire.platform.audit-log', [
            'entries' => AuditActivity::query()->withoutGlobalScopes()->whereNull('firm_id')->with(['causer', 'subject'])->latest('id')->paginate(25),
        ]);
    }
}
