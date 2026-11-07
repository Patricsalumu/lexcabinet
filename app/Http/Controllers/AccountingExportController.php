<?php

namespace App\Http\Controllers;

use App\Enums\Journal;
use App\Exports\AccountingReportExport;
use App\Services\AccountingReports;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export Excel et PDF de tous les états comptables (CPT-07).
 */
class AccountingExportController extends Controller
{
    private const TITLES = [
        'journal' => 'Journal',
        'ledger' => 'Grand livre',
        'balance' => 'Balance',
        'result' => 'Compte de résultat simplifié',
    ];

    public function __invoke(Request $request, string $report, string $format, AccountingReports $reports, SettingsService $settings): Response
    {
        abort_unless(array_key_exists($report, self::TITLES) && in_array($format, ['xlsx', 'pdf'], true), 404);

        $from = Carbon::parse($request->query('from', today()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', today()->endOfMonth()->toDateString()))->endOfDay();

        $data = match ($report) {
            'journal' => ['entries' => $reports->journal($from, $to, Journal::tryFrom((string) $request->query('journal')))],
            'ledger' => ['ledger' => $reports->generalLedger($from, $to, $request->filled('account') ? (int) $request->query('account') : null)],
            'balance' => ['balance' => $reports->trialBalance($from, $to)],
            'result' => ['result' => $reports->incomeStatement($from, $to)],
        };

        $title = self::TITLES[$report];
        $filename = str_replace(' ', '-', mb_strtolower($title)).'_'.$from->format('Y-m-d').'_'.$to->format('Y-m-d');
        $data += ['from' => $from, 'to' => $to, 'title' => $title, 'report' => $report];

        activity('accounting')->causedBy($request->user())
            ->withProperties(['report' => $report, 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->log("Export {$format} : {$title}");

        if ($format === 'xlsx') {
            return Excel::download(new AccountingReportExport($report, $title, $data), $filename.'.xlsx');
        }

        return Pdf::loadView('pdf.accounting', $data + ['firm' => $settings->all(), 'logo' => null])
            ->setPaper('a4', $report === 'journal' || $report === 'ledger' ? 'landscape' : 'portrait')
            ->download($filename.'.pdf');
    }
}
