<?php

namespace App\Services;

use App\Enums\InvoiceLineType;
use App\Models\Invoice;
use App\Support\Money;

/**
 * Totaux d'une facture, en bcmath (jamais en flottants) :
 * - réduction en % sur les seules lignes d'honoraires (FAC-05) ;
 * - TVA calculée après réduction, jamais sur les débours (FAC-08) ;
 * - total = honoraires nets + débours et frais + TVA.
 */
class InvoiceCalculator
{
    public static function lineAmount(string $quantity, string $unitPrice): string
    {
        return Money::round(bcmul($quantity, $unitPrice, 6));
    }

    /**
     * @param  iterable<array{type: InvoiceLineType, amount: string}>  $lines
     * @return array{fees_total: string, discount_amount: string, other_total: string, taxable_base: string, vat_amount: string, total: string}
     */
    public static function totals(iterable $lines, string $discountPercent, bool $vatEnabled, string $vatRate): array
    {
        $fees = '0';
        $otherTaxable = '0';
        $other = '0';

        foreach ($lines as $line) {
            $amount = (string) $line['amount'];
            if ($line['type']->isFee()) {
                $fees = bcadd($fees, $amount, 2);
            } else {
                $other = bcadd($other, $amount, 2);
                if ($line['type']->isTaxable()) {
                    $otherTaxable = bcadd($otherTaxable, $amount, 2);
                }
            }
        }

        $discount = Money::round(bcdiv(bcmul($fees, $discountPercent, 6), '100', 6));
        $feesNet = bcsub($fees, $discount, 2);
        $taxableBase = bcadd($feesNet, $otherTaxable, 2);
        $vat = $vatEnabled ? Money::round(bcdiv(bcmul($taxableBase, $vatRate, 6), '100', 6)) : '0.00';

        return [
            'fees_total' => Money::normalize($fees),
            'discount_amount' => $discount,
            'other_total' => Money::normalize($other),
            'taxable_base' => $vatEnabled ? $taxableBase : '0.00',
            'vat_amount' => $vat,
            'total' => Money::round(bcadd(bcadd($feesNet, $other, 2), $vat, 2)),
        ];
    }

    /** Recalcule et enregistre les totaux d'un brouillon à partir de ses lignes. */
    public function refresh(Invoice $invoice): Invoice
    {
        $lines = $invoice->lines()->get()->map(fn ($l) => ['type' => $l->type, 'amount' => (string) $l->amount]);

        $invoice->fill(self::totals(
            $lines,
            (string) ($invoice->discount_percent ?? '0'),
            (bool) $invoice->vat_enabled,
            (string) ($invoice->vat_rate ?? '0'),
        ))->save();

        return $invoice;
    }
}
