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
    ->beforeEach(function () {
        // Multi-cabinet : chaque test se déroule dans un cabinet (le « cabinet A »).
        $this->firm = \App\Models\Firm::factory()->create(['name' => 'Cabinet de test']);
        app(\App\Support\Tenancy\CurrentFirm::class)->set($this->firm);
        $this->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
    })
    ->in('Feature');

/**
 * Exécute $callback dans un autre cabinet (créé au besoin), puis revient au cabinet du test.
 *
 * @template T
 *
 * @param  callable(\App\Models\Firm): T  $callback
 * @return T
 */
function inOtherFirm(callable $callback, ?\App\Models\Firm $firm = null): mixed
{
    $firm ??= \App\Models\Firm::factory()->create();

    return app(\App\Support\Tenancy\CurrentFirm::class)->run($firm, function () use ($callback, $firm) {
        test()->seed(\Database\Seeders\ChartOfAccountsSeeder::class);

        return $callback($firm);
    });
}

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
