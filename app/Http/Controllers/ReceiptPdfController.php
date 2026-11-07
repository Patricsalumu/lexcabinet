<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\CashMovement;
use App\Services\InvoicePdf;
use Illuminate\Http\Response;

/**
 * Reçu imprimable RC-AAAA-0001 (CAI-03).
 */
class ReceiptPdfController extends Controller
{
    public function __invoke(CashMovement $movement, InvoicePdf $pdf): Response
    {
        abort_unless(request()->user()->can(Permission::CashView->value), 403);
        abort_if($movement->receipt_number === null, 404);

        return response($pdf->receipt($movement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$movement->receipt_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
