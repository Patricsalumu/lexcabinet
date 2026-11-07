<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LegalCase;
use App\Models\User;

/**
 * Secret professionnel : un avocat n'ouvre que ses dossiers et ceux où il est collaborateur.
 */
class LegalCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::CasesViewAll->value, Permission::CasesViewOwn->value]);
    }

    public function view(User $user, LegalCase $case): bool
    {
        if ($user->can(Permission::CasesViewAll->value)) {
            return true;
        }

        return $user->can(Permission::CasesViewOwn->value) && $case->isAssignedTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CasesManage->value);
    }

    /** Modification du dossier, de son statut, de son journal et de ses pièces. */
    public function update(User $user, LegalCase $case): bool
    {
        return $user->can(Permission::CasesManage->value);
    }

    /**
     * Saisie de son propre temps : avocat du dossier (responsable ou collaborateur),
     * ou administrateur.
     */
    public function logTime(User $user, LegalCase $case): bool
    {
        if (! $user->can(Permission::TimeLog->value)) {
            return false;
        }

        return $user->isAdmin() || $case->isAssignedTo($user);
    }

    public function delete(User $user, LegalCase $case): bool
    {
        return false;
    }
}
