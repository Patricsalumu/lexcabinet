<?php

use App\Enums\AppointmentStatus;
use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Livewire\Agenda\AgendaIndex;
use App\Mail\TemplatedMail;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\LegalCase;
use App\Services\SettingsService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
});

afterEach(fn () => Carbon::setTestNow());

/*
| Scénario 3 : le rappel J-1 part automatiquement à l'heure paramétrée ; son envoi apparaît dans le journal.
*/
it('scénario 3 : le rappel J-1 part à l\'heure paramétrée et apparaît dans le journal', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $tomorrow = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => $lawyer->id]);
    $cancelled = Appointment::factory()->at(Carbon::parse('2026-11-10 15:00'))->create(['lawyer_id' => $lawyer->id, 'status' => AppointmentStatus::Cancelled]);
    $later = Appointment::factory()->at(Carbon::parse('2026-11-11 10:00'))->create(['lawyer_id' => $lawyer->id]);

    // 17 h 45 : trop tôt.
    Carbon::setTestNow('2026-11-09 17:45');
    $this->artisan('lexcabinet:send-reminders')->assertSuccessful();
    expect(EmailLog::query()->where('template_key', 'reminder')->count())->toBe(0);

    // 18 h 00 : le rappel part pour le seul rendez-vous actif du lendemain.
    Carbon::setTestNow('2026-11-09 18:00');
    $this->artisan('lexcabinet:send-reminders')->assertSuccessful();

    $log = EmailLog::query()->where('template_key', 'reminder')->sole();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->related_id)->toBe($tomorrow->id)
        ->and($log->subject)->toBe('Rappel : votre rendez-vous de demain à 10h00')
        ->and($tomorrow->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($cancelled->fresh()->reminder_sent_at)->toBeNull()
        ->and($later->fresh()->reminder_sent_at)->toBeNull();

    // Un second passage (toutes les 15 min) ne renvoie rien.
    Carbon::setTestNow('2026-11-09 18:15');
    $this->artisan('lexcabinet:send-reminders')->assertSuccessful();
    expect(EmailLog::query()->where('template_key', 'reminder')->count())->toBe(1);

    // Le journal d'envoi affiche l'e-mail.
    Livewire::actingAs(userWithRoles(Role::Secretary))->test(\App\Livewire\Emails\EmailLogIndex::class)
        ->assertSee('Rappel : votre rendez-vous de demain à 10h00')
        ->assertSee('Envoyé');
});

it('respecte l\'heure de rappel modifiée dans les paramètres', function () {
    app(SettingsService::class)->set('reminders.day_before_time', '16:30');
    Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    Carbon::setTestNow('2026-11-09 16:30');
    $this->artisan('lexcabinet:send-reminders');

    expect(EmailLog::query()->where('template_key', 'reminder')->count())->toBe(1);
});

it('envoie le rappel rapproché (H-2) une seule fois, sauf pour un RDV pris dans ce délai', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    Carbon::setTestNow('2026-11-09 08:15');
    $soon = Appointment::factory()->at(Carbon::parse('2026-11-09 10:00'))->create(['lawyer_id' => $lawyer->id, 'created_at' => '2026-11-05 10:00']);
    $justBooked = Appointment::factory()->at(Carbon::parse('2026-11-09 09:30'))->create(['lawyer_id' => $lawyer->id, 'created_at' => '2026-11-09 08:00']);

    $this->artisan('lexcabinet:send-reminders');
    $this->artisan('lexcabinet:send-reminders');

    expect(EmailLog::query()->where('template_key', 'reminder_soon')->count())->toBe(1)
        ->and($soon->fresh()->short_reminder_sent_at)->not->toBeNull()
        ->and($justBooked->fresh()->short_reminder_sent_at)->toBeNull();
});

