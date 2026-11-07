<?php

namespace App\Livewire\Emails;

use App\Enums\Permission;
use App\Models\EmailTemplate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Modèles d'e-mails modifiables avec variables (NOT-06).
 */
#[Layout('layouts.app')]
#[Title('Modèles d\'e-mails')]
class EmailTemplates extends Component
{
    /** @var array<string, array{subject: string, body: string}> */
    public array $templates = [];

    public function mount(): void
    {
        $this->authorize(Permission::EmailTemplatesManage->value);

        foreach (EmailTemplate::query()->orderBy('id')->get() as $t) {
            $this->templates[$t->key] = ['subject' => $t->subject, 'body' => $t->body];
        }
    }

    public function save(string $key): void
    {
        $this->authorize(Permission::EmailTemplatesManage->value);

        $this->validate([
            "templates.{$key}.subject" => ['required', 'string', 'max:200'],
            "templates.{$key}.body" => ['required', 'string', 'max:10000'],
        ], [], ["templates.{$key}.subject" => 'objet', "templates.{$key}.body" => 'message']);

        $template = EmailTemplate::query()->where('key', $key)->firstOrFail();
        $template->update([
            'subject' => $this->templates[$key]['subject'],
            'body' => $this->templates[$key]['body'],
            'updated_by' => auth()->id(),
        ]);

        session()->flash('status', 'Modèle « '.$template->name.' » enregistré.');
    }

    public function render(): View
    {
        return view('livewire.emails.templates', [
            'names' => EmailTemplate::query()->pluck('name', 'key'),
            'variables' => EmailTemplate::VARIABLES,
        ]);
    }
}
