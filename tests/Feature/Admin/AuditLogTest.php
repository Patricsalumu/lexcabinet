<?php

use App\Enums\Role;
use App\Livewire\Admin\AuditLog;
use App\Models\AuditActivity;
use Livewire\Livewire;

it('affiche l\'auteur et l\'heure des actions', function () {
    $admin = userWithRoles(Role::Admin);
    activity('test')->causedBy($admin)->log('Action de démonstration');

    Livewire::actingAs($admin)->test(AuditLog::class)
        ->assertSee('Action de démonstration')
        ->assertSee($admin->name)
        ->assertSee(now()->format('d/m/Y'));
});

it('filtre par texte et par utilisateur', function () {
    $admin = userWithRoles(Role::Admin);
    $other = userWithRoles(Role::Secretary);
    activity('test')->causedBy($admin)->log('Première action');
    activity('test')->causedBy($other)->log('Seconde action');

    Livewire::actingAs($admin)->test(AuditLog::class)
        ->set('search', 'Seconde')
        ->assertSee('Seconde action')
        ->assertDontSee('Première action')
        ->set('search', '')
        ->set('userId', (string) $admin->id)
        ->assertSee('Première action')
        ->assertDontSee('Seconde action');
});

it('interdit la modification et la suppression d\'une entrée du journal', function () {
    activity('test')->log('Entrée figée');
    $entry = AuditActivity::query()->latest('id')->first();

    expect(fn () => $entry->update(['description' => 'Falsifiée']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(AuditActivity::query()->find($entry->id)->description)->toBe('Entrée figée');
});

it('réserve le journal d\'audit à l\'administrateur', function (Role $role) {
    Livewire::actingAs(userWithRoles($role))->test(AuditLog::class)->assertForbidden();
})->with([Role::Lawyer, Role::Secretary, Role::Cashier, Role::Accountant]);
