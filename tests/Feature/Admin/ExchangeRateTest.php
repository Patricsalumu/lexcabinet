<?php

use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Admin\ExchangeRates;
use App\Models\AuditActivity;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('renvoie le taux du jour, sinon le dernier taux connu', function () {
    $service = app(ExchangeRateService::class);
    $service->record(today()->subDays(7), '2790', RateSource::Manual);

    expect($service->rateFor()->rate)->toBe('2790.0000')
        ->and($service->isMissingFor())->toBeTrue();

    $service->record(today(), '2800', RateSource::Bcc);

    expect($service->rateFor()->rate)->toBe('2800.0000')
        ->and($service->rateFor()->source)->toBe(RateSource::Bcc)
        ->and($service->isMissingFor())->toBeFalse()
        ->and($service->rateFor(today()->subDays(3))->rate)->toBe('2790.0000')
        ->and($service->rateFor(today()->subDays(30)))->toBeNull();
});

it('ne garde qu\'un taux par jour : une nouvelle saisie corrige le taux du jour', function () {
    $service = app(ExchangeRateService::class);
    $service->record(today(), '2800', RateSource::Manual);
    $service->record(today(), '2810.5', RateSource::Market);

    expect(ExchangeRate::query()->count())->toBe(1)
        ->and($service->rateFor()->rate)->toBe('2810.5000');
});

it('refuse un taux futur ou nul', function (string $date, string $rate) {
    app(ExchangeRateService::class)->record(Carbon::parse($date), $rate, RateSource::Manual);
})->with([
    'date future' => [fn () => today()->addDay()->toDateString(), '2800'],
    'taux nul' => [fn () => today()->toDateString(), '0'],
])->throws(InvalidArgumentException::class);

it('permet à l\'administrateur de saisir le taux du jour, avec trace d\'audit', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(ExchangeRates::class)
        ->set('rate', '2805')
        ->set('source', 'bcc')
        ->call('save')
        ->assertHasNoErrors();

    $rate = app(ExchangeRateService::class)->rateFor();
    expect($rate->rate)->toBe('2805.0000')
        ->and($rate->created_by)->toBe($admin->id)
        ->and(AuditActivity::query()->where('log_name', 'exchange_rates')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('refuse la saisie d\'un taux à un autre rôle que l\'administrateur', function (Role $role) {
    Livewire::actingAs(userWithRoles($role))->test(ExchangeRates::class)->assertForbidden();
})->with([Role::Cashier, Role::Accountant, Role::Secretary, Role::Lawyer]);

it('affiche le taux du jour sur le tableau de bord, et une alerte s\'il manque', function () {
    $user = userWithRoles(Role::Cashier);
    $service = app(ExchangeRateService::class);
    $service->record(today()->subDay(), '2790', RateSource::Manual);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Aucun taux saisi aujourd')
        ->assertSee("2\u{00A0}790", false);

    $service->record(today(), '2800', RateSource::Manual);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertDontSee('Aucun taux saisi aujourd')
        ->assertSee("2\u{00A0}800", false);
});
