<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF de facture et d'avoir à l'en-tête du cabinet (FAC-12).
 */
class InvoicePdf
{
    public function __construct(private readonly SettingsService $settings) {}

    public function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'client', 'legalCase', 'creditNote']);

        return Pdf::loadView('pdf.invoice', $this->common() + ['invoice' => $invoice])
            ->setPaper('a4')
            ->output();
    }

    public function creditNote(CreditNote $credit): string
    {
        $credit->loadMissing(['invoice.lines', 'client', 'invoice.legalCase']);

        return Pdf::loadView('pdf.credit-note', $this->common() + ['credit' => $credit])
            ->setPaper('a4')
            ->output();
    }

    /** @return array<string, mixed> */
    private function common(): array
    {
        $firm = $this->settings->all();
        $logo = null;
        $path = $firm['firm.logo_path'];
        if ($path && Storage::disk('local')->exists($path)) {
            $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';
            $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
        }

        return ['firm' => $firm, 'logo' => $logo];
    }
}
