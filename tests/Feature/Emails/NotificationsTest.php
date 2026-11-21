<?php

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\EmailStatus;
use App\Enums\RateSource;
use App\Enums\Role;
use App\Livewire\Admin\UserManager;
use App\Livewire\Cases\CaseForm;
use App\Mail\TemplatedMail;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\AccountNotifier;
use App\Services\AppointmentService;
use App\Services\CaseService;
use App\Services\EmailService;
use App\Services\ExchangeRateService;
use App\Services\ReminderService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    app(ExchangeRateService::class)->record(today(), '2800', RateSource::Manual);
});

afterEach(fn () => Carbon::setTestNow());

/** @return \Illuminate\Support\Collection<int, EmailLog> */
function logsFor(string $template)
{
    return EmailLog::query()->where('template_key', $template)->orderBy('id')->get();
}

function openCaseFor(Client $client, User $lead, array $collaborators, User $by): LegalCase
{
    return app(CaseService::class)->open([
        'client_id' => $client->id, 'title' => 'Kivu Agro c/ Tropical Logistics', 'adverse_party' => 'Tropical Logistics SA',
        'lead_lawyer_id' => $lead->id, 'matter' => 'commercial', 'jurisdiction' => 'Tribunal de commerce de Kinshasa/Gombe',
        'docket_number' => 'RCE 1123', 'fee_type' => 'flat', 'fee_amount' => '2500', 'currency' => 'USD',
        'opened_on' => today()->toDateString(),
    ], array_map(fn (User $u) => $u->id, $collaborators), $by);
}

// ------------------------------------------------------------------ Création de compte

it('envoie au nouvel utilisateur un lien sécurisé, jamais de mot de passe', function (Role $role) {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('create')
        ->set('name', 'Me Ruth Kasongo')->set('email', 'ruth.kasongo@example.cd')
        ->set('password', '')                       // le mot de passe est facultatif
        ->set('roles', [$role->value])
        ->call('save')->assertHasNoErrors();

    $user = User::query()->where('email', 'ruth.kasongo@example.cd')->firstOrFail();
    $log = logsFor('account_created')->sole();

    expect($log->recipient_email)->toBe('ruth.kasongo@example.cd')
        ->and($log->status)->toBe(EmailStatus::Sent)
        ->and($log->triggered_by)->toBe($admin->id)
        ->and($log->subject)->toBe('Votre compte LexCabinet — Cabinet Mukendi & Associés')
        ->and($log->body)->toContain('Bonjour Me Ruth Kasongo')
        ->and($log->body)->toContain($role->label())
        ->and($log->body)->toContain('/compte/acces/'.$user->id)
        ->and($log->body)->toContain('72 heures')
        ->and($log->body)->not->toContain('password')
        ->and($log->body)->not->toContain('Mot de passe :');
})->with([Role::Admin, Role::Lawyer, Role::Secretary, Role::Cashier, Role::Accountant]);

it('le lien d\'accès mène à la définition du mot de passe et cesse de fonctionner ensuite', function () {
    $user = userWithRoles(Role::Secretary);
    $url = app(AccountNotifier::class)->accessUrl($user);

    $response = $this->get($url);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/reset-password/')->toContain(urlencode($user->email));

    // Une fois le mot de passe changé, l'ancien lien est refusé.
    $user->forceFill(['password' => 'NouveauMotDePasse!2026'])->save();
    $this->get($url)->assertRedirect(route('login'));

    // Lien falsifié ou expiré : refusé.
    $this->get(route('account.activate', ['user' => $user->id, 'h' => 'x']))->assertRedirect(route('login'));
    $expired = app(AccountNotifier::class)->accessUrl($user->fresh());
    Carbon::setTestNow(now()->addHours(73));
    $this->get($expired)->assertRedirect(route('login'));
});

it('ne prévient pas l\'utilisateur d\'une simple modification de profil, et permet de renvoyer le lien', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Lawyer);

    Livewire::actingAs($admin)->test(UserManager::class)
        ->call('edit', $user->id)->set('phone', '+243 81 000 1111')->call('save')->assertHasNoErrors();
    expect(EmailLog::query()->count())->toBe(0);

    Livewire::actingAs($admin)->test(UserManager::class)->call('resendAccess', $user->id);
    expect(logsFor('account_created')->sole()->recipient_email)->toBe($user->email);
});

// ------------------------------------------------------------------ Création de dossier

