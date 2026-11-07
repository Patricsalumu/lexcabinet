<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des utilisateurs (ADM-02) : création, rôles multiples, activation,
 * déverrouillage et réinitialisation de la double authentification.
 * Aucun utilisateur n'est supprimé : on le désactive.
 */
class UserService
{
    /**
     * @param  array{name: string, title?: ?string, email: string, phone?: ?string, password: string}  $data
     * @param  list<string>  $roles
     */
    public function create(array $data, array $roles, User $by): User
    {
        $roles = $this->validRoles($roles);

        return DB::transaction(function () use ($data, $roles, $by) {
            $user = User::query()->create([
                'name' => $data['name'],
                'title' => $data['title'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();

            $user->syncRoles($roles);
            $this->logRoles($user, [], $roles, $by);

            return $user;
        });
    }

    /**
     * @param  array{name: string, title?: ?string, email: string, phone?: ?string}  $data
     * @param  list<string>  $roles
     */
    public function update(User $user, array $data, array $roles, User $by): User
    {
        $roles = $this->validRoles($roles);

        if ($user->is($by) && $user->isAdmin() && ! in_array(Role::Admin->value, $roles, true)) {
            throw ValidationException::withMessages([
                'roles' => 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $roles, $by) {
            $user->fill([
                'name' => $data['name'],
                'title' => $data['title'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ])->save();

            $before = $user->getRoleNames()->sort()->values()->all();
            $user->syncRoles($roles);
            $after = collect($roles)->sort()->values()->all();

            if ($before !== $after) {
                $this->logRoles($user, $before, $after, $by);
            }

            return $user->refresh();
        });
    }

    public function setActive(User $user, bool $active, User $by): void
    {
        if ($user->is($by) && ! $active) {
            throw ValidationException::withMessages([
                'is_active' => 'Vous ne pouvez pas désactiver votre propre compte.',
            ]);
        }

        $user->update(['is_active' => $active]);

        activity('users')->causedBy($by)->performedOn($user)
            ->log($active ? 'Compte réactivé' : 'Compte désactivé');
    }

    public function unlock(User $user, User $by): void
    {
        $user->forceFill(['locked_at' => null, 'failed_login_attempts' => 0])->saveQuietly();

        activity('users')->causedBy($by)->performedOn($user)->log('Compte déverrouillé');
    }

    public function resetTwoFactor(User $user, User $by): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->saveQuietly();

        activity('users')->causedBy($by)->performedOn($user)->log('Double authentification réinitialisée');
    }

    public function changePassword(User $user, string $password, User $by): void
    {
        $user->forceFill(['password' => $password])->saveQuietly();

        activity('users')->causedBy($by)->performedOn($user)->log('Mot de passe redéfini par l\'administrateur');
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function validRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles)));
        $allowed = array_map(fn (Role $r) => $r->value, Role::cases());

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => 'Attribuez au moins un rôle.']);
        }

        foreach ($roles as $role) {
            if (! in_array($role, $allowed, true)) {
                throw ValidationException::withMessages(['roles' => "Rôle inconnu : {$role}."]);
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function logRoles(User $user, array $before, array $after, User $by): void
    {
        $label = fn (array $roles) => array_map(fn ($r) => Role::from($r)->label(), $roles);

        activity('users')->causedBy($by)->performedOn($user)
            ->withProperties(['old' => $label($before), 'new' => $label($after)])
            ->log('Rôles modifiés');
    }
}
