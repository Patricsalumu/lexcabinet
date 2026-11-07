<?php

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Invoices\InvoiceCreate;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Services\ExchangeRateService;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceService;
use App\Services\SettingsService;
use App\Services\SubscriptionBillingService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 10:00');
    app(ExchangeRateService::class)->record(today(), '2800', RateSource::Manual);
    $this->accountant = userWithRoles(Role::Accountant);
});

afterEach(fn () => Carbon::setTestNow());

function builder(): InvoiceBuilder
{
    return app(InvoiceBuilder::class);
}

/*
| Scénario 4 : le choix d'un dossier au forfait de 2 500 $ affiche le client, son adresse, son téléphone
| et une ligne d'honoraires de 2 500 $ ; une seconde facture de forfait sur ce dossier est refusée.
*/
it('scénario 4 : forfait de 2 500 $, une seule facture', function () {
    $client = Client::factory()->create(['name' => 'Kivu Agro SARL', 'address' => 'Av. du Lac 45, Goma', 'phone' => '+243 99 712 4410']);
    $case = LegalCase::factory()->create(['client_id' => $client->id, 'title' => 'Kivu Agro c/ Tropical Logistics', 'fee_type' => FeeType::Flat, 'fee_amount' => '2500']);

    Livewire::actingAs($this->accountant)->test(InvoiceCreate::class)
        ->set('case_id', (string) $case->id)
        ->assertSee('Kivu Agro SARL')
        ->assertSee('Av. du Lac 45, Goma')
        ->assertSee('+243 99 712 4410')
        ->assertSee('Forfait')
        ->call('create')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->with('lines')->sole();
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines[0]->label)->toBe('Kivu Agro c/ Tropical Logistics, honoraires forfaitaires')
        ->and($invoice->lines[0]->amount)->toBe('2500.00')
        ->and($invoice->lines[0]->isAuto())->toBeTrue()
        ->and($invoice->total)->toBe('2500.00');

    // Seconde facture refusée (brouillon ou émise)…
    Livewire::actingAs($this->accountant)->test(InvoiceCreate::class)
        ->set('case_id', (string) $case->id)
        ->call('create')
        ->assertHasErrors('case_id');
    app(InvoiceService::class)->issue($invoice, $this->accountant);
    expect(fn () => builder()->forCase($case, $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);

    // … sauf après annulation par avoir.
    app(InvoiceService::class)->cancel($invoice->fresh(), 'Erreur de client', userWithRoles(Role::Admin));
    expect(builder()->forCase($case, $this->accountant)->total)->toBe('2500.00');
});

/*
| Scénario 5 : sur un dossier à 120 $/h avec 2 h 30 non facturées, la facture propose 300 $ ;
| à l'émission, ces heures passent « facturées » et la facture suivante ne les reprend pas.
*/
it('scénario 5 : taux horaire, 2 h 30 à 120 $ = 300 $, heures marquées facturées', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id, 'fee_type' => FeeType::Hourly, 'fee_amount' => '120']);
    $case->timeEntries()->createMany([
        ['user_id' => $lawyer->id, 'worked_on' => '2026-11-02', 'minutes' => 90, 'description' => 'Préparation', 'billable' => true],
        ['user_id' => $lawyer->id, 'worked_on' => '2026-11-05', 'minutes' => 60, 'description' => 'Échanges', 'billable' => true],
        ['user_id' => $lawyer->id, 'worked_on' => '2026-11-05', 'minutes' => 45, 'description' => 'Non facturable', 'billable' => false],
    ]);

    $first = builder()->forCase($case, $this->accountant);
    $line = $first->lines()->sole();
    expect($line->quantityLabel())->toBe('2,5')
        ->and($line->amount)->toBe('300.00')
        ->and($line->label)->toBe("Temps passé du 02/11/2026 au 05/11/2026 : 2,5 h au taux de 120,00\u{00A0}\$")
        ->and($first->period_start->toDateString())->toBe('2026-11-02')
        ->and($first->total)->toBe('300.00');

    app(InvoiceService::class)->issue($first, $this->accountant);
    expect(TimeEntry::query()->where('billable', true)->whereNull('invoice_id')->count())->toBe(0)
        ->and(TimeEntry::query()->where('invoice_id', $first->id)->count())->toBe(2);

    // Facture suivante : seulement les nouvelles heures.
    $case->timeEntries()->create(['user_id' => $lawyer->id, 'worked_on' => '2026-11-08', 'minutes' => 30, 'description' => 'Nouvelle', 'billable' => true]);
    $second = builder()->forCase($case, $this->accountant);
    expect($second->lines()->sole()->amount)->toBe('60.00');

    // Sans heure nouvelle : pas de ligne d'honoraires, seulement des lignes complémentaires.
    app(InvoiceService::class)->issue($second, $this->accountant);
    $third = builder()->forCase($case, $this->accountant);
    expect($third->lines()->count())->toBe(0);
    builder()->addManualLine($third, InvoiceLineType::CourtFee, 'Frais de greffe', '1', '25');
    expect($third->fresh()->total)->toBe('25.00');
});

