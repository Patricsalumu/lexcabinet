<?php

use App\Enums\Permission;
use App\Enums\Role;

it('donne toutes les permissions à l\'administrateur', function () {
    $admin = userWithRoles(Role::Admin);

    foreach (Permission::cases() as $permission) {
        expect($admin->can($permission->value))->toBeTrue();
    }
});

it('applique la matrice des droits de CLAUDE.md', function (Role $role, array $allowed, array $denied) {
    $user = userWithRoles($role);

    foreach ($allowed as $permission) {
        expect($user->can($permission->value))->toBeTrue("{$role->value} devrait avoir {$permission->value}");
    }
    foreach ($denied as $permission) {
        expect($user->can($permission->value))->toBeFalse("{$role->value} ne devrait pas avoir {$permission->value}");
    }
})->with([
    'avocat' => [Role::Lawyer,
        [Permission::AgendaView, Permission::CasesViewOwn, Permission::TimeLog],
        [Permission::CasesManage, Permission::AgendaManage, Permission::InvoicesManage, Permission::CasesViewAll, Permission::CashOperate, Permission::SettingsManage]],
    'secretaire' => [Role::Secretary,
        [Permission::AgendaManage, Permission::ClientsManage, Permission::CasesManage, Permission::RemindersSend],
        [Permission::InvoicesManage, Permission::CashOperate, Permission::CashView, Permission::TimeLog, Permission::UsersManage]],
    'caissier' => [Role::Cashier,
        [Permission::CashView, Permission::CashOperate, Permission::InvoicesViewAll],
        [Permission::CasesManage, Permission::InvoicesManage, Permission::CashValidate, Permission::AccountingView]],
    'comptable' => [Role::Accountant,
        [Permission::InvoicesManage, Permission::AccountingManage, Permission::ReportsView, Permission::CashView],
        [Permission::CasesManage, Permission::CashOperate, Permission::CashValidate, Permission::UsersManage]],
]);

it('additionne les droits de plusieurs rôles (avocat + secrétaire)', function () {
    $user = userWithRoles(Role::Lawyer, Role::Secretary);

    expect($user->can(Permission::CasesManage->value))->toBeTrue()   // vient du rôle secrétaire
        ->and($user->can(Permission::TimeLog->value))->toBeTrue()     // vient du rôle avocat
        ->and($user->can(Permission::InvoicesManage->value))->toBeFalse()
        ->and($user->can(Permission::CashOperate->value))->toBeFalse();
});

it('additionne les droits secrétaire + caissière', function () {
    $user = userWithRoles(Role::Secretary, Role::Cashier);

    expect($user->can(Permission::CashOperate->value))->toBeTrue()
        ->and($user->can(Permission::CasesManage->value))->toBeTrue()
        ->and($user->can(Permission::InvoicesManage->value))->toBeFalse();
});
