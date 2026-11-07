<?php

use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\EmailStatus;
use App\Enums\FeeType;
use App\Enums\InvoiceStatus;
use App\Enums\MovementStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Enums\TreasuryType;
use App\Livewire\Cash\CashDesk;
use App\Livewire\Cash\DisbursementForm;
use App\Livewire\Cash\ReceiptForm;
use App\Models\AuditActivity;
use App\Models\CashMovement;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\TreasuryAccount;
use App\Services\CashService;
use App\Services\ExchangeRateService;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    app(ExchangeRateService::class)->record(today(), '2800', RateSource::Manual);

    TreasuryAccount::query()->create(['name' => 'Caisse USD', 'type' => TreasuryType::Cash, 'currency' => 'USD', 'ledger_code' => '5711']);
    TreasuryAccount::query()->create(['name' => 'Caisse CDF', 'type' => TreasuryType::Cash, 'currency' => 'CDF', 'ledger_code' => '5712']);
    TreasuryAccount::query()->create(['name' => 'Rawbank USD', 'type' => TreasuryType::Bank, 'currency' => 'USD', 'ledger_code' => '521']);
    TreasuryAccount::query()->create(['name' => 'Mobile money', 'type' => TreasuryType::MobileMoney, 'currency' => 'USD', 'ledger_code' => '5216']);

    $this->cashier = userWithRoles(Role::Cashier);
    $this->cash = app(CashService::class);
});

afterEach(fn () => Carbon::setTestNow());

function issuedInvoice(string $amount, string $currency = 'USD', ?Client $client = null): Invoice
{
    $case = LegalCase::factory()->create(['fee_amount' => $amount, 'currency' => $currency, 'fee_type' => FeeType::Flat] + ($client ? ['client_id' => $client->id] : []));
    $accountant = userWithRoles(Role::Accountant);
    $draft = app(InvoiceBuilder::class)->forCase($case, $accountant);

    return app(InvoiceService::class)->issue($draft, $accountant, sendEmail: false)->fresh();
}

function openCash(string $usd = '1000', string $cdf = '2000000'): void
{
    $cashier = test()->cashier;
    app(CashService::class)->openSession('0', '0', $cashier);
    // Fonds initiaux : ajustement de démonstration via un solde d'ouverture.
    foreach (['USD' => $usd, 'CDF' => $cdf] as $cur => $amount) {
        CashMovement::query()->create([
            'type' => CashMovementType::OpeningBalance, 'direction' => 'in', 'status' => MovementStatus::Validated,
            'occurred_at' => now(), 'treasury_account_id' => app(CashService::class)->cashAccount(Currency::from($cur))->id,
            'currency' => $cur, 'amount' => $amount, 'exchange_rate' => '2800', 'label' => 'Solde initial',
        ]);
    }
}

/*
| Scénario 8 : une facture de 300 USD payée 150 USD en espèces puis l'équivalent de 150 USD en CDF
| passe au statut « payée », chaque paiement portant son taux.
*/
it('scénario 8 : 150 $ en espèces puis 420 000 FC au taux de 2 800 soldent une facture de 300 $', function () {
    openCash();
    $invoice = issuedInvoice('300');

    $first = $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '150', 'mode' => 'cash'], $this->cashier);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->fresh()->balanceDue())->toBe('150.00');

    Livewire::actingAs($this->cashier)->test(ReceiptForm::class)
        ->set('client_id', (string) $invoice->client_id)
        ->set('invoice_id', (string) $invoice->id)
        ->set('currency', 'CDF')
        ->set('amount', '420000')
        ->set('mode', 'cash')
        ->set('exchange_rate', '2800')
        ->assertSee('150,00')
        ->call('save')
        ->assertHasNoErrors();

    $second = CashMovement::query()->where('currency', 'CDF')->where('type', 'receipt')->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_amount)->toBe('300.00')
        ->and($first->exchange_rate)->toBe('2800.0000')
        ->and($second->exchange_rate)->toBe('2800.0000')
        ->and($second->invoice_amount)->toBe('150.00')
        ->and($first->receipt_number)->toBe('RC-2026-0001')
        ->and($second->receipt_number)->toBe('RC-2026-0002')
        ->and($this->cash->cashBalances()['CDF'])->toBe('2420000.00');
});

