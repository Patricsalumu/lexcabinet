<?php

use App\Enums\Role;
use App\Livewire\Admin\MailSettings;
use App\Mail\TemplatedMail;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailReply;
use App\Models\Setting;
use App\Services\AppointmentService;
use App\Services\ConversationService;
use App\Services\Mailbox\FirmMailConfig;
use App\Services\Mailbox\ImapMailbox;
use App\Services\Mailbox\InboundEmail;
use App\Services\SettingsService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Phase M3 : messagerie propre à chaque cabinet (SMTP d'envoi, boîte IMAP des réponses),
| mots de passe chiffrés, repli sur la messagerie de la plateforme.
*/

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    config(['mail.from.address' => 'lexcabinet@barapp.cloud']);
    $this->admin = userWithRoles(Role::Admin);
});

/** Boîte IMAP simulée : messages fournis par le test, comptes reçus mémorisés. */
class FakeFirmMailbox extends ImapMailbox
{
    public static array $messages = [];

    public static array $accounts = [];

    public function __construct(private readonly ?array $acct = null)
    {
        parent::__construct($acct);
        if ($acct) {
            self::$accounts[] = $acct;
        }
    }

    public function fetchSince(Carbon $since, callable $wanted): array
    {
        $box = $this->acct['username'] ?? 'platform';

        return array_values(array_filter(self::$messages[$box] ?? [], fn (InboundEmail $m) => $wanted($m->messageId, $m->citedIds())));
    }

    public function folders(): array
    {
        return ['INBOX', 'Sent'];
    }

    public function appendToSent(string $rawMessage): void {}
}

function configureFirmMail(array $values = []): void
{
    app(SettingsService::class)->setMany($values + [
        'mail.smtp_enabled' => true, 'mail.smtp_host' => 'mail.cabinet.example', 'mail.smtp_port' => 465, 'mail.smtp_encryption' => 'ssl',
        'mail.smtp_username' => 'contact@cabinet.example', 'mail.smtp_password' => 'SecretSmtp!2026',
        'mail.imap_enabled' => true, 'mail.imap_host' => 'mail.cabinet.example', 'mail.imap_port' => 993, 'mail.imap_encryption' => 'ssl',
        'mail.imap_username' => 'contact@cabinet.example', 'mail.imap_password' => 'SecretImap!2026',
    ]);
}

it('chiffre les mots de passe en base et en cache, sans jamais les journaliser ni les exposer', function () {
    configureFirmMail();
    $settings = app(SettingsService::class);

    $raw = Setting::query()->where('key', 'mail.smtp_password')->value('value');
    expect($raw)->not->toContain('SecretSmtp')
        ->and(Crypt::decryptString($raw))->toBe('SecretSmtp!2026')
        ->and($settings->get('mail.smtp_password'))->toBe('SecretSmtp!2026')
        ->and($settings->all()['mail.smtp_password'])->toBeNull()            // tableau transmis aux vues et PDF
        ->and($settings->hasSecret('mail.imap_password'))->toBeTrue();

    $cached = serialize(Cache::get('lexcabinet.settings.'.$this->firm->id));
    expect($cached)->not->toContain('SecretSmtp')->not->toContain('SecretImap');

    $logs = AuditActivity::query()->where('description', 'like', 'Paramètre modifié : Mot de passe%')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->every(fn ($a) => ! str_contains(json_encode($a->properties), 'Secret')))->toBeTrue();
});

it('enregistre la messagerie depuis les paramètres ; un mot de passe laissé vide est conservé', function () {
    Livewire::actingAs($this->admin)->test(MailSettings::class)
        ->set('smtp_enabled', true)->set('smtp_host', 'mail.cabinet.example')->set('smtp_username', 'contact@cabinet.example')
        ->call('save')->assertHasErrors(['smtp_password']);   // aucun mot de passe encore enregistré

    Livewire::actingAs($this->admin)->test(MailSettings::class)
        ->set('smtp_enabled', true)->set('smtp_host', 'mail.cabinet.example')->set('smtp_username', 'contact@cabinet.example')
        ->set('smtp_password', 'Premier!2026')->call('save')->assertHasNoErrors();

    Livewire::actingAs($this->admin)->test(MailSettings::class)
        ->assertSet('smtp_password', '')->assertSee('enregistré ; laisser vide pour le garder')
        ->set('smtp_host', 'smtp.cabinet.example')->call('save')->assertHasNoErrors();

    expect(app(SettingsService::class)->get('mail.smtp_password'))->toBe('Premier!2026')
        ->and(app(SettingsService::class)->get('mail.smtp_host'))->toBe('smtp.cabinet.example');
});

it('réserve la messagerie du cabinet à son administrateur', function (Role $role) {
    $this->actingAs(userWithRoles($role))->get(route('settings.mail'))->assertForbidden();
})->with([Role::Secretary, Role::Lawyer, Role::Cashier, Role::Accountant]);

it('garde la messagerie propre à chaque cabinet', function () {
    configureFirmMail();

    inOtherFirm(function () {
        $this->seed(SettingsSeeder::class);
        expect(app(FirmMailConfig::class)->smtpConfigured())->toBeFalse()
            ->and(app(SettingsService::class)->get('mail.smtp_password'))->toBeNull()
            ->and(app(FirmMailConfig::class)->fromAddress())->toBe('lexcabinet@barapp.cloud');   // repli : la plateforme
    });
});

