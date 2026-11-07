<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Appointment;
use App\Models\User;

/**
 * L'avocat lit l'agenda ; le secrétariat et l'admin le gèrent.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::AgendaView->value, Permission::AgendaManage->value]);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::AgendaManage->value);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->can(Permission::AgendaManage->value);
    }

    /** Aucune suppression : un rendez-vous s'annule (statut « Annulé »). */
    public function delete(User $user, Appointment $appointment): bool
    {
        return false;
    }
}
