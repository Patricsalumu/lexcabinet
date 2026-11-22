<?php

use App\Enums\AppointmentStatus;
use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Livewire\Agenda\AgendaIndex;
use App\Livewire\Agenda\AppointmentForm;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\LegalCase;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-11-09 09:00'));
});

afterEach(fn () => Carbon::setTestNow());

/** Messages réellement remis au transport « array » (file d'attente synchrone en test). */
function sentMessages()
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
}

function bookAs($user, $client, $lawyer, string $date, string $time, int $duration = 60, array $extra = [])
{
    return Livewire::actingAs($user)->test(AppointmentForm::class)
        ->set('client_id', (string) $client->id)
        ->set('lawyer_id', (string) $lawyer->id)
        ->set('type', $extra['type'] ?? 'consultation')
        ->set('date', $date)
        ->set('time', $time)
        ->set('duration', (string) $duration)
        ->set('location_type', 'office')
        ->set('subject', $extra['subject'] ?? 'Point sur la procédure')
        ->call('save');
}

/*
| Scénario 1 : une secrétaire crée un RDV ; le client reçoit la confirmation avec le fichier .ics.
*/
it('scénario 1 : la création d\'un RDV envoie la confirmation avec le fichier .ics', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create(['name' => 'Esther Kalala', 'email' => 'esther.kalala@example.cd', 'email_consent' => true]);

    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:00')->assertHasNoErrors()->assertRedirect();

    $appointment = Appointment::query()->firstOrFail();
    expect($appointment->status)->toBe(AppointmentStatus::Planned)
        ->and($appointment->starts_at->format('Y-m-d H:i'))->toBe('2026-11-10 10:00')
        ->and($appointment->ends_at->format('H:i'))->toBe('11:00');

    $log = EmailLog::query()->where('template_key', 'confirmation')->firstOrFail();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->recipient_email)->toBe('esther.kalala@example.cd')
        ->and($log->subject)->toBe('Confirmation de votre rendez-vous du mardi 10 novembre 2026')
        ->and($log->body)->toContain('le mardi 10 novembre 2026 à 10h00')
        ->and($log->body)->toContain($lawyer->name)
        ->and($log->sent_at)->not->toBeNull();

    $message = sentMessages()->map->getOriginalMessage()->first(fn ($m) => $m->getTo()[0]->getAddress() === 'esther.kalala@example.cd');
    $ics = collect($message->getAttachments())->first();
    expect($message)->not->toBeNull()
        ->and($ics->getFilename())->toBe('rendez-vous.ics')
        ->and($ics->getMediaSubtype())->toBe('calendar')
        ->and($ics->getBody())->toContain('METHOD:REQUEST')
        ->and($ics->getBody())->toContain('DTSTART:20261110T090000Z') // 10 h à Kinshasa = 9 h UTC
        ->and($ics->getBody())->toContain('UID:rdv-'.$appointment->id.'@');

    expect(AuditActivity::query()->where('description', 'Rendez-vous créé')->where('causer_id', $secretary->id)->exists())->toBeTrue();
});

/*
| Scénario 2 : un second RDV au même créneau pour le même avocat est refusé.
*/
it('scénario 2 : refuse un chevauchement sur l\'agenda d\'un même avocat', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $other = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create();

    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:00', 60)->assertHasNoErrors();

    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:00', 60)->assertHasErrors('time');
    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:30', 60)->assertHasErrors('time');
    bookAs($secretary, $client, $lawyer, '2026-11-10', '09:30', 60)->assertHasErrors('time');

    // Créneaux contigus et autre avocat : acceptés.
    bookAs($secretary, $client, $lawyer, '2026-11-10', '11:00', 30)->assertHasNoErrors();
    bookAs($secretary, $client, $lawyer, '2026-11-10', '09:00', 60)->assertHasNoErrors();
    bookAs($secretary, $client, $other, '2026-11-10', '10:00', 60)->assertHasNoErrors();

    expect(Appointment::query()->count())->toBe(4);
});

