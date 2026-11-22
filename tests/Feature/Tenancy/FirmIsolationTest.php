<?php

use App\Enums\EmailStatus;
use App\Enums\FeeType;
use App\Enums\Role;
use App\Livewire\Agenda\AppointmentForm;
use App\Livewire\Cases\CaseForm;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\Document;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\AccountingReports;
use App\Services\AppointmentService;
use App\Services\ConversationService;
use App\Services\FirmProvisioner;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
use App\Services\Mailbox\InboundEmail;
use App\Services\SettingsService;
use App\Support\Tenancy\CurrentFirm;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Étanchéité entre cabinets (phase M1) : un utilisateur du cabinet A ne voit, ne modifie,
| ne référence et ne télécharge jamais rien du cabinet B, même en saisissant l'adresse.
*/

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    Storage::fake('local');

    $this->secretaryA = userWithRoles(Role::Secretary);
    $this->accountantA = userWithRoles(Role::Accountant);
    $this->lawyerA = userWithRoles(Role::Lawyer);
    $this->clientA = Client::factory()->create(['name' => 'Client du cabinet A']);
    \App\Models\ExchangeRate::factory()->create(['rate_date' => '2026-11-09']);

    // Cabinet B, avec ses propres données.
    $this->b = inOtherFirm(function (Firm $firm) {
        $this->seed(EmailTemplateSeeder::class);
        app(SettingsService::class)->set('firm.name', 'Cabinet B');
        \App\Models\ExchangeRate::factory()->create(['rate_date' => '2026-11-09']);   // taux propres au cabinet
        $lawyer = userWithRoles(Role::Lawyer);
        $accountant = userWithRoles(Role::Accountant);
        $client = Client::factory()->create(['name' => 'Client secret du cabinet B']);
        $case = LegalCase::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $lawyer->id, 'title' => 'Dossier secret B',
            'fee_type' => FeeType::Flat, 'fee_amount' => '500', 'currency' => 'USD']);
        $appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))->create(['client_id' => $client->id, 'lawyer_id' => $lawyer->id, 'subject' => 'Rendez-vous secret B']);
        $invoice = app(InvoiceService::class)->issue(app(InvoiceBuilder::class)->forCase($case, $accountant), $accountant, false)->fresh();
        Storage::disk('local')->put("firms/{$firm->id}/cases/{$case->id}/piece.pdf", '%PDF secret');
        $document = Document::query()->create(['case_id' => $case->id, 'original_name' => 'piece.pdf', 'path' => "firms/{$firm->id}/cases/{$case->id}/piece.pdf",
            'category' => 'evidence', 'mime_type' => 'application/pdf', 'size' => 11, 'uploaded_by' => $lawyer->id]);

        return (object) compact('firm', 'lawyer', 'accountant', 'client', 'case', 'appointment', 'invoice', 'document');
    });
});

afterEach(fn () => Carbon::setTestNow());

it('ne montre jamais les données d\'un autre cabinet dans les listes', function () {
    expect(Client::query()->pluck('name')->all())->toBe(['Client du cabinet A'])
        ->and(LegalCase::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Appointment::query()->count())->toBe(0)
        ->and(User::query()->pluck('id')->all())->not->toContain($this->b->lawyer->id);

    $this->actingAs($this->secretaryA)->get(route('clients.index'))->assertOk()->assertSee('Client du cabinet A')->assertDontSee('Client secret du cabinet B');
    $this->actingAs($this->secretaryA)->get(route('cases.index'))->assertOk()->assertDontSee('Dossier secret B');
    $this->actingAs($this->secretaryA)->get(route('agenda.index', ['date' => '2026-11-10', 'view' => 'list']))->assertOk()->assertDontSee('Rendez-vous secret B');
    $this->actingAs($this->accountantA)->get(route('invoices.index'))->assertOk()->assertDontSee($this->b->invoice->number);
});

