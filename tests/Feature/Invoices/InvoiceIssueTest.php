<?php

use App\Enums\EmailStatus;
use App\Enums\FeeType;
use App\Enums\InvoiceStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Invoices\InvoiceShow;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\ExchangeRateService;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
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

function draftFor(array $caseAttributes = [], ?Client $client = null): Invoice
{
    $case = LegalCase::factory()->create($caseAttributes + ($client ? ['client_id' => $client->id] : []));

    return app(InvoiceBuilder::class)->forCase($case, test()->accountant);
}

it('émet avec un numéro continu, le taux du jour et l\'échéance, puis envoie le PDF par e-mail', function () {
    $client = Client::factory()->create(['email' => 'direction@kivuagro.example', 'email_consent' => true]);
    $first = draftFor(['fee_amount' => '2500'], $client);
    $second = draftFor(['fee_amount' => '800'], $client);

    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $first])
        ->set('terms', '30')->call('saveOptions')
        ->call('issue')->assertHasNoErrors();
    app(InvoiceService::class)->issue($second, $this->accountant);

    $first->refresh();
    expect($first->number)->toBe('FAC-2026-00001')
        ->and($second->fresh()->number)->toBe('FAC-2026-00002')
        ->and($first->status)->toBe(InvoiceStatus::Issued)
        ->and($first->exchange_rate)->toBe('2800.0000')
        ->and($first->issue_date->toDateString())->toBe('2026-11-09')
        ->and($first->due_date->toDateString())->toBe('2026-12-09')
        ->and($first->issued_by)->toBe($this->accountant->id);

    $log = EmailLog::query()->where('template_key', 'invoice')->where('related_id', $first->id)->sole();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->subject)->toBe('Facture FAC-2026-00001 — Cabinet Mukendi & Associés')
        ->and($log->body)->toContain("2\u{00A0}500,00\u{00A0}$")->toContain('09/12/2026');

    $pdf = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($m) => collect($m->getOriginalMessage()->getAttachments())->first())
        ->first(fn ($a) => $a && $a->getFilename() === 'FAC-2026-00001.pdf');
    expect($pdf)->not->toBeNull()
        ->and($pdf->getMediaSubtype())->toBe('pdf')
        ->and(str_starts_with($pdf->getBody(), '%PDF'))->toBeTrue();

    expect(AuditActivity::query()->where('description', 'like', 'Facture FAC-2026-00001 émise%')->where('causer_id', $this->accountant->id)->exists())->toBeTrue();
});

