<?php

use App\Enums\Role;
use App\Livewire\Admin\UserManager;
use App\Models\AuditActivity;
use App\Models\User;
use Livewire\Livewire;

it('permet à l\'administrateur de créer un utilisateur avec plusieurs rôles', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('create')
        ->set('name', 'Me Ruth Kasongo')
        ->set('title', 'Avocate')
        ->set('email', 'ruth.kasongo@mukendi-avocats.example')
        ->set('password', 'motdepasse-solide')
        ->set('roles', [Role::Lawyer->value, Role::Secretary->value])
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'ruth.kasongo@mukendi-avocats.example')->firstOrFail();

    expect($user->hasAllRoles([Role::Lawyer->value, Role::Secretary->value]))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Rôles modifiés')->where('subject_id', $user->id)->where('causer_id', $admin->id)->exists())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Utilisateur créé')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('exige au moins un rôle', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(UserManager::class)
        ->call('create')
        ->set('name', 'Sans rôle')
        ->set('email', 'sans.role@example.cd')
        ->set('password', 'motdepasse-solide')
        ->set('roles', [])
        ->call('save')
        ->assertHasErrors('roles');
});

it('modifie les rôles d\'un utilisateur et trace le changement', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Lawyer);

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('edit', $user->id)
        ->set('roles', [Role::Lawyer->value, Role::Cashier->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->getRoleNames()->sort()->values()->all())->toBe(['avocat', 'caissier']);

    $log = AuditActivity::query()->where('description', 'Rôles modifiés')->where('subject_id', $user->id)->latest('id')->first();
    expect($log->properties['old'])->toBe(['Avocat'])
        ->and($log->properties['new'])->toBe(['Avocat', 'Caisse']);
});

it('empêche l\'administrateur de retirer son propre rôle admin ou de se désactiver', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('edit', $admin->id)
        ->set('roles', [Role::Lawyer->value])
        ->call('save')
        ->assertHasErrors('roles');

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('toggleActive', $admin->id)
        ->assertHasErrors('is_active');

    expect($admin->fresh()->isAdmin())->toBeTrue()
        ->and($admin->fresh()->is_active)->toBeTrue();
});

it('désactive puis réactive un compte, sans le supprimer', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Secretary);

    Livewire::actingAs($admin)->test(UserManager::class)->call('toggleActive', $user->id);
    expect($user->fresh()->is_active)->toBeFalse();

    Livewire::actingAs($admin)->test(UserManager::class)->call('toggleActive', $user->id);
    expect($user->fresh()->is_active)->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Compte désactivé')->exists())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Compte réactivé')->exists())->toBeTrue();
});

it('refuse la gestion des utilisateurs à un non-administrateur', function (Role $role) {
    Livewire::actingAs(userWithRoles($role))->test(UserManager::class)->assertForbidden();
})->with([Role::Lawyer, Role::Secretary, Role::Cashier, Role::Accountant]);

it('refuse aussi les actions directes d\'un non-administrateur', function () {
    $target = userWithRoles(Role::Lawyer);

    expect(userWithRoles(Role::Secretary)->can('update', $target))->toBeFalse()
        ->and(userWithRoles(Role::Admin)->can('delete', $target))->toBeFalse();
});
