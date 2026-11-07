<?php

use App\Enums\Role;
use App\Livewire\Cases\CaseShow;
use App\Models\AuditActivity;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('calcule les heures facturables non facturées (base de la facturation horaire)', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $case->timeEntries()->createMany([
        ['user_id' => $lawyer->id, 'worked_on' => today(), 'minutes' => 90, 'description' => 'A', 'billable' => true],
        ['user_id' => $lawyer->id, 'worked_on' => today(), 'minutes' => 60, 'description' => 'B', 'billable' => true],
        ['user_id' => $lawyer->id, 'worked_on' => today(), 'minutes' => 45, 'description' => 'C', 'billable' => false],
        ['user_id' => $lawyer->id, 'worked_on' => today(), 'minutes' => 120, 'description' => 'D', 'billable' => true, 'invoice_id' => 99],
    ]);

    expect($case->unbilledMinutes())->toBe(150)
        ->and(TimeEntry::formatMinutes(150))->toBe('2 h 30');

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $case])
        ->assertSee('2 h 30 facturables non facturées')
        ->assertSee('Facturé')
        ->assertSee('Non facturable');
});

it('refuse une durée nulle, trop longue ou une date future', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $case])
        ->set('timeHours', '0')->set('timeMinutes', '0')->set('timeDescription', 'Rien')
        ->call('logTime')->assertHasErrors('timeHours')
        ->set('timeHours', '25')->call('logTime')->assertHasErrors('timeHours')
        ->set('timeHours', '1')->set('timeDate', today()->addDay()->toDateString())
        ->call('logTime')->assertHasErrors('timeDate');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('trace la saisie du temps dans le journal d\'audit', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $case = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $case])
        ->set('timeDescription', 'Recherche jurisprudentielle')->call('logTime');

    expect(AuditActivity::query()->where('description', 'Temps saisi')->where('causer_id', $lawyer->id)->exists())->toBeTrue();
});

it('stocke une pièce jointe sur le disque privé, hors du dossier public', function () {
    Storage::fake('local');
    Storage::fake('public');
    $secretary = userWithRoles(Role::Secretary);
    $case = LegalCase::factory()->create();

    Livewire::actingAs($secretary)->test(CaseShow::class, ['case' => $case])
        ->set('uploadCategory', 'evidence')
        ->set('upload', UploadedFile::fake()->create('Contrat de bail.pdf', 300, 'application/pdf'))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $doc = Document::query()->firstOrFail();
    expect($doc->original_name)->toBe('Contrat de bail.pdf')
        ->and($doc->category->value)->toBe('evidence')
        ->and($doc->path)->toStartWith("cases/{$case->id}/")
        ->and($doc->uploaded_by)->toBe($secretary->id);

    Storage::disk('local')->assertExists($doc->path);
    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(AuditActivity::query()->where('description', 'Pièce jointe ajoutée')->exists())->toBeTrue();

    $this->actingAs($secretary)->get(route('cases.documents.download', [$case, $doc]))->assertOk()->assertDownload('Contrat de bail.pdf');
    expect(AuditActivity::query()->where('description', 'Pièce jointe consultée')->exists())->toBeTrue();
});

it('refuse un fichier de plus de 20 Mo ou d\'un format non accepté', function () {
    Storage::fake('local');
    $case = LegalCase::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Secretary))->test(CaseShow::class, ['case' => $case])
        ->set('upload', UploadedFile::fake()->create('trop-gros.pdf', 20481, 'application/pdf'))
        ->call('uploadDocument')
        ->assertHasErrors('upload')
        ->set('upload', UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'))
        ->call('uploadDocument')
        ->assertHasErrors('upload');

    expect(Document::query()->count())->toBe(0);
});

it('refuse une pièce demandée via un autre dossier', function () {
    Storage::fake('local');
    $secretary = userWithRoles(Role::Secretary);
    $a = LegalCase::factory()->create();
    $b = LegalCase::factory()->create();
    $doc = $b->documents()->create(['category' => 'other', 'original_name' => 'x.pdf', 'path' => 'cases/x.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);

    $this->actingAs($secretary)->get(route('cases.documents.download', [$a, $doc]))->assertNotFound();
});
