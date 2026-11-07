<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\InvoicePdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * PDF de facture et d'avoir, servis après contrôle des droits.
 */
class InvoicePdfController extends Controller
{
    public function invoice(Invoice $invoice, InvoicePdf $pdf): Response
    {
        Gate::authorize('view', $invoice);

        return $this->inline($pdf->invoice($invoice), ($invoice->number ?? 'brouillon-'.$invoice->id).'.pdf');
    }

    public function creditNote(CreditNote $creditNote, InvoicePdf $pdf): Response
    {
        Gate::authorize('view', $creditNote->invoice);

        return $this->inline($pdf->creditNote($creditNote), $creditNote->number.'.pdf');
    }

    private function inline(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