it('refuse un paiement qui dépasse le reste dû, conversion comprise', function () {
    openCash();
    $invoice = issuedInvoice('300');

    expect(fn () => $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'CDF', 'amount' => '840028', 'mode' => 'cash', 'exchange_rate' => '2800'], $this->cashier))
        ->toThrow(ValidationException::class);
    expect($invoice->fresh()->paid_amount)->toBe('0.00');
});

it('convertit un paiement en USD sur une facture en CDF', function () {
    openCash();
    $invoice = issuedInvoice('2800000', 'CDF');

    $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '250', 'mode' => 'cash', 'exchange_rate' => '2800'], $this->cashier);

    expect($invoice->fresh()->paid_amount)->toBe('700000.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PartiallyPaid);
});

it('émet un reçu numéroté, l\'envoie au client en PDF et le trace', function () {
    openCash();
    $client = Client::factory()->create(['email' => 'client@example.cd', 'email_consent' => true]);
    $invoice = issuedInvoice('500', client: $client);

    $movement = $this->cash->recordReceipt(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '200', 'mode' => 'transfer', 'reference' => 'VIR-1'], $this->cashier);

    expect($movement->account->name)->toBe('Rawbank USD')
        ->and($movement->cash_session_id)->not->toBeNull();
    $log = EmailLog::query()->where('template_key', 'receipt')->sole();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->body)->toContain("200,00\u{00A0}$")->toContain('Virement')->toContain('RC-2026-0001');

    $this->actingAs($this->cashier)->get(route('cash.receipt-pdf', $movement))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(userWithRoles(Role::Secretary))->get(route('cash.receipt-pdf', $movement))->assertForbidden();
    expect(AuditActivity::query()->where('description', 'like', 'Encaissement RC-2026-0001%')->where('causer_id', $this->cashier->id)->exists())->toBeTrue();
});

it('enregistre une provision sans facture puis l\'impute sur une facture du même client (FAC-13)', function () {
    $client = Client::factory()->create();
    $advance = $this->cash->recordReceipt(['client_id' => $client->id, 'currency' => 'USD', 'amount' => '100', 'mode' => 'mobile_money', 'reference' => 'M-Pesa 7K3P'], $this->cashier);
    expect($advance->isAdvance())->toBeTrue()->and($advance->label)->toBe('Provision sur honoraires');

    $invoice = issuedInvoice('300', client: $client);
    $this->cash->allocateAdvance($advance, $invoice, '60', $this->cashier);

    expect($invoice->fresh()->paid_amount)->toBe('60.00')
        ->and($this->cash->advanceAvailable($advance))->toBe('40.00')
        ->and(fn () => $this->cash->allocateAdvance($advance, $invoice, '50', $this->cashier))->toThrow(ValidationException::class)
        ->and(fn () => $this->cash->allocateAdvance($advance, issuedInvoice('100'), '10', $this->cashier))->toThrow(ValidationException::class); // autre client
});

it('exige une session ouverte pour toute opération en espèces, pas pour la banque', function () {
    $invoice = issuedInvoice('300');

    expect(fn () => $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '100', 'mode' => 'cash'], $this->cashier))
        ->toThrow(ValidationException::class);

    $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '100', 'mode' => 'transfer', 'reference' => 'VIR'], $this->cashier);
    expect($invoice->fresh()->paid_amount)->toBe('100.00');
});