it('libère le créneau d\'un rendez-vous annulé ou reporté', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create();
    $first = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => $lawyer->id, 'status' => AppointmentStatus::Cancelled]);

    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:00')->assertHasNoErrors();

    // Le rendez-vous annulé ne peut plus être réactivé sur un créneau occupé.
    Livewire::actingAs($secretary)->test(AgendaIndex::class)->call('setStatus', $first->id, 'planned');
    expect($first->fresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('envoie un e-mail de modification (.ics version 1) quand le rendez-vous est modifié', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create();
    bookAs($secretary, $client, $lawyer, '2026-11-10', '10:00');
    $appointment = Appointment::query()->firstOrFail();

    Livewire::actingAs($secretary)->test(AppointmentForm::class, ['appointment' => $appointment])
        ->set('time', '15:00')
        ->call('save')
        ->assertHasNoErrors();

    $update = EmailLog::query()->where('template_key', 'appointment_updated')->sole();
    expect($appointment->fresh()->ics_sequence)->toBe(1)
        ->and(EmailLog::query()->where('template_key', 'confirmation')->count())->toBe(1)
        ->and($update->subject)->toBe('Votre rendez-vous a été modifié : mardi 10 novembre 2026 à 15h00')
        ->and($update->body)->toContain('Heure : 15h00');

    $ics = collect(sentMessages()->last()->getOriginalMessage()->getAttachments())->first()->getBody();
    expect($ics)->toContain('SEQUENCE:1')->toContain('DTSTART:20261110T140000Z');

    // Une modification des seules notes internes ne prévient pas le client.
    Livewire::actingAs($secretary)->test(AppointmentForm::class, ['appointment' => $appointment->fresh()])
        ->set('internal_notes', 'Apporter le dossier papier')
        ->call('save');
    expect(EmailLog::query()->count())->toBe(3);   // confirmation, avocat, modification
});

it('prévient le client d\'une annulation ou d\'un report, avec un .ics d\'annulation', function (string $status, string $template) {
    $secretary = userWithRoles(Role::Secretary);
    $appointment = Appointment::factory()->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    Livewire::actingAs($secretary)->test(AgendaIndex::class)->call('setStatus', $appointment->id, $status);

    expect($appointment->fresh()->status->value)->toBe($status);
    $log = EmailLog::query()->where('template_key', $template)->firstOrFail();
    expect($log->status)->toBe(EmailStatus::Sent);

    $ics = collect(sentMessages()->last()->getOriginalMessage()->getAttachments())->first()->getBody();
    expect($ics)->toContain('METHOD:CANCEL')->toContain('STATUS:CANCELLED');
})->with([
    'annulation' => ['cancelled', 'cancellation'],
    'report' => ['postponed', 'postponement'],
]);

it('n\'envoie rien à un client sans consentement, mais journalise le motif', function () {
    $secretary = userWithRoles(Role::Secretary);
    $client = Client::factory()->create(['email_consent' => false]);

    bookAs($secretary, $client, userWithRoles(Role::Lawyer), '2026-11-10', '10:00')->assertHasNoErrors();

    $log = EmailLog::query()->where('template_key', 'confirmation')->firstOrFail();
    expect($log->status)->toBe(EmailStatus::NotSent)
        ->and($log->reason)->toBe('Le client n\'a pas accepté les notifications par e-mail.')
        // Seul l'avocat est prévenu ; rien ne part vers le client.
        ->and(sentMessages())->toHaveCount(1)
        ->and(sentMessages()->first()->getOriginalMessage()->getTo()[0]->getAddress())->not->toBe($client->email);
});

it('prévient l\'avocat concerné à la création du rendez-vous, avec le .ics', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $other = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create(['name' => 'Esther Kalala', 'phone' => '+243 85 220 7731', 'email' => 'esther.kalala@example.cd']);

    Livewire::actingAs($secretary)->test(AppointmentForm::class)
        ->set('client_id', (string) $client->id)->set('lawyer_id', (string) $lawyer->id)
        ->set('date', '2026-11-10')->set('time', '10:00')->set('subject', 'Stratégie de conciliation')
        ->set('internal_notes', 'Apporter le contrat de travail')
        ->call('save')->assertHasNoErrors();

    $log = EmailLog::query()->where('template_key', 'appointment_lawyer')->sole();
    expect($log->recipient_email)->toBe($lawyer->email)
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and($log->subject)->toBe('Nouveau rendez-vous : Esther Kalala, le mardi 10 novembre 2026 à 10h00')
        ->and($log->body)->toContain('+243 85 220 7731')->toContain('Stratégie de conciliation')
        ->toContain('Apporter le contrat de travail')->toContain($secretary->name)
        ->and($log->body)->not->toContain('Moyens de paiement')   // pas de signature client
        ->and(EmailLog::query()->where('recipient_email', $other->email)->exists())->toBeFalse();

    $message = sentMessages()->map->getOriginalMessage()->first(fn ($m) => $m->getTo()[0]->getAddress() === $lawyer->email);
    expect(collect($message->getAttachments())->first()->getFilename())->toBe('rendez-vous.ics');

    // Relance refusée si le rendez-vous a changé d'avocat.
    Appointment::query()->update(['lawyer_id' => $other->id]);
    $log->forceFill(['status' => EmailStatus::Failed])->save();
    expect(app(\App\Services\EmailService::class)->resend($log, userWithRoles(Role::Admin))->status)->toBe(EmailStatus::NotSent);
});

