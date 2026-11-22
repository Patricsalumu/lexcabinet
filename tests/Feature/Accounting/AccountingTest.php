<?php

use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Journal;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Enums\TreasuryType;
use App\Livewire\Accounting\AccountingIndex;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Livewire\Accounting\ManualEntry;
use App\Models\Account;
use App\Models\AuditActivity;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\LegalCase;
use App\Models\TreasuryAccount;
use App\Services\AccountingPoster;
use App\Services\AccountingReports;
use App\Services\CashService;
use App\Services\ExchangeRateService;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    app(ExchangeRateService::class)->record(today(), '2800', RateSource::Manual);
    foreach ([['Caisse USD', 'cash', 'USD', '5711'], ['Caisse CDF', 'cash', 'CDF', '5712'], ['Rawbank USD', 'bank', 'USD', '521'], ['Mobile money', 'mobile_money', 'USD', '5216']] as [$n, $t, $c, $l]) {
        TreasuryAccount::query()->create(['name' => $n, 'type' => $t, 'currency' => $c, 'ledger_code' => $l]);
    }
    $this->accountant = userWithRoles(Role::Accountant);
    $this->cashier = userWithRoles(Role::Cashier);
});

afterEach(fn () => Carbon::setTestNow());

function entryFor(object $source): JournalEntry
{
    return JournalEntry::query()->with('lines.account')
        ->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->latest('id')->firstOrFail();
}

/** @return array<string, array{0: string, 1: string}> code => [débit, crédit] */
function linesOf(JournalEntry $entry): array
{
    return $entry->lines->mapWithKeys(fn ($l) => [$l->account->code => [(string) $l->debit, (string) $l->credit]])->all();
}

function issue(string $amount, string $currency = 'USD', array $vat = []): Invoice
{
    $case = LegalCase::factory()->create(['fee_type' => FeeType::Flat, 'fee_amount' => $amount, 'currency' => $currency]);
    $draft = app(InvoiceBuilder::class)->forCase($case, test()->accountant);
    if ($vat) {
        app(\App\Services\SettingsService::class)->set('vat.enabled', true);
        app(InvoiceBuilder::class)->updateOptions($draft, '0', true, 15, null);
    }

    return app(InvoiceService::class)->issue($draft, test()->accountant, false)->fresh();
}

it('comptabilise l\'émission : 411 au débit, 706 et 4431 au crédit', function () {
    $invoice = issue('1000', vat: [true]);
    $entry = entryFor($invoice);

    expect($entry->journal)->toBe(Journal::Sales)
        ->and($entry->number)->toBe('VT-2026-00001')
        ->and($entry->reference)->toBe($invoice->number)
        ->and(linesOf($entry))->toBe(['411' => ['1160.00', '0.00'], '706' => ['0.00', '1000.00'], '4431' => ['0.00', '160.00']]);
});

it('convertit une facture en CDF au taux de l\'opération et garde le montant d\'origine (CPT-04)', function () {
    $invoice = issue('2800000', 'CDF');
    $line = entryFor($invoice)->lines->first(fn ($l) => $l->account->code === '411');

    expect($line->debit)->toBe('1000.00')
        ->and($line->original_currency)->toBe(Currency::CDF)
        ->and($line->original_amount)->toBe('2800000.00')
        ->and($line->exchange_rate)->toBe('2800.0000');
});

it('comptabilise l\'avoir à l\'inverse de la facture', function () {
    $invoice = issue('500');
    $credit = app(InvoiceService::class)->cancel($invoice, 'Erreur', userWithRoles(Role::Admin));

    expect(linesOf(entryFor($credit)))->toBe(['706' => ['500.00', '0.00'], '411' => ['0.00', '500.00']]);
});