it('garde une numérotation sans trou même si une émission échoue', function () {
    $ok = draftFor();
    $empty = Invoice::query()->create(['client_id' => Client::factory()->create()->id, 'currency' => 'USD', 'status' => 'draft']);

    expect(fn () => app(InvoiceService::class)->issue($empty, $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);
    app(InvoiceService::class)->issue($ok, $this->accountant);

    expect($ok->fresh()->number)->toBe('FAC-2026-00001')->and($empty->fresh()->number)->toBeNull();
});

it('refuse d\'émettre sans aucun taux de change enregistré', function () {
    \App\Models\ExchangeRate::query()->delete();

    expect(fn () => app(InvoiceService::class)->issue(draftFor(), $this->accountant))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('n\'envoie pas la facture à un client sans consentement, mais l\'émet', function () {
    $invoice = draftFor([], Client::factory()->create(['email_consent' => false]));

    app(InvoiceService::class)->issue($invoice, $this->accountant);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and(EmailLog::query()->sole()->status)->toBe(EmailStatus::NotSent);
});

/*
| Scénario 13 : une facture émise ne peut être ni modifiée ni supprimée ; seul un avoir l'annule.
*/
it('scénario 13 : une facture émise est figée ; seul un avoir l\'annule', function () {
    $invoice = draftFor(['fee_amount' => '1000']);
    app(InvoiceService::class)->issue($invoice, $this->accountant);
    $invoice->refresh();

    // Modèle : ni modification, ni suppression, ni ligne ajoutée.
    expect(fn () => $invoice->update(['total' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $invoice->delete())->toThrow(LogicException::class)
        ->and(fn () => $invoice->lines()->first()->update(['amount' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => app(InvoiceBuilder::class)->addManualLine($invoice, \App\Enums\InvoiceLineType::Disbursement, 'X', '1', '1'))->toThrow(LogicException::class);

    // Interface : les actions de brouillon sont refusées.
    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->assertDontSee('Supprimer le brouillon')
        ->call('deleteDraft')->assertForbidden();
    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('discount', '50')->call('saveOptions')->assertForbidden();
    expect($invoice->fresh()->total)->toBe('1000.00');

    // Le comptable ne peut pas émettre d'avoir ; l'administrateur oui.
    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('reason', 'Erreur')->call('cancel')->assertForbidden();

    $admin = userWithRoles(Role::Admin);
    Livewire::actingAs($admin)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('reason', 'Erreur sur le client facturé')->call('cancel')->assertHasNoErrors();

    $credit = CreditNote::query()->sole();
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($credit->number)->toBe('AV-2026-00001')
        ->and($credit->total)->toBe('1000.00')
        ->and($credit->fees_amount)->toBe('1000.00')
        ->and($credit->exchange_rate)->toBe('2800.0000')
        ->and(fn () => $credit->update(['total' => '0']))->toThrow(LogicException::class)
        ->and(fn () => $credit->delete())->toThrow(LogicException::class)
        ->and(AuditActivity::query()->where('description', 'like', '%annulée par l\'avoir AV-2026-00001')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('remet les heures facturées en « à facturer » quand la facture est annulée par avoir', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id, 'fee_type' => FeeType::Hourly, 'fee_amount' => '100']);
    $case->timeEntries()->create(['user_id' => $lawyer->id, 'worked_on' => '2026-11-02', 'minutes' => 120, 'description' => 'A', 'billable' => true]);
    $invoice = app(InvoiceBuilder::class)->forCase($case, $this->accountant);
    app(InvoiceService::class)->issue($invoice, $this->accountant);

    app(InvoiceService::class)->cancel($invoice->fresh(), 'Erreur de taux', userWithRoles(Role::Admin));

    expect($case->unbilledMinutes())->toBe(120);
});

it('refuse l\'avoir sur une facture déjà encaissée', function () {
    $invoice = draftFor();
    app(InvoiceService::class)->issue($invoice, $this->accountant);
    $invoice->refresh()->forceFill(['paid_amount' => '100.00', 'status' => InvoiceStatus::PartiallyPaid])->save();

    expect(fn () => app(InvoiceService::class)->cancel($invoice, 'Motif', userWithRoles(Role::Admin)))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('supprime un brouillon, jamais une facture émise', function () {
    $draft = draftFor();

    Livewire::actingAs($this->accountant)->test(InvoiceShow::class, ['invoice' => $draft])->call('deleteDraft');

    expect(Invoice::query()->count())->toBe(0);
});

it('sert le PDF de la facture et de l\'avoir aux seuls utilisateurs autorisés', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $invoice = draftFor(['lead_lawyer_id' => $lawyer->id]);
    app(InvoiceService::class)->issue($invoice, $this->accountant);
    $credit = app(InvoiceService::class)->cancel($invoice->fresh(), 'Test', userWithRoles(Role::Admin));

    $this->actingAs($this->accountant)->get(route('invoices.pdf', $invoice))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($lawyer)->get(route('credit-notes.pdf', $credit))->assertOk();
    $this->actingAs(userWithRoles(Role::Lawyer))->get(route('invoices.pdf', $invoice))->assertForbidden();
    $this->actingAs(userWithRoles(Role::Secretary))->get(route('invoices.pdf', $invoice))->assertForbidden();
});

it('applique les droits de facturation par rôle', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $mine = draftFor(['lead_lawyer_id' => $lawyer->id]);
    $theirs = draftFor();

    // Avocat : lecture des factures de ses dossiers seulement, aucune création.
    $this->actingAs($lawyer)->get(route('invoices.index'))->assertOk()->assertDontSee('Nouvelle facture');
    $this->actingAs($lawyer)->get(route('invoices.show', $mine))->assertOk()->assertDontSee('Émettre');
    $this->actingAs($lawyer)->get(route('invoices.show', $theirs))->assertForbidden();
    $this->actingAs($lawyer)->get(route('invoices.create'))->assertForbidden();

    // Caissier : lecture de toutes les factures, ni création ni émission.
    $cashier = userWithRoles(Role::Cashier);
    $this->actingAs($cashier)->get(route('invoices.show', $theirs))->assertOk();
    $this->actingAs($cashier)->get(route('invoices.create'))->assertForbidden();
    Livewire::actingAs($cashier)->test(InvoiceShow::class, ['invoice' => $theirs])->call('issue')->assertForbidden();

    // Secrétaire : aucun accès (scénario 15).
    $this->actingAs(userWithRoles(Role::Secretary))->get(route('invoices.create'))->assertForbidden();
    $this->actingAs(userWithRoles(Role::Secretary))->get(route('invoices.show', $mine))->assertForbidden();
});
