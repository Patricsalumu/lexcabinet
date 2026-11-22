<?php

use App\Enums\Role;
use App\Livewire\Emails\EmailLogIndex;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Services\AppointmentService;
use App\Services\WhatsAppShare;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');

    $this->lawyer = userWithRoles(Role::Lawyer);
    $this->lawyer->update(['phone' => '+243 99 123 4567']);
    $this->client = Client::factory()->create(['name' => 'Esther Kalala', 'phone' => '085 220 7731', 'email_consent' => true]);
    $this->appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))
        ->create(['lawyer_id' => $this->lawyer->id, 'client_id' => $this->client->id]);

    app(AppointmentService::class)->sendConfirmation($this->appointment);
    app(AppointmentService::class)->notifyLawyer($this->appointment);
    $this->clientLog = EmailLog::query()->where('template_key', 'confirmation')->sole();
    $this->lawyerLog = EmailLog::query()->where('template_key', 'appointment_lawyer')->sole();
});

afterEach(fn () => Carbon::setTestNow());

it('normalise les numéros au format international (RDC par défaut)', function (string $input, ?string $expected) {
    expect(WhatsAppShare::normalize($input))->toBe($expected);
})->with([
    ['+243 81 000 0000', '243810000000'],
    ['00243 81 000 0000', '243810000000'],
    ['081 000 0000', '243810000000'],
    ['810000000', '243810000000'],
    ['+33 6 12 34 56 78', '33612345678'],
    ['', null],
    ['12', null],
]);

it('partage vers le téléphone du client pour un e-mail client', function () {
    $url = app(WhatsAppShare::class)->url($this->clientLog);

    expect($url)->toStartWith('https://wa.me/243852207731?text=')
        ->and(rawurldecode(parse_url($url, PHP_URL_QUERY)))->toContain('*'.$this->clientLog->subject.'*')->toContain('Esther Kalala');
});

it('partage vers le téléphone de l\'avocat pour un e-mail avocat', function () {
    expect(app(WhatsAppShare::class)->url($this->lawyerLog))->toStartWith('https://wa.me/243991234567?text=');
});

it('vers le téléphone de l\'administrateur pour un e-mail administrateur', function () {
    $admin = userWithRoles(Role::Admin);
    $admin->update(['phone' => '0812223344']);
    $log = EmailLog::query()->create(['template_key' => 'case_created_admin', 'recipient_type' => $admin->getMorphClass(), 'recipient_id' => $admin->id,
        'recipient_name' => $admin->name, 'recipient_email' => $admin->email, 'subject' => 'Nouveau dossier', 'body' => 'Texte', 'status' => 'sent']);

    expect(app(WhatsAppShare::class)->url($log))->toStartWith('https://wa.me/243812223344?text=');
});

it('refuse le partage sans numéro, sans consentement ou vers un avocat qui n\'a plus le rendez-vous', function () {
    $share = app(WhatsAppShare::class);

    $this->client->update(['phone' => null]);
    expect($share->url($this->clientLog->fresh()))->toBeNull()
        ->and($share->refusalReason($this->clientLog->fresh()))->toBe('Aucun numéro de téléphone valide sur la fiche du destinataire.');

    $this->client->update(['phone' => '0852207731', 'email_consent' => false]);
    expect($share->refusalReason($this->clientLog->fresh()))->toBe('Le client n\'a pas accepté les notifications.');

    $this->appointment->update(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);
    expect($share->url($this->lawyerLog->fresh()))->toBeNull();
});

it('affiche le bouton au secrétariat et trace le partage', function () {
    $secretary = userWithRoles(Role::Secretary);

    Livewire::actingAs($secretary)->test(EmailLogIndex::class)
        ->call('select', $this->clientLog->id)
        ->assertSee('Partager sur WhatsApp')
        ->assertSee('https://wa.me/243852207731', false)
        ->call('sharedOnWhatsApp', $this->clientLog->id);

    expect(AuditActivity::query()->where('description', 'E-mail partagé sur WhatsApp')->where('causer_id', $secretary->id)->exists())->toBeTrue();
});

it('refuse le journal et le partage aux autres rôles', function (Role $role) {
    Livewire::actingAs(userWithRoles($role))->test(EmailLogIndex::class)->assertForbidden();
})->with([Role::Lawyer, Role::Cashier, Role::Accountant]);
