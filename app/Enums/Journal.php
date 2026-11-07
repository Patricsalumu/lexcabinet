<?php

namespace App\Enums;

/** CPT-03 : ventes, caisse, banque, opérations diverses. */
enum Journal: string
{
    case Sales = 'VT';
    case Cash = 'CA';
    case Bank = 'BQ';
    case Misc = 'OD';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Ventes',
            self::Cash => 'Caisse',
            self::Bank => 'Banque',
            self::Misc => 'Opérations diverses',
        };
    }
}