it('à l\'ouverture d\'un dossier, prévient les administrateurs, le client et les seuls avocats du dossier', function () {
    $secretary = userWithRoles(Role::Secretary);
    $adminLawyer = userWithRoles(Role::Admin, Role::Lawyer);   // administratrice ET collaboratrice
    $otherAdmin = userWithRoles(Role::Admin);
    $inactiveAdmin = userWithRoles(Role::Admin);
    $inactiveAdmin->update(['is_active' => false]);
    $lead = userWithRoles(Role::Lawyer);
    $outsider = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create(['name' => 'Kivu Agro SARL', 'email' => 'direction@kivuagro.example', 'email_consent' => true]);

    $case = openCaseFor($client, $lead, [$adminLawyer], $secretary);

    // Avocats : un e-mail chacun, le non-concerné n'en reçoit pas.
    $lawyerMails = logsFor('case_created_lawyer');
    expect($lawyerMails->pluck('recipient_email')->sort()->values()->all())->toBe(collect([$lead->email, $adminLawyer->email])->sort()->values()->all())
        ->and(EmailLog::query()->where('recipient_email', $outsider->email)->exists())->toBeFalse();
    $leadMail = $lawyerMails->firstWhere('recipient_email', $lead->email);
    expect($leadMail->body)->toContain($case->reference)->toContain('Kivu Agro SARL')->toContain('avocat responsable')
        ->toContain('Tribunal de commerce de Kinshasa/Gombe')->toContain(route('cases.show', $case))->toContain($adminLawyer->name);

    // Administrateurs actifs, sans doublon pour celle qui est déjà prévenue comme avocate.
    $adminMails = logsFor('case_created_admin');
    expect($adminMails->pluck('recipient_email')->all())->toContain($otherAdmin->email)
        ->and($adminMails->pluck('recipient_email')->all())->not->toContain($adminLawyer->email)
        ->and($adminMails->pluck('recipient_email')->all())->not->toContain($inactiveAdmin->email)   // seulement les actifs
        ->and($adminMails->firstWhere('recipient_email', $otherAdmin->email)->body)->toContain('Ouvert par : '.$secretary->name)->toContain('09/11/2026');

    // Client : bienvenue, sans lien vers l'application ni information interne.
    $clientMail = logsFor('case_created_client')->sole();
    expect($clientMail->recipient_email)->toBe('direction@kivuagro.example')
        ->and($clientMail->status)->toBe(EmailStatus::Sent)
        ->and($clientMail->body)->toContain('bienvenue')->toContain($case->reference)->toContain($lead->name)
        ->and($clientMail->body)->not->toContain('/dossiers/')
        ->and($clientMail->body)->not->toContain('Convention')
        ->and($clientMail->body)->not->toContain('Partie adverse');
});

it('respecte le consentement du client à l\'ouverture du dossier', function () {
    $client = Client::factory()->create(['email_consent' => false]);

    openCaseFor($client, userWithRoles(Role::Lawyer), [], userWithRoles(Role::Secretary));

    expect(logsFor('case_created_client')->sole()->status)->toBe(EmailStatus::NotSent)
        ->and(logsFor('case_created_client')->sole()->reason)->toBe('Le client n\'a pas accepté les notifications par e-mail.');
});

