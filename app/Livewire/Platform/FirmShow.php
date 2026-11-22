<?php

namespace App\Livewire\Platform;

use App\Enums\EmailStatus;
use App\Models\Firm;
use App\Models\User;
use App\Services\PlatformService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Plateforme : fiche d'un cabinet. Nom, état, administrateurs (ajout, renvoi du lien d'accès),
 * suspension ou rétablissement de l'accès. Aucune donnée métier du cabinet n'est affichée.
 */
#[Layout('layouts.app')]
#[Title('Cabinet')]
class FirmShow extends Component
{
    public Firm $firm;

    public string $name = '';

    public string $reason = '';

    public string $admin_name = '';

    public string $admin_email = '';

    public string $admin_phone = '';

    public function mount(Firm $firm): void
    {
        $this->authorize('platform.manage');
        $this->firm = $firm;
        $this->name = $firm->name;
    }

    public function rename(PlatformService $platform): void
    {
        $this->authorize('platform.manage');
        $this->validate(['name' => ['required', 'string', 'max:150', 'unique:firms,name,'.$this->firm->id]], [], ['name' => 'nom du cabinet']);

        $platform->rename($this->firm, $this->name, auth()->user());
        session()->flash('status', 'Nom du cabinet enregistré.');
    }

    public function addAdmin(PlatformService $platform): void
    {
        $this->authorize('platform.manage');
        $data = $this->validate([
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:30'],
        ], [], ['admin_name' => 'nom', 'admin_email' => 'e-mail']);

        $admin = $platform->addAdmin($this->firm, ['name' => $data['admin_name'], 'email' => $data['admin_email'], 'phone' => $data['admin_phone']], auth()->user());
        $this->reset('admin_name', 'admin_email', 'admin_phone');
        session()->flash('status', "{$admin->name} est administrateur du cabinet ; son lien d'accès lui a été envoyé.");
    }

    public function resendAccess(int $userId, PlatformService $platform): void
    {
        $this->authorize('platform.manage');
        $admin = User::query()->withoutGlobalScopes()->where('firm_id', $this->firm->id)->whereKey($userId)->first();
        abort_if($admin === null, 404);

        $log = $platform->resendAdminAccess($this->firm, $admin, auth()->user());
        $log->status === EmailStatus::NotSent
            ? session()->flash('error', 'Lien non envoyé : '.$log->reason)
            : session()->flash('status', "Nouveau lien d'accès envoyé à {$admin->email}.");
    }

    public function suspend(PlatformService $platform): void
    {
        $this->authorize('platform.manage');
        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motif']);

        $platform->setActive($this->firm, false, auth()->user(), $this->reason);
        $this->reason = '';
        session()->flash('status', 'Accès du cabinet suspendu : ses utilisateurs ne peuvent plus se connecter. Aucune donnée n\'a été supprimée.');
    }

    public function reactivate(PlatformService $platform): void
    {
        $this->authorize('platform.manage');
        $platform->setActive($this->firm, true, auth()->user());
        session()->flash('status', 'Accès du cabinet rétabli.');
    }

    public function render(PlatformService $platform): View
    {
        $this->firm->refresh();

        return view('livewire.platform.firm-show', [
            'admins' => $platform->admins($this->firm),
            'usersCount' => User::query()->withoutGlobalScopes()->where('firm_id', $this->firm->id)->count(),
            'activeUsersCount' => User::query()->withoutGlobalScopes()->where('firm_id', $this->firm->id)->where('is_active', true)->count(),
        ]);
    }
}
