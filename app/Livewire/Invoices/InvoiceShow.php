<?php

namespace App\Livewire\Invoices;

use App\Enums\EmailStatus;
use App\Enums\InvoiceLineType;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche facture : brouillon modifiable (lignes complémentaires, réduction, TVA) puis émission ;
 * facture émise figée (PDF, envoi par e-mail, avoir).
 */
#[Layout('layouts.app')]
class InvoiceShow extends Component
{
    public Invoice $invoice;

    // Ligne complémentaire
    public string $lineType = 'disbursement';

    public string $lineLabel = '';

    public string $lineQuantity = '1';

    public string $linePrice = '';

    // Options du brouillon
    public string $discount = '0';

    public bool $vat = false;

    public string $terms = '15';

    public string $notes = '';

    // Avoir
    public string $reason = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->invoice = $invoice;
        $this->syncOptions();
    }

    private function syncOptions(): void
    {
        $this->discount = rtrim(rtrim((string) $this->invoice->discount_percent, '0'), '.') ?: '0';
        $this->vat = $this->invoice->vat_enabled;
        $this->terms = (string) $this->invoice->payment_terms_days;
        $this->notes = (string) $this->invoice->notes;
    }

    public function addLine(InvoiceBuilder $builder): void
    {
        $this->authorize('update', $this->invoice);

        $this->validate([
            'lineType' => ['required', Rule::in(array_map(fn ($t) => $t->value, InvoiceLineType::manualTypes()))],
            'lineLabel' => ['required', 'string', 'max:200'],
            'lineQuantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'linePrice' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
        ], [], ['lineType' => 'type', 'lineLabel' => 'libellé', 'lineQuantity' => 'quantité', 'linePrice' => 'prix unitaire']);

        $builder->addManualLine($this->invoice, InvoiceLineType::from($this->lineType), $this->lineLabel, (string) $this->lineQuantity, (string) $this->linePrice);

        $this->reset(['lineLabel', 'linePrice']);
        $this->lineQuantity = '1';
        $this->invoice->refresh();
    }

    public function removeLine(int $lineId, InvoiceBuilder $builder): void
    {
        $this->authorize('update', $this->invoice);
        $builder->removeManualLine($this->invoice, InvoiceLine::query()->findOrFail($lineId));
        $this->invoice->refresh();
    }

    public function saveOptions(InvoiceBuilder $builder): void
    {
        $this->authorize('update', $this->invoice);

        $this->validate([
            'discount' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'vat' => ['boolean'],
            'terms' => ['required', Rule::in(['0', '15', '30', '45', '60'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['discount' => 'réduction', 'terms' => 'délai de paiement']);

        $builder->updateOptions($this->invoice, (string) $this->discount, $this->vat, (int) $this->terms, $this->notes);
        $this->invoice->refresh();
        session()->flash('status', 'Brouillon mis à jour.');
    }

    public function refreshAuto(InvoiceBuilder $builder): void
    {
        $this->authorize('update', $this->invoice);
        $builder->refreshAutoLines($this->invoice);
        $this->invoice->refresh();
        session()->flash('status', 'Ligne d\'honoraires actualisée.');
    }

    public function issue(InvoiceService $invoices): void
    {
        $this->authorize('update', $this->invoice);

        $issued = $invoices->issue($this->invoice, auth()->user());
        $this->invoice = $issued->refresh();

        $mail = EmailLog::query()
            ->where('related_type', $this->invoice->getMorphClass())
            ->where('related_id', $this->invoice->id)
            ->latest('id')
            ->first();

        session()->flash('status', "Facture {$this->invoice->number} émise."
            .($mail && $mail->status === EmailStatus::NotSent ? ' E-mail non envoyé : '.$mail->reason : ' Envoyée au client par e-mail.'));
    }

    public function deleteDraft(InvoiceBuilder $builder): void
    {
        $this->authorize('delete', $this->invoice);
        $builder->deleteDraft($this->invoice);

        session()->flash('status', 'Brouillon supprimé.');
        $this->redirectRoute('invoices.index', navigate: true);
    }

    public function send(InvoiceService $invoices): void
    {
        $this->authorize('send', $this->invoice);
        $log = $invoices->sendByEmail($this->invoice, auth()->user());

        $log->status === EmailStatus::NotSent
            ? session()->flash('error', 'E-mail non envoyé : '.$log->reason)
            : session()->flash('status', 'Facture envoyée par e-mail à '.$log->recipient_email.'.');
    }

    public function cancel(InvoiceService $invoices): void
    {
        $this->authorize('cancel', $this->invoice);
        $this->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motif']);

        $credit = $invoices->cancel($this->invoice, $this->reason, auth()->user());
        $this->invoice->refresh();
        $this->reset('reason');
        session()->flash('status', "Facture annulée par l'avoir {$credit->number}.");
    }

    public function render(): View
    {
        $this->invoice->load(['lines', 'client', 'legalCase', 'appointment.lawyer', 'creditNote', 'issuedBy']);

        return view('livewire.invoices.show', [
            'lineTypes' => InvoiceLineType::manualTypes(),
        ])->title($this->invoice->displayNumber());
    }
}
