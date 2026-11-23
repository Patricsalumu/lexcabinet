<?php

use App\Enums\FeeType;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Document;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
use App\Services\SettingsService;
use App\Services\Tenancy\FirmImporter;
use App\Support\Tenancy\CurrentFirm;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| Phase M4 : passage d'un cabinet sur son propre serveur. Export chiffré de ses seules données
| et de ses fichiers, puis import sur une installation neuve, sans perte ni mélange.
*/

const ARCHIVE_PASSWORD = 'Transfert-Serveur-2026';

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    Storage::fake('local');
    $this->archive = sys_get_temp_dir().'/lexcabinet-test-'.uniqid().'.zip';

    // Cabinet A : ne doit jamais apparaître dans l'archive de B.
    userWithRoles(Role::Secretary);
    Client::factory()->create(['name' => 'Client du cabinet A', 'email' => 'client.a@example.cd']);

    // Cabinet B : données complètes.
    $this->b = inOtherFirm(function (Firm $firm) {
        $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
        app(SettingsService::class)->setMany(['firm.name' => 'Cabinet B', 'mail.smtp_password' => 'MotDePasseSmtp!']);
        \App\Models\ExchangeRate::factory()->create(['rate_date' => '2026-11-09']);
        $admin = userWithRoles(Role::Admin, Role::Lawyer);
        $collaborator = userWithRoles(Role::Lawyer);
        $accountant = userWithRoles(Role::Accountant);
        $client = Client::factory()->create(['name' => 'Client du cabinet B']);
        $case = LegalCase::factory()->create(['client_id' => $client->id, 'lead_lawyer_id' => $admin->id, 'fee_type' => FeeType::Flat, 'fee_amount' => '900', 'currency' => 'USD']);
        $case->collaborators()->attach($collaborator->id);
        $invoice = app(InvoiceService::class)->issue(app(InvoiceBuilder::class)->forCase($case, $accountant), $accountant, false)->fresh();
        Storage::disk('local')->put("firms/{$firm->id}/cases/{$case->id}/contrat.pdf", '%PDF contrat B');
        Document::query()->create(['case_id' => $case->id, 'original_name' => 'contrat.pdf', 'path' => "firms/{$firm->id}/cases/{$case->id}/contrat.pdf",
            'category' => 'evidence', 'mime_type' => 'application/pdf', 'size' => 14, 'uploaded_by' => $admin->id]);

        return (object) compact('firm', 'admin', 'collaborator', 'client', 'case', 'invoice');
    });
});

afterEach(function () {
    Carbon::setTestNow();
    @unlink($this->archive);
});

function exportFirmB(): void
{
    test()->artisan('lexcabinet:export-firm', ['firm' => test()->b->firm->id, '--output' => test()->archive, '--password' => ARCHIVE_PASSWORD])->assertSuccessful();
}

