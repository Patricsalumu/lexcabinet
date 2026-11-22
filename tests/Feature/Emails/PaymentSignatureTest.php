<?php

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Enums\TreasuryType;
use App\Livewire\Admin\TreasuryAccounts;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\EmailLog;
use App\Models\TreasuryAccount;
use App\Services\AppointmentService;
use App\Services\EmailSignature;
use App\Services\ReminderService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');

    TreasuryAccount::query()->create(['name' => 'Espèces au cabinet', 'type' => TreasuryType::Cash, 'currency' => 'USD', 'ledger_code' => '5711',
        'payment_details' => 'du lundi au vendredi', 'show_to_clients' => true]);
    TreasuryAccount::query()->create(['name' => 'Caisse CDF', 'type' => TreasuryType::Cash, 'currency' => 'CDF', 'ledger_code' => '5712']);
    $this->bank = TreasuryAccount::query()->create(['name' => 'Rawbank', 'type' => TreasuryType::Bank, 'currency' => 'USD', 'ledger_code' => '521',
        'holder_name' => 'Cabinet Mukendi & Associés', 'account_number' => '0501-0000000-01', 'payment_details' => 'SWIFT RAWBCDKI', 'show_to_clients' => true]);
    TreasuryAccount::query()->create(['name' => 'M-Pesa', 'type' => TreasuryType::MobileMoney, 'currency' => 'USD', 'ledger_code' => '5216',
        'holder_name' => 'Cabinet Mukendi & Associés', 'account_number' => '+243 81 000 0000', 'show_to_clients' => true]);
    TreasuryAccount::query()->create(['name' => 'Compte interne', 'type' => TreasuryType::Bank, 'currency' => 'CDF', 'ledger_code' => '521',
        'account_number' => 'SECRET-123', 'show_to_clients' => false]);
});

afterEach(fn () => Carbon::setTestNow());

it('compose la signature : moyens de paiement affichés, puis adresse et contacts', function () {
    $signature = app(EmailSignature::class)->forClients();

    expect($signature)->toBe(
        "Moyens de paiement :\n"
        ."• Rawbank (USD) — Intitulé : Cabinet Mukendi & Associés — N° 0501-0000000-01 — SWIFT RAWBCDKI\n"
        ."• M-Pesa (USD) — Intitulé : Cabinet Mukendi & Associés — N° +243 81 000 0000\n"
        ."• Espèces au cabinet (USD) — du lundi au vendredi\n\n"
        ."Cabinet Mukendi & Associés\n12, avenue de la Justice, Gombe, Kinshasa\nTéléphone : +243 81 000 0000\nE-mail : contact@mukendi-avocats.example"
    )->and($signature)->not->toContain('SECRET-123')    // compte masqué
        ->and($signature)->not->toContain('Caisse CDF');
});

it('ajoute la signature à tous les e-mails clients, et seulement à eux', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => $lawyer->id]);

    app(AppointmentService::class)->sendConfirmation($appointment);
    app(AppointmentService::class)->notifyLawyer($appointment);
    Carbon::setTestNow('2026-11-09 18:00');
    app(ReminderService::class)->sendDayBeforeReminders();

    foreach (['confirmation', 'reminder'] as $template) {
        expect(EmailLog::query()->where('template_key', $template)->sole()->body)
            ->toContain('Moyens de paiement :')->toContain('N° 0501-0000000-01')->toContain('Téléphone : +243 81 000 0000');
    }
    expect(EmailLog::query()->where('template_key', 'appointment_lawyer')->sole()->body)->not->toContain('Moyens de paiement');

    // Dans le message HTML, la signature est en pied de page, sans répéter l'adresse.
    $html = collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map->getOriginalMessage()
        ->first(fn ($m) => $m->getTo()[0]->getAddress() === $appointment->client->email)
        ->getHtmlBody();
    expect($html)->toContain('Moyens de paiement')
        ->and(substr_count($html, '12, avenue de la Justice'))->toBe(1);
});

it('permet de configurer l\'intitulé, le numéro et l\'affichage d\'un moyen de paiement', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(TreasuryAccounts::class)
        ->set('name', 'Airtel Money')->set('type', 'mobile_money')->set('currency', 'USD')
        ->set('holder_name', 'Cabinet Mukendi & Associés')->set('account_number', '+243 99 000 0000')
        ->set('show_to_clients', true)
        ->call('save')->assertHasNoErrors();

    Livewire::actingAs($admin)->test(TreasuryAccounts::class)
        ->call('edit', $this->bank->id)
        ->set('holder_name', 'SCP Mukendi & Associés')
        ->set('show_to_clients', false)
        ->call('save')->assertHasNoErrors();

    $signature = app(EmailSignature::class)->forClients();
    expect($signature)->toContain('Airtel Money (USD) — Intitulé : Cabinet Mukendi & Associés — N° +243 99 000 0000')
        ->and($signature)->not->toContain('Rawbank')
        ->and($this->bank->fresh()->holder_name)->toBe('SCP Mukendi & Associés')
        ->and($this->bank->fresh()->ledger_code)->toBe('521')     // inchangé
        ->and(AuditActivity::query()->where('description', 'Compte de trésorerie modifié')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('réserve la configuration des moyens de paiement à l\'administrateur', function (Role $role) {
    Livewire::actingAs(userWithRoles($role))->test(TreasuryAccounts::class)->assertForbidden();
})->with([Role::Accountant, Role::Cashier, Role::Secretary]);

it('reprend les moyens de paiement sur la facture PDF', function () {
    expect(view('pdf.invoice', [
        'invoice' => tap(new App\Models\Invoice(['currency' => 'USD', 'status' => 'draft', 'total' => '0', 'fees_total' => '0', 'discount_amount' => '0', 'other_total' => '0', 'vat_amount' => '0', 'paid_amount' => '0', 'discount_percent' => '0', 'vat_enabled' => false, 'vat_rate' => '0']),
            fn ($i) => $i->setRelation('lines', collect())->setRelation('client', App\Models\Client::factory()->make())->setRelation('legalCase', null)->setRelation('creditNote', null)),
        'firm' => app(App\Services\SettingsService::class)->all(),
        'logo' => null,
    ])->render())->toContain('Moyens de paiement')->toContain('0501-0000000-01')->not->toContain('SECRET-123');
});