it('comptabilise encaissements, provisions, imputations, décaissements, change et écarts', function () {
    $cash = app(CashService::class);
    $cash->openSession('0', '0', $this->cashier);
    $invoice = issue('300');

    $usd = $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '150', 'mode' => 'cash'], $this->cashier, false);
    $cdf = $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'CDF', 'amount' => '420000', 'mode' => 'cash'], $this->cashier, false);
    expect(entryFor($usd)->journal)->toBe(Journal::Cash)
        ->and(linesOf(entryFor($usd)))->toBe(['5711' => ['150.00', '0.00'], '411' => ['0.00', '150.00']])
        ->and(linesOf(entryFor($cdf)))->toBe(['5712' => ['150.00', '0.00'], '411' => ['0.00', '150.00']]);

    $advance = $cash->recordReceipt(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'amount' => '100', 'mode' => 'mobile_money', 'reference' => 'MP'], $this->cashier, false);
    expect(entryFor($advance)->journal)->toBe(Journal::Bank)
        ->and(linesOf(entryFor($advance)))->toBe(['5216' => ['100.00', '0.00'], '4191' => ['0.00', '100.00']]);

    $other = issue('80');
    Invoice::query()->whereKey($other->id)->update(['client_id' => $advance->client_id]); // même client pour l'imputation
    $allocation = $cash->allocateAdvance($advance, $other->fresh(), '80', $this->cashier);
    expect(linesOf(entryFor($allocation)))->toBe(['4191' => ['80.00', '0.00'], '411' => ['0.00', '80.00']]);

    $rent = $cash->recordDisbursement(['category' => 'rent', 'beneficiary' => 'SCI', 'label' => 'Loyer', 'currency' => 'USD', 'amount' => '100', 'mode' => 'cash'], $this->cashier);
    expect(linesOf(entryFor($rent)))->toBe(['622' => ['100.00', '0.00'], '5711' => ['0.00', '100.00']]);

    $fx = $cash->exchange(Currency::USD, '20', '2800', $this->cashier);
    expect(linesOf(entryFor($fx)))->toBe(['5712' => ['20.00', '0.00'], '5711' => ['0.00', '20.00']]);

    $cash->closeSession($cash->currentSession(), '25', '476000', 'Erreur de rendu', $this->cashier);  // théorique : 30 $ et 476 000 FC
    $adj = CashMovement::query()->where('type', 'adjustment')->sole();
    expect(linesOf(entryFor($adj)))->toBe(['658' => ['5.00', '0.00'], '5711' => ['0.00', '5.00']]);
});

it('ne comptabilise un décaissement en attente qu\'à sa validation', function () {
    $cash = app(CashService::class);
    $cash->openSession('0', '0', $this->cashier);
    CashMovement::query()->create(['type' => 'opening_balance', 'direction' => 'in', 'status' => 'validated', 'occurred_at' => now(), 'treasury_account_id' => $cash->cashAccount(Currency::USD)->id, 'currency' => 'USD', 'amount' => '2000', 'exchange_rate' => '2800', 'label' => 'Fonds']);

    $pc = $cash->recordDisbursement(['category' => 'equipment', 'beneficiary' => 'Kin Informatique', 'label' => 'Ordinateur', 'currency' => 'USD', 'amount' => '820', 'mode' => 'cash'], $this->cashier);
    expect(JournalEntry::query()->where('source_id', $pc->id)->where('source_type', $pc->getMorphClass())->exists())->toBeFalse();

    $cash->validateDisbursement($pc, userWithRoles(Role::Admin));
    expect(linesOf(entryFor($pc)))->toBe(['6054' => ['820.00', '0.00'], '5711' => ['0.00', '820.00']]);
});

it('contre-passe l\'écriture d\'un mouvement contre-passé', function () {
    $cash = app(CashService::class);
    $invoice = issue('300');
    $receipt = $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '300', 'mode' => 'transfer', 'reference' => 'V'], $this->cashier, false);

    $reversal = $cash->reverse($receipt, 'Erreur', $this->cashier);
    $entry = entryFor($reversal);

    expect($entry->reverses_id)->toBe(entryFor($receipt)->id)
        ->and(linesOf($entry))->toBe(['521' => ['0.00', '300.00'], '411' => ['300.00', '0.00']]);
});

/*
| Scénario 11 : chaque facture émise et chaque mouvement de caisse produit une écriture équilibrée ;
| la balance du mois est équilibrée.
*/
it('scénario 11 : écritures équilibrées pour chaque facture et mouvement, balance du mois équilibrée', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    // Les données de démonstration appartiennent au cabinet Mukendi : le test s'y place.
    app(\App\Support\Tenancy\CurrentFirm::class)->set(\App\Models\Firm::query()->where('name', 'Cabinet Mukendi & Associés')->sole());

    // Toutes les opérations (données de démonstration reprises + opérations du test) ont leur écriture.
    $cash = app(CashService::class);
    $invoice = Invoice::query()->where('number', 'like', 'FAC-%-00117')->firstOrFail();
    $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'CDF', 'amount' => '420000', 'mode' => 'cash', 'exchange_rate' => '2800'], userWithRoles(Role::Cashier), false);

    $issued = Invoice::query()->whereNotNull('number')->get();
    $validated = CashMovement::query()->where('status', 'validated')->get();

    foreach ($issued as $i) {
        expect(JournalEntry::query()->where('source_type', $i->getMorphClass())->where('source_id', $i->id)->where('source_event', 'issue')->exists())->toBeTrue("{$i->number} sans écriture");
    }
    foreach ($validated as $m) {
        expect(JournalEntry::query()->where('source_type', $m->getMorphClass())->where('source_id', $m->id)->exists())->toBeTrue("mouvement {$m->id} sans écriture");
    }

    foreach (JournalEntry::query()->with('lines')->get() as $entry) {
        $d = $entry->lines->reduce(fn ($s, $l) => bcadd($s, (string) $l->debit, 2), '0');
        $c = $entry->lines->reduce(fn ($s, $l) => bcadd($s, (string) $l->credit, 2), '0');
        expect($d)->toBe($c, "écriture {$entry->number} déséquilibrée");
    }

    $balance = app(AccountingReports::class)->trialBalance(today()->startOfMonth()->subMonths(2), today()->endOfMonth());
    expect($balance['balanced'])->toBeTrue()
        ->and($balance['debit'])->toBe($balance['credit']);

    // La reprise est idempotente.
    expect(app(AccountingPoster::class)->backfill())->toBe(0);
});