/*
| Scénario 9 : la clôture de caisse affiche le solde théorique et l'écart par devise ;
| un écart sans motif bloque la clôture.
*/
it('scénario 9 : la clôture affiche solde théorique et écart, et refuse un écart sans motif', function () {
    openCash('1000', '2000000');
    $this->cash->recordReceipt(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'amount' => '250', 'mode' => 'cash'], $this->cashier);

    $desk = Livewire::actingAs($this->cashier)->test(CashDesk::class)
        ->call('show', 'close')
        ->set('countUsd', '1240')
        ->set('countCdf', '2000000')
        ->assertSee("1\u{00A0}250,00\u{00A0}$")        // solde théorique USD
        ->assertSee("-10,00\u{00A0}$");                 // écart USD

    $desk->call('closeSession')->assertHasErrors('reason');
    expect($this->cash->currentSession())->not->toBeNull();

    $desk->set('reason', 'Erreur de rendu de monnaie')->call('closeSession')->assertHasNoErrors();

    $session = \App\Models\CashSession::query()->sole();
    expect($session->status)->toBe('closed')
        ->and($session->expected_usd)->toBe('1250.00')
        ->and($session->counted_usd)->toBe('1240.00')
        ->and($session->difference_usd)->toBe('-10.00')
        ->and($session->difference_cdf)->toBe('0.00')
        ->and($session->difference_reason)->toBe('Erreur de rendu de monnaie');

    // L'écart génère un mouvement d'ajustement : les livres égalent le comptage.
    $adjustment = CashMovement::query()->where('type', CashMovementType::Adjustment->value)->sole();
    expect($adjustment->direction)->toBe('out')
        ->and($adjustment->amount)->toBe('10.00')
        ->and($this->cash->cashBalances()['USD'])->toBe('1240.00');
});

it('clôture sans motif quand il n\'y a pas d\'écart, et refuse une seconde ouverture simultanée', function () {
    openCash('100', '0');
    expect(fn () => $this->cash->openSession('0', '0', $this->cashier))->toThrow(ValidationException::class);

    $this->cash->closeSession($this->cash->currentSession(), '100', '0', null, $this->cashier);
    expect(CashMovement::query()->where('type', 'adjustment')->count())->toBe(0);
});

/*
| Scénario 10 : un décaissement au-dessus du seuil reste « en attente » tant que l'administrateur ne l'a pas validé.
*/
it('scénario 10 : un décaissement au-delà du seuil attend la validation de l\'administrateur', function () {
    openCash('2000', '0');

    Livewire::actingAs($this->cashier)->test(DisbursementForm::class)
        ->set('category', 'equipment')->set('beneficiary', 'Kin Informatique')->set('label', 'Ordinateur portable')
        ->set('voucher', 'KI-2291')->set('currency', 'USD')->set('amount', '820')->set('mode', 'cash')
        ->call('save')->assertHasNoErrors();

    $movement = CashMovement::query()->where('type', 'disbursement')->sole();
    expect($movement->status)->toBe(MovementStatus::Pending)
        ->and($this->cash->cashBalances()['USD'])->toBe('2000.00');   // pas encore sorti

    // Le caissier ne peut pas valider.
    Livewire::actingAs($this->cashier)->test(CashDesk::class)->call('approve', $movement->id)->assertForbidden();

    $admin = userWithRoles(Role::Admin);
    Livewire::actingAs($admin)->test(CashDesk::class)->assertSee('Décaissements à valider')->call('approve', $movement->id);

    expect($movement->fresh()->status)->toBe(MovementStatus::Validated)
        ->and($movement->fresh()->validated_by)->toBe($admin->id)
        ->and($this->cash->cashBalances()['USD'])->toBe('1180.00');
});

it('applique le seuil à l\'équivalent CDF, et passe directement sous le seuil', function () {
    openCash('1000', '3000000');
    $data = ['category' => 'transport', 'beneficiary' => 'Taxi', 'label' => 'Course', 'currency' => 'CDF', 'mode' => 'cash'];

    $small = $this->cash->recordDisbursement($data + ['amount' => '1400000'], $this->cashier);   // 500 $ : au seuil
    $big = $this->cash->recordDisbursement($data + ['amount' => '1400280'], $this->cashier);     // 500,10 $
    expect($small->status)->toBe(MovementStatus::Validated)
        ->and($big->status)->toBe(MovementStatus::Pending);

    // L'administrateur peut aussi refuser, avec un motif.
    $this->cash->rejectDisbursement($big, 'Dépense non prévue', userWithRoles(Role::Admin));
    expect($big->fresh()->status)->toBe(MovementStatus::Rejected)
        ->and($this->cash->cashBalances()['CDF'])->toBe('1600000.00');
});

it('refuse une sortie d\'espèces supérieure au solde de la caisse', function () {
    openCash('100', '0');

    expect(fn () => $this->cash->recordDisbursement(['category' => 'supplies', 'beneficiary' => 'Papeterie', 'label' => 'Ramettes', 'currency' => 'USD', 'amount' => '150', 'mode' => 'cash'], $this->cashier))
        ->toThrow(ValidationException::class);
});