/** Simule un serveur neuf : schéma à jour, aucune donnée, aucun fichier. */
function wipeInstallation(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach (Schema::getTables(DB::getDatabaseName()) as $table) {
        if ($table['name'] !== 'migrations') {
            DB::table($table['name'])->delete();
        }
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    Storage::fake('local');
    app(CurrentFirm::class)->forget();
}

function zipEntry(string $archive, string $name, ?string $password = null): string|false
{
    $zip = new ZipArchive;
    $zip->open($archive);
    if ($password) {
        $zip->setPassword($password);
    }
    $content = $zip->getFromName($name);
    $zip->close();

    return $content;
}

it('exporte les seules données du cabinet, dans une archive chiffrée, sans secrets', function () {
    exportFirmB();

    expect(zipEntry($this->archive, 'manifest.json'))->toBeFalse()             // illisible sans mot de passe
        ->and(zipEntry($this->archive, 'data/clients.jsonl', 'mauvais-mot-de-passe'))->toBeFalse();

    $manifest = json_decode(zipEntry($this->archive, 'manifest.json', ARCHIVE_PASSWORD), true);
    $clients = zipEntry($this->archive, 'data/clients.jsonl', ARCHIVE_PASSWORD);
    $users = zipEntry($this->archive, 'data/users.jsonl', ARCHIVE_PASSWORD);
    $settings = zipEntry($this->archive, 'data/settings.jsonl', ARCHIVE_PASSWORD);

    expect($manifest['firm']['id'])->toBe($this->b->firm->id)
        ->and($manifest['counts']['clients'])->toBe(1)
        ->and($manifest['counts']['users'])->toBe(3)
        ->and($manifest['counts']['case_user'])->toBe(1)
        ->and($manifest['files'])->toHaveKey("firms/{$this->b->firm->id}/cases/{$this->b->case->id}/contrat.pdf")
        ->and($clients)->toContain('Client du cabinet B')->not->toContain('Client du cabinet A')
        ->and($users)->not->toContain('"two_factor_secret":"')
        ->and($settings)->not->toContain('MotDePasseSmtp');

    // Le cabinet garde la trace de l'export dans son propre journal.
    expect(inOtherFirm(fn () => \App\Models\AuditActivity::query()->where('description', 'Données du cabinet exportées pour un transfert de serveur')->exists(), $this->b->firm))->toBeTrue();
});

it('importe le cabinet sur un serveur neuf, identifiants, rôles, numéros et fichiers compris', function () {
    exportFirmB();
    $b = $this->b;
    $invoiceNumber = $b->invoice->number;
    $passwordHash = $b->admin->password;
    wipeInstallation();

    $this->artisan('lexcabinet:import-firm', ['archive' => $this->archive, '--password' => ARCHIVE_PASSWORD])
        ->expectsOutputToContain('Cabinet importé : '.$b->firm->name)
        ->assertSuccessful();

    expect(Firm::query()->pluck('id')->all())->toBe([$b->firm->id]);

    app(CurrentFirm::class)->run($b->firm->id, function () use ($b, $invoiceNumber, $passwordHash) {
        $admin = User::query()->findOrFail($b->admin->id);
        expect($admin->hasRole(Role::Admin->value))->toBeTrue()
            ->and($admin->hasRole(Role::Lawyer->value))->toBeTrue()
            ->and($admin->password)->toBe($passwordHash)                       // même mot de passe de connexion
            ->and(Hash::check('password', $admin->password))->toBeTrue()
            ->and(Client::query()->pluck('name')->all())->toBe(['Client du cabinet B'])
            ->and(LegalCase::query()->findOrFail($b->case->id)->collaborators()->pluck('users.id')->all())->toBe([$b->collaborator->id])
            ->and(Invoice::query()->findOrFail($b->invoice->id)->number)->toBe($invoiceNumber)
            ->and(app(SettingsService::class)->get('firm.name'))->toBe('Cabinet B')
            ->and(app(SettingsService::class)->get('mail.smtp_password'))->toBeNull();   // à ressaisir

        Storage::disk('local')->assertExists("firms/{$b->firm->id}/cases/{$b->case->id}/contrat.pdf");

        // La numérotation reprend à la suite, sans doublon.
        $next = app(\App\Services\NumberingService::class);
        DB::transaction(fn () => expect($next->nextCaseReference(2026))->not->toBe($b->case->reference));
    });

    // Le cabinet importé est utilisable : son administrateur se connecte et voit ses données.
    $this->actingAs(User::query()->withoutGlobalScopes()->find($b->admin->id))->get(route('clients.index'))->assertOk()->assertSee('Client du cabinet B');
});

it('vérifie sans rien écrire avec --dry-run', function () {
    exportFirmB();
    wipeInstallation();

    $this->artisan('lexcabinet:import-firm', ['archive' => $this->archive, '--password' => ARCHIVE_PASSWORD, '--dry-run' => true])
        ->expectsOutputToContain('Archive valide')->assertSuccessful();

    expect(Firm::query()->count())->toBe(0)->and(DB::table('clients')->count())->toBe(0);
});

it('refuse un mauvais mot de passe', function () {
    exportFirmB();
    wipeInstallation();

    $this->artisan('lexcabinet:import-firm', ['archive' => $this->archive, '--password' => 'pas-le-bon-mot-de-passe'])
        ->expectsOutputToContain('Mot de passe incorrect')->assertFailed();
    expect(Firm::query()->count())->toBe(0);
});

it('refuse d\'écraser des données existantes (cabinet, identifiants, e-mails, fichiers)', function () {
    exportFirmB();

    // Même installation : tout est déjà là.
    $problems = app(FirmImporter::class)->check($this->archive, ARCHIVE_PASSWORD);
    expect(implode("\n", $problems))->toContain("Un cabinet n° {$this->b->firm->id} existe déjà")
        ->toContain('Table clients')
        ->toContain($this->b->admin->email)
        ->toContain('contrat.pdf');

    $this->artisan('lexcabinet:import-firm', ['archive' => $this->archive, '--password' => ARCHIVE_PASSWORD])->assertFailed();
    expect(DB::table('clients')->count())->toBe(2);   // rien de doublé
});

it('refuse une archive altérée', function () {
    exportFirmB();
    $zip = new ZipArchive;
    $zip->open($this->archive);
    $zip->addFromString('data/clients.jsonl', '{"id":999,"name":"Client inventé"}'."\n");
    $zip->setEncryptionName('data/clients.jsonl', ZipArchive::EM_AES_256, ARCHIVE_PASSWORD);
    $zip->close();
    wipeInstallation();

    expect(app(FirmImporter::class)->check($this->archive, ARCHIVE_PASSWORD))->toContain('Fichier de données altéré ou illisible : data/clients.jsonl.');
});

it('refuse d\'importer sur un schéma plus ancien que celui de l\'archive', function () {
    exportFirmB();
    wipeInstallation();
    $last = DB::table('migrations')->orderByDesc('id')->first();
    DB::table('migrations')->where('id', $last->id)->delete();

    expect(implode(' ', app(FirmImporter::class)->check($this->archive, ARCHIVE_PASSWORD)))->toContain('Migrations manquantes : '.$last->migration);
});

it('exige un mot de passe d\'archive solide', function () {
    $this->artisan('lexcabinet:export-firm', ['firm' => $this->b->firm->id, '--output' => $this->archive, '--password' => 'court'])
        ->expectsOutputToContain('au moins 12 caractères')->assertFailed();
    expect(file_exists($this->archive))->toBeFalse();
});