it('refuse d\'émettre un brouillon dont les heures ont déjà été facturées ailleurs', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id, 'fee_type' => FeeType::Hourly, 'fee_amount' => '100']);
    $case->timeEntries()->create(['user_id' => $lawyer->id, 'worked_on' => '2026-11-02', 'minutes' => 60, 'description' => 'A', 'billable' => true]);

    $a = builder()->forCase($case, $this->accountant);
    $b = builder()->forCase($case, $this->accountant);
    app(InvoiceService::class)->issue($a, $this->accountant);

    expect(fn () => app(InvoiceService::class)->issue($b, $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($b->fresh()->status)->toBe(InvoiceStatus::Draft);

    builder()->refreshAutoLines($b->fresh());
    expect($b->fresh()->lines()->count())->toBe(0);
});

/*
| Scénario 6 : une réduction de 10 % sur 1 000 $ d'honoraires et 50 $ de débours donne 950 $.
*/
it('scénario 6 : la réduction ne touche pas les débours (1 000 $ - 10 % + 50 $ = 950 $)', function () {
    $case = LegalCase::factory()->create(['fee_type' => FeeType::Flat, 'fee_amount' => '1000']);
    $invoice = builder()->forCase($case, $this->accountant);

    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('lineType', 'disbursement')->set('lineLabel', 'Frais de descente')->set('lineQuantity', '1')->set('linePrice', '50')
        ->call('addLine')->assertHasNoErrors()
        ->set('discount', '10')
        ->call('saveOptions')->assertHasNoErrors()
        ->assertSee('Réduction 10 % (honoraires seuls)');

    $invoice->refresh();
    expect($invoice->fees_total)->toBe('1000.00')
        ->and($invoice->discount_amount)->toBe('100.00')
        ->and($invoice->other_total)->toBe('50.00')
        ->and($invoice->total)->toBe('950.00');
});

it('calcule la TVA de 16 % après réduction et jamais sur les débours', function () {
    $totals = InvoiceCalculator::totals([
        ['type' => InvoiceLineType::Fee, 'amount' => '1000.00'],
        ['type' => InvoiceLineType::FileFee, 'amount' => '100.00'],
        ['type' => InvoiceLineType::Disbursement, 'amount' => '50.00'],
        ['type' => InvoiceLineType::CourtFee, 'amount' => '30.00'],
    ], '10', true, '16');

    // Base taxable = 900 (honoraires nets) + 100 (frais de dossier) = 1 000 ; TVA = 160.
    expect($totals['taxable_base'])->toBe('1000.00')
        ->and($totals['vat_amount'])->toBe('160.00')
        ->and($totals['total'])->toBe('1240.00'); // 900 + 100 + 50 + 30 + 160
});

it('arrondit au centime sans flottant', function () {
    $totals = InvoiceCalculator::totals([['type' => InvoiceLineType::Fee, 'amount' => '333.33']], '12.5', true, '16');

    expect($totals['discount_amount'])->toBe('41.67')
        ->and($totals['vat_amount'])->toBe('46.67')   // 291,66 × 16 % = 46,6656
        ->and($totals['total'])->toBe('338.33')
        ->and(InvoiceCalculator::lineAmount('1.6667', '120.00'))->toBe('200.00');
});

it('refuse une réduction hors de 0 à 100 % et une ligne d\'honoraires saisie à la main', function () {
    $invoice = builder()->forCase(LegalCase::factory()->create(), $this->accountant);

    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('discount', '120')->call('saveOptions')->assertHasErrors('discount')
        ->set('lineType', 'fee')->set('lineLabel', 'Honoraires')->set('linePrice', '10')->call('addLine')->assertHasErrors('lineType');
});

