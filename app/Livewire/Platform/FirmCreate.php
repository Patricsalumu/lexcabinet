<?php

namespace App\Livewire\Platform;

use App\Services\PlatformService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Plateforme : création d'un cabinet et de son premier administrateur. Le cabinet reçoit son
 * plan comptable, ses modèles d'e-mails et ses caisses ; l'administrateur, un lien d'accès.
 */
#[Layout('layouts.app')]
#[Title('Nouveau cabinet')]
class FirmCreate extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $admin_name = '';

    public string $admin_email = '';

    public string $admin_phone = '';

    public function mount(): void
    {
        $this->authorize('platform.manage');
    }

    public function save(PlatformService $platform)
    {
        $this->authorize('platform.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150', 'unique:firms,name'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:30'],
        ], [], [
            'name' => 'nom du cabinet', 'admin_name' => 'nom de l\'administrateur', 'admin_email' => 'e-mail de l\'administrateur',
        ]);

        [$firm, $admin] = $platform->createFirm(
            ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'address' => $data['address']],
            ['name' => $data['admin_name'], 'email' => $data['admin_email'], 'phone' => $data['admin_phone']],
            auth()->user(),
        );

        session()->flash('status', "Cabinet « {$firm->name} » créé. {$admin->name} a reçu par e-mail son lien d'accès ({$admin->email}).");

        return $this->redirectRoute('platform.firms.show', $firm, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.platform.firm-create');
    }
}
