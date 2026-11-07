<?php

use App\Enums\Module;
use App\Enums\Role;

/** Modules visibles attendus pour chaque rôle (prototype corrigé par CLAUDE.md). */
dataset('menus', [
    'admin' => [[Role::Admin], Module::cases()],
    'avocat' => [[Role::Lawyer], [Module::Dashboard, Module::Agenda, Module::Clients, Module::Cases, Module::Invoices]],
    'secretaire' => [[Role::Secretary], [Module::Dashboard, Module::Agenda, Module::Clients, Module::Cases, Module::Emails]],
    'caissier' => [[Role::Cashier], [Module::Dashboard, Module::Invoices, Module::Cash]],
    'comptable' => [[Role::Accountant], [Module::Dashboard, Module::Invoices, Module::Cash, Module::Accounting]],
    'avocat + secretaire' => [[Role::Lawyer, Role::Secretary], [Module::Dashboard, Module::Agenda, Module::Clients, Module::Cases, Module::Invoices, Module::Emails]],
]);

it('affiche dans le menu les seuls modules autorisés', function (array $roles, array $visible) {
    $user = userWithRoles(...$roles);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    foreach (Module::cases() as $module) {
        $url = $module === Module::Dashboard ? route('dashboard')
            : ($module === Module::Settings ? route('settings.index') : route('module', $module->value));

        in_array($module, $visible, true)
            ? $response->assertSee('href="'.$url.'"', false)
            : $response->assertDontSee('href="'.$url.'"', false);
    }
})->with('menus');

it('refuse côté serveur l\'accès direct à un module non autorisé', function (array $roles, array $visible) {
    $user = userWithRoles(...$roles);

    foreach (Module::cases() as $module) {
        if (in_array($module, [Module::Dashboard, Module::Settings], true)) {
            continue;
        }

        $this->actingAs($user)->get(route('module', $module->value))
            ->assertStatus(in_array($module, $visible, true) ? 200 : 403);
    }
})->with('menus');

it('refuse les pages de paramètres aux non-administrateurs', function (Role $role) {
    $user = userWithRoles($role);

    foreach (['settings.index', 'settings.firm', 'settings.users', 'settings.rates', 'settings.audit'] as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }
})->with([Role::Lawyer, Role::Secretary, Role::Cashier, Role::Accountant]);

it('ouvre les pages de paramètres à l\'administrateur', function () {
    $admin = userWithRoles(Role::Admin);

    $this->actingAs($admin)->get(route('settings.index'))->assertRedirect(route('settings.firm'));
    foreach (['settings.firm', 'settings.users', 'settings.rates', 'settings.audit'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
});

it('redirige un visiteur non connecté vers la connexion', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('module', 'factures'))->assertRedirect(route('login'));
});
