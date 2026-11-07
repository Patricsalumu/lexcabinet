<?php

namespace App\Livewire\Invoices;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\FeeType;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Services\InvoiceBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Nouvelle facture (FAC-01) : on choisit un dossier, qui affiche le client, son adresse,
 * son téléphone et la convention ; ou une consultation honorée sans dossier (FAC-06).
 */
#[Layout('layouts.app')]
#[Title('Nouvelle facture')]
class InvoiceCreate extends Component
{
    public string $mode = 'case';

    public string $case_id = '';

    public string $appointment_id = '';

    public function mount(): void
    {
        $this->authorize('create', Invoice::class);
        $this->case_id = (string) request()->query('case', '');
        if (request()->query('appointment')) {
            $this->mode = 'consultation';
            $this->appointment_id = (string) request()->query('appointment');
        }
    }

    #[Computed]
    public function selectedCase(): ?LegalCase
    {
        return $this->case_id !== '' ? LegalCase::query()->with('client')->find((int) $this->case_id) : null;
    }

    /** Aperçu de la ligne d'honoraires qui sera générée. */
    #[Computed]
    public function preview(): ?string
    {
        $case = $this->selectedCase;
        if ($case === null) {
            return null;
        }

        $builder = app(InvoiceBuilder::class);

        return match ($case->fee_type) {
            FeeType::Flat => $builder->activeInvoices($case)->exists()
                ? 'Ce dossier au forfait a déjà une facture : une seconde est refusée tant que la première n\'est pas annulée.'
                : 'Une ligne « '.$case->title.', honoraires forfaitaires » sera générée.',
            FeeType::Hourly => (function () use ($case) {
                $minutes = (int) TimeEntry::query()->where('case_id', $case->id)->where('billable', true)->whereNull('invoice_id')->sum('minutes');

                return $minutes
                    ? 'Reprise de '.TimeEntry::formatMinutes($minutes).' facturables non facturées.'
                    : 'Aucune heure facturable nouvelle : la facture ne contiendra que des lignes complémentaires.';
            })(),
            FeeType::Subscription => (function () use ($case, $builder) {
                [$from, $to] = $builder->nextSubscriptionPeriod($case);

                return 'Abonnement du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y').'.';
            })(),
            FeeType::RetainerSuccess => 'Une ligne de provision sera générée ; l\'honoraire de résultat sera précisé plus tard.',
        };
    }

    public function create(InvoiceBuilder $builder): void
    {
        $this->authorize('create', Invoice::class);

        if ($this->mode === 'consultation') {
            $this->validate(['appointment_id' => ['required', 'integer', 'exists:appointments,id']], [], ['appointment_id' => 'consultation']);
            $invoice = $builder->forConsultation(Appointment::query()->findOrFail((int) $this->appointment_id), auth()->user());
        } else {
            $this->validate(['case_id' => ['required', 'integer', 'exists:cases,id']], [], ['case_id' => 'dossier']);
            $invoice = $builder->forCase(LegalCase::query()->findOrFail((int) $this->case_id), auth()->user());
        }

        session()->flash('status', 'Brouillon de facture créé : ajoutez les lignes complémentaires puis émettez-le.');
        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.invoices.create', [
            'cases' => LegalCase::query()->with('client')->active()->orderBy('reference')->get(),
            'consultations' => Appointment::query()->with(['client', 'lawyer'])
                ->where('type', AppointmentType::Consultation->value)
                ->where('status', AppointmentStatus::Honored->value)
                ->whereNull('invoice_id')
                ->orderByDesc('starts_at')
                ->get(),
        ]);
    }
}
