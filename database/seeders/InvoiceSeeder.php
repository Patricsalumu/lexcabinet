<?php

namespace Database\Seeders;

use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\InvoiceBuilder;
use App\Services\InvoiceCalculator;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Factures de démonstration du prototype (FAC-2026-00115 à 00118 émises, un brouillon).
 * Les encaissements arriveront avec la caisse (phase 5).
 */
class InvoiceSeeder extends Seeder
{
    public function run(InvoiceBuilder $builder, InvoiceCalculator $calculator, NumberingService $numbering): void
    {
        $fiston = User::query()->where('email', 'fiston.tshibangu@mukendi-avocats.example')->firstOrFail();
        $case = fn (string $suffix) => LegalCase::query()->where('reference', 'like', "%-{$suffix}")->firstOrFail();
        $day = fn (int $offset) => Carbon::today()->addDays($offset);
        $year = now()->year;

        // Fige un brouillon comme s'il avait été émis à une date passée.
        $issue = function (Invoice $invoice, int $number, int $issuedOffset, int $dueOffset, string $rate) use ($fiston, $year, $day) {
            $invoice->forceFill([
                'number' => sprintf('FAC-%d-%05d', $year, $number),
                'status' => InvoiceStatus::Issued,
                'issue_date' => $day($issuedOffset),
                'payment_terms_days' => $dueOffset - $issuedOffset,
                'due_date' => $day($dueOffset),
                'exchange_rate' => $rate,
                'issued_at' => $day($issuedOffset)->setTime(15, 20),
                'issued_by' => $fiston->id,
            ])->save();

            return $invoice;
        };

        // FAC-00115 : forfait 2 500 $ (Kivu Agro)
        $issue($builder->forCase($case('0038'), $fiston), 115, -20, 10, '2790');

        // FAC-00116 : abonnement Congo Express, période écoulée précédente
        $d4 = $case('0029');
        $periodStart = $d4->opened_on->copy()->addDays(60);
        $issue($builder->forCase($d4, $fiston, [$periodStart, $periodStart->copy()->addDays(29)]), 116, -8, 7, '2790');

        // FAC-00117 : 6 h au taux de 120 $ + 50 $ de débours (Lumbala) ; les deux premières entrées de temps.
        $d2 = $case('0041');
        $entries = TimeEntry::query()->where('case_id', $d2->id)->orderBy('worked_on')->limit(2)->get();
        $i3 = Invoice::query()->create([
            'case_id' => $d2->id, 'client_id' => $d2->client_id, 'fee_type' => $d2->fee_type, 'status' => InvoiceStatus::Draft,
            'currency' => $d2->currency, 'period_start' => $entries->first()->worked_on, 'period_end' => $entries->last()->worked_on,
            'created_by' => $fiston->id,
        ]);
        $hours = bcdiv((string) $entries->sum('minutes'), '60', 4);
        $i3->lines()->create([
            'position' => 1, 'origin' => InvoiceLine::AUTO, 'type' => InvoiceLineType::Fee,
            'label' => 'Temps passé du '.$entries->first()->worked_on->format('d/m/Y').' au '.$entries->last()->worked_on->format('d/m/Y').' : 6 h au taux de 120,00 $',
            'quantity' => $hours, 'unit_price' => '120.00', 'amount' => InvoiceCalculator::lineAmount($hours, '120.00'),
            'taxable' => true, 'time_entry_ids' => $entries->pluck('id')->all(),
        ]);
        $i3->lines()->create([
            'position' => 2, 'origin' => InvoiceLine::MANUAL, 'type' => InvoiceLineType::Disbursement,
            'label' => 'Frais de descente sur la parcelle', 'quantity' => '1', 'unit_price' => '50.00', 'amount' => '50.00', 'taxable' => false,
        ]);
        $calculator->refresh($i3);
        $issue($i3, 117, -12, -2, '2790');
        TimeEntry::query()->whereKey($entries->pluck('id'))->update(['invoice_id' => $i3->id]);

        // FAC-00118 : forfait 2 800 000 FC (Ngoy)
        $issue($builder->forCase($case('0046'), $fiston), 118, -9, 5, '2790');

        // Brouillon : provision de 600 $ (Kalala)
        $builder->forCase($case('0044'), $fiston);

        // La prochaine facture émise recevra FAC-AAAA-00119.
        $numbering->set("invoice:{$year}", 118);
    }
}
