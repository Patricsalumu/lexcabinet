<?php

namespace App\Livewire\Cases;

use App\Enums\CaseEventType;
use App\Enums\CaseStatus;
use App\Enums\DocumentCategory;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Services\CaseService;
use App\Services\DocumentService;
use App\Services\TimeEntryService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Fiche du dossier : convention, statut, journal, temps passé et pièces jointes.
 */
#[Layout('layouts.app')]
class CaseShow extends Component
{
    use WithFileUploads;

    public LegalCase $case;

    public string $status = '';

    // Journal
    public string $eventType = 'act';

    public string $eventAt = '';

    public string $eventDescription = '';

    public string $eventChamber = '';

    // Temps passé
    public string $timeDate = '';

    public string $timeHours = '1';

    public string $timeMinutes = '0';

    public string $timeDescription = '';

    public bool $timeBillable = true;

    // Pièces jointes
    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $uploadCategory = 'procedure';

    public function mount(LegalCase $case): void
    {
        $this->authorize('view', $case);
        $this->case = $case;
        $this->status = $case->status->value;
        $this->eventAt = now()->format('Y-m-d\TH:i');
        $this->timeDate = today()->toDateString();
    }

    public function updatedStatus(CaseService $cases): void
    {
        $this->authorize('update', $this->case);
        $this->validate(['status' => ['required', Rule::enum(CaseStatus::class)]]);

        $cases->changeStatus($this->case, CaseStatus::from($this->status), auth()->user());
        $this->case->refresh();
        session()->flash('status', 'Statut mis à jour : '.$this->case->status->label().'.');
    }

    public function addEvent(CaseService $cases): void
    {
        $this->authorize('update', $this->case);

        $this->validate([
            'eventType' => ['required', Rule::enum(CaseEventType::class)],
            'eventAt' => ['required', 'date'],
            'eventDescription' => ['required', 'string', 'max:2000'],
            'eventChamber' => ['nullable', 'string', 'max:100'],
        ], [], ['eventType' => 'type', 'eventAt' => 'date', 'eventDescription' => 'description', 'eventChamber' => 'chambre']);

        $cases->addEvent($this->case, [
            'type' => $this->eventType,
            'occurred_at' => $this->eventAt,
            'description' => $this->eventDescription,
            'chamber' => $this->eventChamber,
        ], auth()->user());

        $this->reset(['eventDescription', 'eventChamber']);
        session()->flash('status', 'Événement ajouté au journal.');
    }

    public function logTime(TimeEntryService $time): void
    {
        $this->authorize('logTime', $this->case);

        $this->validate([
            'timeDate' => ['required', 'date', 'before_or_equal:today'],
            'timeHours' => ['required', 'integer', 'min:0', 'max:24'],
            'timeMinutes' => ['required', 'integer', 'min:0', 'max:59'],
            'timeDescription' => ['required', 'string', 'max:2000'],
            'timeBillable' => ['boolean'],
        ], [], ['timeDate' => 'date', 'timeHours' => 'heures', 'timeMinutes' => 'minutes', 'timeDescription' => 'description']);

        $minutes = (int) $this->timeHours * 60 + (int) $this->timeMinutes;
        if ($minutes < 1 || $minutes > 24 * 60) {
            $this->addError('timeHours', 'La durée doit être comprise entre 1 minute et 24 heures.');

            return;
        }

        $time->log($this->case, auth()->user(), $this->timeDate, $minutes, $this->timeDescription, $this->timeBillable);

        $this->reset(['timeDescription']);
        $this->timeHours = '1';
        $this->timeMinutes = '0';
        session()->flash('status', 'Temps ajouté au dossier.');
    }

    public function uploadDocument(DocumentService $documents): void
    {
        $this->authorize('update', $this->case);

        $this->validate([
            'upload' => ['required', 'file', 'max:'.DocumentService::MAX_KILOBYTES, 'mimes:'.implode(',', DocumentService::MIMES)],
            'uploadCategory' => ['required', Rule::enum(DocumentCategory::class)],
        ], [
            'upload.max' => 'Le fichier ne doit pas dépasser 20 Mo.',
            'upload.mimes' => 'Formats acceptés : PDF, images (JPEG, PNG, WebP) et Word.',
        ], ['upload' => 'fichier', 'uploadCategory' => 'catégorie']);

        $documents->store($this->case, $this->upload, DocumentCategory::from($this->uploadCategory), auth()->user());

        $this->reset('upload');
        session()->flash('status', 'Pièce jointe ajoutée.');
    }

    public function render(): View
    {
        $this->case->load(['client', 'leadLawyer', 'collaborators']);

        $timeEntries = $this->case->timeEntries()->with('user')->orderByDesc('worked_on')->orderByDesc('id')->get();
        $unbilled = $timeEntries->filter(fn (TimeEntry $t) => $t->billable && ! $t->isInvoiced())->sum('minutes');

        return view('livewire.cases.show', [
            'events' => $this->case->events()->with('author')->orderByDesc('occurred_at')->get(),
            'timeEntries' => $timeEntries,
            'unbilledMinutes' => (int) $unbilled,
            'documents' => $this->case->documents()->with('uploader')->latest()->get(),
            'invoices' => auth()->user()->can('viewAny', \App\Models\Invoice::class)
                ? $this->case->invoices()->orderByDesc('id')->get()
                : collect(),
            'statuses' => CaseStatus::cases(),
            'eventTypes' => CaseEventType::cases(),
            'categories' => DocumentCategory::cases(),
        ])->title($this->case->reference.' · '.$this->case->title);
    }
}