it('refuse une écriture déséquilibrée ou sur un compte inconnu', function () {
    $ledger = app(LedgerService::class);

    expect(fn () => $ledger->post(Journal::Misc, today(), 'Test', [['code' => '622', 'debit' => '100'], ['code' => '5711', 'credit' => '90']]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $ledger->post(Journal::Misc, today(), 'Test', [['code' => '999', 'debit' => '10'], ['code' => '5711', 'credit' => '10']]))
        ->toThrow(ValidationException::class);
});

it('saisit une écriture manuelle équilibrée, puis la contre-passe (CPT-03)', function () {
    Livewire::actingAs($this->accountant)->test(ManualEntry::class)
        ->set('journal', 'OD')->set('label', 'Reclassement')
        ->set('lines.0.code', '638')->set('lines.0.debit', '50')
        ->set('lines.1.code', '618')->set('lines.1.credit', '40')
        ->call('save')->assertHasErrors('lines')
        ->set('lines.1.credit', '50')
        ->call('save')->assertHasNoErrors();

    $entry = JournalEntry::query()->where('is_manual', true)->sole();
    expect($entry->number)->toBe('OD-2026-00001')
        ->and(fn () => $entry->update(['label' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);

    Livewire::actingAs($this->accountant)->test(AccountingIndex::class)
        ->call('askReverse', $entry->id)->set('reverseReason', 'Erreur de compte')->call('reverseEntry')->assertHasNoErrors();
    expect(linesOf(JournalEntry::query()->where('reverses_id', $entry->id)->sole()))->toBe(['638' => ['0.00', '50.00'], '618' => ['50.00', '0.00']]);
});

it('produit grand livre, balance et compte de résultat (CPT-05)', function () {
    $cash = app(CashService::class);
    $invoice = issue('1000');
    $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '400', 'mode' => 'transfer', 'reference' => 'V'], $this->cashier, false);
    $cash->openSession('0', '0', $this->cashier);
    CashMovement::query()->create(['type' => 'opening_balance', 'direction' => 'in', 'status' => 'validated', 'occurred_at' => now(), 'treasury_account_id' => $cash->cashAccount(Currency::USD)->id, 'currency' => 'USD', 'amount' => '500', 'exchange_rate' => '2800', 'label' => 'Fonds']);
    $cash->recordDisbursement(['category' => 'transport', 'beneficiary' => 'Taxi', 'label' => 'Course', 'currency' => 'USD', 'amount' => '30', 'mode' => 'cash'], $this->cashier);

    $reports = app(AccountingReports::class);
    $from = today()->startOfMonth();
    $to = today()->endOfMonth();

    $ledger = collect($reports->generalLedger($from, $to))->keyBy(fn ($a) => $a['account']->code);
    expect($ledger['411']['debit'])->toBe('1000.00')
        ->and($ledger['411']['credit'])->toBe('400.00')
        ->and($ledger['411']['closing'])->toBe('600.00');

    $result = $reports->incomeStatement($from, $to);
    expect($result['total_products'])->toBe('1000.00')
        ->and($result['total_charges'])->toBe('30.00')
        ->and($result['result'])->toBe('970.00');

    Livewire::actingAs($this->accountant)->test(AccountingIndex::class)
        ->set('tab', 'balance')->assertSee('La balance est équilibrée')
        ->set('tab', 'result')->assertSee('Honoraires et prestations de services')
        ->set('tab', 'ledger')->assertSee('Clients');
});

it('exporte tous les états en Excel et en PDF (CPT-07)', function () {
    issue('1000');

    foreach (['journal', 'ledger', 'balance', 'result'] as $report) {
        $this->actingAs($this->accountant)->get(route('accounting.export', ['report' => $report, 'format' => 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->accountant)->get(route('accounting.export', ['report' => $report, 'format' => 'xlsx']))
            ->assertOk()->assertDownload();
    }
});

/*
| CPT-06 : une période clôturée n'accepte plus d'écriture.
*/
it('clôture un mois écoulé et y refuse toute nouvelle écriture', function () {
    $admin = userWithRoles(Role::Admin);
    Carbon::setTestNow('2026-10-20 10:00');
    app(ExchangeRateService::class)->record(today(), '2790', RateSource::Manual);
    $october = issue('500');
    Carbon::setTestNow('2026-11-09 09:00');

    // Le mois en cours ne se clôture pas ; le comptable ne clôture pas.
    expect(fn () => app(LedgerService::class)->closePeriod(2026, 11, $admin))->toThrow(ValidationException::class);
    Livewire::actingAs($this->accountant)->test(AccountingIndex::class)->call('closePeriod', 2026, 10)->assertForbidden();

    Livewire::actingAs($admin)->test(AccountingIndex::class)->set('tab', 'periods')->call('closePeriod', 2026, 10)->assertHasNoErrors();

    expect(fn () => app(LedgerService::class)->post(Journal::Misc, '2026-10-31', 'Tardive', [['code' => '638', 'debit' => '10'], ['code' => '5711', 'credit' => '10']]))
        ->toThrow(ValidationException::class)
        // Un avoir daté d'aujourd'hui (novembre) reste possible sur une facture d'octobre.
        ->and(app(InvoiceService::class)->cancel($october, 'Erreur', $admin)->issue_date->month)->toBe(11);

    expect(AuditActivity::query()->where('description', 'Période Octobre 2026 clôturée')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('gère le plan comptable : ajout, renommage, désactivation ; pas de suppression d\'un compte mouvementé', function () {
    Livewire::actingAs($this->accountant)->test(ChartOfAccounts::class)
        ->set('code', '6281')->set('label', 'Internet')->call('add')->assertHasNoErrors()
        ->set('code', '8000')->set('label', 'Hors classes')->call('add')->assertHasErrors('code');

    expect(Account::query()->where('code', '6281')->value('class'))->toBe(6);

    issue('100');
    expect(fn () => Account::query()->where('code', '411')->first()->delete())->toThrow(LogicException::class);
});

it('réserve la comptabilité au comptable et à l\'administrateur', function (Role $role) {
    $this->actingAs(userWithRoles($role))->get(route('accounting.index'))->assertForbidden();
    $this->actingAs(userWithRoles($role))->get(route('accounting.export', ['report' => 'balance', 'format' => 'pdf']))->assertForbidden();
})->with([Role::Lawyer, Role::Secretary, Role::Cashier]);

/*
| Scénario 14 : le journal d'audit montre l'auteur et l'heure de chacune des actions précédentes.
*/
it('scénario 14 : chaque action métier figure au journal d\'audit avec son auteur et son heure', function () {
    $cash = app(CashService::class);
    $cash->openSession('0', '0', $this->cashier);
    $invoice = issue('300');
    $cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '300', 'mode' => 'cash'], $this->cashier, false);
    $cash->closeSession($cash->currentSession(), '300', '0', null, $this->cashier);

    $expected = [
        [$this->accountant, 'Facture FAC-2026-00001 émise%'],
        [$this->accountant, 'Écriture VT-2026-00001%'],
        [$this->cashier, 'Ouverture de caisse%'],
        [$this->cashier, 'Encaissement RC-2026-0001%'],
        [$this->cashier, 'Écriture CA-2026-00001%'],
        [$this->cashier, 'Clôture de caisse sans écart'],
    ];

    foreach ($expected as [$user, $description]) {
        $log = AuditActivity::query()->where('description', 'like', $description)->first();
        expect($log)->not->toBeNull("« {$description} » absent du journal")
            ->and($log->causer_id)->toBe($user->id)
            ->and($log->created_at->toDateTimeString())->toBe('2026-11-09 09:00:00');
    }

    Livewire::actingAs(userWithRoles(Role::Admin))->test(\App\Livewire\Admin\AuditLog::class)
        ->assertSee('Facture FAC-2026-00001 émise')
        ->assertSee($this->cashier->name)
        ->assertSee('09/11/2026 09:00:00');
});
