<?php

use App\Enums\ClientType;
use App\Enums\Role;
use App\Livewire\Clients\ClientForm;
use App\Livewire\Clients\ClientIndex;
use App\Livewire\Clients\ClientShow;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\LegalCase;
use Livewire\Livewire;

it('crée une personne physique, avec pièce d\'identité et consentement', function () {
    $secretary = userWithRoles(Role::Secretary);

    Livewire::actingAs($secretary)->test(ClientForm::class)
        ->set('type', 'individual')
        ->set('name', 'Esther Kalala')
        ->set('phone', '+243 85 220 7731')
        ->set('email', 'Esther.Kalala@example.cd')
        ->set('id_document_type', 'voter_card')
        ->set('id_document_number', '1234567')
        ->set('rccm', 'ignoré pour une personne physique')
        ->set('email_consent', true)
        ->call('save')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Esther Kalala')->firstOrFail();
    expect($client->type)->toBe(ClientType::Individual)
        ->and($client->email)->toBe('esther.kalala@example.cd')
        ->and($client->rccm)->toBeNull()
        ->and($client->canReceiveEmails())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'Client créé')->where('causer_id', $secretary->id)->exists())->toBeTrue();
});

it('crée une personne morale avec RCCM, ID. Nat. et représentant', function () {
    Livewire::actingAs(userWithRoles(Role::Secretary))->test(ClientForm::class)
        ->set('type', 'company')
        ->set('name', 'Kivu Agro SARL')
        ->set('rccm', 'CD/GOM/RCCM/21-B-00412')
        ->set('id_nat', '5-93-N71204M')
        ->set('representative', 'Pascal Bahati, DG')
        ->call('save')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Kivu Agro SARL')->firstOrFail();
    expect($client->type)->toBe(ClientType::Company)
        ->and($client->rccm)->toBe('CD/GOM/RCCM/21-B-00412')
        ->and($client->representative)->toBe('Pascal Bahati, DG');
});

it('signale un conflit d\'intérêts et exige une confirmation (CLI-04)', function () {
    $secretary = userWithRoles(Role::Secretary);
    Client::factory()->create(['name' => 'Jean-Pierre Lumbala']);
    LegalCase::factory()->create(['adverse_party' => 'Tropical Logistics SA']);

    $component = Livewire::actingAs($secretary)->test(ClientForm::class)
        ->set('name', 'Tropical Logistics');

    expect($component->get('conflicts'))->toHaveCount(1)
        ->and($component->get('conflicts')[0]['label'])->toContain('Partie adverse dans');

    $component->call('save')->assertHasErrors('conflict_ack');
    expect(Client::query()->where('name', 'Tropical Logistics')->exists())->toBeFalse();

    $component->set('conflict_ack', true)->call('save')->assertHasNoErrors();
    expect(Client::query()->where('name', 'Tropical Logistics')->exists())->toBeTrue()
        ->and(AuditActivity::query()->where('description', 'like', 'Contrôle des conflits d\'intérêts : 1 correspondance%')->exists())->toBeTrue();
});

it('détecte un client existant, sans tenir compte des accents ni de la casse', function () {
    Client::factory()->create(['name' => 'Grégoire Mbuyi']);

    $component = Livewire::actingAs(userWithRoles(Role::Secretary))->test(ClientForm::class)
        ->set('name', 'GREGOIRE Kasongo');

    expect($component->get('conflicts'))->toHaveCount(1)
        ->and($component->get('conflicts')[0]['label'])->toBe('Client existant : Grégoire Mbuyi');
});

it('ne demande pas de confirmation sans correspondance', function () {
    Livewire::actingAs(userWithRoles(Role::Secretary))->test(ClientForm::class)
        ->set('name', 'Nouvelle Personne')
        ->call('save')
        ->assertHasNoErrors();

    expect(AuditActivity::query()->where('description', 'Contrôle des conflits d\'intérêts : aucune correspondance')->exists())->toBeTrue();
});

it('réserve la création et la modification des clients au secrétariat et à l\'admin', function (Role $role) {
    $client = Client::factory()->create();

    Livewire::actingAs(userWithRoles($role))->test(ClientForm::class)->assertForbidden();
    Livewire::actingAs(userWithRoles($role))->test(ClientForm::class, ['client' => $client])->assertForbidden();
})->with([Role::Lawyer, Role::Cashier, Role::Accountant]);

it('ne montre à un avocat que les clients de ses dossiers', function () {
    $lawyer = userWithRoles(Role::Lawyer);
    $other = userWithRoles(Role::Lawyer);
    $mine = LegalCase::factory()->create(['lead_lawyer_id' => $lawyer->id]);
    $theirs = LegalCase::factory()->create(['lead_lawyer_id' => $other->id]);

    Livewire::actingAs($lawyer)->test(ClientIndex::class)
        ->assertSee($mine->client->name)
        ->assertDontSee($theirs->client->name);

    Livewire::actingAs($lawyer)->test(ClientShow::class, ['client' => $mine->client])->assertOk();
    Livewire::actingAs($lawyer)->test(ClientShow::class, ['client' => $theirs->client])->assertForbidden();
});

it('affiche les dossiers dans l\'historique du client (CLI-02)', function () {
    $case = LegalCase::factory()->create(['title' => 'Affaire de démonstration']);

    Livewire::actingAs(userWithRoles(Role::Secretary))->test(ClientShow::class, ['client' => $case->client])
        ->assertSee('Affaire de démonstration')
        ->assertSee($case->reference);
});

it('refuse l\'accès aux clients au caissier et au comptable', function (Role $role) {
    $this->actingAs(userWithRoles($role))->get(route('clients.index'))->assertForbidden();
})->with([Role::Cashier, Role::Accountant]);
