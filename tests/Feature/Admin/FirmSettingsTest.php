<?php

use App\Enums\Role;
use App\Livewire\Admin\FirmSettings;
use App\Models\AuditActivity;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(SettingsSeeder::class));

it('charge les paramètres de démonstration du prototype', function () {
    $settings = app(SettingsService::class);

    expect($settings->get('firm.name'))->toBe('Cabinet Mukendi & Associés')
        ->and($settings->get('vat.enabled'))->toBeFalse()
        ->and($settings->get('vat.rate'))->toBe('16.00')
        ->and($settings->get('cash.disbursement_threshold_usd'))->toBe('500.00')
        ->and($settings->get('subscription_auto_issue'))->toBeFalse()
        ->and($settings->get('reminders.day_before_time'))->toBe('18:00')
        ->and($settings->get('recap.time'))->toBe('07:00');
});

it('enregistre les paramètres et trace chaque modification avec son auteur', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(FirmSettings::class)
        ->set('firm.name', 'Cabinet Mukendi & Associés SCP')
        ->set('rules.vat_enabled', true)
        ->set('rules.threshold', '750')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    expect($settings->get('firm.name'))->toBe('Cabinet Mukendi & Associés SCP')
        ->and($settings->get('vat.enabled'))->toBeTrue()
        ->and($settings->get('cash.disbursement_threshold_usd'))->toBe('750.00');

    $logs = AuditActivity::query()->where('log_name', 'settings')->where('causer_id', $admin->id)->get();
    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('description')->all())->toContain('Paramètre modifié : Seuil de validation des décaissements (USD)');

    $threshold = $logs->firstWhere('properties.key', 'cash.disbursement_threshold_usd');
    expect($threshold->properties['old'])->toBe('500.00')
        ->and($threshold->properties['new'])->toBe('750.00');
});

it('valide les montants et les heures', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(FirmSettings::class)
        ->set('rules.vat_rate', '120')
        ->set('rules.threshold', '-5')
        ->set('rules.reminder_time', '25:00')
        ->set('rules.closes_at', '07:00')
        ->call('save')
        ->assertHasErrors(['rules.vat_rate', 'rules.threshold', 'rules.reminder_time', 'rules.closes_at']);
});

it('stocke le logo sur le disque privé et ne le sert qu\'aux utilisateurs connectés', function () {
    Storage::fake('local');
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(FirmSettings::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))
        ->call('save')
        ->assertHasNoErrors();

    $path = app(SettingsService::class)->get('firm.logo_path');
    Storage::disk('local')->assertExists($path);

    $this->actingAs(userWithRoles(Role::Cashier))->get(route('firm.logo'))->assertOk();
    auth()->logout();
    $this->get(route('firm.logo'))->assertRedirect(route('login'));
});

it('refuse la modification des paramètres à un non-administrateur', function () {
    Livewire::actingAs(userWithRoles(Role::Accountant))->test(FirmSettings::class)->assertForbidden();
});
