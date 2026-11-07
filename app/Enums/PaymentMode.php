<?php

namespace App\Enums;

/** CAI-03 : espèces, virement, chèque, mobile money. */
enum PaymentMode: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Cheque = 'cheque';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Espèces',
            self::Transfer => 'Virement',
            self::Cheque => 'Chèque',
            self::MobileMoney => 'Mobile money',
        };
    }

    /** Type de compte de trésorerie qui reçoit ou paie selon le mode. */
    public function treasuryType(): TreasuryType
    {
        return match ($this) {
            self::Cash => TreasuryType::Cash,
            self::Transfer, self::Cheque => TreasuryType::Bank,
            self::MobileMoney => TreasuryType::MobileMoney,
        };
    }
}
