<?php

use App\Enums\Role;
use App\Livewire\Admin\UserManager;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Models\AuditActivity;
use App\Models\User;
use App\Services\TwoFactorService;
use Livewire\Livewire;
use Livewire\Volt\Volt;

function attemptLogin(string $email, string $password)
{
    return Volt::test('pages.auth.login')
        ->set('form.email', $email)
        ->set('form.password', $password)
        ->call('login');
}

it('verrouille le compte après 5 échecs, même avec le bon mot de passe ensuite', function () {
    $user = userWithRoles(Role::Secretary);

    foreach (range(1, 4) as $i) {
        attemptLogin($user->email, 'mauvais')->assertHasErrors('form.email');
        expect($user->fresh()->isLocked())->toBeFalse();
    }

    attemptLogin($user->email, 'mauvais')->assertHasErrors('form.email');
    expect($user->fresh()->isLocked())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'like', 'Compte verrouillé%')->exists())->toBeTrue();

    attemptLogin($user->email, 'password')->assertHasErrors('form.email');
    $this->assertGuest();
});

it('remet le compteur à zéro après une connexion réussie', function () {
    $user = userWithRoles(Role::Secretary);

    attemptLogin($user->email, 'mauvais');
    attemptLogin($user->email, 'mauvais');
    attemptLogin($user->email, 'password')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->failed_login_attempts)->toBe(0)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('permet à l\'administrateur de déverrouiller un compte', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Cashier);
    $user->forceFill(['locked_at' => now(), 'failed_login_attempts' => 5])->save();

    Livewire::actingAs($admin)->test(UserManager::class)->call('unlock', $user->id);
    auth()->logout();

    expect($user->fresh()->isLocked())->toBeFalse();
    attemptLogin($user->email, 'password')->assertHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('refuse la connexion d\'un compte désactivé', function () {
    $user = userWithRoles(Role::Lawyer);
    $user->update(['is_active' => false]);

    attemptLogin($user->email, 'password')->assertHasErrors('form.email');
    $this->assertGuest();
});

it('déconnecte un utilisateur désactivé pendant sa session', function () {
    $user = userWithRoles(Role::Lawyer);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->update(['is_active' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('laisse la double authentification facultative par défaut, même pour l\'administrateur', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRoles(Role::Admin)->create();

    expect(config('lexcabinet.admin_two_factor'))->toBeFalse();
    attemptLogin($admin->email, 'password')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($admin);
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('two-factor.setup'))->assertOk()->assertSee('Facultatif');
});

it('l\'impose à l\'administrateur si ADMIN_TWO_FACTOR=true', function () {
    config(['lexcabinet.admin_two_factor' => true]);
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRoles(Role::Admin)->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    $this->actingAs($admin)->get(route('settings.users'))->assertRedirect(route('two-factor.setup'));
    $this->actingAs($admin)->get(route('two-factor.setup'))->assertOk();
});

it('active la double authentification avec un code valide', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRoles(Role::Admin)->create();
    $this->actingAs($admin);

    $component = Livewire::test(TwoFactorSetup::class);
    $secret = session('two_factor.pending_secret');

    $component->set('code', '000000')->call('confirm')->assertHasErrors('code');
    $component->set('code', app(TwoFactorService::class)->currentCode($secret))->call('confirm')
        ->assertHasNoErrors()->assertRedirect(route('dashboard'));

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

it('demande le code TOTP à la connexion d\'un administrateur', function () {
    $admin = userWithRoles(Role::Admin);   // double authentification déjà activée

    attemptLogin($admin->email, 'password')->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    Livewire::test(TwoFactorChallenge::class)->set('code', '000000')->call('verify')->assertHasErrors('code');
    $this->assertGuest();

    $code = app(TwoFactorService::class)->currentCode($admin->fresh()->two_factor_secret);
    Livewire::test(TwoFactorChallenge::class)->set('code', $code)->call('verify')
        ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($admin);
});

it('n\'exige pas de code d\'un utilisateur qui ne l\'a pas activée', function () {
    $user = userWithRoles(Role::Accountant);

    attemptLogin($user->email, 'password')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
});

it('permet à tout utilisateur de l\'activer, puis demande son code à la connexion', function () {
    $user = userWithRoles(Role::Secretary);
    $this->actingAs($user);

    $component = Livewire::test(TwoFactorSetup::class);
    $component->set('code', app(TwoFactorService::class)->currentCode(session('two_factor.pending_secret')))->call('confirm')->assertHasNoErrors();
    auth()->logout();

    attemptLogin($user->email, 'password')->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
});

it('permet de la désactiver avec un code valide, sauf si elle est imposée', function () {
    $admin = userWithRoles(Role::Admin);   // double authentification activée
    $secret = $admin->two_factor_secret;
    $this->actingAs($admin);

    Livewire::test(TwoFactorSetup::class)->set('code', '000000')->call('disable')->assertHasErrors('code');
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    config(['lexcabinet.admin_two_factor' => true]);
    Livewire::test(TwoFactorSetup::class)->set('code', app(TwoFactorService::class)->currentCode($secret))->call('disable')->assertForbidden();

    config(['lexcabinet.admin_two_factor' => false]);
    Livewire::test(TwoFactorSetup::class)->set('code', app(TwoFactorService::class)->currentCode($secret))->call('disable')->assertHasNoErrors();
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and(AuditActivity::query()->where('description', 'Double authentification désactivée')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('ne propose pas d\'inscription publique', function () {
    $this->get('/register')->assertNotFound();
});
