<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export Excel d'un état comptable (CPT-07), à partir du même tableau que l'écran.
 */
class AccountingReportExport implements FromView, ShouldAutoSize, WithTitle
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        private readonly string $report,
        private readonly string $title,
        private readonly array $data,
    ) {}

    public function view(): View
    {
        return view('exports.accounting', $this->data + ['report' => $this->report, 'title' => $this->title, 'numeric' => true]);
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }
}
