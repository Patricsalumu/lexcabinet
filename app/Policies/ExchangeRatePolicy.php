<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RatesManage->value);
    }

    /** CAI-07 : seul l'administrateur saisit les taux. */
    public function create(User $user): bool
    {
        return $user->can(Permission::RatesManage->value);
    }

    public function update(User $user, ExchangeRate $rate): bool
    {
        return $user->can(Permission::RatesManage->value);
    }

    public function delete(User $user, ExchangeRate $rate): bool
    {
        return false;
    }
}
