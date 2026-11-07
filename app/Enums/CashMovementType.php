<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Receipt = 'receipt';
    case Disbursement = 'disbursement';
    case Exchange = 'exchange';
    case Adjustment = 'adjustment';
    case OpeningBalance = 'opening_balance';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Encaissement',
            self::Disbursement => 'Décaissement',
            self::Exchange => 'Change',
            self::Adjustment => 'Écart de caisse',
            self::OpeningBalance => 'Solde d\'ouverture',
            self::Reversal => 'Contre-passation',
        };
    }
}
