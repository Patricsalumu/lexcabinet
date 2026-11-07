<?php

namespace App\Enums;

enum Currency: string
{
    case USD = 'USD';
    case CDF = 'CDF';

    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::CDF => 'FC',
        };
    }

    /** Nombre de décimales affichées : 1 250,00 $ et 3 500 000 FC. */
    public function displayDecimals(): int
    {
        return match ($this) {
            self::USD => 2,
            self::CDF => 0,
        };
    }
}