it('envoie par le SMTP du cabinet, depuis son adresse, quand il est configuré', function () {
    configureFirmMail(['mail.from_address' => 'cabinet@cabinet.example']);
    $mail = app(FirmMailConfig::class);

    // Transport de développement : le SMTP du cabinet n'est pas utilisé.
    expect($mail->smtpConfigured())->toBeTrue()->and($mail->usesOwnSmtp())->toBeFalse();

    config(['mail.default' => 'smtp']);
    $name = $mail->registerMailer();
    expect($mail->usesOwnSmtp())->toBeTrue()
        ->and($mail->fromAddress())->toBe('cabinet@cabinet.example')
        ->and(config("mail.mailers.{$name}"))->toMatchArray(['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'mail.cabinet.example', 'port' => 465, 'username' => 'contact@cabinet.example', 'password' => 'SecretSmtp!2026']);

    // L'e-mail emprunte bien le transport du cabinet (remplacé ici par un transport « array »).
    $appointment = Appointment::factory()->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);
    config(['mail.default' => 'array']);
    app(AppointmentService::class)->sendConfirmation($appointment);
    $log = EmailLog::query()->where('template_key', 'confirmation')->sole();

    config(['mail.default' => 'smtp', 'mail.mailers.firm_test' => ['transport' => 'array']]);
    $fake = Mockery::mock(FirmMailConfig::class, [app(SettingsService::class), app(\App\Support\Tenancy\CurrentFirm::class)])->makePartial();
    $fake->shouldReceive('usesOwnSmtp')->andReturnTrue();
    $fake->shouldReceive('registerMailer')->andReturn('firm_test');
    $fake->shouldReceive('fromAddress')->andReturn('cabinet@cabinet.example');
    app()->instance(FirmMailConfig::class, $fake);

    (new TemplatedMail($log->id))->to($log->recipient_email)->send(app('mail.manager'));

    $sent = collect(app('mail.manager')->mailer('firm_test')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
    expect($sent->getFrom()[0]->getAddress())->toBe('cabinet@cabinet.example')
        ->and($sent->getHeaders()->get(TemplatedMail::MAILER_HEADER)->getBodyAsString())->toBe('firm_test');
});

it('teste l\'envoi et la connexion à la boîte depuis les paramètres', function () {
    configureFirmMail();
    Mail::fake();
    app()->bind(ImapMailbox::class, fn ($app, $params) => new FakeFirmMailbox($params['account'] ?? null));

    Livewire::actingAs($this->admin)->test(MailSettings::class)
        ->call('testSmtp')->assertSet('testOk', true)->assertSee('Envoi accepté par le serveur')
        ->call('testImap')->assertSet('testOk', true)->assertSee('Connexion réussie. Dossiers : INBOX, Sent.');

    expect(end(FakeFirmMailbox::$accounts)['password'])->toBe('SecretImap!2026');
});

it('relève la boîte de chaque cabinet et n\'y importe que les réponses de ce cabinet', function () {
    FakeFirmMailbox::$messages = [];
    app()->bind(ImapMailbox::class, fn ($app, $params) => new FakeFirmMailbox($params['account'] ?? null));
    config(['imap.accounts.default.host' => null]);   // pas de boîte de plateforme

    // Cabinet A : e-mail envoyé, sans boîte propre.
    $appointmentA = Appointment::factory()->create(['lawyer_id' => userWithRoles(Role::Lawyer)->id]);
    app(AppointmentService::class)->sendConfirmation($appointmentA);
    $logA = EmailLog::query()->where('template_key', 'confirmation')->sole();

    // Cabinet B : sa propre boîte.
    [$firmB, $logB, $clientB] = inOtherFirm(function ($firm) {
        $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
        configureFirmMail(['mail.imap_username' => 'boite@cabinet-b.example']);
        $client = Client::factory()->create();
        $appointment = Appointment::factory()->create(['client_id' => $client->id, 'lawyer_id' => userWithRoles(Role::Lawyer)->id]);
        app(AppointmentService::class)->sendConfirmation($appointment);

        return [$firm, EmailLog::query()->where('template_key', 'confirmation')->sole(), $client];
    });

    FakeFirmMailbox::$messages['boite@cabinet-b.example'] = [
        new InboundEmail('b1@x', $logB->message_id, [], $clientB->email, null, 'Re', 'Réponse B', '', now()),
        new InboundEmail('a1@x', $logA->message_id, [], 'intrus@example.com', null, 'Re', 'Réponse A égarée', '', now()),
    ];

    expect(app(ConversationService::class)->fetch())->toBe(1);

    $imported = EmailReply::query()->withoutGlobalScopes()->get();
    expect($imported)->toHaveCount(1)
        ->and($imported->first()->firm_id)->toBe($firmB->id)
        ->and($imported->first()->message_id)->toBe('b1@x')
        ->and(ConversationService::lastFetchAt($firmB->id))->not->toBeNull();
});

it('affiche la messagerie de chaque cabinet dans le diagnostic', function () {
    configureFirmMail();

    $this->artisan('lexcabinet:mail-check')
        ->expectsOutputToContain('Cabinet · '.$this->firm->name)
        ->expectsOutputToContain('SMTP du cabinet (mail.cabinet.example)')
        ->run();
});
