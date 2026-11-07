<?php

use App\Enums\Role;
use Livewire\Volt\Volt;

test('la page profil s\'affiche, sans suppression de compte', function () {
    $this->actingAs(userWithRoles(Role::Lawyer))
        ->get(route('profile'))
        ->assertOk()
        ->assertSeeVolt('profile.update-profile-information-form')
        ->assertSeeVolt('profile.update-password-form')
        ->assertDontSeeVolt('profile.delete-user-form');
});

test('les informations du profil se mettent à jour', function () {
    $user = userWithRoles(Role::Lawyer);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $user->refresh();

    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('le statut de vérification ne change pas si l\'e-mail ne change pas', function () {
    $user = userWithRoles(Role::Lawyer);
    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});