it('crée un prospect depuis l\'agenda, avec contrôle des conflits', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    Client::factory()->create(['name' => 'Rachel Mwamba']);

    $form = Livewire::actingAs($secretary)->test(AppointmentForm::class)
        ->set('newProspect', true)
        ->set('prospect_name', 'Rachel Kabila')
        ->set('prospect_email', 'rachel.k@example.cd')
        ->set('lawyer_id', (string) $lawyer->id)
        ->set('date', '2026-11-10')->set('time', '10:00')
        ->set('subject', 'Premier rendez-vous');

    $form->call('save')->assertHasErrors('conflict_ack');
    expect(Appointment::query()->count())->toBe(0)
        ->and(Client::query()->where('name', 'Rachel Kabila')->exists())->toBeFalse();

    $form->set('conflict_ack', true)->call('save')->assertHasNoErrors();
    $prospect = Client::query()->where('name', 'Rachel Kabila')->firstOrFail();
    expect($prospect->is_prospect)->toBeTrue()
        ->and(Appointment::query()->first()->client_id)->toBe($prospect->id);
});

it('refuse un dossier qui n\'appartient pas au client', function () {
    $secretary = userWithRoles(Role::Secretary);
    $client = Client::factory()->create();
    $otherCase = LegalCase::factory()->create();

    Livewire::actingAs($secretary)->test(AppointmentForm::class)
        ->set('client_id', (string) $client->id)
        ->set('lawyer_id', (string) userWithRoles(Role::Lawyer)->id)
        ->set('date', '2026-11-10')->set('time', '10:00')->set('subject', 'RDV')
        ->set('case_id', (string) $otherCase->id)
        ->call('save')
        ->assertHasErrors('case_id');
});

it('permet à l\'avocat de lire l\'agenda (le sien par défaut) mais pas de créer ni modifier', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $colleague = userWithRoles(Role::Lawyer);
    $mine = Appointment::factory()->create(['lawyer_id' => $lawyer->id, 'subject' => 'Mon rendez-vous']);
    Appointment::factory()->at(Carbon::parse('2026-11-10 15:00'))->create(['lawyer_id' => $colleague->id, 'subject' => 'Rendez-vous du confrère']);

    Livewire::actingAs($lawyer)->test(AgendaIndex::class)
        ->assertSet('lawyer', (string) $lawyer->id)
        ->assertSee('Mon rendez-vous')
        ->assertDontSee('Rendez-vous du confrère')
        ->assertDontSee('Nouveau rendez-vous')
        ->set('lawyer', '')
        ->assertSee('Rendez-vous du confrère');

    $this->actingAs($lawyer)->get(route('agenda.create'))->assertForbidden();
    $this->actingAs($lawyer)->get(route('agenda.edit', $mine))->assertForbidden();
    Livewire::actingAs($lawyer)->test(AgendaIndex::class)->call('setStatus', $mine->id, 'cancelled')->assertForbidden();
    expect($mine->fresh()->status)->toBe(AppointmentStatus::Planned);
});

it('refuse l\'agenda au caissier et au comptable', function (Role $role) {
    $this->actingAs(userWithRoles($role))->get(route('agenda.index'))->assertForbidden();
})->with([Role::Cashier, Role::Accountant]);

it('affiche les audiences des dossiers dans l\'agenda, avec juridiction et chambre (RDV-06)', function () {
    $secretary = userWithRoles(Role::Secretary);
    $case = LegalCase::factory()->create(['jurisdiction' => 'Tribunal de commerce de Kinshasa/Gombe']);
    $case->events()->create(['type' => 'hearing', 'occurred_at' => Carbon::parse('2026-11-11 09:30'), 'description' => 'Plaidoiries', 'chamber' => 'Chambre 2']);

    Livewire::actingAs($secretary)->test(AgendaIndex::class)
        ->assertSee('Audience du dossier')
        ->assertSee('Tribunal de commerce de Kinshasa/Gombe, Chambre 2')
        ->assertSee('Plaidoiries');
});

it('propose les vues jour, semaine et liste', function () {
    $secretary = userWithRoles(Role::Secretary);
    Appointment::factory()->at(Carbon::parse('2026-11-09 14:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id, 'subject' => 'Aujourd\'hui']);
    Appointment::factory()->at(Carbon::parse('2026-11-20 14:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id, 'subject' => 'Dans onze jours']);

    Livewire::actingAs($secretary)->test(AgendaIndex::class)
        ->set('view', 'day')->assertSee('Aujourd\'hui')->assertDontSee('Dans onze jours')
        ->set('view', 'week')->assertSee('Aujourd\'hui')->assertDontSee('Dans onze jours')
        ->set('view', 'list')->assertSee('Aujourd\'hui')->assertSee('Dans onze jours');
});
