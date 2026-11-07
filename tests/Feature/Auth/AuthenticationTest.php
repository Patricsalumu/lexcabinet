<?php

use App\Enums\Role;
use App\Livewire\Layout\LogoutButton;
use Livewire\Livewire;
use Livewire\Volt\Volt;

test('la page de connexion s\'affiche en français', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSeeVolt('pages.auth.login')
        ->assertSee('Se connecter')
        ->assertSee('Mot de passe oublié ?');
});

test('un utilisateur se connecte depuis la page de connexion', function () {
    $user = userWithRoles(Role::Secretary);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('un mauvais mot de passe est refusé avec un message en français', function () {
    $user = userWithRoles(Role::Secretary);

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password')
        ->call('login')
        ->assertHasErrors(['form.email' => trans('auth.failed')])
        ->assertNoRedirect();

    $this->assertGuest();
});

test('le menu latéral s\'affiche', function () {
    $this->actingAs(userWithRoles(Role::Secretary))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tableau de bord')
        ->assertSee('Se déconnecter');
});

test('un utilisateur se déconnecte, et la déconnexion est tracée', function () {
    $user = userWithRoles(Role::Secretary);
    $this->actingAs($user);

    Livewire::test(LogoutButton::class)
        ->call('logout')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect(\App\Models\AuditActivity::query()->where('description', 'Déconnexion')->where('causer_id', $user->id)->exists())->toBeTrue();
});
