<?php

use App\Enums\Role;
use App\Livewire\Cases\CaseForm;
use App\Livewire\Cases\CaseIndex;
use App\Livewire\Cases\CaseShow;
use App\Models\Client;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
});

function caseWithDocument(array $attributes = []): array
{
    $case = LegalCase::factory()->create($attributes);
    Storage::disk('local')->put("cases/{$case->id}/piece.pdf", '%PDF-1.4 contenu');
    $doc = $case->documents()->create([
        'category' => 'procedure', 'original_name' => 'assignation.pdf', 'path' => "cases/{$case->id}/piece.pdf",
        'mime_type' => 'application/pdf', 'size' => 16,
    ]);

    return [$case, $doc];
}

/*
| Scénario de recette 12 : un avocat ne peut ouvrir ni le dossier ni les pièces d'un dossier
| qui ne lui est pas attribué ; il peut y saisir son temps (sur ses dossiers), mais ne peut
| créer ni dossier, ni rendez-vous, ni facture.
*/
it('scénario 12 : un avocat n\'ouvre ni le dossier ni les pièces d\'un dossier qui ne lui est pas attribué', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    [$case, $doc] = caseWithDocument(['title' => 'Dossier d\'un confrère']);

    $this->actingAs($lawyer)->get(route('cases.show', $case))->assertForbidden();
    $this->actingAs($lawyer)->get(route('cases.documents.download', [$case, $doc]))->assertForbidden();

    Livewire::actingAs($lawyer)->test(CaseIndex::class)->assertDontSee('Dossier d\'un confrère');
});

it('scénario 12 : un avocat ouvre ses dossiers et ceux où il est collaborateur, pièces comprises', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    [$lead, $leadDoc] = caseWithDocument(['lead_lawyer_id' => $lawyer->id, 'title' => 'Mon dossier']);
    [$collab, $collabDoc] = caseWithDocument(['title' => 'Dossier en collaboration']);
    $collab->collaborators()->attach($lawyer->id);

    $this->actingAs($lawyer)->get(route('cases.show', $lead))->assertOk();
    $this->actingAs($lawyer)->get(route('cases.show', $collab))->assertOk();
    $this->actingAs($lawyer)->get(route('cases.documents.download', [$lead, $leadDoc]))->assertOk()->assertDownload('assignation.pdf');
    $this->actingAs($lawyer)->get(route('cases.documents.download', [$collab, $collabDoc]))->assertOk();

    Livewire::actingAs($lawyer)->test(CaseIndex::class)
        ->assertSee('Mon dossier')
        ->assertSee('Dossier en collaboration');
});

it('scénario 12 : un avocat saisit son temps sur son dossier, pas sur celui d\'un autre', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $mine = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $theirs = LegalCase::factory()->create();

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $mine])
        ->set('timeHours', '2')->set('timeMinutes', '30')
        ->set('timeDescription', 'Préparation de l\'audience')
        ->call('logTime')
        ->assertHasNoErrors();

    $entry = TimeEntry::query()->firstOrFail();
    expect($entry->user_id)->toBe($lawyer->id)->and($entry->minutes)->toBe(150);

    expect($lawyer->can('logTime', $theirs))->toBeFalse();
    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $theirs])->assertForbidden();
});

it('scénario 12 : un avocat ne peut créer ni modifier un dossier, ni ajouter pièce ou événement', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $mine = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);

    $this->actingAs($lawyer)->get(route('cases.create'))->assertForbidden();
    $this->actingAs($lawyer)->get(route('cases.edit', $mine))->assertForbidden();
    $this->actingAs($lawyer)->get(route('cases.show', $mine))->assertOk()
        ->assertDontSee('Nouveau dossier')
        ->assertDontSee('Ajouter la pièce')
        ->assertDontSee('Ajouter au journal')
        ->assertSee('Ajouter mon temps');

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $mine])
        ->set('upload', UploadedFile::fake()->create('piece.pdf', 10, 'application/pdf'))
        ->call('uploadDocument')
        ->assertForbidden();

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $mine])
        ->set('eventDescription', 'Note')
        ->call('addEvent')
        ->assertForbidden();

    Livewire::actingAs($lawyer)->test(CaseShow::class, ['case' => $mine])
        ->set('status', 'closed')
        ->assertForbidden();
});

/*
| Scénario de recette 15 : une secrétaire crée un dossier mais ne voit aucun bouton de facturation.
| (La route de création de facture arrive en phase 4 ; le module Factures lui est déjà refusé.)
*/
it('scénario 15 : la secrétaire crée un dossier sans voir de facturation', function () {
    $secretary = userWithRoles(Role::Secretary);
    $lawyer = userWithRoles(Role::Lawyer);
    $client = Client::factory()->create();

    Livewire::actingAs($secretary)->test(CaseForm::class)
        ->set('client_id', (string) $client->id)
        ->set('title', 'Dossier créé par le secrétariat')
        ->set('lead_lawyer_id', (string) $lawyer->id)
        ->set('fee_type', 'hourly')
        ->set('fee_amount', '120')
        ->call('save')
        ->assertHasNoErrors();

    $case = LegalCase::query()->firstOrFail();
    $case->timeEntries()->create(['user_id' => $lawyer->id, 'worked_on' => today(), 'minutes' => 150, 'description' => 'Travail', 'billable' => true]);

    $this->actingAs($secretary)->get(route('cases.show', $case))->assertOk()
        ->assertDontSee('Factur', false)   // ni « Facturer », ni « Facture »
        ->assertDontSee(route('module', 'factures'));
    $this->actingAs($secretary)->get(route('module', 'factures'))->assertForbidden();
    expect($secretary->can('logTime', $case))->toBeFalse();
});

/*
| Scénario de recette 16 : un utilisateur avocat + secrétaire peut créer un dossier et saisir son temps.
*/
it('scénario 16 : avocat + secrétaire crée un dossier et saisit son temps', function () {
    $both = userWithRoles(Role::Lawyer, Role::Secretary);
    $client = Client::factory()->create();

    $component = Livewire::actingAs($both)->test(CaseForm::class);
    expect($component->get('lead_lawyer_id'))->toBe((string) $both->id); // responsable par défaut

    $component->set('client_id', (string) $client->id)
        ->set('title', 'Dossier de Me Kanku')
        ->set('fee_amount', '1000')
        ->call('save')
        ->assertHasNoErrors();

    $case = LegalCase::query()->firstOrFail();

    Livewire::actingAs($both)->test(CaseShow::class, ['case' => $case])
        ->set('timeHours', '1')->set('timeMinutes', '0')
        ->set('timeDescription', 'Premier rendez-vous')
        ->call('logTime')
        ->assertHasNoErrors();

    expect($case->timeEntries()->where('user_id', $both->id)->count())->toBe(1);
});

it('refuse les dossiers au caissier et au comptable', function (Role $role) {
    $case = LegalCase::factory()->create();

    $this->actingAs(userWithRoles($role))->get(route('cases.index'))->assertForbidden();
    $this->actingAs(userWithRoles($role))->get(route('cases.show', $case))->assertForbidden();
})->with([Role::Cashier, Role::Accountant]);
