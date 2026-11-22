<?php

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Livewire\Platform\FirmCreate;
use App\Livewire\Platform\FirmIndex;
use App\Livewire\Platform\FirmShow;
use App\Livewire\Platform\PlatformAuditLog;
use App\Models\Account;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Firm;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Tenancy\CurrentFirm;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;

/*
| Phase M2 : espace Plateforme. Le super administrateur crée les cabinets et leurs
| administrateurs, suspend un cabinet ; il n'accède à aucune donnée métier.
*/

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, SettingsSeeder::class, EmailTemplateSeeder::class]);
    $this->super = superAdmin();
    $this->clientA = Client::factory()->create(['name' => 'Client confidentiel A']);
});

function superAdmin(): User
{
    return app(CurrentFirm::class)->runWithoutFirm(function () {
        $user = User::factory()->create(['name' => 'Super Admin']);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    });
}

function loginAs(string $email, string $password)
{
    return Volt::test('pages.auth.login')->set('form.email', $email)->set('form.password', $password)->call('login');
}

function createFirmAs(User $super, array $overrides = [])
{
    return Livewire::actingAs($super)->test(FirmCreate::class)
        ->set('name', $overrides['name'] ?? 'Cabinet Ngoy & Associés')
        ->set('email', $overrides['email'] ?? 'contact@ngoy-avocats.example')
        ->set('phone', '+243 81 222 3344')
        ->set('admin_name', $overrides['admin_name'] ?? 'Me Paul Ngoy')
        ->set('admin_email', $overrides['admin_email'] ?? 'paul.ngoy@ngoy-avocats.example')
        ->call('save');
}

it('crée un cabinet prêt à l\'emploi et son administrateur, qui reçoit un lien d\'accès', function () {
    createFirmAs($this->super)->assertHasNoErrors()->assertRedirect();

    $firm = Firm::query()->where('name', 'Cabinet Ngoy & Associés')->sole();
    $admin = User::query()->withoutGlobalScopes()->where('email', 'paul.ngoy@ngoy-avocats.example')->sole();

    expect($admin->firm_id)->toBe($firm->id)
        ->and($admin->hasRole(Role::Admin->value))->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeFalse();

    app(CurrentFirm::class)->run($firm, function () use ($admin) {
        $log = EmailLog::query()->where('template_key', EmailTemplate::ACCOUNT_CREATED)->sole();
        expect($log->recipient_email)->toBe($admin->email)
            ->and($log->status)->toBe(EmailStatus::Sent)
            ->and($log->body)->toContain('compte/acces/')->not->toContain('password')
            ->and(Account::query()->where('code', '411')->exists())->toBeTrue()
            ->and(TreasuryAccount::query()->where('type', 'cash')->count())->toBe(2)
            ->and(app(SettingsService::class)->get('firm.name'))->toBe('Cabinet Ngoy & Associés')
            ->and(app(SettingsService::class)->get('firm.email'))->toBe('contact@ngoy-avocats.example')
            ->and(Client::query()->count())->toBe(0);
    });

    expect(AuditActivity::query()->withoutGlobalScopes()->whereNull('firm_id')->where('description', 'Cabinet créé, avec son administrateur')->where('causer_id', $this->super->id)->exists())->toBeTrue();
});

it('refuse un e-mail d\'administrateur déjà utilisé et un nom de cabinet en double', function () {
    $existing = userWithRoles(Role::Secretary);

    createFirmAs($this->super, ['admin_email' => $existing->email])->assertHasErrors('admin_email');
    createFirmAs($this->super, ['name' => $this->firm->name])->assertHasErrors('name');
    expect(Firm::query()->count())->toBe(1);
});

it('réserve l\'espace Plateforme au super administrateur', function (Role $role) {
    $user = userWithRoles($role);

    foreach (['platform.firms', 'platform.firms.create', 'platform.audit'] as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }
    $this->actingAs($user)->get(route('platform.firms.show', $this->firm))->assertForbidden();
    Livewire::actingAs($user)->test(FirmCreate::class)->assertForbidden();
})->with([Role::Admin, Role::Secretary, Role::Lawyer, Role::Cashier, Role::Accountant]);

it('tient le super administrateur à l\'écart des données des cabinets', function () {
    app(CurrentFirm::class)->forget();   // comme en production : aucun cabinet avant la requête

    foreach (['dashboard', 'clients.index', 'cases.index', 'invoices.index', 'cash.index', 'accounting.index', 'emails.index', 'messages.index', 'settings.users'] as $route) {
        $this->actingAs($this->super)->get(route($route))->assertRedirect(route('platform.firms'));
    }
    $this->actingAs($this->super)->get(route('clients.show', $this->clientA))->assertRedirect(route('platform.firms'));

    // Même en appelant directement un composant, aucune donnée : droits absents et cabinet inconnu.
    Livewire::actingAs($this->super)->test(\App\Livewire\Clients\ClientIndex::class)->assertForbidden();
    expect(Client::query()->count())->toBe(0);

    // Les pages Plateforme ne montrent que des informations de compte.
    $this->actingAs($this->super)->get(route('platform.firms'))->assertOk()->assertSee($this->firm->name)->assertDontSee('Client confidentiel A');
    $this->actingAs($this->super)->get(route('platform.firms.show', $this->firm))->assertOk()->assertDontSee('Client confidentiel A');
});

