<?php

use App\Enums\CaseStatus;
use App\Enums\FeeType;
use App\Enums\Role;
use App\Livewire\Cases\CaseForm;
use App\Livewire\Cases\CaseShow;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\LegalCase;
use App\Services\NumberingService;
use Livewire\Livewire;

function openCaseAs($user, array $overrides = [])
{
    $lawyer = $overrides['lawyer'] ?? userWithRoles(Role::Lawyer);
    $client = $overrides['client'] ?? Client::factory()->create();

    return Livewire::actingAs($user)->test(CaseForm::class)
        ->set('client_id', (string) $client->id)
        ->set('title', $overrides['title'] ?? 'Kivu Agro c/ Tropical Logistics')
        ->set('adverse_party', $overrides['adverse_party'] ?? '')
        ->set('matter', 'commercial')
        ->set('lead_lawyer_id', (string) $lawyer->id)
        ->set('collaborators', $overrides['collaborators'] ?? [])
        ->set('fee_type', $overrides['fee_type'] ?? 'flat')
        ->set('fee_amount', $overrides['fee_amount'] ?? '2500')
        ->set('currency', $overrides['currency'] ?? 'USD')
        ->set('opened_on', $overrides['opened_on'] ?? today()->toDateString())
        ->call('save');
}

it('ouvre un dossier numéroté LEX-AAAA-NNNN avec sa convention d\'honoraires', function () {
    $secretary = userWithRoles(Role::Secretary);

    openCaseAs($secretary)->assertHasNoErrors();
    openCaseAs($secretary, ['title' => 'Deuxième dossier'])->assertHasNoErrors();

    $cases = LegalCase::query()->orderBy('id')->get();
    $year = now()->year;

    expect($cases[0]->reference)->toBe("LEX-{$year}-0001")
        ->and($cases[1]->reference)->toBe("LEX-{$year}-0002")
        ->and($cases[0]->status)->toBe(CaseStatus::Open)
        ->and($cases[0]->fee_type)->toBe(FeeType::Flat)
        ->and($cases[0]->fee_amount)->toBe('2500.00')
        ->and(AuditActivity::query()->where('description', 'Dossier ouvert')->where('causer_id', $secretary->id)->count())->toBe(2);
});

it('poursuit la numérotation existante sans trou', function () {
    $year = now()->year;
    \Illuminate\Support\Facades\DB::transaction(fn () => app(NumberingService::class)->set("case:{$year}", 46));

    openCaseAs(userWithRoles(Role::Secretary))->assertHasNoErrors();

    expect(LegalCase::query()->first()->reference)->toBe("LEX-{$year}-0047");
});

it('ne consomme aucun numéro si l\'ouverture échoue (pas de trou)', function () {
    $numbering = app(NumberingService::class);

    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($numbering) {
            $numbering->next('case:2026');
            throw new RuntimeException('échec simulé');
        });
    } catch (RuntimeException) {
    }

    $next = \Illuminate\Support\Facades\DB::transaction(fn () => $numbering->next('case:2026'));
    expect($next)->toBe(1);
});

it('fixe la prochaine facture d\'abonnement 30 jours après l\'ouverture (DOS-08)', function () {
    $opened = today()->subDays(5);

    openCaseAs(userWithRoles(Role::Secretary), ['fee_type' => 'subscription', 'fee_amount' => '800', 'opened_on' => $opened->toDateString()])
        ->assertHasNoErrors();

    $case = LegalCase::query()->firstOrFail();
    expect($case->next_subscription_invoice_on->toDateString())->toBe($opened->copy()->addDays(30)->toDateString());
});

it('enregistre une convention en CDF', function () {
    openCaseAs(userWithRoles(Role::Secretary), ['fee_amount' => '2800000', 'currency' => 'CDF'])->assertHasNoErrors();

    expect(LegalCase::query()->first()->currency->value)->toBe('CDF');
});

it('exige un avocat comme responsable et comme collaborateurs', function () {
    $notLawyer = userWithRoles(Role::Cashier);

    openCaseAs(userWithRoles(Role::Secretary), ['lawyer' => $notLawyer])->assertHasErrors('lead_lawyer_id');
    expect(LegalCase::query()->count())->toBe(0);
});

it('alerte quand la partie adverse est un client du cabinet', function () {
    Client::factory()->create(['name' => 'Tropical Logistics SA']);

    openCaseAs(userWithRoles(Role::Secretary), ['adverse_party' => 'Tropical Logistics SA'])->assertHasNoErrors();

    expect(AuditActivity::query()->where('description', 'like', 'Alerte conflit d\'intérêts%')->exists())->toBeTrue();
});

it('enregistre les collaborateurs et trace leur modification', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lead = userWithRoles(Role::Lawyer);
    $colleague = userWithRoles(Role::Lawyer);

    openCaseAs($secretary, ['lawyer' => $lead, 'collaborators' => [(string) $colleague->id, (string) $lead->id]])->assertHasNoErrors();

    $case = LegalCase::query()->firstOrFail();
    expect($case->collaborators->pluck('id')->all())->toBe([$colleague->id]); // le responsable n'est pas dupliqué

    Livewire::actingAs($secretary)->test(CaseForm::class, ['case' => $case])
        ->set('collaborators', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($case->fresh()->collaborators)->toBeEmpty()
        ->and(AuditActivity::query()->where('description', 'Collaborateurs du dossier modifiés')->exists())->toBeTrue();
});

it('change le statut, et la clôture arrête l\'abonnement', function () {
    $secretary = userWithRoles(Role::Secretary);
    $case = LegalCase::factory()->create(['fee_type' => FeeType::Subscription, 'next_subscription_invoice_on' => today()->addDays(10)]);

    Livewire::actingAs($secretary)->test(CaseShow::class, ['case' => $case])
        ->set('status', 'closed')
        ->assertHasNoErrors()
        ->assertSee('Abonnement arrêté');

    expect($case->fresh()->status)->toBe(CaseStatus::Closed)
        ->and($case->fresh()->closed_on->isToday())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Statut du dossier modifié : Clôturé')->exists())->toBeTrue();
});

it('ajoute actes, audiences et notes au journal du dossier', function () {
    $case = LegalCase::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Secretary))->test(CaseShow::class, ['case' => $case])
        ->set('eventType', 'hearing')
        ->set('eventAt', today()->addDays(3)->format('Y-m-d').'T09:30')
        ->set('eventChamber', 'Chambre 2')
        ->set('eventDescription', 'Plaidoiries')
        ->call('addEvent')
        ->assertHasNoErrors()
        ->assertSee('Plaidoiries');

    $event = $case->events()->firstOrFail();
    expect($event->type->value)->toBe('hearing')
        ->and($event->chamber)->toBe('Chambre 2')
        ->and($event->occurred_at->format('H:i'))->toBe('09:30');
});

it('affiche les prochaines audiences sur le tableau de bord', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id, 'title' => 'Dossier à plaider']);
    $case->events()->create(['type' => 'hearing', 'occurred_at' => today()->addDay()->setTime(10, 0), 'description' => 'Plaidoiries finales']);
    $other = LegalCase::factory()->create(['title' => 'Dossier confidentiel']);
    $other->events()->create(['type' => 'hearing', 'occurred_at' => today()->addDay()->setTime(11, 0), 'description' => 'Audience secrète']);

    $this->actingAs($lawyer)->get(route('dashboard'))
        ->assertSee('Plaidoiries finales')
        ->assertDontSee('Audience secrète');
});
