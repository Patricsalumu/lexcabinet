<?php

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Livewire\Messages\ConversationIndex;
use App\Models\Appointment;
use App\Models\AuditActivity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailReply;
use App\Services\AppointmentService;
use App\Services\ConversationService;
use App\Services\Mailbox\ImapMailbox;
use App\Services\Mailbox\InboundEmail;
use App\Support\QuotedText;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, EmailTemplateSeeder::class]);
    Carbon::setTestNow('2026-11-09 09:00');
    config(['mail.from.address' => 'lexcabinet@barapp.cloud']);
    Storage::fake('local');

    $this->lawyer = userWithRoles(Role::Lawyer);
    $this->client = Client::factory()->create(['name' => 'Esther Kalala', 'email' => 'esther.kalala@example.cd', 'email_consent' => true]);
    $this->appointment = Appointment::factory()->at(Carbon::parse('2026-11-10 10:00'))
        ->create(['lawyer_id' => $this->lawyer->id, 'client_id' => $this->client->id]);
    app(AppointmentService::class)->sendConfirmation($this->appointment);
    $this->log = EmailLog::query()->where('template_key', 'confirmation')->sole();
});

afterEach(fn () => Carbon::setTestNow());

function inbound(array $overrides = []): InboundEmail
{
    $data = $overrides + [
        'messageId' => 'CAF123@mail.gmail.com',
        'inReplyTo' => null,
        'references' => [],
        'fromEmail' => 'esther.kalala@example.cd',
        'fromName' => 'Esther Kalala',
        'subject' => 'Re: Confirmation de votre rendez-vous',
        'text' => "Merci, je serai là à 10 h.\n\nLe lun. 9 nov. 2026 à 09:00, Cabinet <lexcabinet@barapp.cloud> a écrit :\n> Bonjour Esther,\n> Nous confirmons…",
        'html' => '',
        'date' => Carbon::parse('2026-11-09 10:15'),
    ];

    return new InboundEmail(...$data);
}

function sentMails()
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->map->getOriginalMessage();
}