/*
| Scénario 7 : un dossier en abonnement de 800 $ génère sa facture 30 jours après l'ouverture, une seule par période.
*/
it('scénario 7 : abonnement de 800 $, facture à J+30 puis tous les 30 jours, sans doublon', function () {
    $case = LegalCase::factory()->create([
        'title' => 'Congo Express, conseil juridique OHADA', 'fee_type' => FeeType::Subscription, 'fee_amount' => '800',
        'opened_on' => '2026-10-01', 'next_subscription_invoice_on' => '2026-10-31',
    ]);
    $billing = app(SubscriptionBillingService::class);

    expect($billing->run(Carbon::parse('2026-10-30')))->toHaveCount(0);

    $created = $billing->run(Carbon::parse('2026-10-31'));
    expect($created)->toHaveCount(1);
    $invoice = $created[0]->fresh('lines');
    expect($invoice->status)->toBe(InvoiceStatus::Draft)   // subscription_auto_issue = false
        ->and($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-10-30')
        ->and($invoice->lines[0]->label)->toBe('Congo Express, conseil juridique OHADA, abonnement du 01/10/2026 au 30/10/2026')
        ->and($invoice->total)->toBe('800.00')
        ->and($case->fresh()->next_subscription_invoice_on->toDateString())->toBe('2026-11-30');

    // Relancée le même jour : aucun doublon.
    expect($billing->run(Carbon::parse('2026-10-31')))->toHaveCount(0);

    // Une facture manuelle de la même période est refusée.
    $case->forceFill(['next_subscription_invoice_on' => '2026-10-31'])->save();
    expect($billing->run(Carbon::parse('2026-10-31')))->toHaveCount(0)
        ->and(fn () => builder()->forCase($case->fresh(), $this->accountant, [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-30')]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rattrape les périodes manquées et s\'arrête à la clôture du dossier', function () {
    $case = LegalCase::factory()->create(['fee_type' => FeeType::Subscription, 'fee_amount' => '800', 'opened_on' => '2026-08-01', 'next_subscription_invoice_on' => '2026-08-31']);
    $closed = LegalCase::factory()->create(['fee_type' => FeeType::Subscription, 'fee_amount' => '800', 'status' => 'closed', 'opened_on' => '2026-08-01', 'next_subscription_invoice_on' => '2026-08-31']);

    $this->artisan('lexcabinet:bill-subscriptions')->assertSuccessful();

    expect(Invoice::query()->where('case_id', $case->id)->count())->toBe(3)   // 31/08, 30/09, 30/10
        ->and(Invoice::query()->where('case_id', $closed->id)->count())->toBe(0);
});

it('émet directement les factures d\'abonnement si le paramètre est activé', function () {
    app(SettingsService::class)->set('subscription_auto_issue', true);
    LegalCase::factory()->create(['fee_type' => FeeType::Subscription, 'fee_amount' => '800', 'opened_on' => '2026-10-10', 'next_subscription_invoice_on' => '2026-11-09']);

    $created = app(SubscriptionBillingService::class)->run();

    expect($created[0]->status)->toBe(InvoiceStatus::Issued)->and($created[0]->number)->toBe('FAC-2026-00001');
});

it('génère une ligne de provision pour la convention provision + résultat', function () {
    $case = LegalCase::factory()->create(['fee_type' => FeeType::RetainerSuccess, 'fee_amount' => '600']);

    $a = builder()->forCase($case, $this->accountant);
    $b = builder()->forCase($case, $this->accountant); // nombre libre

    expect($a->lines()->sole()->label)->toBe("Provision sur honoraires, dossier {$case->reference}")
        ->and($b->total)->toBe('600.00');
});

it('facture une consultation honorée sans dossier, au tarif par défaut (FAC-06)', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-06 10:00'))->create([
        'lawyer_id' => $lawyer->id, 'type' => AppointmentType::Consultation, 'status' => AppointmentStatus::Honored,
    ]);

    Livewire::actingAs($this->accountant)->test(InvoiceCreate::class)
        ->set('mode', 'consultation')->set('appointment_id', (string) $appointment->id)
        ->call('create')->assertHasNoErrors();

    $invoice = Invoice::query()->with('lines')->sole();
    expect($invoice->case_id)->toBeNull()
        ->and($invoice->client_id)->toBe($appointment->client_id)
        ->and($invoice->currency)->toBe(Currency::USD)
        ->and($invoice->lines[0]->type)->toBe(InvoiceLineType::Consultation)
        ->and($invoice->lines[0]->label)->toBe('Consultation du 06/11/2026 avec '.$lawyer->name)
        ->and($invoice->total)->toBe('100.00')
        ->and($appointment->fresh()->invoice_id)->toBe($invoice->id);

    // Pas deux fois ; et la suppression du brouillon la rend de nouveau facturable.
    expect(fn () => builder()->forConsultation($appointment->fresh(), $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);
    builder()->deleteDraft($invoice);
    expect($appointment->fresh()->invoice_id)->toBeNull();

    // Une consultation non honorée ne se facture pas.
    $planned = Appointment::factory()->create(['lawyer_id' => $lawyer->id, 'type' => AppointmentType::Consultation]);
    expect(fn () => builder()->forConsultation($planned, $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);
});
