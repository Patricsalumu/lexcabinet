<?php

namespace App\Livewire\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Utilisateurs, rôles multiples, activation et déverrouillage (ADM-02).
 */
#[Layout('layouts.app')]
#[Title('Utilisateurs et rôles')]
class UserManager extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $title = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    /** @var list<string> */
    public array $roles = [];

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function create(): void
    {
        $this->authorize('create', User::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->title = (string) $user->title;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->roles = $user->getRoleNames()->values()->all();
        $this->showForm = true;
    }

    public function save(UserService $users): void
    {
        $editing = $this->editingId ? User::query()->findOrFail($this->editingId) : null;
        $editing ? $this->authorize('update', $editing) : $this->authorize('create', User::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($editing?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', Password::min(8)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(Role::class)],
        ]);

        $by = auth()->user();

        if ($editing) {
            $users->update($editing, $data, $data['roles'], $by);
            if ($data['password']) {
                $users->changePassword($editing, $data['password'], $by);
            }
            session()->flash('status', 'Utilisateur mis à jour.');
        } else {
            $users->create($data, $data['roles'], $by);
            session()->flash('status', 'Utilisateur créé. Un e-mail lui a été envoyé avec un lien sécurisé pour définir son mot de passe.');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function toggleActive(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $users->setActive($user, ! $user->is_active, auth()->user());
    }

    public function resendAccess(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $log = $users->resendAccess($user, auth()->user());
        session()->flash('status', $log->status === \App\Enums\EmailStatus::NotSent
            ? 'Lien d\'accès non envoyé : '.$log->reason
            : 'Lien d\'accès envoyé à '.$user->email.'.');
    }

    public function unlock(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $users->unlock($user, auth()->user());
        session()->flash('status', 'Compte déverrouillé.');
    }

    public function resetTwoFactor(int $id, UserService $users): void
    {
        $user = User::query()->findOrFail($id);
        $this->authorize('update', $user);

        $users->resetTwoFactor($user, auth()->user());
        session()->flash('status', 'Double authentification réinitialisée.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'title', 'email', 'phone', 'password', 'roles']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.admin.user-manager', [
            'users' => User::query()->with('roles')->orderBy('name')->get(),
            'roleOptions' => Role::options(),
        ]);
    }
}