it('n\'envoie rien si l\'ouverture du dossier échoue (transaction annulée)', function () {
    $client = Client::factory()->create();
    $lead = userWithRoles(Role::Lawyer);
    $secretary = userWithRoles(Role::Secretary);

    try {
        DB::transaction(function () use ($client, $lead, $secretary) {
            openCaseFor($client, $lead, [], $secretary);
            throw new RuntimeException('échec simulé après la création');
        });
    } catch (RuntimeException) {
    }

    expect(LegalCase::query()->count())->toBe(0)
        ->and(EmailLog::query()->count())->toBe(0)
        ->and(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});

// ------------------------------------------------------------------ Modification de dossier

it('prévient le client et les avocats d\'une modification significative, jamais les administrateurs', function () {
    $secretary = userWithRoles(Role::Secretary);
    $admin = userWithRoles(Role::Admin);
    $lead = userWithRoles(Role::Lawyer);
    $colleague = userWithRoles(Role::Lawyer);
    $case = openCaseFor(Client::factory()->create(), $lead, [$colleague], $secretary);
    EmailLog::query()->delete();

    Livewire::actingAs($secretary)->test(CaseForm::class, ['case' => $case])
        ->set('title', 'Kivu Agro c/ Tropical Logistics SA (appel)')
        ->set('docket_number', 'RCA 77')
        ->call('save')->assertHasNoErrors();

    $client = logsFor('case_updated_client')->sole();
    expect($client->body)->toContain('Intitulé : Kivu Agro c/ Tropical Logistics → Kivu Agro c/ Tropical Logistics SA (appel)')
        ->toContain('N° de rôle : RCE 1123 → RCA 77')
        ->and($client->body)->not->toContain('/dossiers/');
    expect(logsFor('case_updated_lawyer')->pluck('recipient_email')->sort()->values()->all())
        ->toBe(collect([$lead->email, $colleague->email])->sort()->values()->all())
        ->and(EmailLog::query()->where('recipient_email', $admin->email)->exists())->toBeFalse()
        ->and(logsFor('case_created_admin'))->toBeEmpty();
});

it('n\'envoie rien pour une modification sans changement réel', function () {
    $secretary = userWithRoles(Role::Secretary);
    $case = openCaseFor(Client::factory()->create(), userWithRoles(Role::Lawyer), [], $secretary);
    EmailLog::query()->delete();

    Livewire::actingAs($secretary)->test(CaseForm::class, ['case' => $case])->call('save')->assertHasNoErrors();

    expect(EmailLog::query()->count())->toBe(0);
});

it('prévient l\'avocat ajouté et l\'avocat retiré, chacun par un message dédié', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lead = userWithRoles(Role::Lawyer);
    $leaving = userWithRoles(Role::Lawyer);
    $joining = userWithRoles(Role::Lawyer);
    $case = openCaseFor(Client::factory()->create(), $lead, [$leaving], $secretary);
    EmailLog::query()->delete();

    Livewire::actingAs($secretary)->test(CaseForm::class, ['case' => $case])
        ->set('collaborators', [(string) $joining->id])
        ->call('save')->assertHasNoErrors();

    $added = logsFor('case_lawyer_added')->sole();
    $removed = logsFor('case_lawyer_removed')->sole();
    expect($added->recipient_email)->toBe($joining->email)
        ->and($added->body)->toContain(route('cases.show', $case))
        ->and($removed->recipient_email)->toBe($leaving->email)
        ->and($removed->body)->toContain($case->reference)
        ->and($removed->body)->not->toContain('/dossiers/')
        // Le responsable, resté dans l'équipe, est informé ; le partant ne reçoit plus que son retrait.
        ->and(logsFor('case_updated_lawyer')->pluck('recipient_email')->all())->toBe([$lead->email])
        ->and(logsFor('case_updated_client')->sole()->body)->toContain($joining->name);

    // Confidentialité : un e-mail d'équipe ne peut pas être relancé vers l'avocat retiré.
    $old = EmailLog::query()->create([
        'template_key' => 'case_updated_lawyer', 'recipient_name' => $leaving->name, 'recipient_email' => $leaving->email,
        'subject' => 'x', 'body' => 'x', 'status' => EmailStatus::Failed, 'recipient_type' => $leaving->getMorphClass(),
        'recipient_id' => $leaving->id, 'related_type' => $case->getMorphClass(), 'related_id' => $case->id,
    ]);
    $resent = app(EmailService::class)->resend($old, userWithRoles(Role::Admin));
    expect($resent->status)->toBe(EmailStatus::NotSent)
        ->and($resent->reason)->toBe('Ce destinataire n\'est plus associé au dossier.');
});

it('prévient le client et les avocats d\'un changement de statut', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lead = userWithRoles(Role::Lawyer);
    $case = openCaseFor(Client::factory()->create(), $lead, [], $secretary);
    EmailLog::query()->delete();

    app(CaseService::class)->changeStatus($case, CaseStatus::Deliberation, $secretary);

    expect(logsFor('case_updated_client')->sole()->body)->toContain('Statut : Ouvert → En délibéré')
        ->and(logsFor('case_updated_lawyer')->sole()->recipient_email)->toBe($lead->email);
});

// ------------------------------------------------------------------ Rendez-vous

it('replanifie un rendez-vous reporté et envoie les nouvelles informations avec un .ics à jour', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => $lawyer->id]);
    $service = app(AppointmentService::class);

    $service->changeStatus($appointment, AppointmentStatus::Postponed, $secretary);
    expect(logsFor('postponement')->sole()->status)->toBe(EmailStatus::Sent);

    $service->update($appointment->fresh(), [
        'client_id' => $appointment->client_id, 'lawyer_id' => $lawyer->id, 'type' => 'consultation',
        'date' => '2026-11-12', 'time' => '14:00', 'duration' => 60, 'location_type' => 'office', 'subject' => 'Consultation',
    ], $secretary);

    $fresh = $appointment->fresh();
    $update = logsFor('appointment_updated')->sole();
    expect($fresh->status)->toBe(AppointmentStatus::Planned)
        ->and($fresh->ics_sequence)->toBe(2)
        ->and($update->body)->toContain('jeudi 12 novembre 2026')->toContain('14h00');
});