it('enregistre un change interne : un mouvement avec montant cédé et montant reçu', function () {
    openCash('1000', '0');

    Livewire::actingAs($this->cashier)->test(CashDesk::class)
        ->call('show', 'exchange')
        ->set('fxFrom', 'USD')->set('fxAmount', '200')->set('fxRate', '2800')
        ->assertSee("560\u{00A0}000\u{00A0}FC")
        ->call('exchange')->assertHasNoErrors();

    $fx = CashMovement::query()->where('type', 'exchange')->sole();
    expect($fx->amount)->toBe('200.00')
        ->and($fx->counter_amount)->toBe('560000.00')
        ->and($fx->exchange_rate)->toBe('2800.0000')
        ->and($this->cash->cashBalances())->toBe(['USD' => '800.00', 'CDF' => '560000.00']);
});

it('corrige par contre-passation, sans supprimer ni modifier (CAI-08)', function () {
    openCash('1000', '0');
    $invoice = issuedInvoice('300');
    $receipt = $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '300', 'mode' => 'cash'], $this->cashier);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    expect(fn () => $receipt->update(['amount' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $receipt->delete())->toThrow(LogicException::class);

    Livewire::actingAs($this->cashier)->test(CashDesk::class)
        ->call('show', 'reverse', $receipt->id)
        ->call('reverse')->assertHasErrors('reason')
        ->set('reason', 'Erreur de facture')->call('reverse')->assertHasNoErrors();

    $reversal = CashMovement::query()->where('type', 'reversal')->sole();
    expect($reversal->reverses_id)->toBe($receipt->id)
        ->and($reversal->direction)->toBe('out')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->paid_amount)->toBe('0.00')
        ->and($this->cash->cashBalances()['USD'])->toBe('1000.00')
        ->and(fn () => $this->cash->reverse($receipt, 'Encore', $this->cashier))->toThrow(ValidationException::class);

    // Un change contre-passé remet les deux devises en l'état.
    $fx = $this->cash->exchange(Currency::USD, '100', '2800', $this->cashier);
    $this->cash->reverse($fx, 'Erreur de sens', $this->cashier);
    expect($this->cash->cashBalances())->toBe(['USD' => '1000.00', 'CDF' => '0.00']);
});

it('refuse l\'avoir sur une facture encaissée', function () {
    openCash();
    $invoice = issuedInvoice('300');
    $this->cash->recordReceipt(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'currency' => 'USD', 'amount' => '50', 'mode' => 'cash'], $this->cashier);

    expect(userWithRoles(Role::Admin)->can('cancel', $invoice->fresh()))->toBeTrue()
        ->and(fn () => app(InvoiceService::class)->cancel($invoice->fresh(), 'Motif', userWithRoles(Role::Admin)))->toThrow(ValidationException::class);
});

it('applique les droits de la caisse par rôle', function () {
    // Comptable : lecture seule.
    $accountant = userWithRoles(Role::Accountant);
    $this->actingAs($accountant)->get(route('cash.index'))->assertOk()->assertDontSee('Ouvrir la caisse');
    $this->actingAs($accountant)->get(route('cash.receipt'))->assertForbidden();
    Livewire::actingAs($accountant)->test(CashDesk::class)->call('show', 'open')->assertForbidden();

    // Secrétaire et avocat : aucun accès (le secrétariat n'encaisse pas).
    foreach ([Role::Secretary, Role::Lawyer] as $role) {
        $this->actingAs(userWithRoles($role))->get(route('cash.index'))->assertForbidden();
    }

    // Secrétaire + caissière : peut encaisser (droits additionnés).
    $both = userWithRoles(Role::Secretary, Role::Cashier);
    $this->actingAs($both)->get(route('cash.receipt'))->assertOk();
});

it('affiche le solde de caisse et les encaissements du jour sur le tableau de bord (DB-02)', function () {
    openCash('1000', '500000');
    $this->cash->recordReceipt(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'amount' => '75', 'mode' => 'cash'], $this->cashier);

    $this->actingAs($this->cashier)->get(route('dashboard'))
        ->assertSee("1\u{00A0}075,00\u{00A0}$", false)
        ->assertSee("500\u{00A0}000\u{00A0}FC", false)
        ->assertSee("75,00\u{00A0}$", false);
});
