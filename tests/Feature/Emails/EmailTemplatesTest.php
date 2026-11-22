<?php

use App\Enums\Role;
use App\Livewire\Emails\EmailTemplates;
use App\Models\AuditActivity;
use App\Models\EmailTemplate;
use App\Services\IcsGenerator;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]));

it('remplace les variables et laisse intactes les variables inconnues', function () {
    expect(EmailTemplate::render('Bonjour {client}, le {date} à {heure}. {inconnue}', ['client' => 'Esther', 'date' => 'lundi', 'heure' => '10h00']))
        ->toBe('Bonjour Esther, le lundi à 10h00. {inconnue}');
});

it('livre tous les modèles du cabinet', function () {
    expect(EmailTemplate::query()->pluck('key')->sort()->values()->all())
        ->toBe(collect(\App\Support\EmailTemplateDefaults::all())->keys()->sort()->values()->all())
        ->and(EmailTemplate::query()->count())->toBe(20);
});

it('ajoute les nouveaux modèles sans écraser un modèle personnalisé', function () {
    EmailTemplate::query()->where('key', 'confirmation')->update(['subject' => 'Objet personnalisé']);
    EmailTemplate::query()->where('key', 'account_created')->delete();

    $this->seed(EmailTemplateSeeder::class);

    expect(EmailTemplate::query()->where('key', 'confirmation')->value('subject'))->toBe('Objet personnalisé')
        ->and(EmailTemplate::query()->where('key', 'account_created')->exists())->toBeTrue();
});

it('permet au secrétariat de modifier un modèle, avec trace d\'audit', function () {
    $secretary = userWithRoles(Role::Secretary);

    Livewire::actingAs($secretary)->test(EmailTemplates::class)
        ->set('templates.confirmation.subject', 'Votre rendez-vous du {date} est confirmé')
        ->call('save', 'confirmation')
        ->assertHasNoErrors();

    expect(EmailTemplate::query()->where('key', 'confirmation')->value('subject'))->toBe('Votre rendez-vous du {date} est confirmé')
        ->and(AuditActivity::query()->where('description', 'Modèle d\'e-mail modifié')->where('causer_id', $secretary->id)->exists())->toBeTrue();
});

it('réserve les modèles et le journal au secrétariat et à l\'admin', function (Role $role) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('emails.templates'))->assertForbidden();
    $this->actingAs($user)->get(route('emails.index'))->assertForbidden();
})->with([Role::Lawyer, Role::Cashier, Role::Accountant]);

it('produit un fichier .ics conforme (lignes CRLF, échappement, repli à 75 octets)', function () {
    $appointment = \App\Models\Appointment::factory()->create([
        'subject' => 'Revue des statuts, pacte d\'associés; et annexes de la société Congo Express Transports SARL',
        'lawyer_id' => userWithRoles(Role::Lawyer)->id,
    ]);

    $ics = app(IcsGenerator::class)->forAppointment($appointment);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toEndWith("END:VCALENDAR\r\n")
        ->toContain('statuts\\, pacte d\'associés\\; et');

    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});