it('envoie à 7 h le récapitulatif du jour à chaque avocat qui a un agenda', function () {
    $joel = userWithRoles(Role::Lawyer);
    $sarah = userWithRoles(Role::Lawyer);
    $idle = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create(['name' => 'Kivu Agro SARL']);
    Appointment::factory()->at(Carbon::parse('2026-11-09 11:30'))->create(['lawyer_id' => $joel->id, 'client_id' => $client->id]);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $sarah->id, 'title' => 'Lumbala, litige parcellaire', 'jurisdiction' => 'TGI Ndjili']);
    $case->events()->create(['type' => 'hearing', 'occurred_at' => Carbon::parse('2026-11-09 09:00'), 'description' => 'Audience', 'chamber' => 'Chambre 1']);

    Carbon::setTestNow('2026-11-09 06:45');
    $this->artisan('lexcabinet:send-daily-recap');
    expect(EmailLog::query()->where('template_key', 'recap')->count())->toBe(0);

    Carbon::setTestNow('2026-11-09 07:00');
    $this->artisan('lexcabinet:send-daily-recap');
    $this->artisan('lexcabinet:send-daily-recap');

    $logs = EmailLog::query()->where('template_key', 'recap')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('recipient_email')->all())->not->toContain($idle->email)
        ->and($logs->firstWhere('recipient_email', $joel->email)->body)->toContain('11h30 · Consultation · Kivu Agro SARL · Cabinet')
        ->and($logs->firstWhere('recipient_email', $sarah->email)->body)->toContain('09h00 · Audience · Lumbala, litige parcellaire · TGI Ndjili, Chambre 1');
});

it('permet au secrétariat de lancer les rappels à la main, pas à l\'avocat', function () {
    Carbon::setTestNow('2026-11-09 10:00');
    Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    Livewire::actingAs(userWithRoles(Role::Lawyer))->test(AgendaIndex::class)->call('sendReminders')->assertForbidden();
    expect(EmailLog::query()->count())->toBe(0);

    Livewire::actingAs(userWithRoles(Role::Secretary))->test(AgendaIndex::class)->call('sendReminders');
    expect(EmailLog::query()->where('template_key', 'reminder')->count())->toBe(1);
});

it('marque l\'e-mail en échec après les tentatives, puis permet de le relancer', function () {
    Carbon::setTestNow('2026-11-09 10:00');
    $secretary = userWithRoles(Role::Secretary);
    $client = Client::factory()->create(['email' => null, 'email_consent' => true]);
    $appointment = Appointment::factory()->create(['client_id' => $client->id, 'lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    app(\App\Services\AppointmentService::class)->sendConfirmation($appointment, $secretary);
    $log = EmailLog::query()->sole();
    expect($log->status)->toBe(EmailStatus::NotSent)->and($log->reason)->toBe('Aucune adresse e-mail sur la fiche.');

    // Relance sans correction : toujours refusée, nouvelle trace.
    Livewire::actingAs($secretary)->test(\App\Livewire\Emails\EmailLogIndex::class)->call('resend', $log->id);
    expect(EmailLog::query()->count())->toBe(2);

    // Correction de la fiche puis relance : l'e-mail part.
    $client->update(['email' => 'client@example.cd']);
    Livewire::actingAs($secretary)->test(\App\Livewire\Emails\EmailLogIndex::class)->call('resend', $log->id);
    $resent = EmailLog::query()->latest('id')->first();
    expect($resent->status)->toBe(EmailStatus::Sent)
        ->and($resent->resent_from_id)->toBe($log->id)
        ->and($resent->recipient_email)->toBe('client@example.cd');

    // Échec du transport après 3 tentatives : statut « Échec » avec l'erreur.
    (new TemplatedMail($resent->id))->failed(new RuntimeException('Connexion SMTP refusée'));
    expect($resent->fresh()->status)->toBe(EmailStatus::Failed)
        ->and($resent->fresh()->reason)->toBe('Connexion SMTP refusée')
        ->and((new TemplatedMail(1))->tries)->toBe(3);
});
