<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::ClientsViewAll->value, Permission::ClientsViewOwn->value]);
    }

    /** Un avocat ne voit que les clients de ses dossiers. */
    public function view(User $user, Client $client): bool
    {
        if ($user->can(Permission::ClientsViewAll->value)) {
            return true;
        }

        return $user->can(Permission::ClientsViewOwn->value)
            && $client->cases()->assignedTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClientsManage->value);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can(Permission::ClientsManage->value);
    }

    /** Pas de suppression : la fiche porte l'historique (dossiers, factures, paiements). */
    public function delete(User $user, Client $client): bool
    {
        return false;
    }
}