// ------------------------------------------------------------------ Rappels : échecs et doublons

it('retente un rappel dont l\'envoi a échoué, sans doublon, dans la limite de trois échecs', function () {
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);
    $reminders = app(ReminderService::class);
    Carbon::setTestNow('2026-11-09 18:00');

    expect($reminders->sendDayBeforeReminders())->toBe(1)
        ->and($reminders->sendDayBeforeReminders())->toBe(0);              // pas de doublon

    // Échec définitif du worker : le rappel redevient « à envoyer ».
    $first = logsFor('reminder')->sole();
    (new TemplatedMail($first->id))->failed(new RuntimeException('SMTP indisponible'));
    expect($first->fresh()->status)->toBe(EmailStatus::Failed)
        ->and($appointment->fresh()->reminder_sent_at)->toBeNull();

    expect($reminders->sendDayBeforeReminders())->toBe(1);
    (new TemplatedMail(logsFor('reminder')->last()->id))->failed(new RuntimeException('SMTP indisponible'));
    expect($reminders->sendDayBeforeReminders())->toBe(1);
    (new TemplatedMail(logsFor('reminder')->last()->id))->failed(new RuntimeException('SMTP indisponible'));

    // Troisième échec : plus de nouvelle tentative automatique (relance manuelle possible).
    expect($appointment->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($reminders->sendDayBeforeReminders())->toBe(0)
        ->and(logsFor('reminder'))->toHaveCount(3);
});

it('ne marque pas le rappel si l\'e-mail n\'a pas pu être créé', function () {
    $appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);
    \App\Models\EmailTemplate::query()->where('key', 'reminder')->delete();
    $this->mock(EmailService::class, function ($mock) {
        $mock->shouldReceive('automaticAttempts')->andReturn(0);
        $mock->shouldReceive('appointmentVars')->andReturn([]);
        $mock->shouldReceive('send')->andThrow(new RuntimeException('base indisponible'));
    });
    Carbon::setTestNow('2026-11-09 18:00');

    expect(app(ReminderService::class)->sendDayBeforeReminders())->toBe(0)
        ->and($appointment->fresh()->reminder_sent_at)->toBeNull();
});

it('retente le récapitulatif quotidien en échec, une seule fois réussi par jour', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    Appointment::factory()->at(Carbon::parse('2026-11-09 11:00'))->create(['lawyer_id' => $lawyer->id]);
    $reminders = app(ReminderService::class);
    Carbon::setTestNow('2026-11-09 07:00');

    expect($reminders->sendDailyRecaps())->toBe(1);
    (new TemplatedMail(logsFor('recap')->sole()->id))->failed(new RuntimeException('SMTP indisponible'));
    expect($reminders->sendDailyRecaps())->toBe(1)
        ->and($reminders->sendDailyRecaps())->toBe(0)
        ->and(logsFor('recap'))->toHaveCount(2);
});

// ------------------------------------------------------------------ File d'attente

it('met chaque e-mail dans la file database après le commit, et rien en cas d\'annulation', function () {
    config(['queue.default' => 'database']);
    $client = Client::factory()->create();
    $appointment = Appointment::factory()->create(['client_id' => $client->id, 'lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    DB::transaction(function () use ($appointment) {
        app(AppointmentService::class)->sendConfirmation($appointment);
        expect(DB::table('jobs')->count())->toBe(0);   // pas avant le commit
    });

    $job = DB::table('jobs')->sole();
    expect(json_decode($job->payload, true)['displayName'])->toBe(TemplatedMail::class)
        ->and(logsFor('confirmation')->sole()->status)->toBe(EmailStatus::Queued);   // en attente du worker

    try {
        DB::transaction(function () use ($appointment) {
            app(AppointmentService::class)->sendConfirmation($appointment);
            throw new RuntimeException('annulé');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(logsFor('confirmation'))->toHaveCount(1);

    // Le worker traite le job : l'e-mail passe à « Envoyé ».
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(logsFor('confirmation')->sole()->status)->toBe(EmailStatus::Sent);
});

it('précise dans le journal qu\'un transport de développement ne délivre rien', function () {
    $appointment = Appointment::factory()->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);

    app(AppointmentService::class)->sendConfirmation($appointment);

    $log = logsFor('confirmation')->sole();
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and($log->reason)->toContain('Non délivré')->toContain('array');
});

it('signale par lexcabinet:mail-check une file qui n\'est pas traitée', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);

    $this->artisan('lexcabinet:mail-check')
        ->expectsOutputToContain('aucun worker ne traite la file')
        ->assertFailed();
});
