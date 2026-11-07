<?php

namespace App\Enums;

/** FAC-09 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Issued => 'Émise',
            self::PartiallyPaid => 'Partiellement payée',
            self::Paid => 'Payée',
            self::Cancelled => 'Annulée',
        };
    }

    /** Une facture émise (ou au-delà) est figée. */
    public function isLocked(): bool
    {
        return $this !== self::Draft;
    }

    /** Facture qui attend un encaissement. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700',
            self::Issued => 'bg-indigo-50 text-indigo-900',
            self::PartiallyPaid => 'bg-amber-50 text-amber-900',
            self::Paid => 'bg-emerald-50 text-emerald-800',
            self::Cancelled => 'bg-slate-100 text-slate-500 line-through',
        };
    }
}
