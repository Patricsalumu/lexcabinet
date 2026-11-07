<?php

namespace App\Enums;

/**
 * Catégories de décaissement (CAI-05) et compte de charge associé (CLAUDE.md, écritures automatiques).
 */
enum DisbursementCategory: string
{
    case Rent = 'rent';
    case Salaries = 'salaries';
    case CourtFees = 'court_fees';
    case Transport = 'transport';
    case Supplies = 'supplies';
    case Equipment = 'equipment';
    case ClientDisbursement = 'client_disbursement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => 'Loyer',
            self::Salaries => 'Salaires',
            self::CourtFees => 'Frais de greffe et de justice',
            self::Transport => 'Transport',
            self::Supplies => 'Fournitures',
            self::Equipment => 'Équipement',
            self::ClientDisbursement => 'Débours client',
            self::Other => 'Autre',
        };
    }

    public function ledgerCode(): string
    {
        return match ($this) {
            self::Rent => '622',
            self::Salaries => '661',
            self::CourtFees => '646',
            self::Transport => '618',
            self::Supplies => '6055',
            self::Equipment => '6054',
            self::ClientDisbursement => '4711',
            self::Other => '638',
        };
    }
}
