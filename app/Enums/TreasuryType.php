<?php

namespace App\Enums;

enum TreasuryType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Caisse',
            self::Bank => 'Banque',
            self::MobileMoney => 'Mobile money',
        };
    }
}