it('suspend un cabinet : ses utilisateurs sont déconnectés et ne peuvent plus se connecter ; rien n\'est effacé', function () {
    $secretary = userWithRoles(Role::Secretary);

    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])->call('suspend')->assertHasErrors('reason');
    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])
        ->set('reason', 'Fin de la période de test')->call('suspend')->assertHasNoErrors();

    expect($this->firm->fresh()->is_active)->toBeFalse();

    // Session en cours : déconnecté à la requête suivante.
    $this->actingAs($secretary)->get(route('clients.index'))->assertRedirect(route('login'));
    $this->assertGuest();

    // Nouvelle connexion : refusée avec un message clair.
    loginAs($secretary->email, 'password')->assertHasErrors(['form.email' => trans('auth.firm_inactive')]);

    expect(Client::query()->where('name', 'Client confidentiel A')->exists())->toBeTrue()
        ->and(AuditActivity::query()->withoutGlobalScopes()->whereNull('firm_id')->where('description', 'Accès du cabinet suspendu')->exists())->toBeTrue();

    // Rétablissement.
    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])->call('reactivate');
    loginAs($secretary->email, 'password')->assertHasNoErrors();
});

it('ajoute un administrateur et renvoie un lien d\'accès', function () {
    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])
        ->set('admin_name', 'Me Claire Banza')->set('admin_email', 'claire.banza@example.cd')
        ->call('addAdmin')->assertHasNoErrors()
        ->assertSee('claire.banza@example.cd');

    $admin = User::query()->where('email', 'claire.banza@example.cd')->sole();
    expect($admin->firm_id)->toBe($this->firm->id)->and($admin->isAdmin())->toBeTrue();

    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])->call('resendAccess', $admin->id);
    expect(EmailLog::query()->where('template_key', EmailTemplate::ACCOUNT_CREATED)->where('recipient_email', 'claire.banza@example.cd')->count())->toBe(2);

    // Un compte d'un autre cabinet ne peut pas être visé depuis cette fiche.
    $other = inOtherFirm(fn () => userWithRoles(Role::Admin));
    Livewire::actingAs($this->super)->test(FirmShow::class, ['firm' => $this->firm])->call('resendAccess', $other->id)->assertNotFound();
});

it('liste les cabinets avec leurs administrateurs, sans données métier', function () {
    $admin = userWithRoles(Role::Admin);
    inOtherFirm(fn () => Client::factory()->create(['name' => 'Client confidentiel B']), Firm::factory()->create(['name' => 'Cabinet B']));

    Livewire::actingAs($this->super)->test(FirmIndex::class)
        ->assertSee($this->firm->name)->assertSee('Cabinet B')->assertSee($admin->email)
        ->assertDontSee('Client confidentiel A')->assertDontSee('Client confidentiel B');
});

it('n\'affiche dans le journal de la plateforme que ses propres actions', function () {
    createFirmAs($this->super);
    activity('clients')->performedOn($this->clientA)->log('Client consulté');   // action d'un cabinet

    Livewire::actingAs($this->super)->test(PlatformAuditLog::class)
        ->assertSee('Cabinet créé, avec son administrateur')
        ->assertDontSee('Client consulté');
});

it('range dans le cabinet concerné les traces de connexion faites hors cabinet', function () {
    $secretary = userWithRoles(Role::Secretary);
    app(CurrentFirm::class)->forget();

    loginAs($secretary->email, 'mauvais');
    app(CurrentFirm::class)->set($this->firm);

    expect(AuditActivity::query()->withoutGlobalScopes()->whereNull('firm_id')->where('subject_id', $secretary->id)->exists())->toBeFalse();
});

it('crée le super administrateur en console, mot de passe saisi masqué', function () {
    $this->artisan('lexcabinet:create-super-admin', ['email' => 'root@plateforme.example', '--name' => 'Patrick'])
        ->expectsQuestion('Mot de passe (12 caractères minimum, saisie masquée)', 'MotDePasse2026')
        ->expectsQuestion('Confirmez le mot de passe', 'MotDePasse2026')
        ->assertSuccessful();

    $user = User::query()->withoutGlobalScopes()->where('email', 'root@plateforme.example')->sole();
    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->firm_id)->toBeNull()
        ->and(Hash::check('MotDePasse2026', $user->password))->toBeTrue();

    // Mot de passe trop court : refusé.
    $this->artisan('lexcabinet:create-super-admin', ['email' => 'autre@plateforme.example'])
        ->expectsQuestion('Mot de passe (12 caractères minimum, saisie masquée)', 'court')
        ->expectsQuestion('Confirmez le mot de passe', 'court')
        ->assertFailed();

    // Adresse d'un compte de cabinet : refusée.
    $this->artisan('lexcabinet:create-super-admin', ['email' => userWithRoles(Role::Admin)->email])->assertFailed();
});

it('ne fait jamais d\'un compte de cabinet un super administrateur', function () {
    $admin = userWithRoles(Role::Admin);
    $admin->forceFill(['is_super_admin' => true])->save();   // même si l'indicateur était forcé

    expect($admin->fresh()->isSuperAdmin())->toBeFalse();
    $this->actingAs($admin->fresh())->get(route('platform.firms'))->assertForbidden();

    // Et l'indicateur n'est pas assignable par formulaire.
    expect(in_array('is_super_admin', (new User)->getFillable(), true))->toBeFalse();
});
