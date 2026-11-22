<?php

namespace App\Livewire\Emails;

use App\Enums\EmailStatus;
use App\Enums\Permission;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\EmailService;
use App\Services\ReminderService;
use App\Services\WhatsAppShare;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal d'envoi des e-mails (NOT-07) : destinataire, modèle, date, statut et relance manuelle.
 */
#[Layout('layouts.app')]
#[Title('E-mails')]
class EmailLogIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $template = '';

    #[Url(as: 'e')]
    public ?int $selected = null;

    public function mount(): void
    {
        $this->authorize(Permission::EmailsView->value);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'template'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $id): void
    {
        $this->selected = $id;
    }

    public function resend(int $id, EmailService $emails): void
    {
        $this->authorize(Permission::RemindersSend->value);
        $log = EmailLog::query()->findOrFail($id);

        try {
            $new = $emails->resend($log, auth()->user());
        } catch (InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        activity('emails')->causedBy(auth()->user())->performedOn($log)->log('E-mail relancé à la main');
        $this->selected = $new->id;

        $new->status === EmailStatus::NotSent
            ? session()->flash('error', 'Toujours impossible : '.$new->reason.' Complétez la fiche puis relancez.')
            : session()->flash('status', 'E-mail remis en file d\'attente.');
    }

    /** Trace le partage WhatsApp ; le lien lui-même s'ouvre dans un nouvel onglet. */
    public function sharedOnWhatsApp(int $id, WhatsAppShare $whatsApp): void
    {
        $this->authorize(Permission::RemindersSend->value);
        $log = EmailLog::query()->findOrFail($id);

        if ($reason = $whatsApp->refusalReason($log)) {
            session()->flash('error', 'Partage impossible : '.$reason);

            return;
        }

        activity('emails')->causedBy(auth()->user())->performedOn($log)
            ->withProperties(['phone' => $whatsApp->phone($log->recipient)])
            ->log('E-mail partagé sur WhatsApp');
    }

    public function sendRecap(ReminderService $reminders): void
    {
        $this->authorize(Permission::RemindersSend->value);

        $count = $reminders->sendDailyRecaps(by: auth()->user(), ignoreTime: true);
        activity('emails')->causedBy(auth()->user())->log("Récapitulatif du jour lancé à la main : {$count} avocat(s)");

        session()->flash('status', $count ? "{$count} récapitulatif(s) envoyé(s)." : 'Aucun récapitulatif à envoyer (déjà envoyés ou agendas vides).');
    }

    public function render(): View
    {
        $logs = EmailLog::query()
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->template !== '', fn ($q) => $q->where('template_key', $this->template))
            ->latest('id')
            ->paginate(20);

        $current = $this->selected
            ? EmailLog::query()->with('triggeredBy')->find($this->selected)
            : $logs->first();

        return view('livewire.emails.index', [
            'logs' => $logs,
            'current' => $current,
            'statuses' => EmailStatus::cases(),
            'templates' => EmailTemplate::query()->orderBy('name')->pluck('name', 'key'),
            'counts' => EmailLog::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'canSend' => auth()->user()->can(Permission::RemindersSend->value),
            'whatsApp' => $current ? [
                'url' => app(WhatsAppShare::class)->url($current),
                'reason' => app(WhatsAppShare::class)->refusalReason($current),
            ] : null,
        ]);
    }
}