it('donne à chaque e-mail un Message-ID propre, sur le domaine de l\'expéditeur', function () {
    expect($this->log->message_id)->toEndWith('@barapp.cloud');

    $message = sentMails()->first(fn ($m) => $m->getTo()[0]->getAddress() === 'esther.kalala@example.cd');
    expect($message->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<'.$this->log->message_id.'>');

    // Une relance reçoit un nouvel identifiant.
    $this->log->forceFill(['status' => EmailStatus::Failed])->save();
    $resent = app(\App\Services\EmailService::class)->resend($this->log, userWithRoles(Role::Admin));
    expect($resent->message_id)->not->toBe($this->log->message_id)->not->toBeNull();
});

it('importe une réponse à un e-mail de l\'application, une seule fois', function () {
    $service = app(ConversationService::class);

    $reply = $service->import(inbound(['inReplyTo' => $this->log->message_id, 'references' => [$this->log->message_id]]));

    expect($reply)->not->toBeNull()
        ->and($reply->thread_id)->toBe($this->log->id)
        ->and($reply->email_log_id)->toBe($this->log->id)
        ->and($reply->sender->is($this->client))->toBeTrue()
        ->and($reply->excerpt())->toBe('Merci, je serai là à 10 h.')
        ->and($reply->body)->toContain('> Bonjour Esther')
        ->and($reply->read_at)->toBeNull()
        ->and(AuditActivity::query()->where('description', 'Réponse reçue par e-mail')->exists())->toBeTrue();

    // Doublon (relève suivante) : ignoré.
    expect($service->import(inbound(['inReplyTo' => $this->log->message_id])))->toBeNull()
        ->and(EmailReply::query()->count())->toBe(1);
});

it('ignore le courrier qui ne répond pas à un e-mail de l\'application', function () {
    $service = app(ConversationService::class);

    expect($service->import(inbound(['messageId' => 'a@x', 'inReplyTo' => null, 'references' => []])))->toBeNull()
        ->and($service->import(inbound(['messageId' => 'b@x', 'inReplyTo' => 'inconnu@ailleurs.com'])))->toBeNull()
        ->and(EmailReply::query()->count())->toBe(0);
});

it('rattache une seconde réponse à la même conversation, même si elle cite la première', function () {
    $service = app(ConversationService::class);
    $first = $service->import(inbound(['inReplyTo' => $this->log->message_id]));

    $second = $service->import(inbound(['messageId' => 'second@mail.gmail.com', 'inReplyTo' => $first->message_id, 'references' => [$first->message_id], 'fromEmail' => 'inconnu@gmail.com']));

    expect($second->thread_id)->toBe($this->log->id)
        ->and($second->sender)->toBeNull();      // adresse inconnue : affichée comme telle
});

it('conserve les pièces jointes et les sert aux seules personnes autorisées', function () {
    $reply = app(ConversationService::class)->import(inbound([
        'inReplyTo' => $this->log->message_id,
        'attachments' => [['name' => 'contrat.pdf', 'content' => '%PDF-1.4 contenu', 'mime' => 'application/pdf']],
    ]));

    expect($reply->attachments[0]['name'])->toBe('contrat.pdf');
    Storage::disk('local')->assertExists($reply->attachments[0]['path']);

    $this->actingAs(userWithRoles(Role::Secretary))->get(route('messages.attachment', [$reply->id, 0]))->assertOk();
    $this->actingAs($this->lawyer)->get(route('messages.attachment', [$reply->id, 0]))->assertOk();          // son rendez-vous
    $this->actingAs(userWithRoles(Role::Lawyer))->get(route('messages.attachment', [$reply->id, 0]))->assertForbidden();
});

it('montre la conversation au secrétariat et à l\'avocat concerné, pas aux autres', function () {
    app(ConversationService::class)->import(inbound(['inReplyTo' => $this->log->message_id]));
    $service = app(ConversationService::class);

    expect($service->visibleThreads(userWithRoles(Role::Secretary))->count())->toBe(1)
        ->and($service->visibleThreads($this->lawyer)->count())->toBe(1)
        ->and($service->visibleThreads(userWithRoles(Role::Lawyer))->count())->toBe(0)
        ->and($service->unreadCount($this->lawyer))->toBe(1);

    Livewire::actingAs(userWithRoles(Role::Cashier))->test(ConversationIndex::class)->assertForbidden();
    Livewire::actingAs(userWithRoles(Role::Accountant))->test(ConversationIndex::class)->assertForbidden();
    Livewire::actingAs(userWithRoles(Role::Lawyer))->test(ConversationIndex::class)
        ->assertDontSee('Merci, je serai là');
});

it('affiche le fil, marque les réponses comme lues et permet de répondre dans la conversation', function () {
    $reply = app(ConversationService::class)->import(inbound(['inReplyTo' => $this->log->message_id, 'references' => [$this->log->message_id]]));
    $secretary = userWithRoles(Role::Secretary);

    Livewire::actingAs($secretary)->test(ConversationIndex::class)
        ->assertSee('Merci, je serai là à 10 h.')
        ->assertSee('Répondre à Esther Kalala')
        ->set('message', "Parfait, à demain.\nCordialement")
        ->call('send')
        ->assertHasNoErrors();

    expect($reply->fresh()->read_at)->not->toBeNull()
        ->and($reply->fresh()->read_by)->toBe($secretary->id);

    $answer = EmailLog::query()->where('template_key', 'conversation_reply')->sole();
    expect($answer->thread_id)->toBe($this->log->id)
        ->and($answer->status)->toBe(EmailStatus::Sent)
        ->and($answer->recipient_email)->toBe('esther.kalala@example.cd')
        ->and($answer->subject)->toBe('RE : '.$this->log->subject)
        ->and($answer->body)->toContain("Parfait, à demain.\nCordialement")->toContain($secretary->name)
        ->and($answer->body)->toContain('12, avenue de la Justice')     // signature client
        ->and(AuditActivity::query()->where('description', 'Réponse envoyée depuis la messagerie')->where('causer_id', $secretary->id)->exists())->toBeTrue();

    // En-têtes de fil : le logiciel du client range la réponse dans la même conversation.
    $message = sentMails()->last();
    expect($message->getHeaders()->get('In-Reply-To')->getBodyAsString())->toBe('<CAF123@mail.gmail.com>')
        ->and($message->getHeaders()->get('References')->getBodyAsString())->toContain('<'.$this->log->message_id.'>')->toContain('<CAF123@mail.gmail.com>');

    // La réponse du client à notre réponse rejoint la même conversation.
    $next = app(ConversationService::class)->import(inbound(['messageId' => 'next@mail.gmail.com', 'inReplyTo' => $answer->message_id]));
    expect($next->thread_id)->toBe($this->log->id);
});

it('laisse l\'avocat concerné répondre, mais pas un autre', function () {
    app(ConversationService::class)->import(inbound(['inReplyTo' => $this->log->message_id]));
    $thread = $this->log->fresh();

    expect(app(ConversationService::class)->canReply($this->lawyer, $thread))->toBeTrue()
        ->and(app(ConversationService::class)->canReply(userWithRoles(Role::Lawyer), $thread))->toBeFalse();

    expect(fn () => app(ConversationService::class)->reply($thread, 'Bonjour', userWithRoles(Role::Lawyer)))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('relève la boîte : seules les réponses nouvelles sont téléchargées puis importées', function () {
    $log = $this->log;
    $fake = new class extends ImapMailbox
    {
        public array $downloaded = [];

        public array $messages = [];

        public function isConfigured(): bool
        {
            return true;
        }

        public function fetchSince(Carbon $since, callable $wanted): array
        {
            return array_values(array_filter($this->messages, function (InboundEmail $m) use ($wanted) {
                $keep = $wanted($m->messageId, $m->citedIds());
                if ($keep) {
                    $this->downloaded[] = $m->messageId;
                }

                return $keep;
            }));
        }
    };
    $fake->messages = [
        inbound(['messageId' => 'ok@x', 'inReplyTo' => $log->message_id]),
        inbound(['messageId' => 'pub@x', 'inReplyTo' => null, 'subject' => 'Newsletter']),
    ];
    app()->instance(ImapMailbox::class, $fake);

    $this->artisan('lexcabinet:fetch-replies')->expectsOutputToContain('1 nouvelle(s) réponse(s)')->assertSuccessful();
    expect($fake->downloaded)->toBe(['ok@x']);

    // Relève suivante : rien de nouveau, rien n'est retéléchargé.
    $fake->downloaded = [];
    $this->artisan('lexcabinet:fetch-replies')->expectsOutputToContain('0 nouvelle(s) réponse(s)');
    expect($fake->downloaded)->toBe([]);
});

it('ne relève rien tant que la boîte n\'est pas configurée', function () {
    config(['imap.accounts.default.host' => null]);

    $this->artisan('lexcabinet:fetch-replies')->expectsOutputToContain('non configurée')->assertSuccessful();
});

it('sépare le texte de la citation (Gmail, Outlook, citations « > »)', function (string $text, string $expected) {
    expect(QuotedText::strip($text))->toBe($expected);
})->with([
    'gmail coupé' => ["D'accord.\n\nLe lun. 9 nov. 2026 à 09:00, Cabinet <a@b.cd> a\nécrit :\n> texte", "D'accord."],
    'anglais' => ["Ok\nOn Mon, Nov 9, 2026 at 9:00 AM Cabinet <a@b.cd> wrote:\n> text", 'Ok'],
    'outlook' => ["Bien reçu.\n\nDe : Cabinet <a@b.cd>\nEnvoyé : lundi", 'Bien reçu.'],
    'sans citation' => ["Une ligne\nDeux lignes", "Une ligne\nDeux lignes"],
]);

it('convertit un message HTML seul en texte', function () {
    $reply = app(ConversationService::class)->import(inbound(['inReplyTo' => $this->log->message_id, 'text' => '', 'html' => '<p>Bonjour,</p><p>Je confirme&nbsp;<b>10 h</b>.</p><script>x</script>']));

    expect($reply->body)->toBe("Bonjour,\nJe confirme 10 h.");
});
