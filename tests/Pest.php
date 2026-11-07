<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/*
| Les tests de fonctionnalités tournent sur la base MariaDB « lexcabinet_test »
| (voir phpunit.xml), jamais sur la base de travail « lexcabinet ».
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Crée un utilisateur avec les rôles donnés (en créant rôles et permissions au besoin).
 * Un administrateur reçoit une double authentification déjà activée, pour que
 * les tests qui ne portent pas sur la 2FA accèdent directement à l'application.
 */
function userWithRoles(Role ...$roles): User
{
    if (RoleModel::query()->doesntExist()) {
        test()->seed(RolesAndPermissionsSeeder::class);
    }

    $user = User::factory()->withRoles(...$roles)->create();

    if ($user->isAdmin()) {
        $user->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $user->fresh();
}