it('refuse l\'accès direct aux pages et fichiers d\'un autre cabinet (404)', function () {
    $b = $this->b;
    $admin = userWithRoles(Role::Admin);

    foreach ([
        route('clients.show', $b->client), route('clients.edit', $b->client),
        route('cases.show', $b->case), route('cases.edit', $b->case),
        route('cases.documents.download', [$b->case, $b->document]),
        route('agenda.edit', $b->appointment),
        route('invoices.show', $b->invoice), route('invoices.pdf', $b->invoice),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertNotFound();
    }
});

it('refuse de référencer un client, un avocat ou un dossier d\'un autre cabinet', function () {
    Livewire::actingAs($this->secretaryA)->test(AppointmentForm::class)
        ->set('client_id', (string) $this->b->client->id)->set('lawyer_id', (string) $this->b->lawyer->id)
        ->set('date', '2026-11-12')->set('time', '10:00')->set('subject', 'Tentative')
        ->call('save')->assertHasErrors(['client_id', 'lawyer_id']);

    Livewire::actingAs($this->secretaryA)->test(CaseForm::class)
        ->set('client_id', (string) $this->clientA->id)->set('lead_lawyer_id', (string) $this->b->lawyer->id)
        ->set('title', 'Dossier A')->call('save')->assertHasErrors(['lead_lawyer_id']);

    expect(Appointment::query()->count())->toBe(0);
});

it('numérote factures et dossiers séparément dans chaque cabinet', function () {
    $case = LegalCase::factory()->create(['client_id' => $this->clientA->id, 'lead_lawyer_id' => $this->lawyerA->id, 'fee_type' => FeeType::Flat, 'fee_amount' => '800', 'currency' => 'USD']);
    $invoice = app(InvoiceService::class)->issue(app(InvoiceBuilder::class)->forCase($case, $this->accountantA), $this->accountantA, false);

    expect($invoice->number)->toBe($this->b->invoice->number)            // même numéro, sans conflit
        ->and($invoice->number)->toEndWith('-00001')
        ->and($invoice->firm_id)->toBe($this->firm->id)
        ->and($this->b->invoice->firm_id)->toBe($this->b->firm->id);
});

it('garde des paramètres, des modèles d\'e-mails et une comptabilité propres à chaque cabinet', function () {
    EmailTemplate::query()->where('key', 'confirmation')->update(['subject' => 'Objet du cabinet A']);

    expect(app(SettingsService::class)->get('firm.name'))->toBe('Cabinet Mukendi & Associés')
        ->and(inOtherFirm(fn () => app(SettingsService::class)->get('firm.name'), $this->b->firm))->toBe('Cabinet B')
        ->and(inOtherFirm(fn () => EmailTemplate::query()->where('key', 'confirmation')->value('subject'), $this->b->firm))->not->toBe('Objet du cabinet A');

    // La balance du cabinet A ignore les écritures de la facture du cabinet B.
    $balance = app(AccountingReports::class)->trialBalance(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    expect(collect($balance['rows'] ?? $balance)->count())->toBe(0);
});

it('n\'affiche pas le journal d\'audit d\'un autre cabinet', function () {
    expect(AuditActivity::query()->where('subject_type', (new Invoice)->getMorphClass())->where('subject_id', $this->b->invoice->id)->exists())->toBeFalse()
        ->and(inOtherFirm(fn () => AuditActivity::query()->where('subject_id', $this->b->invoice->id)->where('subject_type', (new Invoice)->getMorphClass())->exists(), $this->b->firm))->toBeTrue();
});

it('traite chaque job de la file dans le cabinet qui l\'a créé', function () {
    config(['queue.default' => 'database']);
    inOtherFirm(fn () => app(AppointmentService::class)->sendConfirmation($this->b->appointment), $this->b->firm);

    // Le worker tourne hors du cabinet B (ici : dans le cabinet A) et retrouve pourtant le journal de B.
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    $log = inOtherFirm(fn () => EmailLog::query()->where('template_key', 'confirmation')->sole(), $this->b->firm);
    expect($log->status)->toBe(EmailStatus::Sent)
        ->and(EmailLog::query()->count())->toBe(0)                      // rien dans le cabinet A
        ->and(app(CurrentFirm::class)->id())->toBe($this->firm->id);  // contexte rétabli après le job
});

it('exécute les tâches planifiées pour chaque cabinet', function () {
    Appointment::factory()->at(Carbon::parse('2026-11-10 11:00'))->create(['client_id' => $this->clientA->id, 'lawyer_id' => $this->lawyerA->id]);
    Carbon::setTestNow('2026-11-09 18:05');

    $this->artisan('lexcabinet:send-reminders')->assertSuccessful();

    expect(EmailLog::query()->where('template_key', 'reminder')->count())->toBe(1)
        ->and(inOtherFirm(fn () => EmailLog::query()->where('template_key', 'reminder')->count(), $this->b->firm))->toBe(1);
});

it('range une réponse reçue dans le cabinet de l\'e-mail d\'origine', function () {
    $log = inOtherFirm(function () {
        app(AppointmentService::class)->sendConfirmation($this->b->appointment);

        return EmailLog::query()->where('template_key', 'confirmation')->sole();
    }, $this->b->firm);

    app(CurrentFirm::class)->forget();   // la relève tourne hors cabinet
    $reply = app(ConversationService::class)->import(new InboundEmail('r1@x', $log->message_id, [], $this->b->client->email, null, 'Re', 'Merci', '', now()));
    app(CurrentFirm::class)->set($this->firm);

    expect($reply->firm_id)->toBe($this->b->firm->id)
        ->and($reply->sender_id)->toBe($this->b->client->id)
        ->and(app(ConversationService::class)->visibleThreads($this->secretaryA)->count())->toBe(0);
});

it('refuse toute donnée métier hors cabinet : lecture vide, création impossible', function () {
    app(CurrentFirm::class)->forget();

    expect(Client::query()->count())->toBe(0)
        ->and(fn () => Client::factory()->create())->toThrow(LogicException::class);
});

it('refuse de rattacher une donnée à un autre cabinet que le cabinet courant', function () {
    // firm_id n'est pas assignable en masse : il est ignoré et remplacé par le cabinet courant.
    expect(Client::query()->create(['firm_id' => $this->b->firm->id, 'type' => 'individual', 'name' => 'Intrus'])->firm_id)->toBe($this->firm->id);

    // Forcé explicitement : refusé.
    expect(fn () => Client::factory()->make(['name' => 'Intrus 2'])->forceFill(['firm_id' => $this->b->firm->id])->save())
        ->toThrow(LogicException::class);

    $client = $this->clientA;
    $client->firm_id = $this->b->firm->id;
    expect(fn () => $client->save())->toThrow(LogicException::class);
});

it('prépare un nouveau cabinet prêt à l\'emploi (plan comptable, modèles, caisses, paramètres)', function () {
    $firm = app(FirmProvisioner::class)->create('Cabinet Nouveau', ['firm.email' => 'contact@nouveau.example']);

    inOtherFirm(function () {
        expect(\App\Models\Account::query()->where('code', '411')->exists())->toBeTrue()
            ->and(EmailTemplate::query()->count())->toBe(count(\App\Support\EmailTemplateDefaults::all()))
            ->and(\App\Models\TreasuryAccount::query()->where('type', 'cash')->count())->toBe(2)
            ->and(app(SettingsService::class)->get('firm.name'))->toBe('Cabinet Nouveau')
            ->and(app(SettingsService::class)->get('firm.email'))->toBe('contact@nouveau.example');
    }, $firm);

    // Rien n'a fuité dans le cabinet A.
    expect(app(SettingsService::class)->get('firm.email'))->not->toBe('contact@nouveau.example');
});

it('place chaque requête dans le cabinet de l\'utilisateur connecté', function () {
    app(CurrentFirm::class)->forget();   // comme en production : aucun cabinet avant la requête
    $this->actingAs($this->b->lawyer)->get(route('cases.show', $this->b->case))->assertOk()->assertSee('Dossier secret B');
    expect(app(CurrentFirm::class)->id())->toBe($this->b->firm->id);
});

it('garde une seule adresse e-mail par compte sur toute la plateforme', function () {
    expect(fn () => DB::table('users')->insert(['firm_id' => $this->firm->id, 'name' => 'X', 'email' => $this->b->lawyer->email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
