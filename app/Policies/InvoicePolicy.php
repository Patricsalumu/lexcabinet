<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Models\Invoice;
use App\Models\User;

/**
 * Facturation : le comptable (et l'admin) crée, émet et envoie ; le caissier lit ;
 * l'avocat lit les factures de ses dossiers ; l'avoir est réservé à l'administrateur.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::InvoicesViewAll->value, Permission::InvoicesViewOwn->value]);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->can(Permission::InvoicesViewAll->value)) {
            return true;
        }

        return $user->can(Permission::InvoicesViewOwn->value)
            && $invoice->legalCase !== null
            && $invoice->legalCase->isAssignedTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::InvoicesManage->value);
    }

    /** Modifier ou émettre : un brouillon seulement. */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesManage->value) && $invoice->status === InvoiceStatus::Draft;
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesManage->value)
            && $invoice->status->isLocked()
            && $invoice->status !== InvoiceStatus::Cancelled;
    }

    /** Annulation par avoir (réservée à l'administrateur, comme dans le prototype). */
    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesCancel->value)
            && $invoice->status->isLocked()
            && $invoice->status !== InvoiceStatus::Cancelled;
    }

    /** Seul un brouillon se supprime. */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can(Permission::InvoicesManage->value) && $invoice->status === InvoiceStatus::Draft;
    }
}
